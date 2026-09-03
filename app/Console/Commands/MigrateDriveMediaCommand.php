<?php

namespace App\Console\Commands;

use App\Models\DataSheet;
use App\Models\Invoice;
use App\Models\Pelanggan;
use App\Models\Ticket;
use App\Models\WarehouseReturn;
use App\Services\MediaStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MigrateDriveMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:migrate-drive {--limit=0 : Limit number of records to process per table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download semua foto dari Google Drive / URL eksternal dan simpan langsung ke database dan penyimpanan lokal server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Memulai migrasi foto dari Google Drive ke penyimpanan lokal server...');
        $limit = (int) $this->option('limit');

        $totalDownloaded = 0;
        $totalErrors = 0;

        // 1. DataSheets
        if (Schema::hasTable('data_sheets')) {
            $this->info("\n📂 Memproses tabel data_sheets...");
            $photoFields = [
                'foto_rumah_url'       => 'datasheet/houses',
                'foto_odp_url'         => 'datasheet/odp',
                'foto_modem_url'       => 'datasheet/modem',
                'foto_redaman_url'     => 'datasheet/redaman',
                'foto_label_kabel_url' => 'datasheet/label_kabel',
                'foto_ktp_url'         => 'datasheet/ktp',
                'foto_dokumen_url'     => 'datasheet/documents',
            ];

            $query = DataSheet::query();
            if ($limit > 0) $query->limit($limit);
            $sheets = $query->get();

            $bar = $this->output->createProgressBar(count($sheets));
            $bar->start();

            foreach ($sheets as $item) {
                $changed = false;
                foreach ($photoFields as $field => $folder) {
                    $val = $item->{$field};
                    if (!empty($val) && (str_contains($val, 'drive.google.com') || str_contains($val, 'docs.google.com') || str_contains($val, 'googleusercontent.com') || str_starts_with($val, 'http://') || str_starts_with($val, 'https://'))) {
                        $prefix = ($item->username_pppoe ?: 'ds') . '_' . $field;
                        $localPath = MediaStorageService::downloadAndStoreFromUrl($val, $folder, $prefix);
                        if ($localPath && $localPath !== $val) {
                            $item->{$field} = $localPath;
                            $changed = true;
                            $totalDownloaded++;
                        }
                    }
                }
                if ($changed) {
                    $item->save();
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
        }

        // 2. Tickets
        if (Schema::hasTable('tickets')) {
            $this->info("\n📂 Memproses tabel tickets...");
            $ticketFields = [
                'foto_rumah'       => 'tickets/houses',
                'foto_sebelum'     => 'tickets/evidence',
                'foto_sesudah'     => 'tickets/modem',
                'foto_odp'         => 'tickets/odp',
                'foto_redaman'     => 'tickets/redaman',
                'foto_label_kabel' => 'tickets/label_kabel',
                'foto_dokumen'     => 'tickets/documents',
                'bukti_pembayaran' => 'payments',
            ];

            $query = Ticket::query();
            if ($limit > 0) $query->limit($limit);
            $tickets = $query->get();

            $bar = $this->output->createProgressBar(count($tickets));
            $bar->start();

            foreach ($tickets as $t) {
                $changed = false;
                foreach ($ticketFields as $field => $folder) {
                    $val = $t->{$field};
                    if (!empty($val) && (str_contains($val, 'drive.google.com') || str_contains($val, 'docs.google.com') || str_contains($val, 'googleusercontent.com') || str_starts_with($val, 'http://') || str_starts_with($val, 'https://'))) {
                        $prefix = ($t->ticket_number ?: 'tkt') . '_' . $field;
                        $localPath = MediaStorageService::downloadAndStoreFromUrl($val, $folder, $prefix);
                        if ($localPath && $localPath !== $val) {
                            $t->{$field} = $localPath;
                            $changed = true;
                            $totalDownloaded++;
                        }
                    }
                }
                if ($changed) {
                    $t->save();
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
        }

        // 3. Pelanggan
        if (Schema::hasTable('pelanggan')) {
            $this->info("\n📂 Memproses tabel pelanggan...");
            $pelangganFields = [
                'foto_odp'           => 'datasheet/odp',
                'foto_redaman'       => 'datasheet/redaman',
                'foto_label_kabel'   => 'datasheet/label_kabel',
                'foto_dokumen'       => 'datasheet/documents',
                'foto_identitas_onu' => 'datasheet/modem',
            ];

            $pelangganList = Pelanggan::all();
            foreach ($pelangganList as $p) {
                $changed = false;
                foreach ($pelangganFields as $field => $folder) {
                    $val = $p->{$field};
                    if (!empty($val) && (str_contains($val, 'drive.google.com') || str_contains($val, 'docs.google.com') || str_contains($val, 'googleusercontent.com') || str_starts_with($val, 'http://') || str_starts_with($val, 'https://'))) {
                        $prefix = ($p->username ?: 'pelanggan') . '_' . $field;
                        $localPath = MediaStorageService::downloadAndStoreFromUrl($val, $folder, $prefix);
                        if ($localPath && $localPath !== $val) {
                            $p->{$field} = $localPath;
                            $changed = true;
                            $totalDownloaded++;
                        }
                    }
                }
                if ($changed) {
                    $p->save();
                }
            }
        }

        // 4. Invoices
        if (Schema::hasTable('invoices')) {
            $this->info("\n📂 Memproses tabel invoices...");
            $invoices = Invoice::whereNotNull('bukti_bayar')->get();
            foreach ($invoices as $inv) {
                $val = $inv->bukti_bayar;
                if (!empty($val) && (str_contains($val, 'drive.google.com') || str_contains($val, 'docs.google.com') || str_contains($val, 'googleusercontent.com') || str_starts_with($val, 'http://') || str_starts_with($val, 'https://'))) {
                    $prefix = ($inv->nomor_invoice ?: 'inv') . '_bukti';
                    $localPath = MediaStorageService::downloadAndStoreFromUrl($val, 'payments', $prefix);
                    if ($localPath && $localPath !== $val) {
                        $inv->bukti_bayar = $localPath;
                        $inv->save();
                        $totalDownloaded++;
                    }
                }
            }
        }

        // 5. Warehouse Returns
        if (Schema::hasTable('warehouse_returns')) {
            $this->info("\n📂 Memproses tabel warehouse_returns...");
            $returns = WarehouseReturn::whereNotNull('foto_barang')->get();
            foreach ($returns as $ret) {
                $val = $ret->foto_barang;
                if (!empty($val) && (str_contains($val, 'drive.google.com') || str_contains($val, 'docs.google.com') || str_contains($val, 'googleusercontent.com') || str_starts_with($val, 'http://') || str_starts_with($val, 'https://'))) {
                    $prefix = ($ret->nomor_retur ?: 'ret') . '_barang';
                    $localPath = MediaStorageService::downloadAndStoreFromUrl($val, 'warehouse', $prefix);
                    if ($localPath && $localPath !== $val) {
                        $ret->foto_barang = $localPath;
                        $ret->save();
                        $totalDownloaded++;
                    }
                }
            }
        }

        $this->newLine();
        $this->info("✅ Migrasi selesai! Total {$totalDownloaded} file foto telah berhasil didownload dan disimpan ke penyimpanan lokal server.");

        return Command::SUCCESS;
    }
}
