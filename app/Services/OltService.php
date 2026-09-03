<?php

namespace App\Services;

use App\Models\Olt;
use Illuminate\Support\Facades\Cache;
use Throwable;

class OltService
{
    /**
     * Get aggregate statistics across all OLTs.
     */
    public function getSummary(bool $useCache = true): array
    {
        $fetcher = function () {
            $olts = Olt::all();

            $totalOlts = $olts->count();
            $onlineOlts = $olts->where('status', 'online')->count();
            $warningOlts = $olts->where('status', 'warning')->count();
            $offlineOlts = $olts->where('status', 'offline')->count();

            $totalOnus = (int) $olts->sum('total_onu');
            $onlineOnus = (int) $olts->sum('online_onu');
            $offlineOnus = (int) $olts->sum('offline_onu');

            $hsgqCount = $olts->where('brand', 'HSGQ')->count();
            $globalCount = $olts->where('brand', 'Global')->count();
            $eponCount = $olts->where('type', 'EPON')->count();
            $gponCount = $olts->where('type', 'GPON')->count();

            $availability = $totalOnus > 0 ? round(($onlineOnus / $totalOnus) * 100, 1) : 0;

            return [
                'total_olts'    => $totalOlts,
                'online_olts'   => $onlineOlts,
                'warning_olts'  => $warningOlts,
                'offline_olts'  => $offlineOlts,
                'total_onus'    => $totalOnus,
                'online_onus'   => $onlineOnus,
                'offline_onus'  => $offlineOnus,
                'availability'  => $availability,
                'hsgq_count'    => $hsgqCount,
                'global_count'  => $globalCount,
                'epon_count'    => $eponCount,
                'gpon_count'    => $gponCount,
            ];
        };

        if ($useCache) {
            return Cache::remember('olts_summary', 60, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Get all OLTs with optional filtering.
     */
    public function getOlts(array $filters = []): array
    {
        $query = Olt::query();

        if (!empty($filters['brand']) && $filters['brand'] !== 'all') {
            $query->where('brand', $filters['brand']);
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', strtoupper($filters['type']));
        }

        if (!empty($filters['pon_ports']) && $filters['pon_ports'] !== 'all') {
            $query->where('pon_ports', (int) $filters['pon_ports']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhere('location_name', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'asc')->get()->toArray();
    }

    protected static ?array $cachedMapMarkers = null;

    /**
     * Get GIS Map Markers format for Leaflet.js (Instant in-memory 0ms).
     */
    public function getMapMarkers(): array
    {
        if (static::$cachedMapMarkers !== null) {
            return static::$cachedMapMarkers;
        }

        $olts = Olt::whereNotNull('latitude')->whereNotNull('longitude')->get();
        $markers = [];

        foreach ($olts as $olt) {
            $markers[] = [
                'id'            => $olt->id,
                'name'          => $olt->name,
                'brand'         => $olt->brand,
                'type'          => $olt->type,
                'pon_ports'     => $olt->pon_ports,
                'ip_address'    => $olt->ip_address,
                'location_name' => $olt->location_name,
                'lat'           => (float) $olt->latitude,
                'lng'           => (float) $olt->longitude,
                'status'        => $olt->status,
                'temperature'   => $olt->temperature,
                'cpu_usage'     => $olt->cpu_usage,
                'ram_usage'     => $olt->ram_usage,
                'total_onu'     => $olt->total_onu,
                'online_onu'    => $olt->online_onu,
                'offline_onu'   => $olt->offline_onu,
                'availability'  => $olt->availability,
            ];
        }

        return static::$cachedMapMarkers = $markers;
    }

    public static function clearCache(): void
    {
        static::$cachedMapMarkers = null;
    }

    /**
     * Ping test to an OLT host.
     */
    public function pingOlt(int $id): array
    {
        $olt = Olt::find($id);
        if (!$olt) {
            return ['status' => false, 'message' => 'OLT tidak ditemukan.', 'latency' => 0];
        }

        $host = $olt->ip_address;
        $port = $olt->web_port ?: 80;
        $start = microtime(true);

        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 1.2);
            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($fp) {
                fclose($fp);
                return [
                    'status'  => true,
                    'message' => "🟢 OLT {$olt->name} Online ({$latency} ms)",
                    'latency' => $latency,
                    'ip'      => $host,
                ];
            }

            // Secondary check on telnet port
            $fp2 = @fsockopen($host, $olt->telnet_port ?: 23, $errno, $errstr, 1.0);
            $latency2 = round((microtime(true) - $start) * 1000, 2);
            if ($fp2) {
                fclose($fp2);
                return [
                    'status'  => true,
                    'message' => "🟢 OLT {$olt->name} Online via Telnet ({$latency2} ms)",
                    'latency' => $latency2,
                    'ip'      => $host,
                ];
            }

            return [
                'status'  => false,
                'message' => "🔴 OLT {$olt->name} ({$host}) Tidak Merespon (Timeout)",
                'latency' => 0,
                'ip'      => $host,
            ];
        } catch (Throwable $e) {
            return [
                'status'  => false,
                'message' => "🔴 Gagal menghubungi OLT: " . $e->getMessage(),
                'latency' => 0,
                'ip'      => $host,
            ];
        }
    }
}
