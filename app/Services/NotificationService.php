<?php

namespace App\Services;

use App\Models\Odp;
use App\Models\Olt;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Send alert when a POP OLT is DOWN (Mati Listrik / Host Unreachable).
     */
    public static function notifyPopDown(Olt $olt, string $reason = 'Mati Listrik PLN / Host Unreachable'): array
    {
        $setting = Setting::getSetting();
        if (!$setting->notify_pop_down) {
            return ['success' => false, 'message' => 'Notifikasi POP Down dinonaktifkan di pengaturan.'];
        }

        $time = date('d-m-Y H:i:s') . ' WIB';
        $location = $olt->location_name ?: 'POP Area';
        $totalOnu = $olt->total_onu ?: 0;

        $msg = "🚨 *[ALERT EONET NOC] POP OLT PADAM / MATI LISTRIK*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "🏢 *Perangkat:* {$olt->name}\n"
             . "📍 *Lokasi:* {$location}\n"
             . "🌐 *IP Address:* {$olt->ip_address}\n"
             . "⚠️ *Status:* 🔴 *DOWN / MATI LISTRIK*\n"
             . "👥 *Terdampak:* {$totalOnu} Pelanggan ONU\n"
             . "⚡ *Dugaan Gangguan:* {$reason}\n"
             . "⏰ *Waktu Kejadian:* {$time}\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📡 *EONET NOC Automated Fiber Monitoring*";

        return self::broadcastAlert($msg, $setting);
    }

    /**
     * Send recovery alert when a POP OLT is UP (Listrik Hidup Kembali).
     */
    public static function notifyPopUp(Olt $olt, float $downDurationMinutes = 0): array
    {
        $setting = Setting::getSetting();
        if (!$setting->notify_pop_up) {
            return ['success' => false, 'message' => 'Notifikasi POP UP dinonaktifkan di pengaturan.'];
        }

        $time = date('d-m-Y H:i:s') . ' WIB';
        $location = $olt->location_name ?: 'POP Area';
        $durationStr = $downDurationMinutes > 0 ? round($downDurationMinutes, 1) . " Menit" : "Beberapa saat";

        $msg = "✅ *[RECOVERY EONET NOC] LISTRIK HIDUP / POP OLT UP*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "🏢 *Perangkat:* {$olt->name}\n"
             . "📍 *Lokasi:* {$location}\n"
             . "🌐 *IP Address:* {$olt->ip_address}\n"
             . "🟢 *Status:* 🟢 *ONLINE / RECOVERY*\n"
             . "⚡ *Durasi Padam:* {$durationStr}\n"
             . "🌡️ *Suhu OLT:* {$olt->temperature} °C\n"
             . "👥 *Online ONU:* {$olt->online_onu} / {$olt->total_onu} Unit\n"
             . "⏰ *Waktu Pulih:* {$time}\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📡 *EONET NOC Automated Fiber Monitoring*";

        return self::broadcastAlert($msg, $setting);
    }

    /**
     * Send alert when a Fiber Cut / ODP failure occurs.
     */
    public static function notifyOdpFailure(Odp $odp, string $failureType, string $detail = ''): array
    {
        $setting = Setting::getSetting();
        if (!$setting->notify_fiber_cut) {
            return ['success' => false, 'message' => 'Notifikasi ODP dinonaktifkan di pengaturan.'];
        }

        $time = date('d-m-Y H:i:s') . ' WIB';
        $oltName = $odp->olt ? $odp->olt->name : 'OLT Utama';

        $icon = "⚠️";
        $typeLabel = "GANGGUAN ODP";

        if ($failureType === 'fiber_cut') {
            $icon = "✂️";
            $typeLabel = "🔴 KABEL FIBER CUT / PUTUS";
        } elseif ($failureType === 'power_off') {
            $icon = "🔌";
            $typeLabel = "🟡 ADAPTOR DICABUT / MATI LOKAL";
        } elseif ($failureType === 'mati_lampu') {
            $icon = "⚡";
            $typeLabel = "⚡ MATI LAMPU AREA";
        }

        $msg = "{$icon} *[ALERT {$typeLabel}]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📦 *Titik ODP:* {$odp->nama_odp}\n"
             . "📍 *Lokasi:* {$odp->lokasi}\n"
             . "🏢 *OLT Induk:* {$oltName} (PON {$odp->pon_port})\n"
             . "👥 *Terdampak:* {$odp->offline_pelanggan} / {$odp->total_pelanggan} Pelanggan\n"
             . "📋 *Keterangan:* " . ($detail ?: $odp->keterangan_gangguan ?: 'Terdeteksi gangguan') . "\n"
             . "⏰ *Waktu:* {$time}\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Mohon tim lapangan segera cek titik tiang.";

        return self::broadcastAlert($msg, $setting);
    }

    /**
     * Send recovery alert when an ODP is resolved and back online.
     */
    public static function notifyOdpRecovery(Odp $odp): array
    {
        $setting = Setting::getSetting();
        if (!$setting->notify_pop_up && !$setting->notify_fiber_cut) {
            return ['success' => false, 'message' => 'Notifikasi recovery dinonaktifkan di pengaturan.'];
        }

        $time = date('d-m-Y H:i:s') . ' WIB';
        $oltName = $odp->olt ? $odp->olt->name : 'OLT Utama';

        $msg = "✅ *[RECOVERY 🟢 ODP NORMAL KEMBALI]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📦 *Titik ODP:* {$odp->nama_odp}\n"
             . "📍 *Lokasi:* {$odp->lokasi}\n"
             . "🏢 *OLT Induk:* {$oltName} (PON {$odp->pon_port})\n"
             . "👥 *Status:* Seluruh {$odp->total_pelanggan} Pelanggan Online\n"
             . "📶 *Redaman:* Normal (-19.2 dBm)\n"
             . "⏰ *Waktu:* {$time}\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Jalur distribusi telah normal kembali.";

        return self::broadcastAlert($msg, $setting);
    }

    /**
     * Broadcast alert via WhatsApp and Telegram based on configuration.
     */
    public static function broadcastAlert(string $message, ?Setting $setting = null): array
    {
        $setting = $setting ?: Setting::getSetting();
        $results = [
            'whatsapp' => ['sent' => false, 'message' => 'WhatsApp gateway nonaktif'],
            'telegram' => ['sent' => false, 'message' => 'Telegram bot nonaktif'],
        ];

        // 1. Send WhatsApp Gateway (e.g. Fonnte / Wablas / Wwebjs)
        if ($setting->wa_gateway_enabled && !empty($setting->wa_api_token) && !empty($setting->wa_target_phone)) {
            $results['whatsapp'] = self::sendWhatsApp(
                $setting->wa_target_phone,
                $message,
                $setting->wa_api_token,
                $setting->wa_api_url ?: 'https://api.fonnte.com/send',
                $setting->wa_provider ?: 'fonnte'
            );
        }

        // 2. Send Telegram Bot
        if ($setting->telegram_enabled && !empty($setting->telegram_bot_token) && !empty($setting->telegram_chat_id)) {
            $results['telegram'] = self::sendTelegram(
                $setting->telegram_chat_id,
                $message,
                $setting->telegram_bot_token
            );
        }

        ActivityLogService::log(
            'INFO',
            'Notifikasi NOC',
            "Mengirim alert broadcast ke WA/Telegram: " . mb_substr($message, 0, 80) . "...",
            'System Notification'
        );

        return [
            'success' => $results['whatsapp']['sent'] || $results['telegram']['sent'],
            'results' => $results,
        ];
    }

    /**
     * Send message via WhatsApp API.
     */
    public static function sendWhatsApp(string $target, string $message, string $token, string $url = '', string $provider = 'fonnte'): array
    {
        $setting = Setting::getSetting();
        if (!$setting->wa_gateway_enabled) {
            return [
                'sent'    => false,
                'message' => 'Integrasi WhatsApp Gateway (Fonnte) sedang NONAKTIF (OFF) di Pengaturan Sistem.',
            ];
        }

        try {
            // Support phone numbers, WhatsApp Group IDs (e.g. 1203630xxx@g.us), and multiple targets separated by comma
            $targetClean = preg_replace('/[^0-9a-zA-Z@._\-,]/', '', trim($target));
            if (empty($url)) {
                $url = ($provider === 'fonnte') ? 'https://api.fonnte.com/send' : ($setting->wa_api_url ?: 'https://api.fonnte.com/send');
            }

            if ($provider === 'fonnte') {
                $response = Http::withHeaders([
                    'Authorization' => $token,
                ])->timeout(5)->post($url, [
                    'target'  => $targetClean,
                    'message' => $message,
                ]);

                $data = $response->json();
                return [
                    'sent'     => $response->successful() && ($data['status'] ?? false) !== false,
                    'message'  => $data['reason'] ?? ($response->successful() ? 'Terkirim via Fonnte' : 'Gagal kirim'),
                    'response' => $data,
                ];
            }

            // Generic POST Webhook / Wwebjs / Baileys
            $response = Http::withToken($token)->timeout(5)->post($url, [
                'to'      => $targetClean,
                'phone'   => $targetClean,
                'message' => $message,
                'text'    => $message,
            ]);

            return [
                'sent'     => $response->successful(),
                'message'  => $response->successful() ? 'Terkirim via WA Gateway' : 'HTTP Error ' . $response->status(),
                'response' => $response->json() ?: $response->body(),
            ];
        } catch (Throwable $e) {
            Log::error("WhatsApp Notification Error: " . $e->getMessage());
            return [
                'sent'    => false,
                'message' => 'Koneksi WA Gateway error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send message via Telegram Bot API.
     */
    public static function sendTelegram(string $chatId, string $message, string $botToken): array
    {
        try {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $response = Http::timeout(5)->post($url, [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ]);

            $data = $response->json();
            return [
                'sent'     => $response->successful() && ($data['ok'] ?? false) === true,
                'message'  => $response->successful() ? 'Terkirim via Telegram Bot' : ($data['description'] ?? 'Gagal kirim'),
                'response' => $data,
            ];
        } catch (Throwable $e) {
            Log::error("Telegram Notification Error: " . $e->getMessage());
            return [
                'sent'    => false,
                'message' => 'Koneksi Telegram error: ' . $e->getMessage(),
            ];
        }
    }

    public static function notifyCustomerTicketCreated(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        $ispName = $setting->nama_isp ?: 'EONET';
        $time = $ticket->created_at ? $ticket->created_at->format('d/m/Y H:i') . ' WIB' : date('d/m/Y H:i') . ' WIB';

        // 1. KIRIM WHATSAPP KE PELANGGAN HANYA UNTUK TIKET GANGGUAN (PSB DIKECUALIKAN)
        if ($ticket->type === 'trouble' && !empty($ticket->pelanggan_telepon) && !empty($setting->wa_api_token)) {
            $kategori = $ticket->kategori_label;
            $msgCustomer = "🎫 *[TIKET KENDALA BERHASIL DIBUAT - {$ispName}]*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Halo Kak *{$ticket->pelanggan_nama}*,\n\n"
                         . "Laporan keluhan Anda telah berhasil kami daftarkan dan *DIJADWALKAN* ke dalam antrean tim teknisi kami.\n\n"
                         . "📋 *Nomor Tiket:* `{$ticket->ticket_number}`\n"
                         . "📝 *Kendala:* {$kategori}\n"
                         . "📍 *Alamat:* {$ticket->alamat}\n"
                         . "⏳ *Status:* *Terdaftar (Menunggu Penugasan Teknisi)*\n"
                         . "⏰ *Waktu Lapor:* {$time}\n\n"
                         . "Simpan Nomor Tiket ini untuk kemudahan pelacakan. Saat teknisi kami berangkat menuju rumah Anda, Anda akan mendapatkan notifikasi WhatsApp berikutnya.\n\n"
                         . "⚠️ *PENTING:*\n"
                         . "Nomor ini adalah *BROADCAST SISTEM OTOMATIS (NO-REPLY)* dan *TIDAK DAPAT MENERIMA TELEPON/CHAT*.\n"
                         . "━━━━━━━━━━━━━━━━━━━━━━\n"
                         . "📞 *Layanan Pelanggan {$ispName}*";

            self::sendWhatsApp(
                target: $ticket->pelanggan_telepon,
                message: $msgCustomer,
                token: $setting->wa_api_token,
                provider: $setting->wa_provider ?: 'fonnte'
            );
        }

        // 2. BROADCAST KE GRUP TELEGRAM TIM (INTERNAL UNTUK SEMUA JENIS TIKET & PSB)
        self::notifyTeamTicketCreated($ticket);

        return ['sent' => true, 'message' => 'Notifikasi tiket terbuat berhasil diproses.'];
    }

    /**
     * Broadcast new ticket / PSB registration to Team Telegram Group.
     */
    public static function notifyTeamTicketCreated(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        if (empty($setting->telegram_bot_token) || empty($setting->telegram_chat_id)) {
            return ['sent' => false, 'message' => 'Telegram Bot belum dikonfigurasi.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $typeLabel = ($ticket->type === 'psb') ? 'Pasang Baru (PSB)' : ($ticket->type === 'dismantle' ? 'Cabut Alat (Dismantle)' : 'Tiket Gangguan');
        $time = $ticket->created_at ? $ticket->created_at->format('d/m/Y H:i') . ' WIB' : date('d/m/Y H:i') . ' WIB';

        // Format customer phone for direct WhatsApp click
        $cleanPhone = preg_replace('/[^0-9]/', '', (string)$ticket->pelanggan_telepon);
        if (str_starts_with($cleanPhone, '0')) $cleanPhone = '62' . substr($cleanPhone, 1);
        elseif (str_starts_with($cleanPhone, '8')) $cleanPhone = '62' . $cleanPhone;
        $phoneLink = !empty($cleanPhone) ? "[{$ticket->pelanggan_telepon}](https://wa.me/{$cleanPhone}) 💬" : '-';

        // Shareloc & Foto Rumah
        $mapsLink = $ticket->shareloc_url ? "\n🗺️ *Maps:* {$ticket->shareloc_url}" : "";
        $fotoRumahUrl = $ticket->foto_rumah ? (str_starts_with($ticket->foto_rumah, 'http') ? $ticket->foto_rumah : url('storage/' . $ticket->foto_rumah)) : null;
        $fotoRumahLink = $fotoRumahUrl ? "\n🏡 *Foto Rumah:* [Buka Foto Rumah]({$fotoRumahUrl})" : "";

        $odpInfo = $ticket->odp ? "\n📦 *ODP:* {$ticket->odp->nama_odp}" : "";
        $paketInfo = $ticket->paket ?: ($ticket->paket_layanan ?: ($ticket->deskripsi_masalah ?: '-'));
        $marketing = $ticket->nama_marketing ? "\n👤 *Marketing:* {$ticket->nama_marketing}" : "";

        $msgTelegram = "🎫 *[PENDAFTARAN TUGAS BARU - {$ispName}]*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Halo Tim, ada tugas baru masuk ke antrean:\n\n"
                     . "📌 *Jenis:* *{$typeLabel}*\n"
                     . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                     . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}*\n"
                     . "📞 *No. HP:* {$phoneLink}\n"
                     . "📍 *Alamat:* {$ticket->alamat}{$odpInfo}\n"
                     . "📝 *Paket/Kendala:* {$paketInfo}{$marketing}{$mapsLink}{$fotoRumahLink}\n"
                     . "⏰ *Waktu Masuk:* {$time}\n"
                     . "⏳ *Status:* *Menunggu Disposisi Team Leader*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "📡 *EONET Automated Notification System*";

        return self::sendTelegram(
            chatId: $setting->telegram_chat_id,
            message: $msgTelegram,
            botToken: $setting->telegram_bot_token
        );
    }

    /**
     * Send WhatsApp notification to Customer when Technician is dispatched (trouble tickets only).
     */
    public static function notifyCustomerTicketAssigned(\App\Models\Ticket $ticket): array
    {
        // Only send to customer for trouble tickets, exclude PSB as requested
        if ($ticket->type !== 'trouble') {
            return ['sent' => false, 'message' => 'Notifikasi WA customer hanya untuk tiket gangguan (PSB dikecualikan).'];
        }

        $setting = Setting::getSetting();
        if (empty($ticket->pelanggan_telepon) || empty($setting->wa_api_token)) {
            return ['sent' => false, 'message' => 'Nomor HP pelanggan atau WA Gateway belum diatur.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $tech = $ticket->technician;
        $techName = $tech ? $tech->nama : 'Teknisi Lapangan';
        $techPhone = $tech ? ($tech->no_wa ?: '-') : '-';
        $cleanTech = preg_replace('/[^0-9]/', '', $techPhone);
        if (str_starts_with($cleanTech, '0')) $cleanTech = '62' . substr($cleanTech, 1);
        elseif (str_starts_with($cleanTech, '8')) $cleanTech = '62' . $cleanTech;
        $waLinkInfo = !empty($cleanTech) ? "💬 *Chat WA Teknisi:* https://wa.me/{$cleanTech}" : "";

        $msg = "🚗 *[UPDATE TEKNISI DITUGASKAN - {$ispName}]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo Kak *{$ticket->pelanggan_nama}*,\n\n"
             . "Tiket laporan kendala Anda *`{$ticket->ticket_number}`* telah ditugaskan kepada teknisi kami:\n\n"
             . "👤 *Teknisi Bertugas:* *{$techName}*\n"
             . "📋 *Nomor Tiket:* `{$ticket->ticket_number}`\n"
             . "📍 *Tujuan:* {$ticket->alamat}\n"
             . "⚡ *Status:* *Terjadwal Disposisi Teknisi*\n\n"
             . "⚠️ *PENTING:*\n"
             . "Nomor ini adalah *BROADCAST SISTEM OTOMATIS (NO-REPLY)* dan *TIDAK DAPAT DITELEPON / DICHAT*.\n\n"
             . ($waLinkInfo ? "👉 *Untuk chat / koordinasi dengan teknisi:*\n{$waLinkInfo}\n" : "")
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📞 *Tim Lapangan {$ispName}*";

        return self::sendWhatsApp(
            target: $ticket->pelanggan_telepon,
            message: $msg,
            token: $setting->wa_api_token,
            provider: $setting->wa_provider ?: 'fonnte'
        );
    }

    /**
     * Send WhatsApp notification to Customer when Ticket is Resolved.
     */
    public static function notifyCustomerTicketResolved(\App\Models\Ticket $ticket): array
    {
        // Only send resolved WhatsApp to customer for trouble tickets (PSB excluded)
        if ($ticket->type !== 'trouble') {
            return ['sent' => false, 'message' => 'Notifikasi WA penyelesaian hanya untuk tiket gangguan.'];
        }

        $setting = Setting::getSetting();
        if (empty($ticket->pelanggan_telepon) || empty($setting->wa_api_token)) {
            return ['sent' => false, 'message' => 'Nomor HP pelanggan atau WA Gateway belum diatur.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $time = date('d/m/Y H:i') . ' WIB';
        $notes = $ticket->catatan_teknisi ?: 'Perbaikan dan pengecekan sinyal selesai normal.';

        $msg = "✅ *[PENYELESAIAN TIKET - {$ispName}]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo Kak *{$ticket->pelanggan_nama}*,\n\n"
             . "Kabar baik! Tiket kendala Anda *`{$ticket->ticket_number}`* telah *SELESAI* diperbaiki oleh teknisi kami:\n\n"
             . "📋 *Nomor Tiket:* `{$ticket->ticket_number}`\n"
             . "🟢 *Status:* *SELESAI / NORMAL*\n"
             . "⏰ *Waktu Selesai:* {$time}\n"
             . "📝 *Catatan Perbaikan:* {$notes}\n\n"
             . "Silakan periksa kembali koneksi internet Anda. Terima kasih atas kerja sama dan kepercayaan Anda menggunakan layanan {$ispName}.\n\n"
             . "⚠️ *PENTING:*\n"
             . "Nomor ini adalah *BROADCAST SISTEM OTOMATIS (NO-REPLY)* dan *TIDAK MENERIMA TELEPON/CHAT*.\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "⭐ *Layanan Pelanggan {$ispName}*";

        return self::sendWhatsApp(
            target: $ticket->pelanggan_telepon,
            message: $msg,
            token: $setting->wa_api_token,
            provider: $setting->wa_provider ?: 'fonnte'
        );
    }

    /**
     * Send notification to assigned Technician (Telegram Personal & Telegram Team Group).
     * Includes direct click-to-chat WhatsApp link for customer phone, shareloc Maps, and house photo.
     */
    public static function notifyTechnicianAssigned(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        $tech = $ticket->technician;
        if (!$tech) {
            return ['sent' => false, 'message' => 'Teknisi belum ditentukan.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $typeLabel = ($ticket->type === 'psb') ? 'Pasang Baru (PSB)' : ($ticket->type === 'dismantle' ? 'Cabut Alat (Dismantle)' : 'Tiket Gangguan');
        $paketName = $ticket->paket ?: ($ticket->paket_layanan ?: '-');
        $infoKendala = ($ticket->type === 'psb') ? "Paket: {$paketName}" : "Kendala: " . ($ticket->deskripsi_masalah ?: ($ticket->kategori_label ?: '-'));

        // Format Customer Phone as Direct Clickable WhatsApp Link
        $cleanPhone = preg_replace('/[^0-9]/', '', (string)$ticket->pelanggan_telepon);
        if (str_starts_with($cleanPhone, '0')) $cleanPhone = '62' . substr($cleanPhone, 1);
        elseif (str_starts_with($cleanPhone, '8')) $cleanPhone = '62' . $cleanPhone;
        $custPhoneFormatted = !empty($cleanPhone) 
            ? "[{$ticket->pelanggan_telepon}](https://wa.me/{$cleanPhone}) 💬 *(Klik untuk Chat WA)*" 
            : ($ticket->pelanggan_telepon ?: '-');

        // Shareloc & Foto Rumah
        $mapsInfo = $ticket->shareloc_url ? "\n🗺️ *Shareloc Maps:* {$ticket->shareloc_url}" : "";
        $fotoRumahUrl = $ticket->foto_rumah ? (str_starts_with($ticket->foto_rumah, 'http') ? $ticket->foto_rumah : url('storage/' . $ticket->foto_rumah)) : null;
        $fotoRumahInfo = $fotoRumahUrl ? "\n🏡 *Foto Rumah:* [Klik Buka Foto Rumah]({$fotoRumahUrl})" : "";

        $odpInfo = $ticket->odp ? "\n📦 *ODP:* {$ticket->odp->nama_odp}" . ($ticket->port_odp ? " (Port: {$ticket->port_odp})" : "") : "";
        $marketingInfo = $ticket->nama_marketing ? "\n👤 *Marketing:* {$ticket->nama_marketing}" : "";
        $catatanTl = $ticket->catatan_tl ? "\n💬 *Pesan TL:* {$ticket->catatan_tl}" : "";

        $msgTelegram = "🎯 *[TUGAS LAPANGAN BARU - {$ispName}]*\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "Halo *{$tech->nama}*, Anda mendapatkan penugasan tugas baru dari Team Leader:\n\n"
                     . "📌 *Jenis:* *{$typeLabel}*\n"
                     . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                     . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}*\n"
                     . "📞 *No. HP Pelanggan:* {$custPhoneFormatted}\n"
                     . "📍 *Alamat:* {$ticket->alamat}{$odpInfo}\n"
                     . "📝 *Keterangan:* {$infoKendala}{$marketingInfo}{$catatanTl}{$mapsInfo}{$fotoRumahInfo}\n\n"
                     . "👉 Buka aplikasi EONET di HP dan klik *Mulai Pengerjaan / OTW* saat berangkat.\n"
                     . "━━━━━━━━━━━━━━━━━━━━━━\n"
                     . "📡 *EONET Automated Task Router*";

        $sentCount = 0;

        // 1. PRIORITY: Send directly to Technician's Personal Telegram Chat ID
        if (!empty($tech->telegram_chat_id) && !empty($setting->telegram_bot_token)) {
            $resTelTech = self::sendTelegram(
                chatId: $tech->telegram_chat_id,
                message: $msgTelegram,
                botToken: $setting->telegram_bot_token
            );
            if ($resTelTech['sent'] ?? false) $sentCount++;
        }

        // 2. Broadcast copy to Team Telegram Group
        if (!empty($setting->telegram_chat_id) && !empty($setting->telegram_bot_token)) {
            $resTelGroup = self::sendTelegram(
                chatId: $setting->telegram_chat_id,
                message: $msgTelegram,
                botToken: $setting->telegram_bot_token
            );
            if ($resTelGroup['sent'] ?? false) $sentCount++;
        }

        return [
            'sent' => $sentCount > 0,
            'message' => "Notifikasi penugasan terkirim ke Telegram teknisi/grup tim.",
        ];
    }

    /**
     * Send notification alert to Customer Service (CS) & NOC via Telegram when ticket / PSB is completed.
     */
    public static function notifyCsTicketDone(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        if (empty($setting->telegram_bot_token) || empty($setting->telegram_chat_id)) {
            return ['sent' => false, 'message' => 'Telegram Bot belum dikonfigurasi.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $notes = $ticket->catatan_teknisi ?: ($ticket->catatan_noc ?: 'Selesai normal.');
        $isPsb = ($ticket->type === 'psb');
        $time = date('d/m/Y H:i') . ' WIB';

        if ($isPsb) {
            $paketName = $ticket->paket ?: ($ticket->paket_layanan ?: '-');
            $msg = "🎉 *[LAPORAN PASANG BARU (PSB) SELESAI & AKTIF]*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Halo Tim NOC & CS,\n\n"
                 . "Pemasangan Baru (PSB) telah *SELESAI & DIAKTIFKAN RESMI*:\n\n"
                 . "📋 *No. Registrasi:* `{$ticket->ticket_number}`\n"
                 . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}*\n"
                 . "📦 *Paket:* {$paketName}\n"
                 . "🌐 *PPPoE:* " . ($ticket->pelanggan_username ?: '-') . "\n"
                 . "📊 *Redaman Akhir (OPM):* " . ($ticket->redaman_sesudah ?: 'Normal') . " dBm\n"
                 . "🔧 *Teknisi:* " . ($ticket->technician?->nama ?? 'Tim Lapangan') . "\n"
                 . "⏰ *Waktu Selesai:* {$time}\n"
                 . "📝 *Catatan:* {$notes}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📡 *EONET Automated System*";
        } else {
            $msg = "📢 *[PEMBERITAHUAN - TIKET GANGGUAN SELESAI BY NOC]*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Halo Tim CS & NOC,\n\n"
                 . "Tiket laporan kendala pelanggan telah *SELESAI DIVERIFIKASI RESMI*:\n\n"
                 . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                 . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}* [PPPoE: " . ($ticket->pelanggan_username ?: '-') . "]\n"
                 . "🟢 *Status:* *DONE (PPPoE & Sinyal Normal)*\n"
                 . "📊 *Redaman Akhir (OPM):* " . ($ticket->redaman_sesudah ?: 'Normal') . " dBm\n"
                 . "⏰ *Waktu Selesai:* {$time}\n"
                 . "📝 *Catatan:* {$notes}\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📡 *EONET NOC Automated System*";
        }

        return self::sendTelegram(
            chatId: $setting->telegram_chat_id,
            message: $msg,
            botToken: $setting->telegram_bot_token
        );
    }

    /**
     * Send notification alert to Team Telegram Group when Dismantle (Cabut Alat) is completed.
     */
    public static function notifyDismantleCompleted(\App\Models\Ticket $ticket, array $mikrotikStatus = []): array
    {
        $setting = Setting::getSetting();
        if (empty($setting->telegram_bot_token) || empty($setting->telegram_chat_id)) {
            return ['sent' => false, 'message' => 'Telegram Bot belum dikonfigurasi.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $time = date('d/m/Y H:i') . ' WIB';
        $notes = $ticket->catatan_teknisi ?: 'Perangkat modem & adaptor berhasil diamankan.';
        $kelengkapan = $ticket->kelengkapan_alat ?: 'Modem ONT & Adaptor';
        $mtStatus = $mikrotikStatus['message'] ?? 'Secret PPPoE dinonaktifkan (Disabled)';

        $msg = "📦 *[LAPORAN CABUT ALAT SELESAI (DISMANTLE)]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo Tim Finance, TL, & NOC,\n\n"
             . "Penarikan / Cabut Alat pelanggan telah *SELESAI DILAKUKAN* oleh Teknisi:\n\n"
             . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
             . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}*\n"
             . "🌐 *PPPoE:* `{$ticket->pelanggan_username}`\n"
             . "📞 *No. HP:* {$ticket->pelanggan_telepon}\n"
             . "📍 *Alamat:* {$ticket->alamat}\n"
             . "📦 *Kelengkapan:* {$kelengkapan}\n"
             . "🔢 *SN ONT:* " . ($ticket->serial_number_ont ?: '-') . "\n"
             . "🔧 *Teknisi Bertugas:* " . ($ticket->technician?->nama ?? 'Tim Lapangan') . "\n"
             . "⏰ *Waktu Cabut:* {$time}\n"
             . "🔌 *Status MikroTik:* {$mtStatus}\n"
             . "📝 *Catatan:* {$notes}\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📡 *EONET Automated System*";

        return self::sendTelegram(
            chatId: $setting->telegram_chat_id,
            message: $msg,
            botToken: $setting->telegram_bot_token
        );
    }

    /**
     * Send WhatsApp notification when Technician clicks OTW / Menuju Lokasi (to Customer, with No-Reply & Tech WA Link; and update to Telegram Group).
     */
    public static function notifyCustomerTechnicianOtw(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        $ispName = $setting->nama_isp ?: 'EONET';
        $tech = $ticket->technician;
        $techName = $tech ? $tech->nama : 'Teknisi Lapangan';
        $techPhone = $tech ? ($tech->no_wa ?: '-') : '-';
        $time = date('d/m/Y H:i') . ' WIB';

        $waLinkInfo = '';
        if ($tech && !empty($tech->no_wa)) {
            $cleanTech = preg_replace('/[^0-9]/', '', $tech->no_wa);
            if (str_starts_with($cleanTech, '0')) $cleanTech = '62' . substr($cleanTech, 1);
            elseif (str_starts_with($cleanTech, '8')) $cleanTech = '62' . $cleanTech;
            $waLinkInfo = "💬 *Chat WA Teknisi:* https://wa.me/{$cleanTech}";
        }

        $typeLabel = ($ticket->type === 'psb') ? 'Pemasangan Baru (PSB)' : ($ticket->type === 'dismantle' ? 'Penarikan / Cabut Alat' : 'Perbaikan Kendala Internet');
        $sentCount = 0;

        // 1. Notifikasi WhatsApp ke PELANGGAN (dengan No-Reply Disclaimer & Direct Link Chat Teknisi)
        if (!empty($ticket->pelanggan_telepon) && !empty($setting->wa_api_token)) {
            $msgCustomer = "🚗 *[TEKNISI MENUJU KE LOKASI RUMAH ANDA - {$ispName}]*\n"
                         . "━━━━━━━━━━━━━━━━━━━━━━\n"
                         . "Halo Kak *{$ticket->pelanggan_nama}*,\n\n"
                         . "Teknisi kami saat ini sedang dalam perjalanan (*OTW*) menuju lokasi rumah Anda untuk pengerjaan *{$typeLabel}*:\n\n"
                         . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                         . "👤 *Teknisi Bertugas:* *{$techName}*\n"
                         . "📍 *Alamat:* {$ticket->alamat}\n"
                         . "⏰ *Waktu Berangkat:* {$time}\n\n"
                         . "Mohon pastikan ada orang di rumah untuk mempermudah teknisi saat tiba di lokasi.\n\n"
                         . "⚠️ *PENTING:*\n"
                         . "Nomor ini adalah *BROADCAST SISTEM OTOMATIS (TIDAK DAPAT DITELEPON / DICHAT)*.\n\n"
                         . ($waLinkInfo ? "👉 *Untuk koordinasi lokasi/chat, silakan hubungi langsung Teknisi kami:*\n{$waLinkInfo}\n\n" : "")
                         . "━━━━━━━━━━━━━━━━━━━━━━\n"
                         . "📞 *Tim Lapangan {$ispName}*";

            $resCust = self::sendWhatsApp(
                target: $ticket->pelanggan_telepon,
                message: $msgCustomer,
                token: $setting->wa_api_token,
                provider: $setting->wa_provider ?: 'fonnte'
            );
            if ($resCust['sent'] ?? false) $sentCount++;
        }

        // 2. Pemberitahuan Internal ke Tim NOC & TEAM LEADER via TELEGRAM
        $msgNocTl = "🚗 *[INFO OTW - TEKNISI MENUJU LOKASI]*\n"
                  . "━━━━━━━━━━━━━━━━━━━━━━\n"
                  . "Halo Tim NOC & Team Leader,\n\n"
                  . "Teknisi telah mengonfirmasi berangkat (*OTW*) menuju lokasi pelanggan:\n\n"
                  . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                  . "👤 *Pelanggan:* {$ticket->pelanggan_nama} [PPPoE: " . ($ticket->pelanggan_username ?: '-') . "]\n"
                  . "🔧 *Teknisi:* *{$techName}* ({$techPhone})\n"
                  . "📍 *Alamat:* {$ticket->alamat}\n"
                  . "⏰ *Waktu Berangkat:* {$time}\n"
                  . "⚡ *Status:* *In-Progress (OTW Lapangan)*\n"
                  . "━━━━━━━━━━━━━━━━━━━━━━\n"
                  . "📡 *EONET NOC Automated System*";

        if (!empty($setting->telegram_bot_token) && !empty($setting->telegram_chat_id)) {
            self::sendTelegram(
                chatId: $setting->telegram_chat_id,
                message: $msgNocTl,
                botToken: $setting->telegram_bot_token
            );
            $sentCount++;
        }

        return ['sent' => $sentCount > 0, 'message' => "Notifikasi OTW berhasil dikirim."];
    }

    /**
     * Send WhatsApp notification to Customer when Technician arrived but House is Empty / Pending.
     */
    public static function notifyCustomerPendingHouseEmpty(\App\Models\Ticket $ticket, string $notes = '', string $kategori = 'rumah_kosong'): array
    {
        $setting = Setting::getSetting();
        if (empty($ticket->pelanggan_telepon) || empty($setting->wa_api_token) || !$setting->wa_gateway_enabled) {
            return ['sent' => false, 'message' => 'Nomor HP pelanggan atau token WA Gateway belum diatur / dinonaktifkan.'];
        }

        $ispName = $setting->nama_isp ?: 'EONET';
        $tech = $ticket->technician;
        $techName = $tech ? $tech->nama : 'Teknisi Lapangan';
        $techPhone = $tech ? ($tech->no_wa ?: '-') : '-';
        $time = date('d/m/Y H:i') . ' WIB';

        $waLink = '';
        if ($tech && !empty($tech->no_wa)) {
            $cleanTech = preg_replace('/[^0-9]/', '', $tech->no_wa);
            if (str_starts_with($cleanTech, '0')) $cleanTech = '62' . substr($cleanTech, 1);
            elseif (str_starts_with($cleanTech, '8')) $cleanTech = '62' . $cleanTech;
            $waLink = "https://wa.me/{$cleanTech}";
        }

        $reasonTitle = "RUMAH DALAM KEADAAN KOSONG / PELANGGAN TIDAK ADA DI LOKASI";
        if (str_contains(strtolower($notes), 'reschedule') || $kategori === 'reschedule') {
            $reasonTitle = "PERMINTAAN RESCHEDULE / JADWAL ULANG";
        } elseif (str_contains(strtolower($notes), 'sparepart') || $kategori === 'sparepart') {
            $reasonTitle = "MENUNGGU MATERIAL / PERANGKAT KHUSUS";
        }

        $msg = "⚠️ *[PEMBERITAHUAN KUNJUNGAN TEKNISI - {$ispName}]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo Kak *{$ticket->pelanggan_nama}*,\n\n"
             . "Teknisi kami *{$techName}* telah tiba di lokasi alamat Anda untuk pengerjaan tiket `{$ticket->ticket_number}`:\n\n"
             . "📌 *Status:* *{$reasonTitle}*\n"
             . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
             . "👤 *Teknisi Bertugas:* *{$techName}*\n"
             . "⏰ *Waktu Kunjungan:* {$time}\n"
             . "📝 *Catatan Lapangan:* " . ($notes ?: 'Pelanggan tidak berada di lokasi saat teknisi tiba.') . "\n\n"
             . "Tiket Anda saat ini dalam status *PENDING*. Untuk konfirmasi jadwal kedatangan atau penjadwalan ulang, silakan hubungi langsung teknisi kami:\n"
             . ($waLink ? "👉 *Chat WhatsApp Teknisi:* {$waLink}\n" : "")
             . "📞 *Telepon Teknisi:* {$techPhone}\n\n"
             . "Terima kasih atas kerja samanya.\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📞 *Layanan Pelanggan {$ispName}*";

        return self::sendWhatsApp(
            target: $ticket->pelanggan_telepon,
            message: $msg,
            token: $setting->wa_api_token,
            provider: $setting->wa_provider ?: 'fonnte'
        );
    }

    /**
     * Alias for pending sparepart / customer absent notification.
     */
    public static function notifyCustomerPendingSparepart(\App\Models\Ticket $ticket, string $notes = '', string $kategori = 'rumah_kosong'): array
    {
        return self::notifyCustomerPendingHouseEmpty($ticket, $notes, $kategori);
    }

    /**
     * Send alert to Telegram Team Group when Technician / TL submits resolution.
     */
    public static function notifyNocTicketResolvedByTechnician(\App\Models\Ticket $ticket): array
    {
        $setting = Setting::getSetting();
        if (empty($setting->telegram_bot_token) || empty($setting->telegram_chat_id)) {
            return ['sent' => false, 'message' => 'Telegram Bot belum dikonfigurasi.'];
        }

        $tech = $ticket->technician;
        $techName = $tech ? $tech->nama : 'Teknisi Lapangan';
        $time = date('d/m/Y H:i') . ' WIB';
        $pppoe = $ticket->pelanggan_username ?: '-';
        $redaman = $ticket->redaman_sesudah ?: ($ticket->redaman_sebelum ?: 'Normal');
        $notes = $ticket->catatan_teknisi ?: 'Pekerjaan fisik lapangan selesai.';
        $isPsb = ($ticket->type === 'psb');

        $ticketUrl = url("/tiket/{$ticket->id}");

        if ($isPsb) {
            $msg = "🔔 *[LAPORAN PASANG BARU SELESAI - NOTIFIKASI NOC]*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Halo Tim NOC / Administrator,\n\n"
                 . "Teknisi di lapangan telah melaporkan *PASANG BARU SELESAI (PSB DONE)*:\n\n"
                 . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                 . "👤 *Pelanggan:* {$ticket->pelanggan_nama}\n"
                 . "🌐 *PPPoE:* {$pppoe}\n"
                 . "🔧 *Teknisi:* *{$techName}*\n"
                 . "📊 *Redaman Akhir:* {$redaman} dBm\n"
                 . "📝 *Catatan:* {$notes}\n"
                 . "⏰ *Waktu:* {$time}\n\n"
                 . "👉 *Klik untuk Validasi / Alokasi VLAN:* [Buka Tiket]({$ticketUrl})\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📡 *EONET NOC Automated System*";
        } else {
            $msg = "🔔 *[LAPORAN TEKNISI SELESAI - MENUNGGU VERIFIKASI NOC]*\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "Halo Tim NOC / Administrator,\n\n"
                 . "Teknisi di lapangan telah melaporkan *SELESAI PERBAIKAN (DONE PPPoE)* pada tiket gangguan:\n\n"
                 . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                 . "👤 *Pelanggan:* {$ticket->pelanggan_nama} [PPPoE: {$pppoe}]\n"
                 . "🔧 *Teknisi:* *{$techName}*\n"
                 . "📊 *Redaman Akhir:* {$redaman} dBm\n"
                 . "📝 *Catatan:* {$notes}\n"
                 . "⏰ *Waktu:* {$time}\n\n"
                 . "👉 *Klik untuk Validasi & Tutup Tiket:* [Buka Tiket]({$ticketUrl})\n"
                 . "━━━━━━━━━━━━━━━━━━━━━━\n"
                 . "📡 *EONET NOC Automated System*";
        }

        return self::sendTelegram(
            chatId: $setting->telegram_chat_id,
            message: $msg,
            botToken: $setting->telegram_bot_token
        );
    }

    /**
     * Forward Customer WhatsApp Reply to Assigned Technician and Team Group via Telegram.
     */
    public static function notifyCustomerReplyForwarded(\App\Models\Ticket $ticket, string $incomingMessage, string $senderPhone, string $senderName = ''): array
    {
        $setting = Setting::getSetting();
        $ispName = $setting->nama_isp ?: 'EONET';
        $time = date('d/m/Y H:i') . ' WIB';
        $tech = $ticket->technician;

        // Clean phone for direct wa.me link
        $cleanSender = preg_replace('/[^0-9]/', '', $senderPhone);
        if (str_starts_with($cleanSender, '0')) $cleanSender = '62' . substr($cleanSender, 1);
        elseif (str_starts_with($cleanSender, '8')) $cleanSender = '62' . $cleanSender;

        $forwardMsg = "💬 *[BALASAN WHATSAPP PELANGGAN - {$ispName}]*\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👤 *Pelanggan:* *{$ticket->pelanggan_nama}*\n"
                    . "📋 *No. Tiket:* `{$ticket->ticket_number}`\n"
                    . "📍 *Alamat:* {$ticket->alamat}\n"
                    . "📩 *Isi Pesan Pelanggan:*\n"
                    . "──────────────────────\n"
                    . "“_{$incomingMessage}_”\n"
                    . "──────────────────────\n\n"
                    . "👉 *Chat Balik Pelanggan:* https://wa.me/{$cleanSender}\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📡 *EONET Automated Webhook Router*";

        $sentCount = 0;

        // 1. Forward directly to Assigned Technician's Personal Telegram
        if ($tech && !empty($tech->telegram_chat_id) && !empty($setting->telegram_bot_token)) {
            $res = self::sendTelegram(
                chatId: $tech->telegram_chat_id,
                message: $forwardMsg,
                botToken: $setting->telegram_bot_token
            );
            if ($res['sent'] ?? false) $sentCount++;
        }

        // 2. Broadcast to Telegram Team Group
        if (!empty($setting->telegram_bot_token) && !empty($setting->telegram_chat_id)) {
            $res = self::sendTelegram(
                chatId: $setting->telegram_chat_id,
                message: $forwardMsg,
                botToken: $setting->telegram_bot_token
            );
            if ($res['sent'] ?? false) $sentCount++;
        }

        return [
            'sent' => $sentCount > 0,
            'message' => "Balasan pelanggan diteruskan ke Telegram.",
        ];
    }
}
