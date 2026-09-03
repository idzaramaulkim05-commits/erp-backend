<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Olt;
use App\Models\Router;
use App\Models\Setting;
use App\Services\ActivityLogService;
use App\Services\MikrotikService;
use App\Services\OltService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Display Master Setting (Submenu: Router, OLT, Integrasi WhatsApp/Sheet, Parameter ISP).
     */
    public function index(Request $request): View
    {
        $setting = Setting::getSetting();
        $routers = Router::orderBy('is_default', 'desc')->orderBy('name', 'asc')->get();
        $olts = Olt::orderBy('id', 'asc')->get();
        $activeTab = $request->query('tab', 'router');
        $defaultRouter = Router::getDefaultRouter();
        $telemetry = (new MikrotikService($defaultRouter))->getTelemetry(true);

        return view('setting.index', [
            'page'       => 'setting',
            'setting'    => $setting,
            'routers'    => $routers,
            'olts'       => $olts,
            'activeTab'  => $activeTab,
            'telemetry'  => $telemetry,
        ]);
    }

    /**
     * Verify administrator password to unlock sensitive credentials.
     */
    public function verifyAdmin(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => '🔴 Sesi tidak valid. Silakan login kembali.',
            ], 401);
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            ActivityLogService::log(
                'WARNING',
                'Verifikasi Pengaturan Gagal',
                "Percobaan membuka kunci kredensial Master Setting gagal dengan password salah oleh {$user->username}",
                $user->username
            );

            return response()->json([
                'success' => false,
                'message' => '🔴 Password verifikasi salah! Akses kredensial ditolak.',
            ], 403);
        }

        session(['master_settings_unlocked_until' => now()->addMinutes(30)]);

        ActivityLogService::log(
            'INFO',
            'Buka Kunci Master Setting',
            "Super Admin {$user->username} berhasil memverifikasi password dan membuka parameter Master Setting",
            $user->username
        );

        return response()->json([
            'success' => true,
            'message' => '✅ Verifikasi berhasil! Kredensial telah dibuka.',
        ]);
    }

    /**
     * Update general ISP & integration settings (WhatsApp Gateway, Google Sheet, ISP Identity).
     * 100% INDEPENDENT: Does not touch or disable any router in routers table.
     */
    public function update(Request $request): RedirectResponse
    {
        $setting = Setting::getSetting();

        $validated = $request->validate([
            'nama_isp'                 => 'nullable|string|max:100',
            'refresh_interval'         => 'nullable|numeric|min:1|max:3600',
            'gateway_ip'               => 'nullable|string|max:50',
            'ping_dns1'                => 'nullable|string|max:50',
            'ping_dns2'                => 'nullable|string|max:50',
            'wa_gateway_enabled'       => 'nullable|boolean',
            'wa_provider'              => 'nullable|string|max:50',
            'wa_api_token'             => 'nullable|string|max:255',
            'wa_api_url'               => 'nullable|string|max:255',
            'wa_target_phone'          => 'nullable|string|max:100',
            'telegram_enabled'         => 'nullable|boolean',
            'telegram_bot_token'       => 'nullable|string|max:255',
            'telegram_chat_id'         => 'nullable|string|max:100',
            'notify_pop_down'          => 'nullable|boolean',
            'notify_pop_up'            => 'nullable|boolean',
            'notify_fiber_cut'         => 'nullable|boolean',
            'google_sheet_url'         => 'nullable|string|max:500',
            'google_sheet_webhook_url' => 'nullable|string|max:500',
            'sheet_tab_pelanggan_fix'  => 'nullable|string|max:100',
            'gdrive_folder_foto_odp'         => 'nullable|string|max:255',
            'gdrive_folder_foto_redaman'     => 'nullable|string|max:255',
            'gdrive_folder_foto_dokumen'     => 'nullable|string|max:255',
            'gdrive_folder_foto_onu'         => 'nullable|string|max:255',
            'gdrive_folder_foto_rumah'       => 'nullable|string|max:255',
            'gdrive_folder_foto_evidence'    => 'nullable|string|max:255',
            'gdrive_folder_foto_payments'    => 'nullable|string|max:255',
            'gdrive_folder_foto_label_kabel' => 'nullable|string|max:255',
        ]);

        $validated['nama_isp'] = $validated['nama_isp'] ?? $setting->nama_isp;
        $validated['refresh_interval'] = $validated['refresh_interval'] ?? $setting->refresh_interval ?? 2;
        $validated['wa_gateway_enabled'] = $request->has('wa_gateway_enabled')
            ? $request->boolean('wa_gateway_enabled')
            : (bool) $setting->wa_gateway_enabled;
        $validated['telegram_enabled'] = $request->has('telegram_enabled')
            ? $request->boolean('telegram_enabled')
            : (bool) $setting->telegram_enabled;
        $validated['notify_pop_down'] = $request->has('notify_pop_down')
            ? $request->boolean('notify_pop_down')
            : (bool) $setting->notify_pop_down;
        $validated['notify_pop_up'] = $request->has('notify_pop_up')
            ? $request->boolean('notify_pop_up')
            : (bool) $setting->notify_pop_up;
        $validated['notify_fiber_cut'] = $request->has('notify_fiber_cut')
            ? $request->boolean('notify_fiber_cut')
            : (bool) $setting->notify_fiber_cut;

        $setting->update($validated);

        // Clear general caches
        Cache::forget('system_setting');
        Cache::forget('system_setting_general');

        ActivityLogService::log(
            'INFO',
            'Master Setting Update',
            "Memperbarui parameter ISP & Integrasi ({$setting->nama_isp})",
            Auth::user()?->nama ?? 'Admin'
        );

        $tab = $request->input('redirect_tab', 'general');

        return redirect()->route('setting.index', ['tab' => $tab])
            ->with('sukses', '✅ Pengaturan Sistem & Integrasi berhasil disimpan!');
    }

    /**
     * Set a router as primary default.
     */
    public function setDefaultRouter(int $id): RedirectResponse
    {
        Router::query()->update(['is_default' => false]);
        $router = Router::findOrFail($id);
        $router->is_default = true;
        $router->is_active = true;
        $router->save();

        Cache::forget('global_header_telemetry');
        Cache::forget('mikrotik_telemetry_default');

        return redirect()->route('setting.index', ['tab' => 'router'])
            ->with('sukses', "⭐ Router '{$router->name}' berhasil dijadikan sebagai Router Utama (Default)!");
    }

    /**
     * Quick toggle router active status.
     */
    public function toggleRouter(int $id): RedirectResponse
    {
        $router = Router::findOrFail($id);
        $router->is_active = !$router->is_active;
        $router->save();

        Cache::forget('mikrotik_telemetry_router_' . $router->id);
        Cache::forget('mikrotik_interfaces_router_' . $router->id);

        $statusStr = $router->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('setting.index', ['tab' => 'router'])
            ->with('sukses', "Status Router '{$router->name}' berhasil {$statusStr}.");
    }

    /**
     * Quick toggle OLT active status.
     */
    public function toggleOlt(int $id): RedirectResponse
    {
        $olt = Olt::findOrFail($id);
        $olt->is_active = !$olt->is_active;
        $olt->save();

        $statusStr = $olt->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()->route('setting.index', ['tab' => 'olt'])
            ->with('sukses', "Status OLT '{$olt->name}' berhasil {$statusStr}.");
    }

    /**
     * AJAX Test Connection for a Router.
     */
    public function testRouter(Request $request): JsonResponse
    {
        $routerId = $request->input('router_id');
        $router = $routerId ? Router::find($routerId) : null;

        if (!$router) {
            // Build temporary router from form input
            $router = new Router([
                'name'       => $request->input('name', 'Test Router'),
                'ip_address' => $request->input('ip_address'),
                'port'       => (int) $request->input('port', 8728),
                'username'   => $request->input('username', 'admin'),
                'password'   => $request->input('password', ''),
                'is_active'  => true,
            ]);
        }

        $service = new MikrotikService($router);
        $telemetry = $service->getTelemetry(false);

        if (!empty($telemetry['connected'])) {
            return response()->json([
                'success' => true,
                'message' => "✅ Koneksi ke Router MikroTik '{$router->name}' ({$router->ip_address}:{$router->port}) BERHASIL! (Model: " . ($telemetry['board_name'] ?? $telemetry['board'] ?? 'MikroTik') . ", CPU: " . ($telemetry['cpu_load'] ?? $telemetry['cpu'] ?? '0%') . ")",
                'telemetry' => $telemetry,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "🔴 Gagal terhubung ke Router MikroTik '{$router->name}' ({$router->ip_address}:{$router->port}). Pastikan IP, Port API (8728), Username, dan Password sudah benar.",
        ]);
    }

    /**
     * Test sending Telegram message.
     */
    public function testTelegram(Request $request): JsonResponse
    {
        $botToken = trim((string)$request->input('bot_token', ''));
        $chatId = trim((string)$request->input('chat_id', ''));

        if (empty($botToken) || empty($chatId)) {
            $setting = Setting::getSetting();
            $botToken = $botToken ?: $setting->telegram_bot_token;
            $chatId = $chatId ?: $setting->telegram_chat_id;
        }

        if (empty($botToken) || empty($chatId)) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Token Bot Telegram atau Chat ID belum diisi.',
            ], 422);
        }

        $time = date('d/m/Y H:i:s') . ' WIB';
        $user = Auth::user()?->nama ?? 'Admin';
        $msg = "🤖 *[TEST NOTIFIKASI TELEGRAM EONET]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo Tim, integrasi Telegram Bot berhasil terhubung ke sistem!\n\n"
             . "👤 *Pengirim:* {$user}\n"
             . "⏰ *Waktu Uji:* {$time}\n"
             . "⚡ *Status:* 🟢 *ONLINE & READY*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📡 *EONET Automated Notification System*";

        $res = \App\Services\NotificationService::sendTelegram($chatId, $msg, $botToken);

        return response()->json([
            'success' => $res['sent'] ?? false,
            'message' => ($res['sent'] ?? false) ? '✅ Pesan uji coba berhasil terkirim ke Telegram!' : ('🔴 Gagal: ' . ($res['message'] ?? 'Periksa Token Bot & Chat ID')),
        ]);
    }

    /**
     * Test sending WhatsApp message.
     */
    public function testWa(Request $request): JsonResponse
    {
        $token = trim((string)$request->input('token', ''));
        $target = trim((string)$request->input('target', ''));
        $provider = trim((string)$request->input('provider', 'fonnte'));

        if (empty($token) || empty($target)) {
            $setting = Setting::getSetting();
            $token = $token ?: $setting->wa_api_token;
            $target = $target ?: $setting->wa_target_phone;
            $provider = $provider ?: ($setting->wa_provider ?: 'fonnte');
        }

        if (empty($token) || empty($target)) {
            return response()->json([
                'success' => false,
                'message' => '⚠️ Token API WhatsApp atau Nomor Target belum diisi.',
            ], 422);
        }

        $time = date('d/m/Y H:i:s') . ' WIB';
        $user = Auth::user()?->nama ?? 'Admin';
        $msg = "💬 *[TEST NOTIFIKASI WHATSAPP EONET]*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "Halo, integrasi WhatsApp Gateway berhasil terhubung ke sistem!\n\n"
             . "👤 *Pengirim:* {$user}\n"
             . "⏰ *Waktu Uji:* {$time}\n"
             . "⚡ *Status:* 🟢 *ONLINE*\n"
             . "━━━━━━━━━━━━━━━━━━━━━━\n"
             . "📡 *EONET Automated System*";

        $res = \App\Services\NotificationService::sendWhatsApp($target, $msg, $token, $provider);

        return response()->json([
            'success' => $res['sent'] ?? false,
            'message' => ($res['sent'] ?? false) ? '✅ Pesan uji coba berhasil terkirim ke WhatsApp!' : ('🔴 Gagal: ' . ($res['message'] ?? 'Periksa Token & Nomor Target')),
        ]);
    }
}
