<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltRealFetcherService;
use Illuminate\Console\Command;

class SyncOltCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'olt:sync {id? : ID OLT spesifik atau kosongkan untuk semua}';

    /**
     * The console command description.
     */
    protected $description = 'Tarik data real dari perangkat OLT fisik via SNMP/Telnet';

    /**
     * Execute the console command.
     */
    public function handle(OltRealFetcherService $fetcher): int
    {
        $id = $this->argument('id');

        if ($id) {
            $olt = Olt::find($id);
            if (!$olt) {
                $this->error("OLT dengan ID {$id} tidak ditemukan.");
                return 1;
            }
            $this->syncOne($olt, $fetcher);
        } else {
            $olts = Olt::all();
            $this->info("Menyinkronkan total {$olts->count()} unit OLT fisik...");
            foreach ($olts as $olt) {
                $this->syncOne($olt, $fetcher);
            }
        }

        $this->info("✨ Sinkronisasi selesai!");
        return 0;
    }

    protected function syncOne(Olt $olt, OltRealFetcherService $fetcher): void
    {
        $this->line("📡 Menghubungi {$olt->name} ({$olt->ip_address}) ...");
        $result = $fetcher->fetchRealData($olt);

        if ($result['status'] === 'online') {
            $this->info("   " . $result['message']);
            $this->line("   -> Suhu: {$result['temperature']}°C | CPU: {$result['cpu_usage']}% | ONU: {$result['online_onu']}/{$result['total_onu']}");
        } else {
            $this->warn("   " . $result['message']);
        }
    }
}
