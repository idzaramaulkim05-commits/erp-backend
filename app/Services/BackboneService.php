<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;

class BackboneService
{
    /**
     * Get backbone routers with optical diagnostics.
     *
     * @param bool $useCache
     * @return array
     */
    public function getBackboneData(bool $useCache = true): array
    {
        $fetcher = function () {
            $routersConfig = config('backbone.routers', []);
            $dbRouters = Router::whereIn('type', ['crs', 'switch'])->get()->keyBy(function($r) {
                return strtolower(trim($r->ip_address));
            });

            $backbone = [];

            // Fast reliable socket probe for all 4 CRS routers
            $reachableIndexes = [];
            foreach ($routersConfig as $k => $rConfig) {
                $host = $rConfig['host'];
                $dbMatch = $dbRouters->get(strtolower(trim($host)));
                $port = $dbMatch && $dbMatch->port ? (int) $dbMatch->port : (int) ($rConfig['port'] ?? 8728);
                $s = @fsockopen($host, $port, $errno, $errstr, 0.3);
                if ($s) {
                    $reachableIndexes[$k] = true;
                    @fclose($s);
                }
            }

            foreach ($routersConfig as $k => $rConfig) {
                $host = $rConfig['host'];
                $dbMatch = $dbRouters->get(strtolower(trim($host)));

                // Prefer database credentials if available
                $id = $rConfig['id'] ?? md5($rConfig['nama']);
                $nama = $dbMatch ? $dbMatch->name : $rConfig['nama'];
                $user = $dbMatch && $dbMatch->username ? $dbMatch->username : $rConfig['user'];
                $pass = $dbMatch ? ($dbMatch->password ?? '') : $rConfig['pass'];
                $port = $dbMatch && $dbMatch->port ? (int) $dbMatch->port : (int) ($rConfig['port'] ?? 8728);

                $defaultInterfaces = [];
                foreach ($rConfig['interfaces'] as $iface) {
                    $label = is_array($iface) ? ($iface['label'] ?? $iface['name']) : $iface;
                    $name  = is_array($iface) ? ($iface['name'] ?? $iface['label']) : $iface;
                    $defaultInterfaces[] = [
                        'label'        => $label,
                        'name'         => $name,
                        'rx'           => '-',
                        'tx'           => '-',
                        'temp'         => '-',
                        'volt'         => '-',
                        'status'       => 'unknown',
                        'signal_class' => 'offline',
                    ];
                }

                $routerData = [
                    'id'         => $id,
                    'router_id'  => $dbMatch ? $dbMatch->id : null,
                    'nama'       => $nama,
                    'host'       => $host,
                    'port'       => $port,
                    'status'     => '🔴 Offline',
                    'is_online'  => false,
                    'interfaces' => $defaultInterfaces,
                    'all_ports'  => [],
                    'logs'       => [],
                ];

                if (empty($reachableIndexes[$k])) {
                    $backbone[] = $routerData;
                    continue;
                }

                try {
                    $config = new Config([
                        'host'     => $host,
                        'user'     => $user,
                        'pass'     => $pass,
                        'port'     => $port,
                        'timeout'  => 1, // Quick 1-second timeout
                        'attempts' => 1,
                    ]);

                    $client = new Client($config);
                    $interfaces = [];

                    foreach ($rConfig['interfaces'] as $iface) {
                        $label = is_array($iface) ? ($iface['label'] ?? $iface['name']) : $iface;
                        $name  = is_array($iface) ? ($iface['name'] ?? $iface['label']) : $iface;

                        $rx = '-';
                        $tx = '-';
                        $temp = '-';
                        $volt = '-';
                        $status = 'unknown';
                        $signalClass = 'offline';

                        $candidateNames = [$name];
                        if (stripos($label, 'katibung') !== false || stripos($name, 'katibung') !== false) {
                            $candidateNames = array_unique([$name, 'sfp3-POP KATIBUNG', 'sfp-sfpplus4-POP-KATIBUNG', 'sfp-sfpplus4', 'sfp4-POP KATIBUNG', 'sfp4', 'sfp3-POP TANJUNGAN', 'sfp3', 'sfp5-POP KATIBUNG', 'sfp5']);
                        }

                        foreach ($candidateNames as $candName) {
                            try {
                                $query = new Query('/interface/ethernet/monitor');
                                $query->equal('numbers', $candName);
                                $query->equal('once', '');
                                $result = $client->query($query)->read();

                                if (!empty($result[0]) && (!empty($result[0]['status']) || !empty($result[0]['sfp-rx-power']))) {
                                    $name = $candName;
                                    $rx = $result[0]['sfp-rx-power'] ?? '-';
                                    $tx = $result[0]['sfp-tx-power'] ?? '-';
                                    $temp = $result[0]['sfp-temperature'] ?? '-';
                                    $volt = $result[0]['sfp-supply-voltage'] ?? '-';
                                    $status = $result[0]['status'] ?? 'ok';

                                    $rxNum = (float) $rx;
                                    if ($rx !== '-' && $rxNum > -15) {
                                        $signalClass = 'good'; // green
                                    } elseif ($rx !== '-' && $rxNum > -20) {
                                        $signalClass = 'warning'; // yellow
                                    } else {
                                        $signalClass = 'danger'; // red
                                    }
                                    break;
                                }
                            } catch (Throwable $e) {
                                // Try next candidate
                            }
                        }

                        $interfaces[] = [
                            'label'        => $label,
                            'name'         => $name,
                            'rx'           => $rx,
                            'tx'           => $tx,
                            'temp'         => $temp,
                            'volt'         => $volt,
                            'status'       => $status,
                            'signal_class' => $signalClass,
                        ];
                    }

                    $routerData['status'] = '🟢 Connected';
                    $routerData['is_online'] = true;
                    $routerData['interfaces'] = $interfaces;

                    // Attach full detailed ports and logs
                    if ($dbMatch) {
                        try {
                            $svc = new MikrotikService($dbMatch);
                            $routerData['all_ports'] = $svc->getInterfacesDetailed(true);
                            $routerData['logs'] = $svc->getSystemLogs(true);
                        } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {
                    $routerData['status'] = '🔴 Offline';
                    $routerData['is_online'] = false;
                }

                $backbone[] = $routerData;
            }

            if (!empty($backbone)) {
                Cache::forever('backbone_sfp_diagnostics_persistent', $backbone);
            }

            return $backbone;
        };

        if ($useCache) {
            return Cache::remember('backbone_sfp_diagnostics', 180, $fetcher);
        }

        return $fetcher();
    }
}
