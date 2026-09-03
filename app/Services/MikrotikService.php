<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;

class MikrotikService
{
    protected ?Setting $setting = null;
    protected ?Router $router = null;
    protected ?Client $client = null;
    protected bool $connected = false;
    protected bool $connectAttempted = false;
    protected int $timeout = 1;

    protected string $host = '';
    protected int $port = 8728;
    protected string $user = '';
    protected string $pass = '';
    protected ?string $wanInterface = null;
    protected ?string $pppoeInterface = null;
    protected string $deviceKey = 'default';

    public function __construct(Router|Setting|null $device = null)
    {
        if ($device instanceof Router) {
            $this->router = $device;
            $this->host = $device->ip_address ?? '';
            $this->port = (int) ($device->port ?: 8728);
            $this->user = $device->username ?? 'admin';
            $this->pass = $device->password ?? '';
            $this->wanInterface = $device->wan_interface;
            $this->pppoeInterface = $device->pppoe_interface;
            $this->deviceKey = 'router_' . $device->id;
        } elseif ($device instanceof Setting) {
            $this->setting = $device;
            $this->host = $device->mikrotik_ip ?? '';
            $this->port = (int) ($device->mikrotik_port ?: 8728);
            $this->user = $device->mikrotik_user ?? 'admin';
            $this->pass = $device->mikrotik_password ?? '';
            $this->wanInterface = $device->wan_interface;
            $this->pppoeInterface = $device->pppoe_interface;
            $this->deviceKey = 'setting';
        } else {
            // Default to default router or setting
            $defaultRouter = Router::getDefaultRouter();
            if ($defaultRouter) {
                $this->router = $defaultRouter;
                $this->host = $defaultRouter->ip_address ?? '';
                $this->port = (int) ($defaultRouter->port ?: 8728);
                $this->user = $defaultRouter->username ?? 'admin';
                $this->pass = $defaultRouter->password ?? '';
                $this->wanInterface = $defaultRouter->wan_interface;
                $this->pppoeInterface = $defaultRouter->pppoe_interface;
                $this->deviceKey = 'router_' . $defaultRouter->id;
            } else {
                $this->setting = Setting::getSetting();
                $this->host = $this->setting->mikrotik_ip ?? '';
                $this->port = (int) ($this->setting->mikrotik_port ?: 8728);
                $this->user = $this->setting->mikrotik_user ?? 'admin';
                $this->pass = $this->setting->mikrotik_password ?? '';
                $this->wanInterface = $this->setting->wan_interface;
                $this->pppoeInterface = $this->setting->pppoe_interface;
                $this->deviceKey = 'setting';
            }
        }
    }

    /**
     * Get or create RouterOS client (Ultra-fast non-blocking).
     */
    public function getClient(): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if ($this->connectAttempted || empty($this->host)) {
            return null;
        }

        $this->connectAttempted = true;

        // Fast TCP socket pre-check (0.4s) to prevent blocking HTTP requests
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 0.4);
        if (!$fp) {
            $this->connected = false;
            $this->client = null;
            return null;
        }
        fclose($fp);

        try {
            $config = new Config([
                'host'     => $this->host,
                'user'     => $this->user,
                'pass'     => $this->pass,
                'port'     => $this->port,
                'timeout'  => 1,
                'attempts' => 1,
            ]);

            $this->client = new Client($config);
            $this->connected = true;
            return $this->client;
        } catch (Throwable $e) {
            $this->connected = false;
            $this->client = null;
            return null;
        }
    }

    /**
     * Check if router is reachable.
     */
    public function isConnected(): bool
    {
        return $this->getClient() !== null;
    }

    /**
     * Test connection with specific credentials.
     */
    public static function testConnection(string $host, string $user, string $pass, int $port = 8728): array
    {
        try {
            $config = new Config([
                'host'     => $host,
                'user'     => $user,
                'pass'     => $pass,
                'port'     => $port,
                'timeout'  => 3,
                'attempts' => 1,
            ]);

            $client = new Client($config);
            $query = new Query('/system/resource/print');
            $res = $client->query($query)->read();

            if (!empty($res)) {
                $board = $res[0]['board-name'] ?? 'MikroTik';
                $version = $res[0]['version'] ?? '';
                return [
                    'status'  => true,
                    'message' => "🟢 Berhasil terhubung ke {$board} ({$version})",
                    'data'    => $res[0] ?? [],
                ];
            }

            return [
                'status'  => true,
                'message' => '🟢 Berhasil terhubung ke MikroTik',
            ];
        } catch (Throwable $e) {
            return [
                'status'  => false,
                'message' => '🔴 Gagal terhubung ke MikroTik: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get system telemetry (CPU, RAM, Uptime, Version, Board, Health/Temp, Interfaces, Traffic)
     */
    public function getTelemetry(bool $useCache = true): array
    {
        $default = [
            'connected'       => false,
            'cpu'             => 0,
            'cpu_count'       => 1,
            'cpu_frequency'   => 0,
            'ram'             => 0,
            'free_memory_mb'  => 0,
            'total_memory_mb' => 0,
            'uptime'          => '-',
            'version'         => '-',
            'board'           => '-',
            'architecture'    => '-',
            'temperature'     => null,
            'cpu_temperature' => null,
            'voltage'         => null,
            'wan'             => 'Down',
            'pppoe'           => 'Down',
            'rx'              => 0.0,
            'tx'              => 0.0,
            'wan_name'        => $this->wanInterface ?? 'ether1',
            'pppoe_name'      => $this->pppoeInterface ?? 'ether2',
            'total_interfaces'=> 0,
            'active_ports'    => 0,
            'down_ports'      => 0,
            'total_users'     => 0,
            'online'          => 0,
            'offline'         => 0,
        ];

        $fetcher = function () use ($default) {
            $client = $this->getClient();
            if (!$client) {
                return $default;
            }

            $result = $default;
            $result['connected'] = true;

            // 1. Resources
            try {
                $resourceQuery = new Query('/system/resource/print');
                $resource = $client->query($resourceQuery)->read();
                
                if (!empty($resource[0])) {
                    $r = $resource[0];
                    $result['cpu'] = (int) ($r['cpu-load'] ?? 0);
                    $result['cpu_count'] = (int) ($r['cpu-count'] ?? 1);
                    $result['cpu_frequency'] = (int) ($r['cpu-frequency'] ?? 0);
                    $totalMem = (float) ($r['total-memory'] ?? 1);
                    $freeMem = (float) ($r['free-memory'] ?? 0);
                    $result['ram'] = $totalMem > 0 ? round((($totalMem - $freeMem) / $totalMem) * 100) : 0;
                    $result['free_memory_mb'] = round($freeMem / (1024 * 1024), 1);
                    $result['total_memory_mb'] = round($totalMem / (1024 * 1024), 1);
                    $result['uptime'] = $r['uptime'] ?? '-';
                    $result['version'] = $r['version'] ?? '-';
                    $result['board'] = $r['board-name'] ?? '-';
                    $result['architecture'] = $r['architecture-name'] ?? ($r['platform'] ?? '-');
                }
            } catch (Throwable $e) {}

            // 2. Health & Hardware Sensors (Temperature, Voltage)
            try {
                $healthQuery = new Query('/system/health/print');
                $health = $client->query($healthQuery)->read();
                if (!empty($health)) {
                    foreach ($health as $item) {
                        $name = strtolower($item['name'] ?? '');
                        $value = $item['value'] ?? ($item['temperature'] ?? ($item['voltage'] ?? null));
                        
                        if (str_contains($name, 'cpu') && str_contains($name, 'temp')) {
                            $result['cpu_temperature'] = (float) $value;
                        } elseif (str_contains($name, 'temp') || str_contains($name, 'board')) {
                            $result['temperature'] = (float) $value;
                        } elseif (str_contains($name, 'voltage')) {
                            $result['voltage'] = (float) $value;
                        }
                    }
                }
            } catch (Throwable $e) {}

            // 3. Interface Counts & Status
            try {
                $ifaceQuery = new Query('/interface/print');
                $ifaceQuery->equal('.proplist', 'name,type,running,disabled');
                $interfaces = $client->query($ifaceQuery)->read();

                $result['total_interfaces'] = count($interfaces);
                $activeCount = 0;
                $downCount = 0;

                foreach ($interfaces as $iface) {
                    $running = ($iface['running'] ?? 'false') === 'true';
                    $disabled = ($iface['disabled'] ?? 'false') === 'true';
                    $name = $iface['name'] ?? '';

                    if ($running && !$disabled) {
                        $activeCount++;
                    } else {
                        $downCount++;
                    }

                    // Check WAN Interface
                    if (!empty($this->wanInterface) && $name === $this->wanInterface) {
                        $result['wan'] = ($running && !$disabled) ? 'Running' : 'Down';
                    }

                    // Check PPPoE Interface
                    if (!empty($this->pppoeInterface) && $name === $this->pppoeInterface) {
                        $result['pppoe'] = ($running && !$disabled) ? 'Running' : 'Down';
                    }
                }

                $result['active_ports'] = $activeCount;
                $result['down_ports'] = $downCount;
            } catch (Throwable $e) {}

            // 4. Traffic on WAN / Default Interface
            try {
                if (!empty($this->wanInterface)) {
                    $trafficQuery = new Query('/interface/monitor-traffic');
                    $trafficQuery->equal('interface', $this->wanInterface);
                    $trafficQuery->equal('once', '');
                    $traffic = $client->query($trafficQuery)->read();

                    if (!empty($traffic[0])) {
                        $result['rx'] = round((float)($traffic[0]['rx-bits-per-second'] ?? 0) / 1000000, 2);
                        $result['tx'] = round((float)($traffic[0]['tx-bits-per-second'] ?? 0) / 1000000, 2);
                    }
                }
            } catch (Throwable $e) {}

            // 5. PPP Counts (if router runs PPPoE Server)
            try {
                $secretQuery = new Query('/ppp/secret/print');
                $secretQuery->equal('count-only', '');
                $secretData = $client->query($secretQuery)->read();
                $result['total_users'] = isset($secretData['after']['ret']) ? (int)$secretData['after']['ret'] : count($secretData);

                $activeQuery = new Query('/ppp/active/print');
                $activeQuery->equal('count-only', '');
                $activeData = $client->query($activeQuery)->read();
                $result['online'] = isset($activeData['after']['ret']) ? (int)$activeData['after']['ret'] : count($activeData);
                $result['offline'] = max(0, $result['total_users'] - $result['online']);
            } catch (Throwable $e) {}

            return $result;
        };

        if ($useCache) {
            return Cache::remember('mikrotik_telemetry_' . $this->deviceKey, 30, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Get detailed list of all interfaces, ports, SFP status, and assigned IPs.
     */
    public function getInterfacesDetailed(bool $useCache = true): array
    {
        $cacheKey = 'mikrotik_interfaces_' . $this->deviceKey;

        $fetcher = function () {
            $client = $this->getClient();
            if (!$client) {
                return [];
            }

            $list = [];

            try {
                // 1. Fetch interfaces reliably for ROS v6 and v7
                $qIface = new Query('/interface/print');
                $interfaces = $client->query($qIface)->read();

                // 2. Ethernet details (speed, MAC)
                $etherMap = [];
                try {
                    $qEther = new Query('/interface/ethernet/print');
                    $ethers = $client->query($qEther)->read();
                    foreach ($ethers as $e) {
                        $eName = $e['name'] ?? '';
                        if ($eName) {
                            $etherMap[$eName] = $e;
                        }
                    }
                } catch (Throwable $e) {}

                // 3. IP Address map
                $ipMap = [];
                try {
                    $qIp = new Query('/ip/address/print');
                    $ips = $client->query($qIp)->read();
                    foreach ($ips as $ip) {
                        $ifaceName = $ip['interface'] ?? '';
                        if ($ifaceName) {
                            if (!isset($ipMap[$ifaceName])) {
                                $ipMap[$ifaceName] = [];
                            }
                            $ipMap[$ifaceName][] = $ip['address'] ?? '';
                        }
                    }
                } catch (Throwable $e) {}

                // Build detailed items (Physical ports, SFP, VLAN, Bridge, Bonding, etc.)
                foreach ($interfaces as $item) {
                    $type = $item['type'] ?? 'ether';
                    $typeLower = strtolower($type);
                    $isDynamic = ($item['dynamic'] ?? 'false') === 'true';

                    // Skip dynamic per-user PPPoE client tunnels to keep port list clean and blazing fast
                    if ($isDynamic && str_contains($typeLower, 'pppoe')) {
                        continue;
                    }

                    $name = $item['name'] ?? '-';
                    if ($name === '-' || empty($name)) continue;

                    $running = ($item['running'] ?? 'false') === 'true';
                    $disabled = ($item['disabled'] ?? 'false') === 'true';
                    $comment = $item['comment'] ?? '';

                    $eData = $etherMap[$name] ?? [];
                    $mac = $eData['mac-address'] ?? ($item['mac-address'] ?? '-');
                    $speed = $eData['speed'] ?? ($eData['rate'] ?? ($eData['orig-rate'] ?? '-'));
                    
                    // Determine readable port type badge
                    $nameLower = strtolower($name);

                    if (str_contains($nameLower, 'qsfp') || str_contains($nameLower, '40g')) {
                        $portType = '40G QSFP+';
                        $portIcon = '⚡';
                    } elseif (str_contains($nameLower, 'sfp+') || str_contains($nameLower, '10g') || str_contains($nameLower, 'sfpplus')) {
                        $portType = '10G SFP+';
                        $portIcon = '💎';
                    } elseif (str_contains($nameLower, 'sfp')) {
                        $portType = '1G SFP';
                        $portIcon = '🔹';
                    } elseif ($typeLower === 'vlan') {
                        $portType = 'VLAN';
                        $portIcon = '🔀';
                    } elseif ($typeLower === 'bridge') {
                        $portType = 'Bridge';
                        $portIcon = '🌉';
                    } elseif ($typeLower === 'bonding') {
                        $portType = 'Bonding Trunk';
                        $portIcon = '🔗';
                    } else {
                        $portType = 'Ethernet RJ45';
                        $portIcon = '🔌';
                    }

                    // Format Traffic
                    $rxBytes = (float) ($item['rx-byte'] ?? 0);
                    $txBytes = (float) ($item['tx-byte'] ?? 0);

                    $list[] = [
                        'name'        => $name,
                        'type'        => $type,
                        'port_type'   => $portType,
                        'port_icon'   => $portIcon,
                        'running'     => $running,
                        'disabled'    => $disabled,
                        'link_status' => $disabled ? 'Disabled' : ($running ? 'Link Up' : 'Link Down'),
                        'speed'       => $speed !== '-' ? $speed : ($running ? '1 Gbps' : '-'),
                        'mac'         => $mac,
                        'ip'          => isset($ipMap[$name]) ? implode(', ', $ipMap[$name]) : '-',
                        'rx_bytes'    => $this->formatBytes($rxBytes),
                        'tx_bytes'    => $this->formatBytes($txBytes),
                        'comment'     => $comment,
                    ];
                }
            } catch (Throwable $e) {
                Log::warning("Interface detail fetch error for {$this->host}: " . $e->getMessage());
            }

            return $list;
        };

        if ($useCache) {
            return Cache::remember($cacheKey, 60, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Get live traffic bandwidth in Mbps.
     */
    public function getTraffic(?string $customInterface = null): array
    {
        $client = $this->getClient();
        $targetInterface = $customInterface ?? $this->wanInterface;

        if (!$client || empty($targetInterface)) {
            return ['rx' => 0.0, 'tx' => 0.0];
        }

        try {
            $query = new Query('/interface/monitor-traffic');
            $query->equal('interface', $targetInterface);
            $query->equal('once', '');
            $traffic = $client->query($query)->read();

            if (!empty($traffic[0])) {
                $rx = round((float)($traffic[0]['rx-bits-per-second'] ?? 0) / 1000000, 2);
                $tx = round((float)($traffic[0]['tx-bits-per-second'] ?? 0) / 1000000, 2);
                return ['rx' => $rx, 'tx' => $tx];
            }

            return ['rx' => 0.0, 'tx' => 0.0];
        } catch (Throwable $e) {
            return ['rx' => 0.0, 'tx' => 0.0];
        }
    }

    /**
     * Ping a target address from MikroTik.
     */
    public function pingTarget(string $target = '8.8.8.8'): array
    {
        $client = $this->getClient();
        if (!$client) {
            return ['status' => false, 'ping' => 'Timeout', 'value' => 0];
        }

        try {
            $query = new Query('/ping');
            $query->equal('address', $target);
            $query->equal('count', '2');
            $reply = $client->query($query)->read();

            $status = false;
            $pingText = 'Timeout';
            $pingValue = 0.0;

            foreach ($reply as $row) {
                if (isset($row['time'])) {
                    $status = true;
                    $pingText = $row['time'];
                    $cleaned = str_replace(['ms', 'us', 's'], '', $pingText);
                    $pingValue = (float) $cleaned;
                    break;
                }
            }

            return [
                'status' => $status,
                'ping'   => $pingText,
                'value'  => $pingValue,
                'target' => $target,
            ];
        } catch (Throwable $e) {
            return ['status' => false, 'ping' => 'Timeout', 'value' => 0];
        }
    }

    /**
     * Get all PPPoE Secrets merged with Active sessions (Optimized & Fast).
     */
    public function getPppoeSecrets(bool $useCache = true): array
    {
        $cacheKey = 'mikrotik_pppoe_secrets_' . $this->deviceKey;
        $persistentKey = 'mikrotik_persistent_secrets_' . $this->deviceKey;
        $globalKey = 'mikrotik_persistent_secrets_latest';

        $fetcher = function () use ($persistentKey, $globalKey) {
            $client = $this->getClient();
            if (!$client) {
                // Fallback to persistent storage so customer records never disappear when router disconnects
                $fallback = Cache::get($persistentKey) ?? Cache::get($globalKey) ?? [];
                if (!empty($fallback) && is_array($fallback)) {
                    return array_map(function ($c) {
                        return array_merge($c, [
                            'online' => false,
                            'status' => 'Offline',
                            'uptime' => '-',
                            'status_category' => ($c['disabled'] ?? false) ? 'disabled' : 'offline_normal',
                        ]);
                    }, $fallback);
                }
                return [];
            }

            try {
                // 1. Fetch only essential fields for Secrets (including last-logged-out & disconnect reason)
                $qSecret = new Query('/ppp/secret/print');
                $qSecret->equal('.proplist', '.id,name,service,profile,comment,disabled,remote-address,caller-id,last-logged-out,last-caller-id,last-disconnect-reason');
                $secrets = $client->query($qSecret)->read();

                if (empty($secrets) || !is_array($secrets)) {
                    $fallback = Cache::get($persistentKey) ?? Cache::get($globalKey) ?? [];
                    return is_array($fallback) ? $fallback : [];
                }

                // 2. Fetch only essential fields for Active sessions
                $qActive = new Query('/ppp/active/print');
                $qActive->equal('.proplist', '.id,name,service,address,uptime,caller-id');
                $active = $client->query($qActive)->read();

                // Hash map indexing for O(1) matching
                $activeMap = [];
                if (!empty($active) && is_array($active)) {
                    foreach ($active as $act) {
                        $actName = $act['name'] ?? '';
                        if ($actName !== '') {
                            $activeMap[$actName] = $act;
                        }
                    }
                }

                $list = [];
                $dateCache = [];

                foreach ($secrets as $sec) {
                    $username = $sec['name'] ?? '';
                    if ($username === '') {
                        continue;
                    }

                    $service = $sec['service'] ?? 'pppoe';
                    if ($service !== 'pppoe' && $service !== 'any') {
                        continue;
                    }

                    $isDisabled = ($sec['disabled'] ?? 'false') === 'true';
                    $comment = $sec['comment'] ?? '';
                    $profile = $sec['profile'] ?? 'default';

                    $isOnline = isset($activeMap[$username]);
                    $actData = $isOnline ? $activeMap[$username] : null;

                    $ip = '-';
                    $uptime = '-';
                    $callerId = $sec['caller-id'] ?? '';
                    $lastCallerId = $sec['last-caller-id'] ?? '';
                    $lastDisconnectReason = $sec['last-disconnect-reason'] ?? '';
                    $lastLoggedOutRaw = $sec['last-logged-out'] ?? '';

                    if ($isOnline && $actData) {
                        $ip = $actData['address'] ?? ($sec['remote-address'] ?? '-');
                        $uptime = $actData['uptime'] ?? '-';
                        if (!empty($actData['caller-id'])) {
                            $callerId = $actData['caller-id'];
                        }
                    } else {
                        $ip = $sec['remote-address'] ?? '-';
                        if (empty($callerId) && !empty($lastCallerId)) {
                            $callerId = $lastCallerId;
                        }
                    }

                    if ($isOnline) {
                        $statusCategory = 'online';
                    } elseif ($isDisabled) {
                        $statusCategory = 'disabled';
                    } else {
                        $statusCategory = 'offline_normal';
                    }

                    // Fast memoized Last Logged Out timestamp & offline duration calculation
                    $lastLoggedOutFormatted = '-';
                    $lastLoggedOutIso = null;
                    $offlineDuration = '-';

                    if (!empty($lastLoggedOutRaw) && $lastLoggedOutRaw !== '-' && !str_contains($lastLoggedOutRaw, '1970')) {
                        if (!isset($dateCache[$lastLoggedOutRaw])) {
                            $lastLoggedOutCarbon = $this->parseMikrotikDateTime($lastLoggedOutRaw);
                            if ($lastLoggedOutCarbon) {
                                $dateCache[$lastLoggedOutRaw] = [
                                    'fmt' => $lastLoggedOutCarbon->locale('id')->translatedFormat('d M Y, H:i'),
                                    'iso' => $lastLoggedOutCarbon->toIso8601String(),
                                    'diff'=> $lastLoggedOutCarbon->locale('id')->diffForHumans(null, false, false, 2),
                                ];
                            } else {
                                $dateCache[$lastLoggedOutRaw] = ['fmt' => $lastLoggedOutRaw, 'iso' => null, 'diff' => '-'];
                            }
                        }
                        $lastLoggedOutFormatted = $dateCache[$lastLoggedOutRaw]['fmt'];
                        $lastLoggedOutIso = $dateCache[$lastLoggedOutRaw]['iso'];
                        $offlineDuration = $dateCache[$lastLoggedOutRaw]['diff'];
                    }

                    $list[] = [
                        'id'                     => $sec['.id'] ?? '',
                        'username'               => $username,
                        'name'                   => $comment ? $comment : $username,
                        'nama'                   => $comment ? $comment : $username,
                        'comment'                => $comment,
                        'profile'                => $profile,
                        'paket'                  => $profile,
                        'service'                => $service,
                        'status'                 => $isOnline ? 'Online' : 'Offline',
                        'online'                 => $isOnline,
                        'disabled'               => $isDisabled,
                        'is_isolated'            => $isDisabled,
                        'status_category'        => $statusCategory,
                        'ip'                     => $ip,
                        'uptime'                 => $uptime,
                        'caller_id'              => $callerId,
                        'last_logged_out'        => $lastLoggedOutFormatted,
                        'last_logged_out_raw'    => $lastLoggedOutRaw,
                        'last_logged_out_iso'    => $lastLoggedOutIso,
                        'last_disconnect_reason' => $lastDisconnectReason ?: '-',
                        'offline_duration'       => $offlineDuration,
                    ];
                }

                usort($list, function ($a, $b) {
                    $order = ['online' => 1, 'offline_normal' => 2, 'disabled' => 3];
                    $rankA = $order[$a['status_category']] ?? 4;
                    $rankB = $order[$b['status_category']] ?? 4;
                    if ($rankA !== $rankB) {
                        return $rankA <=> $rankB;
                    }
                    return strcasecmp($a['username'], $b['username']);
                });

                if (!empty($list)) {
                    Cache::forever($persistentKey, $list);
                    Cache::forever($globalKey, $list);
                }

                return $list;
            } catch (Throwable $e) {
                Log::error("Failed to fetch PPPoE secrets: " . $e->getMessage());
                $fallback = Cache::get($persistentKey) ?? Cache::get($globalKey) ?? [];
                return is_array($fallback) ? $fallback : [];
            }
        };

        if ($useCache) {
            return Cache::remember($cacheKey, 180, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Toggle (Enable/Disable/Isolir) PPPoE Secret in MikroTik.
     */
    public function togglePppoeSecret(string $username, bool $enable): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [
                'success' => false,
                'message' => '🔴 Gagal terhubung ke RouterOS MikroTik.',
            ];
        }

        try {
            $qFind = new Query('/ppp/secret/print');
            $qFind->where('name', $username);
            $qFind->equal('.proplist', '.id,name,disabled');
            $found = $client->query($qFind)->read();

            if (empty($found[0]['.id'])) {
                return [
                    'success' => false,
                    'message' => "🔴 Akun secret '{$username}' tidak ditemukan di MikroTik.",
                ];
            }

            $secretId = $found[0]['.id'];

            $qSet = new Query('/ppp/secret/set');
            $qSet->equal('.id', $secretId);
            $qSet->equal('disabled', $enable ? 'no' : 'yes');
            $client->query($qSet)->read();

            if (!$enable) {
                try {
                    $qAct = new Query('/ppp/active/print');
                    $qAct->where('name', $username);
                    $qAct->equal('.proplist', '.id');
                    $actFound = $client->query($qAct)->read();

                    if (!empty($actFound[0]['.id'])) {
                        $qRemove = new Query('/ppp/active/remove');
                        $qRemove->equal('.id', $actFound[0]['.id']);
                        $client->query($qRemove)->read();
                    }
                } catch (Throwable $e) {}
            }

            Cache::forget('mikrotik_pppoe_secrets_' . $this->deviceKey);
            Cache::forget('mikrotik_telemetry_' . $this->deviceKey);

            $actionText = $enable ? '🟢 DIHIDUPKAN KEMBALI (Buka Isolir)' : '⛔ DIISOLIR / DINONAKTIFKAN';

            return [
                'success' => true,
                'message' => "✅ Akun pelanggan '{$username}' berhasil {$actionText}!",
                'is_active' => $enable,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => '🔴 Gagal mengubah status di MikroTik: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete PPPoE Secret permanently from MikroTik and kick active session.
     */
    public function deletePppoeSecret(string $username): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [
                'success' => false,
                'message' => '🔴 Gagal terhubung ke RouterOS MikroTik. Periksa koneksi atau IP/Port API.',
            ];
        }

        try {
            // 1. Kick active session if currently online
            try {
                $qAct = new Query('/ppp/active/print');
                $qAct->where('name', $username);
                $qAct->equal('.proplist', '.id,name');
                $actFound = $client->query($qAct)->read();

                if (empty($actFound[0]['.id'])) {
                    // Fallback search in all active connections
                    $qActAll = new Query('/ppp/active/print');
                    $qActAll->equal('.proplist', '.id,name');
                    $allAct = $client->query($qActAll)->read();
                    foreach ($allAct as $a) {
                        if (strcasecmp($a['name'] ?? '', $username) === 0 && !empty($a['.id'])) {
                            $actFound = [$a];
                            break;
                        }
                    }
                }

                if (!empty($actFound[0]['.id'])) {
                    $actId = $actFound[0]['.id'];
                    try {
                        $qRemoveAct = new Query('/ppp/active/remove');
                        $qRemoveAct->equal('numbers', $actId);
                        $client->query($qRemoveAct)->read();
                    } catch (Throwable $eAct1) {
                        $qRemoveAct = new Query('/ppp/active/remove');
                        $qRemoveAct->equal('.id', $actId);
                        $client->query($qRemoveAct)->read();
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Could not kick active session for {$username}: " . $e->getMessage());
            }

            // 2. Find matching secret ID
            $qFind = new Query('/ppp/secret/print');
            $qFind->where('name', $username);
            $qFind->equal('.proplist', '.id,name');
            $found = $client->query($qFind)->read();

            if (empty($found[0]['.id'])) {
                // Fallback search across all secrets (support prefix matching / without domain suffix)
                $qAll = new Query('/ppp/secret/print');
                $qAll->equal('.proplist', '.id,name');
                $allSecrets = $client->query($qAll)->read();
                foreach ($allSecrets as $sec) {
                    $secName = $sec['name'] ?? '';
                    if (!empty($sec['.id']) && (
                        strcasecmp($secName, $username) === 0 || 
                        (str_contains($username, '@') && strcasecmp($secName, explode('@', $username)[0]) === 0) ||
                        (str_contains($secName, '@') && strcasecmp(explode('@', $secName)[0], $username) === 0)
                    )) {
                        $found = [$sec];
                        break;
                    }
                }
            }

            if (empty($found[0]['.id'])) {
                return [
                    'success' => false,
                    'message' => "🔴 Akun secret '{$username}' tidak ditemukan di MikroTik.",
                ];
            }

            $secretId = $found[0]['.id'];

            // 3. Remove secret using both RouterOS command syntax conventions
            $deleted = false;
            $lastErr = '';

            try {
                $qDel = new Query('/ppp/secret/remove');
                $qDel->equal('numbers', $secretId);
                $client->query($qDel)->read();
                $deleted = true;
            } catch (Throwable $e1) {
                $lastErr = $e1->getMessage();
                try {
                    $qDel = new Query('/ppp/secret/remove');
                    $qDel->equal('.id', $secretId);
                    $client->query($qDel)->read();
                    $deleted = true;
                } catch (Throwable $e2) {
                    $lastErr = $e2->getMessage();
                }
            }

            if (!$deleted) {
                return [
                    'success' => false,
                    'message' => "🔴 Gagal menghapus secret di MikroTik: " . $lastErr,
                ];
            }

            // Clear all caches
            Cache::forget('mikrotik_pppoe_secrets_' . $this->deviceKey);
            Cache::forget('mikrotik_telemetry_' . $this->deviceKey);
            Cache::forget('mikrotik_pppoe_secrets_default');
            Cache::forget('mikrotik_telemetry_default');

            return [
                'success' => true,
                'message' => "🗑️ Akun pelanggan PPPoE '{$username}' berhasil dihapus permanen dari Router MikroTik!",
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => '🔴 Gagal menghapus secret PPPoE: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create or update PPPoE Secret in RouterOS MikroTik.
     */
    public function createOrUpdatePppoeSecret(
        string $username,
        string $password = '1',
        string $profile = 'default',
        string $service = 'pppoe',
        ?string $comment = null,
        ?string $remoteAddress = null
    ): array {
        $client = $this->getClient();
        if (!$client) {
            return [
                'success' => false,
                'message' => '🔴 Gagal terhubung ke RouterOS MikroTik.',
            ];
        }

        try {
            $qFind = new Query('/ppp/secret/print');
            $qFind->where('name', $username);
            $qFind->equal('.proplist', '.id,name');
            $found = $client->query($qFind)->read();

            if (!empty($found[0]['.id'])) {
                $secretId = $found[0]['.id'];
                $qSet = new Query('/ppp/secret/set');
                $qSet->equal('.id', $secretId);
                $qSet->equal('password', $password);
                $qSet->equal('profile', $profile);
                $qSet->equal('service', $service);
                if ($comment !== null) {
                    $qSet->equal('comment', $comment);
                }
                if ($remoteAddress !== null) {
                    $qSet->equal('remote-address', $remoteAddress);
                }
                $client->query($qSet)->read();
            } else {
                $qAdd = new Query('/ppp/secret/add');
                $qAdd->equal('name', $username);
                $qAdd->equal('password', $password);
                $qAdd->equal('profile', $profile);
                $qAdd->equal('service', $service);
                if ($comment !== null) {
                    $qAdd->equal('comment', $comment);
                }
                if ($remoteAddress !== null) {
                    $qAdd->equal('remote-address', $remoteAddress);
                }
                $client->query($qAdd)->read();
            }

            Cache::forget('mikrotik_pppoe_secrets_' . $this->deviceKey);
            Cache::forget('mikrotik_telemetry_' . $this->deviceKey);
            Cache::forget('mikrotik_pppoe_secrets_default');
            Cache::forget('mikrotik_telemetry_default');

            return [
                'success' => true,
                'message' => "✅ Akun PPPoE '{$username}' berhasil disimpan di Router MikroTik!",
            ];
        } catch (Throwable $e) {
            Log::error("Failed to create/update PPPoE secret: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '🔴 Gagal membuat secret di MikroTik: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update PPPoE Profile (Upgrade / Downgrade Paket) and kick active session so change takes effect immediately.
     */
    public function updatePppoeProfile(string $username, string $newProfile, ?string $newComment = null): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [
                'success' => false,
                'message' => '🔴 Gagal terhubung ke RouterOS MikroTik.',
            ];
        }

        try {
            // 1. Find Secret ID
            $qFind = new Query('/ppp/secret/print');
            $qFind->where('name', $username);
            $qFind->equal('.proplist', '.id,name,profile,comment');
            $found = $client->query($qFind)->read();

            if (empty($found[0]['.id'])) {
                return [
                    'success' => false,
                    'message' => "🔴 Secret PPPoE '{$username}' tidak ditemukan di MikroTik.",
                ];
            }

            $secretId = $found[0]['.id'];
            $qSet = new Query('/ppp/secret/set');
            $qSet->equal('.id', $secretId);
            $qSet->equal('profile', $newProfile);
            if ($newComment !== null) {
                $qSet->equal('comment', $newComment);
            }
            $client->query($qSet)->read();

            // 2. Kick active session to force reconnect with new speed profile
            try {
                $qActive = new Query('/ppp/active/print');
                $qActive->where('name', $username);
                $qActive->equal('.proplist', '.id,name');
                $active = $client->query($qActive)->read();

                if (!empty($active[0]['.id'])) {
                    $qRemove = new Query('/ppp/active/remove');
                    $qRemove->equal('.id', $active[0]['.id']);
                    $client->query($qRemove)->read();
                }
            } catch (\Throwable $e) {
                Log::info("Kick active PPPoE session notice for {$username}: " . $e->getMessage());
            }

            // Invalidate caches
            Cache::forget('mikrotik_pppoe_secrets_' . $this->deviceKey);
            Cache::forget('mikrotik_telemetry_' . $this->deviceKey);
            Cache::forget('mikrotik_pppoe_secrets_default');
            Cache::forget('mikrotik_telemetry_default');

            return [
                'success' => true,
                'message' => "✅ Profil paket PPPoE '{$username}' berhasil diperbarui ke '{$newProfile}' dan sesi koneksi diperbarui!",
            ];
        } catch (\Throwable $e) {
            Log::error("Failed to update PPPoE profile on MikroTik: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '🔴 Gagal memperbarui profil di MikroTik: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch PPP Profiles list from RouterOS MikroTik (/ppp/profile/print).
     */
    public function getPppProfiles(): array
    {
        return Cache::remember("mikrotik_ppp_profiles_{$this->deviceKey}", 30, function () {
            $client = $this->getClient();
            if (!$client) {
                return [
                    ['name' => 'default', 'rate_limit' => null],
                    ['name' => 'default-encryption', 'rate_limit' => null],
                ];
            }

            try {
                $q = new Query('/ppp/profile/print');
                $q->equal('.proplist', '.id,name,local-address,remote-address,rate-limit,only-one,comment');
                $res = $client->query($q)->read();

                $profiles = [];
                foreach ($res as $p) {
                    if (!empty($p['name'])) {
                        $profiles[] = [
                            'id'             => $p['.id'] ?? null,
                            'name'           => $p['name'],
                            'local_address'  => $p['local-address'] ?? null,
                            'remote_address' => $p['remote-address'] ?? null,
                            'rate_limit'     => $p['rate-limit'] ?? null,
                            'comment'        => $p['comment'] ?? null,
                        ];
                    }
                }
                return !empty($profiles) ? $profiles : [
                    ['name' => 'default', 'rate_limit' => null],
                    ['name' => 'default-encryption', 'rate_limit' => null],
                ];
            } catch (Throwable $e) {
                Log::warning("Gagal fetch PPP profiles MikroTik ({$this->host}): " . $e->getMessage());
                return [
                    ['name' => 'default', 'rate_limit' => null],
                    ['name' => 'default-encryption', 'rate_limit' => null],
                ];
            }
        });
    }

    /**
     * Get live PPPoE logs with activity classification.
     */
    public function getPppoeLogs(bool $useCache = true): array
    {
        $cacheKey = 'mikrotik_pppoe_logs_' . $this->deviceKey;

        $fetcher = function () {
            $client = $this->getClient();
            if (!$client) {
                return ['logs' => [], 'connected' => 0, 'failed' => 0, 'disconnect' => 0];
            }

            try {
                $qLog = new Query('/log/print');
                $qLog->equal('.proplist', '.id,time,topics,message');
                $rawLogs = $client->query($qLog)->read();

                if (empty($rawLogs) || !is_array($rawLogs)) {
                    return ['logs' => [], 'connected' => 0, 'failed' => 0, 'disconnect' => 0];
                }

                $logs = [];
                $connectedCount = 0;
                $failedCount = 0;
                $disconnectCount = 0;

                // Process from newest to oldest
                foreach (array_reverse($rawLogs) as $entry) {
                    $topics = strtolower($entry['topics'] ?? '');
                    $message = $entry['message'] ?? '';
                    $time = $entry['time'] ?? '';

                    // Filter only relevant PPPoE / PPP / Auth / Connection logs
                    if (!str_contains($topics, 'ppp') && !str_contains($topics, 'pppoe') && !str_contains(strtolower($message), 'pppoe') && !str_contains(strtolower($message), 'user')) {
                        continue;
                    }

                    $actionType = 'info';
                    $actionBadge = 'ℹ️ Info';
                    $actionClass = 'badge-secondary';
                    $username = '-';
                    $ip = '-';

                    if (str_contains(strtolower($message), 'authentication failed') || str_contains($topics, 'error')) {
                        $actionType = 'failed';
                        $actionBadge = '🔴 Auth Failed';
                        $actionClass = 'badge-danger';
                        $failedCount++;
                    } elseif (str_contains(strtolower($message), 'connection established') || str_contains(strtolower($message), 'logged in') || str_contains(strtolower($message), 'connected')) {
                        $actionType = 'connected';
                        $actionBadge = '🟢 Connected';
                        $actionClass = 'badge-success';
                        $connectedCount++;
                    } elseif (str_contains(strtolower($message), 'disconnected') || str_contains(strtolower($message), 'logged out') || str_contains(strtolower($message), 'terminated')) {
                        $actionType = 'disconnect';
                        $actionBadge = '⚠️ Disconnected';
                        $actionClass = 'badge-warning';
                        $disconnectCount++;
                    }

                    // Extract username if present (e.g. user 0904-saipurrohim@sandaran or user helpdesk)
                    if (preg_match('/user\s+([^\s<>,]+)/i', $message, $m)) {
                        $username = trim($m[1], " <>,:;\"'");
                    }

                    // Extract IP if present
                    if (preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $message, $mIp)) {
                        $ip = $mIp[0];
                    }

                    $logs[] = [
                        'id'           => $entry['.id'] ?? '',
                        'time'         => $time,
                        'topics'       => $entry['topics'] ?? '',
                        'message'      => $message,
                        'raw'          => $message,
                        'keterangan'   => $entry['topics'] ?? 'pppoe,ppp',
                        'action_type'  => $actionType,
                        'action_badge' => $actionBadge,
                        'status'       => $actionBadge,
                        'action_class' => $actionClass,
                        'badge'        => $actionClass,
                        'username'     => $username,
                        'ip'           => $ip,
                    ];

                    if (count($logs) >= 200) {
                        break;
                    }
                }

                return [
                    'logs'       => $logs,
                    'connected'  => $connectedCount,
                    'failed'     => $failedCount,
                    'disconnect' => $disconnectCount,
                ];
            } catch (Throwable $e) {
                Log::warning("Failed to fetch PPPoE logs: " . $e->getMessage());
                return ['logs' => [], 'connected' => 0, 'failed' => 0, 'disconnect' => 0];
            }
        };

        if ($useCache) {
            return Cache::remember($cacheKey, 2, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Get raw RouterOS system logs.
     */
    public function getSystemLogs(bool $useCache = true): array
    {
        $cacheKey = 'mikrotik_system_logs_' . $this->deviceKey;

        $fetcher = function () {
            $client = $this->getClient();
            if (!$client) {
                return [];
            }

            try {
                $qLog = new Query('/log/print');
                $qLog->equal('.proplist', '.id,time,topics,message');
                $rawLogs = $client->query($qLog)->read();

                if (empty($rawLogs) || !is_array($rawLogs)) {
                    return [];
                }

                return array_slice(array_reverse($rawLogs), 0, 150);
            } catch (Throwable $e) {
                return [];
            }
        };

        if ($useCache) {
            return Cache::remember($cacheKey, 2, $fetcher);
        }

        return $fetcher();
    }

    /**
     * Execute live Ping Terminal on MikroTik and return formatted console output.
     */
    public function runPingTerminal(string $target, int $count = 4, int $size = 56): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [
                'success' => false,
                'target'  => $target,
                'output'  => "🔴 Gagal terhubung ke RouterOS MikroTik ({$this->host}:{$this->port}).\nPastikan port API dan status router aktif.",
                'summary' => ['sent' => 0, 'received' => 0, 'loss' => 100, 'avg' => '-'],
            ];
        }

        $cleanTarget = trim($target);
        $count = max(1, min(20, $count));
        $size = max(28, min(1500, $size));

        try {
            $q = new Query('/ping');
            $q->equal('address', $cleanTarget);
            $q->equal('count', (string) $count);
            $q->equal('size', (string) $size);
            $replies = $client->query($q)->read();

            $lines = [];
            $lines[] = "[admin@" . ($this->router->name ?? 'MikroTik') . "] > /ping address={$cleanTarget} count={$count} size={$size}";
            $lines[] = sprintf("%-40s %5s %4s %8s %s", "HOST", "SIZE", "TTL", "TIME", "STATUS");

            $sent = 0;
            $received = 0;
            $loss = 100;
            $minRtt = '-';
            $avgRtt = '-';
            $maxRtt = '-';

            if (!empty($replies) && is_array($replies)) {
                foreach ($replies as $r) {
                    $host = $r['host'] ?? $cleanTarget;
                    $rSize = $r['size'] ?? $size;
                    $ttl = $r['ttl'] ?? ($r['status'] ?? '-');
                    $time = $r['time'] ?? ($r['status'] ?? 'timeout');
                    $status = isset($r['time']) ? 'echo reply' : ($r['status'] ?? 'timeout');

                    $lines[] = sprintf("%-40s %5s %4s %8s %s", $host, $rSize, $ttl, $time, $status);

                    $sent = (int) ($r['sent'] ?? $sent + 1);
                    $received = (int) ($r['received'] ?? $received);
                    $loss = (int) ($r['packet-loss'] ?? $loss);
                    if (isset($r['min-rtt'])) $minRtt = $r['min-rtt'];
                    if (isset($r['avg-rtt'])) $avgRtt = $r['avg-rtt'];
                    if (isset($r['max-rtt'])) $maxRtt = $r['max-rtt'];
                }

                $lines[] = sprintf("    sent=%d received=%d packet-loss=%d%% min-rtt=%s avg-rtt=%s max-rtt=%s",
                    $sent, $received, $loss, $minRtt, $avgRtt, $maxRtt
                );
            } else {
                $lines[] = "    Packet sent but no response received (timeout).";
            }

            return [
                'success' => true,
                'target'  => $cleanTarget,
                'output'  => implode("\n", $lines),
                'summary' => [
                    'sent'     => $sent,
                    'received' => $received,
                    'loss'     => $loss,
                    'min'      => $minRtt,
                    'avg'      => $avgRtt,
                    'max'      => $maxRtt,
                ]
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'target'  => $cleanTarget,
                'output'  => "Ping execution error: " . $e->getMessage(),
                'summary' => ['sent' => 0, 'received' => 0, 'loss' => 100, 'avg' => '-'],
            ];
        }
    }

    /**
     * Parse various MikroTik / RouterOS datetime formats safely.
     */
    protected function parseMikrotikDateTime(?string $timeStr): ?Carbon
    {
        if (empty($timeStr)) {
            return null;
        }

        $trimmed = trim($timeStr);
        if ($trimmed === '' || $trimmed === '-' || str_contains($trimmed, '1970') || $trimmed === '00:00:00' || strtolower($trimmed) === 'none') {
            return null;
        }

        try {
            // 1. Format: mmm/dd/yyyy HH:MM:SS (e.g. aug/25/2026 14:30:15 or aug/05/2026 09:10:00)
            if (preg_match('/^([a-z]{3})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}:\d{2}:\d{2})$/i', $trimmed, $m)) {
                return Carbon::createFromFormat('M/d/Y H:i:s', "{$m[1]}/{$m[2]}/{$m[3]} {$m[4]}");
            }

            // 2. Format: mmm/dd HH:MM:SS (e.g. aug/25 14:30:15 without year)
            if (preg_match('/^([a-z]{3})\/(\d{1,2})\s+(\d{1,2}:\d{2}:\d{2})$/i', $trimmed, $m)) {
                $year = date('Y');
                return Carbon::createFromFormat('Y M/d H:i:s', "{$year} {$m[1]}/{$m[2]} {$m[3]}");
            }

            // 3. Format: HH:MM:SS (time only, today)
            if (preg_match('/^(\d{1,2}:\d{2}:\d{2})$/', $trimmed, $m)) {
                $today = date('Y-m-d');
                return Carbon::createFromFormat('Y-m-d H:i:s', "{$today} {$m[1]}");
            }

            return Carbon::parse($trimmed);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Format bytes to readable string (GB, MB, KB).
     */
    protected function formatBytes(float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return number_format($bytes, 0) . ' B';
    }

    /**
     * Disable a PPPoE secret in MikroTik and disconnect any active session.
     */
    public function disablePppoeSecret(string $username): array
    {
        if (!$this->connect()) {
            return ['success' => false, 'message' => 'Gagal koneksi ke MikroTik'];
        }

        try {
            $username = trim($username);
            if (empty($username)) {
                return ['success' => false, 'message' => 'Username PPPoE kosong.'];
            }

            $query = (new Query('/ppp/secret/print'))->where('name', $username);
            $secrets = $this->client->query($query)->read();

            if (empty($secrets)) {
                return ['success' => false, 'message' => "Secret PPPoE '{$username}' tidak ditemukan di MikroTik."];
            }

            foreach ($secrets as $sec) {
                if (isset($sec['.id'])) {
                    // Disable secret
                    $disableQuery = (new Query('/ppp/secret/set'))
                        ->equal('.id', $sec['.id'])
                        ->equal('disabled', 'yes')
                        ->equal('comment', 'OFF - Cabut Alat ' . date('d/m/Y H:i'));
                    $this->client->query($disableQuery)->read();
                }
            }

            // Also kick active connection if online
            $activeQuery = (new Query('/ppp/active/print'))->where('name', $username);
            $actives = $this->client->query($activeQuery)->read();
            foreach ($actives as $act) {
                if (isset($act['.id'])) {
                    $removeActive = (new Query('/ppp/active/remove'))->equal('.id', $act['.id']);
                    $this->client->query($removeActive)->read();
                }
            }

            return ['success' => true, 'message' => "Secret PPPoE '{$username}' berhasil dinonaktifkan (Disabled) & session aktif diputus."];
        } catch (Throwable $e) {
            Log::error("Mikrotik Disable PPPoE Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Permanently delete a PPPoE secret in MikroTik and terminate any active session.
     */
    public function removePppoeSecret(string $username): array
    {
        return $this->deletePppoeSecret($username);
    }
}
