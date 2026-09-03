<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Olt extends Model
{
    use HasFactory;

    protected $table = 'olts';

    protected $fillable = [
        'name',
        'brand',
        'type',
        'pon_ports',
        'ip_address',
        'snmp_port',
        'snmp_community',
        'telnet_port',
        'web_port',
        'username',
        'password',
        'location_name',
        'latitude',
        'longitude',
        'status',
        'temperature',
        'cpu_usage',
        'ram_usage',
        'voltage',
        'total_onu',
        'online_onu',
        'offline_onu',
        'pon_data',
        'notes',
    ];

    protected $casts = [
        'pon_data'     => 'array',
        'pon_ports'    => 'integer',
        'total_onu'    => 'integer',
        'online_onu'   => 'integer',
        'offline_onu'  => 'integer',
        'temperature'  => 'float',
        'voltage'      => 'float',
        'cpu_usage'    => 'integer',
        'ram_usage'    => 'integer',
        'latitude'     => 'float',
        'longitude'    => 'float',
    ];

    protected static ?\Illuminate\Database\Eloquent\Collection $cachedCollection = null;

    public static function getCachedAll(): \Illuminate\Database\Eloquent\Collection
    {
        if (static::$cachedCollection !== null) {
            return static::$cachedCollection;
        }

        return static::$cachedCollection = static::orderBy('name', 'asc')->get();
    }

    public static function clearCache(): void
    {
        static::$cachedCollection = null;
    }

    /**
     * Calculate ONU availability percentage.
     */
    public function getAvailabilityAttribute(): float
    {
        if ($this->total_onu <= 0) {
            return 0.0;
        }
        return round(($this->online_onu / $this->total_onu) * 100, 1);
    }

    /**
     * Generate default PON telemetry array if not present.
     */
    public static function generateDefaultPonData(string $type, int $ponCount, int $totalOnu = 64): array
    {
        $ports = [];
        $onusPerPort = (int) ceil($totalOnu / max(1, $ponCount));

        for ($i = 1; $i <= $ponCount; $i++) {
            $online = rand(max(0, $onusPerPort - 4), $onusPerPort);
            $offline = max(0, $onusPerPort - $online);
            $txPower = round(rand(250, 450) / 100, 2); // +2.50 to +4.50 dBm
            $rxAvg = round(-1 * (rand(1700, 2400) / 100), 2); // -17.00 to -24.00 dBm
            $temp = round(rand(410, 490) / 10, 1);

            $ports[] = [
                'port'        => $i,
                'name'        => 'PON ' . $i,
                'status'      => 'up',
                'tx_power'    => $txPower . ' dBm',
                'rx_avg'      => $rxAvg . ' dBm',
                'temperature' => $temp . ' °C',
                'voltage'     => '3.3 V',
                'current'     => rand(12, 18) . ' mA',
                'total_onu'   => $onusPerPort,
                'online_onu'  => $online,
                'offline_onu' => $offline,
            ];
        }

        return $ports;
    }

    public function odps()
    {
        return $this->hasMany(Odp::class, 'olt_id');
    }
}
