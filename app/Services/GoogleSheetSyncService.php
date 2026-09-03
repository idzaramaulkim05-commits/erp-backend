<?php

namespace App\Services;

use App\Models\DataSheet;
use App\Models\Pelanggan;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSheetSyncService
{
    /**
     * Extract pure Google Drive Folder ID from standard Google Drive URL or ID.
     */
    public static function extractFolderId(?string $urlOrId): ?string
    {
        if (empty($urlOrId)) return null;
        $urlOrId = trim($urlOrId);
        if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $urlOrId, $m)) {
            return $m[1];
        }
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $urlOrId, $m)) {
            return $m[1];
        }
        return $urlOrId;
    }

    /**
     * Convert local storage file to Base64 package for Google Apps Script Google Drive upload.
     */
    public static function fileToBase64(?string $relativePath): ?array
    {
        if (empty($relativePath)) return null;
        $relativePath = trim($relativePath);

        // If already remote URL
        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return [
                'type'     => 'url',
                'url'      => $relativePath,
                'filename' => basename(parse_url($relativePath, PHP_URL_PATH) ?: 'image.jpg'),
            ];
        }

        $cleanPath = ltrim(str_replace(['storage/', 'public/'], '', $relativePath), '/\\');
        $fullPath = storage_path('app/public/' . $cleanPath);

        if (file_exists($fullPath) && is_file($fullPath)) {
            $mime = @mime_content_type($fullPath) ?: 'image/jpeg';
            $content = base64_encode(file_get_contents($fullPath));
            return [
                'type'     => 'base64',
                'filename' => basename($fullPath),
                'mime'     => $mime,
                'data'     => $content,
            ];
        }

        return null;
    }

    /**
     * Send Real-time Ticket/PSB update with automatic background fallback or direct dispatch.
     */
    public static function syncTicketToGoogleSheetAsync(Ticket $ticket): void
    {
        $ticketId = (int) $ticket->id;
        if ($ticketId <= 0) return;

        // Run after the HTTP response has been sent to the user's browser (Fast Response, Anti Delay!)
        dispatch(function () use ($ticketId) {
            $ticket = Ticket::find($ticketId);
            if ($ticket) {
                try {
                    self::syncTicketToGoogleSheet($ticket);
                } catch (\Throwable $e) {
                    Log::warning("Async ticket sheet sync error: " . $e->getMessage());
                }
            }
        })->afterResponse();
    }

    /**
     * Send Real-time Ticket/PSB update to Google Sheet & Google Drive via Google Apps Script Webhook.
     */
    public static function syncTicketToGoogleSheet(Ticket $ticket): bool
    {
        try {
            $setting = Setting::getSetting();
            $webhookUrl = trim((string)($setting->google_sheet_webhook_url ?? ''));

            // If no webhook URL configured, exit gracefully
            if (empty($webhookUrl) || !str_contains($webhookUrl, 'script.google.com')) {
                return false;
            }

            $isPsb = ($ticket->type === 'psb');
            $isDismantle = ($ticket->type === 'dismantle');
            $sheetName = $isDismantle
                ? 'CABUT ALAT'
                : ($isPsb ? ($setting->sheet_tab_pelanggan_fix ?: 'Pelanggan Aktif') : 'TIKET');

            $mapsUrl = $ticket->shareloc_url;
            if (empty($mapsUrl) && !empty($ticket->latitude) && !empty($ticket->longitude)) {
                $mapsUrl = "https://www.google.com/maps?q={$ticket->latitude},{$ticket->longitude}";
            }

            $tglPasang = $ticket->resolved_at ?? ($ticket->validated_at ?? ($ticket->noc_assigned_vlan_at ?? $ticket->created_at));
            $tglHari = $tglPasang ? $tglPasang->format('d') : date('d');
            $tglFormatted = $tglPasang ? $tglPasang->format('d/m/Y') : date('d/m/Y');

            // Convert photos to base64 for Drive uploading
            $fRumah    = self::fileToBase64($ticket->foto_rumah ?: $ticket->foto_sebelum);
            $fOdp      = self::fileToBase64($ticket->foto_odp);
            $fRedaman  = self::fileToBase64($ticket->foto_redaman);
            $fDokumen  = self::fileToBase64($ticket->foto_dokumen);
            $fOnu      = self::fileToBase64($ticket->foto_sesudah);
            $fLabel    = self::fileToBase64($ticket->foto_label_kabel);
            $fEvidence = self::fileToBase64($ticket->foto_sebelum ?: $ticket->foto_sesudah);
            $fPayment  = self::fileToBase64($ticket->payment_proof);

            $statusPelanggan = in_array($ticket->status, ['ready_activation', 'resolved', 'closed'])
                ? 'DONE INSTAL'
                : ($ticket->status === 'cancelled' ? 'BATAL' : strtoupper(str_replace('_', ' ', $ticket->status)));

            $statusBayarInstalasi = ($ticket->payment_status === 'approved')
                ? 'SUDAH FINANCE BILLING'
                : 'BELUM SETOR FINANCE BILLING';

            $merkModem = '-';
            $wReq = \App\Models\WarehouseRequest::where('ticket_id', $ticket->id)->with('items.warehouseItem')->first();
            if ($wReq) {
                foreach ($wReq->items as $wItem) {
                    if ($wItem->warehouseItem && (stripos($wItem->warehouseItem->nama_barang, 'modem') !== false || stripos($wItem->warehouseItem->nama_barang, 'onu') !== false || stripos($wItem->warehouseItem->nama_barang, 'hg8') !== false || stripos($wItem->warehouseItem->nama_barang, 'zte') !== false || stripos($wItem->warehouseItem->nama_barang, 'f6') !== false)) {
                        $merkModem = $wItem->warehouseItem->nama_barang;
                        break;
                    }
                }
            }
            if ($merkModem === '-' && !empty($ticket->serial_number_ont)) {
                $merkModem = $ticket->serial_number_ont;
            }

            $latVal = (!empty($ticket->latitude) && (string)$ticket->latitude !== '-1') ? (string)$ticket->latitude : ($ticket->shareloc_url ?: '-');
            $lngVal = (!empty($ticket->longitude) && (string)$ticket->longitude !== '-1') ? (string)$ticket->longitude : ($ticket->shareloc_url ?: '-');

            $payload = [
                'action'                 => 'sync_ticket',
                'sheet'                  => $setting->sheet_tab_pelanggan_fix ?: 'Pelanggan Aktif',
                'no_tiket'               => $ticket->ticket_number,
                'jenis_tiket'            => strtoupper($ticket->type),
                'kategori_pelanggan'     => $ticket->kategori_pelanggan ?: 'MR',
                'nama_pelanggan'         => $ticket->pelanggan_nama,
                'telepon'                => $ticket->pelanggan_telepon,
                'id_customer'            => $ticket->id_customer ?: ($ticket->pelanggan_id ? "CUST-{$ticket->pelanggan_id}" : '-'),
                'alamat'                 => $ticket->alamat,
                'username_pppoe'         => $ticket->pelanggan_username ?: '-',
                'pembaruan_pppoe'        => $ticket->pembaruan_pppoe ?: '-',
                'tanggal_jatuh_tempo'    => $ticket->tanggal_jatuh_tempo ?: $tglHari,
                'tanggal_instalasi'      => $tglFormatted,
                'paket'                  => $ticket->paket_layanan ?: '-',
                'marketing'              => $ticket->nama_marketing ?: 'EONET',
                'metode_bayar'           => strtoupper($ticket->payment_method ?: 'CASH'),
                'status_pelanggan'       => $statusPelanggan,
                'status_bayar_instalasi' => $statusBayarInstalasi,
                'ip'                     => $ticket->ip_address ?: '-',
                'harga_paket'            => $ticket->harga_paket ? (int)$ticket->harga_paket : '-',
                'biaya_pasang'           => $ticket->payment_amount ? (int)$ticket->payment_amount : ($ticket->biaya_registrasi ? (int)$ticket->biaya_registrasi : '-'),
                'mac_ont'                => $ticket->mac_ont ?: '-',
                'pon_sn'                 => $ticket->pon_sn ?: '-',
                'serial_number'          => $ticket->serial_number_ont ?: '-',
                'merk_modem'             => $merkModem,
                'latitude'               => $latVal,
                'longitude'              => $lngVal,
                'alasan_cabut'           => $ticket->alasan_cabut ?: '-',
                'kelengkapan'            => $ticket->kelengkapan_alat ?: '-',
                'redaman_akhir'          => $ticket->redaman_sesudah ?: '-',
                'catatan'                => $ticket->catatan_teknisi ?: ($ticket->deskripsi_masalah ?: ($ticket->catatan_cs ?: '-')),

                // Google Drive Destination Folder IDs
                'folder_id_foto_rumah'       => self::extractFolderId($setting->gdrive_folder_foto_rumah),
                'folder_id_foto_odp'         => self::extractFolderId($setting->gdrive_folder_foto_odp),
                'folder_id_foto_onu'         => self::extractFolderId($setting->gdrive_folder_foto_onu),
                'folder_id_foto_redaman'     => self::extractFolderId($setting->gdrive_folder_foto_redaman),
                'folder_id_foto_dokumen'     => self::extractFolderId($setting->gdrive_folder_foto_dokumen),
                'folder_id_foto_evidence'    => self::extractFolderId($setting->gdrive_folder_foto_evidence),
                'folder_id_foto_payments'    => self::extractFolderId($setting->gdrive_folder_foto_payments),
                'folder_id_foto_label_kabel' => self::extractFolderId($setting->gdrive_folder_foto_label_kabel),

                // Base64 or URL Photos
                'foto_rumah_url'          => (($fRumah['type'] ?? '') === 'url') ? ($fRumah['url'] ?? '') : '',
                'foto_rumah_base64'       => $fRumah['data'] ?? '',
                'foto_rumah_filename'     => $fRumah['filename'] ?? '',

                'foto_odp_url'            => (($fOdp['type'] ?? '') === 'url') ? ($fOdp['url'] ?? '') : '',
                'foto_odp_base64'         => $fOdp['data'] ?? '',
                'foto_odp_filename'       => $fOdp['filename'] ?? '',

                'foto_onu_url'            => (($fOnu['type'] ?? '') === 'url') ? ($fOnu['url'] ?? '') : '',
                'foto_onu_base64'         => $fOnu['data'] ?? '',
                'foto_onu_filename'       => $fOnu['filename'] ?? '',

                'foto_redaman_url'        => (($fRedaman['type'] ?? '') === 'url') ? ($fRedaman['url'] ?? '') : '',
                'foto_redaman_base64'     => $fRedaman['data'] ?? '',
                'foto_redaman_filename'   => $fRedaman['filename'] ?? '',

                'foto_dokumen_url'        => (($fDokumen['type'] ?? '') === 'url') ? ($fDokumen['url'] ?? '') : '',
                'foto_dokumen_base64'     => $fDokumen['data'] ?? '',
                'foto_dokumen_filename'   => $fDokumen['filename'] ?? '',

                'foto_label_kabel_url'      => (($fLabel['type'] ?? '') === 'url') ? ($fLabel['url'] ?? '') : '',
                'foto_label_kabel_base64'   => $fLabel['data'] ?? '',
                'foto_label_kabel_filename' => $fLabel['filename'] ?? '',

                'foto_evidence_url'         => (($fEvidence['type'] ?? '') === 'url') ? ($fEvidence['url'] ?? '') : '',
                'foto_evidence_base64'      => $fEvidence['data'] ?? '',
                'foto_evidence_filename'    => $fEvidence['filename'] ?? '',

                'foto_payments_url'         => (($fPayment['type'] ?? '') === 'url') ? ($fPayment['url'] ?? '') : '',
                'foto_payments_base64'      => $fPayment['data'] ?? '',
                'foto_payments_filename'    => $fPayment['filename'] ?? '',
            ];

            // Send HTTP POST request as raw JSON for Google Apps Script Web App
            $response = Http::timeout(120)
                ->withoutVerifying()
                ->withBody(json_encode($payload), 'application/json')
                ->post($webhookUrl);

            if ($response->successful()) {
                $resData = $response->json();
                if (!empty($resData['urls']) && is_array($resData['urls'])) {
                    $u = $resData['urls'];
                    
                    // Keep track of local paths we want to delete
                    $filesToDelete = [];
                    $ticketUpdate = [];

                    if (!empty($u['foto_rumah']) && !empty($ticket->foto_rumah)) {
                        $filesToDelete[] = $ticket->foto_rumah;
                        $ticketUpdate['foto_rumah'] = $u['foto_rumah'];
                    }
                    if (!empty($u['foto_odp']) && !empty($ticket->foto_odp)) {
                        $filesToDelete[] = $ticket->foto_odp;
                        $ticketUpdate['foto_odp'] = $u['foto_odp'];
                    }
                    if (!empty($u['foto_onu']) && !empty($ticket->foto_sesudah)) {
                        $filesToDelete[] = $ticket->foto_sesudah;
                        $ticketUpdate['foto_sesudah'] = $u['foto_onu'];
                        
                        // Also update associated WarehouseReturn files
                        \App\Models\WarehouseReturn::where('ticket_id', $ticket->id)
                            ->where('foto_barang', $ticket->foto_sesudah)
                            ->update(['foto_barang' => $u['foto_onu']]);
                    }
                    if (!empty($u['foto_label_kabel']) && !empty($ticket->foto_label_kabel)) {
                        $filesToDelete[] = $ticket->foto_label_kabel;
                        $ticketUpdate['foto_label_kabel'] = $u['foto_label_kabel'];
                    }
                    if (!empty($u['foto_redaman']) && !empty($ticket->foto_redaman)) {
                        $filesToDelete[] = $ticket->foto_redaman;
                        $ticketUpdate['foto_redaman'] = $u['foto_redaman'];
                    }
                    if (!empty($u['foto_dokumen']) && !empty($ticket->foto_dokumen)) {
                        $filesToDelete[] = $ticket->foto_dokumen;
                        $ticketUpdate['foto_dokumen'] = $u['foto_dokumen'];
                    }
                    if (!empty($u['foto_evidence']) && !empty($ticket->foto_sebelum)) {
                        $filesToDelete[] = $ticket->foto_sebelum;
                        $ticketUpdate['foto_sebelum'] = $u['foto_evidence'];
                    }
                    if (!empty($u['foto_payments']) && !empty($ticket->bukti_pembayaran)) {
                        $filesToDelete[] = $ticket->bukti_pembayaran;
                        $ticketUpdate['bukti_pembayaran'] = $u['foto_payments'];
                    }

                    if (!empty($ticketUpdate)) {
                        $ticket->update($ticketUpdate);
                        
                        // Delete local files
                        foreach ($filesToDelete as $file) {
                            self::deleteLocalFile($file);
                        }
                    }

                    // Update DataSheet URLs
                    $dsUpdate = [];
                    if (!empty($u['foto_rumah'])) $dsUpdate['foto_rumah_url'] = $u['foto_rumah'];
                    if (!empty($u['foto_odp'])) $dsUpdate['foto_odp_url'] = $u['foto_odp'];
                    if (!empty($u['foto_onu'])) $dsUpdate['foto_modem_url'] = $u['foto_onu'];
                    if (!empty($u['foto_label_kabel'])) $dsUpdate['foto_label_kabel_url'] = $u['foto_label_kabel'];
                    if (!empty($u['foto_redaman'])) $dsUpdate['foto_redaman_url'] = $u['foto_redaman'];
                    if (!empty($u['foto_dokumen'])) $dsUpdate['foto_dokumen_url'] = $u['foto_dokumen'];
                    if (!empty($dsUpdate)) {
                        $pUser = $ticket->pelanggan_username ?: ($ticket->id_customer ?: $ticket->pelanggan_nama);
                        DataSheet::where('username_pppoe', $pUser)
                            ->orWhere('nama_pelanggan', $ticket->pelanggan_nama)
                            ->update($dsUpdate);
                    }
                }
            } else {
                Log::warning("Google Sheet Webhook Sync HTTP {$response->status()}: " . substr($response->body(), 0, 200));
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Google Sheet Webhook Sync Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send customer update from DataSheet to Google Sheet & Google Drive asynchronously.
     */
    public static function syncDataSheetToGoogleSheetAsync(DataSheet $item): void
    {
        $itemId = (int) $item->id;
        if ($itemId <= 0) return;

        dispatch(function () use ($itemId) {
            $item = DataSheet::find($itemId);
            if ($item) {
                try {
                    self::syncDataSheetToGoogleSheet($item);
                } catch (\Throwable $e) {
                    Log::warning("Async customer datasheet sync error: " . $e->getMessage());
                }
            }
        })->afterResponse();
    }

    /**
     * Send customer update from DataSheet to Google Sheet & Google Drive.
     */
    public static function syncDataSheetToGoogleSheet(DataSheet $item): bool
    {
        try {
            $setting = Setting::getSetting();
            $webhookUrl = trim((string)($setting->google_sheet_webhook_url ?? ''));

            if (empty($webhookUrl) || !str_contains($webhookUrl, 'script.google.com')) {
                return false;
            }

            $raw = is_array($item->raw_data) ? $item->raw_data : (json_decode($item->raw_data, true) ?: []);
            $idCust = $raw['id_customer'] ?? null;
            if (!empty($idCust) && $idCust === $item->nik_ktp) {
                $idCust = null;
            }

            $fRumah   = self::fileToBase64($item->foto_rumah_url);
            $fOdp     = self::fileToBase64($item->foto_odp_url);
            $fRedaman = self::fileToBase64($item->foto_redaman_url);
            $fDokumen = self::fileToBase64($item->foto_dokumen_url);
            $fOnu     = self::fileToBase64($item->foto_modem_url);
            $fLabel   = self::fileToBase64($item->foto_label_kabel_url ?? null);

            $mapsUrl = $item->lokasi_maps ?: ($raw['shareloc_url'] ?? '');
            $latVal = $raw['latitude'] ?? ($mapsUrl ?: '-');
            $lngVal = $raw['longitude'] ?? ($mapsUrl ?: '-');

            $tglPasang = $item->tanggal_instalasi ?: date('d/m/Y');
            $tglJatuhTempo = $item->tanggal_jatuh_tempo ?: ($tglPasang ? explode('/', $tglPasang)[0] : '27');

            $payload = [
                'action'                 => 'sync_customer',
                'sheet'                  => $setting->sheet_tab_pelanggan_fix ?: 'Pelanggan Aktif',
                'no_tiket'               => $raw['ticket_number'] ?? '',
                'kategori_pelanggan'     => $raw['kategori_pelanggan'] ?? 'MR',
                'nama_pelanggan'         => $item->nama_pelanggan ?: $item->username_pppoe,
                'no_hp'                  => $item->telepon ?: '-',
                'telepon'                => $item->telepon ?: '-',
                'id_customer'            => $idCust ?: ($item->nik_ktp ?: '-'),
                'nik_ktp'                => $item->nik_ktp ?: ($idCust ?: '-'),
                'alamat'                 => $item->alamat ?: '-',
                'username_pppoe'         => $item->username_pppoe,
                'pembaruan_pppoe'        => $raw['pembaruan_pppoe'] ?? '-',
                'tanggal_jatuh_tempo'    => $tglJatuhTempo,
                'paket'                  => $item->paket ?: '-',
                'metode_bayar'           => $raw['metode_bayar'] ?? 'CASH',
                'status'                 => strtoupper(trim((string)($raw[12] ?? ($item->status_langganan === 'dismantle' ? 'UNISTALL' : 'DONE INSTAL')))),
                'status_pelanggan'       => strtoupper(trim((string)($raw[12] ?? ($item->status_langganan === 'dismantle' ? 'UNISTALL' : 'DONE INSTAL')))),
                'status_bayar_instalasi' => $item->status_pembayaran ?: 'SUDAH FINANCE BILLING',
                'status_bayar'           => $item->status_pembayaran ?: 'SUDAH FINANCE BILLING',
                'ip'                     => $item->ip_address ?: '-',
                'ip_address'             => $item->ip_address ?: '-',
                'harga_paket'            => !is_null($item->harga_paket) ? (float) $item->harga_paket : '-',
                'biaya_pasang'           => !is_null($item->biaya_pasang) ? (float) $item->biaya_pasang : '-',
                'odp'                    => $item->nama_odp ?: '-',
                'port_odp'               => $item->port_odp ?: '-',
                'vlan'                   => $item->vlan ?: '-',
                'mac_ont'                => $item->mac_address ?: '-',
                'pon_sn'                 => $item->pon_sn ?: '-',
                'serial_number'          => $item->serial_number ?: '-',
                'kelengkapan'            => $item->keterangan ?: '-',
                'catatan'                => $item->keterangan ?: '-',
                'tanggal_instalasi'      => $tglPasang,
                'tanggal'                => $tglPasang,
                'merk_modem'             => $item->olt_server && $item->olt_server !== '-' ? $item->olt_server : 'Modem ONU',
                'shareloc_url'           => $mapsUrl ?: '-',
                'latitude'               => $latVal,
                'longitude'              => $lngVal,

                // Google Drive Folder IDs
                'folder_id_foto_rumah'    => self::extractFolderId($setting->gdrive_folder_foto_rumah),
                'folder_id_foto_odp'      => self::extractFolderId($setting->gdrive_folder_foto_odp),
                'folder_id_foto_onu'      => self::extractFolderId($setting->gdrive_folder_foto_onu),
                'folder_id_foto_redaman'  => self::extractFolderId($setting->gdrive_folder_foto_redaman),
                'folder_id_foto_dokumen'  => self::extractFolderId($setting->gdrive_folder_foto_dokumen),

                // Photos
                'foto_rumah_url'          => (($fRumah['type'] ?? '') === 'url') ? ($fRumah['url'] ?? '') : '',
                'foto_rumah_base64'       => $fRumah['data'] ?? '',
                'foto_rumah_filename'     => $fRumah['filename'] ?? '',

                'foto_odp_url'            => (($fOdp['type'] ?? '') === 'url') ? ($fOdp['url'] ?? '') : '',
                'foto_odp_base64'         => $fOdp['data'] ?? '',
                'foto_odp_filename'       => $fOdp['filename'] ?? '',

                'foto_onu_url'            => (($fOnu['type'] ?? '') === 'url') ? ($fOnu['url'] ?? '') : '',
                'foto_onu_base64'         => $fOnu['data'] ?? '',
                'foto_onu_filename'       => $fOnu['filename'] ?? '',

                'foto_redaman_url'        => (($fRedaman['type'] ?? '') === 'url') ? ($fRedaman['url'] ?? '') : '',
                'foto_redaman_base64'     => $fRedaman['data'] ?? '',
                'foto_redaman_filename'   => $fRedaman['filename'] ?? '',

                'foto_dokumen_url'        => (($fDokumen['type'] ?? '') === 'url') ? ($fDokumen['url'] ?? '') : '',
                'foto_dokumen_base64'     => $fDokumen['data'] ?? '',
                'foto_dokumen_filename'   => $fDokumen['filename'] ?? '',

                'foto_label_kabel_url'      => (($fLabel['type'] ?? '') === 'url') ? ($fLabel['url'] ?? '') : '',
                'foto_label_kabel_base64'   => $fLabel['data'] ?? '',
                'foto_label_kabel_filename' => $fLabel['filename'] ?? '',
            ];

            $response = Http::timeout(120)->withoutVerifying()->withBody(json_encode($payload), 'application/json')->post($webhookUrl);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("DataSheet Google Sheet Sync Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete local storage file safely.
     */
    private static function deleteLocalFile(?string $relativePath): void
    {
        if (empty($relativePath)) return;
        $relativePath = trim($relativePath);
        if (str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return;
        }
        $cleanPath = ltrim(str_replace(['storage/', 'public/'], '', $relativePath), '/\\');
        $fullPath = storage_path('app/public/' . $cleanPath);
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
            Log::info("Deleted local file: " . $fullPath);
        }
    }
}
