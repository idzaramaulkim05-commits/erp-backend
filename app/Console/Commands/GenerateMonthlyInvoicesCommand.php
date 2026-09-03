<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

class GenerateMonthlyInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoice:generate-monthly {--bulan= : Nomor bulan (1-12)} {--tahun= : Tahun (e.g. 2026)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Otomatis generate invoice bulanan untuk seluruh pelanggan aktif pada awal bulan (tgl 1)';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceService $invoiceService): int
    {
        $bulan = (int) ($this->option('bulan') ?: now()->month);
        $tahun = (int) ($this->option('tahun') ?: now()->year);

        $this->info("Memulai pembuatan invoice bulanan untuk periode {$bulan}/{$tahun}...");

        $res = $invoiceService->generateMonthlyInvoices($bulan, $tahun);

        if (!empty($res['success'])) {
            $this->info(" Berhasil membuat {$res['created_count']} invoice baru.");
            $this->info(" Dilewati (Status UNINSTALL): {$res['skipped_uninstalled']}");
            $this->info(" Dilewati (Ada Tunggakan Belum Lunas): {$res['skipped_unpaid_backlog']}");
            $this->info(" Dilewati (Sudah Ada Invoice Bulan Ini): {$res['skipped_already_exists']}");
            $this->info(" Total Nominal Tagihan: Rp " . number_format($res['total_nominal'], 0, ',', '.'));
            return Command::SUCCESS;
        }

        $this->error("Gagal generate invoice: " . ($res['message'] ?? 'Unknown error'));
        return Command::FAILURE;
    }
}
