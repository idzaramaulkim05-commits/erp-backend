<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Ticket;
use App\Models\Setting;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle incoming WhatsApp Webhook messages (Fonnte / Direct Gateway).
     */
    public function handleWhatsApp(Request $request): JsonResponse
    {
        // Support all Fonnte / WhatsApp payload styles (JSON, form-data, query)
        $sender = (string) ($request->input('sender') ?? ($request->input('from') ?? ($request->input('phone') ?? '')));
        $message = trim((string) ($request->input('message') ?? ($request->input('text') ?? ($request->input('body') ?? ''))));
        $senderName = (string) ($request->input('name') ?? ($request->input('pushname') ?? ''));

        if (empty($sender) || empty($message)) {
            return response()->json([
                'status'  => false,
                'message' => 'Empty sender or message.',
            ], 200); // Return 200 to acknowledge webhook providers
        }

        // Clean sender phone number
        $cleanSender = preg_replace('/[^0-9]/', '', $sender);

        Log::info("WhatsApp Webhook Received from {$cleanSender} ({$senderName}): '{$message}'");

        // 1. Search for matching active ticket
        $ticket = Ticket::findActiveByPhone($cleanSender);

        if (!$ticket) {
            ActivityLogService::log(
                'INFO',
                'WA Webhook (No Ticket Matched)',
                "Pesan WA masuk dari {$cleanSender}: \"{$message}\" (Tidak terhubung ke tiket aktif)",
                'WhatsApp Gateway'
            );

            return response()->json([
                'status'  => true,
                'matched' => false,
                'message' => "Pesan diterima namun tidak ada tiket aktif yang cocok dengan nomor {$cleanSender}.",
            ], 200);
        }

        // 2. Update Ticket record with latest customer reply
        $ticket->customer_last_reply = $message;
        $ticket->customer_last_reply_at = now();
        $ticket->save();

        // 3. Record in Ticket Log / Activity History
        $logAction = '💬 Balasan WhatsApp Pelanggan';
        $logNotes = "“{$message}” (Diterima via WhatsApp Webhook dari {$sender})";
        
        $ticket->logs()->create([
            'user_id'     => null,
            'user_name'   => $senderName ?: "Pelanggan ({$ticket->pelanggan_nama})",
            'action'      => $logAction,
            'from_status' => $ticket->status,
            'to_status'   => $ticket->status,
            'notes'       => $logNotes,
        ]);

        ActivityLogService::log(
            'SUCCESS',
            'WA Balasan Pelanggan',
            "Pelanggan #{$ticket->ticket_number} ({$ticket->pelanggan_nama}) membalas via WA: \"{$message}\"",
            'WhatsApp Gateway'
        );

        // 4. Forward notification to Technician, Team Leader, and NOC
        $forwardResult = NotificationService::notifyCustomerReplyForwarded(
            ticket: $ticket,
            incomingMessage: $message,
            senderPhone: $cleanSender,
            senderName: $senderName
        );

        // 5. Store live event in Cache for instant UI popup (5 minutes expiry)
        $replyEvent = [
            'ticket_id'     => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'customer_name' => $ticket->pelanggan_nama,
            'message'       => $message,
            'time'          => now()->format('H:i') . ' WIB',
            'timestamp'     => now()->timestamp,
        ];
        Cache::put('latest_customer_reply_event_' . $ticket->id, $replyEvent, 300);
        Cache::put('global_latest_reply_event', $replyEvent, 300);

        return response()->json([
            'status'         => true,
            'matched'        => true,
            'ticket_number'  => $ticket->ticket_number,
            'forward_status' => $forwardResult['message'] ?? 'OK',
            'message'        => "✅ Balasan pelanggan #{$ticket->ticket_number} berhasil dicatat dan diteruskan ke tim lapangan & NOC.",
        ], 200);
    }
}
