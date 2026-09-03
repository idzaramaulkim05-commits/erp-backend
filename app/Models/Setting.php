<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'setting';

    public $timestamps = false;

    protected $fillable = [
        'nama_isp',
        'mikrotik_ip',
        'mikrotik_user',
        'mikrotik_password',
        'mikrotik_port',
        'wan_interface',
        'pppoe_interface',
        'refresh_interval',
        'logo',
        'gateway_ip',
        'ping_dns1',
        'ping_dns2',
        'wa_gateway_enabled',
        'wa_provider',
        'wa_api_token',
        'wa_api_url',
        'wa_target_phone',
        'telegram_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
        'notify_pop_down',
        'notify_pop_up',
        'notify_fiber_cut',
        'google_sheet_url',
        'google_sheet_webhook_url',
        'sheet_tab_pelanggan_fix',
        'gdrive_folder_foto_odp',
        'gdrive_folder_foto_redaman',
        'gdrive_folder_foto_dokumen',
        'gdrive_folder_foto_onu',
        'gdrive_folder_foto_rumah',
        'gdrive_folder_foto_evidence',
        'gdrive_folder_foto_payments',
        'gdrive_folder_foto_label_kabel',
        'sheet_last_synced_at',
    ];

    protected $casts = [
        'sheet_last_synced_at' => 'datetime',
        'wa_gateway_enabled'   => 'boolean',
        'telegram_enabled'     => 'boolean',
        'notify_pop_down'      => 'boolean',
        'notify_pop_up'        => 'boolean',
        'notify_fiber_cut'     => 'boolean',
    ];

    protected static ?self $cachedSetting = null;

    /**
     * Helper to get the primary singleton setting (memoized).
     */
    public static function getSetting(): self
    {
        if (static::$cachedSetting !== null) {
            return static::$cachedSetting;
        }

        return static::$cachedSetting = static::first() ?? static::create([
            'nama_isp'           => 'EONET',
            'mikrotik_ip'        => '192.168.10.1',
            'mikrotik_user'      => 'helpdesk',
            'mikrotik_password'  => 'helpdesk123',
            'mikrotik_port'      => 8728,
            'wan_interface'      => 'ether1-ISP',
            'pppoe_interface'    => 'all',
            'refresh_interval'   => 5,
            'ping_dns1'          => '8.8.8.8',
            'ping_dns2'          => '1.1.1.1',
            'wa_gateway_enabled' => true,
            'wa_provider'        => 'fonnte',
            'wa_api_url'         => 'https://api.fonnte.com/send',
        ]);
    }

    public static function clearCache(): void
    {
        static::$cachedSetting = null;
    }
}
