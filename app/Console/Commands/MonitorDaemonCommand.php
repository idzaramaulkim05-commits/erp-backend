<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Odp;
use App\Models\Setting;
use App\Services\NotificationService;
use App\Services\OltRealFetcherService;
use Illuminate\Console\Command;

class MonitorDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'monitor:daemon {--interval=15 : Interval polling dalam detik}';

    /**
     * The console command description.
     */
    protected $description = 'Background Worker otomatis untuk memantau OLT & ODP secara Real-time dan mengirim notifikasi WhatsApp';

    /**
     * Execute the console command.
     */
    public function handle(OltRealFetcherService $fetcher): int
    {
        $interval = (int) $this->option('interval') ?: 15;
        $this->info("🚀 Background Worker NOC Monitoring EONET Aktif!");
        $this->info("📡 Memeriksa status perangkat OLT & ODP fisik setiap {$interval} detik...");
        $this->line("Tekan CTRL+C untuk keluar.\n");

        while (true) {
            $olts = Olt::all();
            foreach ($olts as $olt) {
                $oldStatus = $olt->status;
                $result = $fetcher->fetchRealData($olt);

                // 1. Check POP Outage / Recovery
                if ($oldStatus === 'online' && $result['status'] === 'offline') {
                    $this->error("🚨 [ALERT] POP DOWN: {$olt->name} ({$olt->ip_address}) mati listrik / tidak merespon!");
                    NotificationService::notifyPopDown($olt, 'Mati Lampu PLN / OLT Tidak Merespon');
                } elseif ($oldStatus === 'offline' && $result['status'] === 'online') {
                    $this->info("✅ [RECOVERY] POP UP: {$olt->name} ({$olt->ip_address}) listrik normal kembali!");
                    NotificationService::notifyPopUp($olt, 5.0);
                }
            }

            sleep($interval);
        }

        return 0;
    }
}
