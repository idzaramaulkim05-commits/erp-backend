<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_number')->unique(); // e.g. TKT-202608-0001, PSB-202608-0001, DIS-202608-0001
                $table->string('type', 30)->default('trouble'); // trouble, psb, dismantle, relokasi, maintenance
                $table->string('kategori')->default('pon_los'); // pon_los, redaman_tinggi, kabel_putus, modem_rusak, psb_baru, dll
                $table->string('prioritas', 20)->default('normal'); // low, normal, high, urgent
                $table->string('kategori_pelanggan', 50)->default('regular');
                
                // Pelanggan Info
                $table->string('pelanggan_nama');
                $table->string('nama_depan', 50)->nullable();
                $table->string('nama_belakang', 50)->nullable();
                $table->string('provinsi_kode', 10)->nullable();
                $table->string('kabupaten_kode', 10)->nullable();
                $table->string('kecamatan_kode', 10)->nullable();
                $table->string('desa_kode', 10)->nullable();
                $table->string('id_customer', 50)->nullable()->index();
                $table->string('pelanggan_username', 100)->nullable()->index(); // PPPoE username
                $table->string('pppoe_password', 50)->default('1')->nullable();
                $table->string('vlan', 50)->nullable();
                $table->timestamp('request_vlan_at')->nullable();
                $table->string('noc_assigned_vlan_by', 50)->nullable();
                $table->timestamp('noc_assigned_vlan_at')->nullable();
                $table->string('pelanggan_telepon', 50)->nullable();
                $table->string('pelanggan_telepon_alt', 50)->nullable();
                $table->string('nama_marketing', 100)->nullable();
                $table->text('alamat')->nullable();
                $table->string('patokan_alamat')->nullable();
                $table->text('shareloc_url')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('foto_rumah')->nullable();
                
                // Technical & Hardware Links
                $table->unsignedBigInteger('odp_id')->nullable();
                $table->unsignedBigInteger('olt_id')->nullable();
                $table->string('paket')->nullable();
                $table->string('paket_layanan')->nullable();
                $table->text('alasan_cabut')->nullable();
                $table->text('kelengkapan_alat')->nullable();
                
                // Status Workflow
                $table->string('status', 50)->default('pending_noc');
                
                // Descriptions & Notes
                $table->text('deskripsi_keluhan')->nullable();
                $table->text('catatan_cs')->nullable();
                $table->text('catatan_noc')->nullable();
                $table->text('catatan_tl')->nullable();
                $table->text('catatan_teknisi')->nullable();
                $table->text('customer_last_reply')->nullable();
                $table->timestamp('customer_last_reply_at')->nullable();
                
                // Resolution & Installation Data
                $table->string('foto_sebelum')->nullable();
                $table->string('foto_sesudah')->nullable();
                $table->string('foto_odp')->nullable();
                $table->string('foto_redaman')->nullable();
                $table->string('foto_label_kabel')->nullable();
                $table->string('foto_dokumen')->nullable();
                $table->string('redaman_sebelum')->nullable();
                $table->string('redaman_sesudah')->nullable();
                $table->string('serial_number_ont')->nullable();
                $table->string('pon_sn')->nullable();
                $table->string('mac_ont')->nullable();
                $table->string('port_odp')->nullable();
                $table->integer('panjang_kabel')->nullable();
                
                // PSB & Payment Verification Data
                $table->decimal('biaya_pasang', 15, 2)->default(0);
                $table->decimal('harga_paket', 15, 2)->default(0);
                $table->string('payment_method', 30)->nullable(); // cash, transfer
                $table->string('payment_status', 50)->nullable(); // pending_cash_settlement, pending_transfer_verification, approved, rejected
                $table->string('bukti_pembayaran')->nullable();
                $table->text('catatan_pembayaran')->nullable();
                $table->string('payment_verified_by', 50)->nullable();
                $table->timestamp('payment_verified_at')->nullable();
                
                // User Tracking & Multi-Technician
                $table->string('created_by', 50)->nullable();
                $table->string('validated_by', 50)->nullable();
                $table->timestamp('validated_at')->nullable();
                $table->string('assigned_by', 50)->nullable();
                $table->string('assigned_to', 50)->nullable();
                $table->json('assigned_technicians')->nullable(); // Array of technician IDs
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('in_progress_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('closed_by', 50)->nullable();
                $table->timestamp('closed_at')->nullable();
                
                $table->timestamps();
                
                $table->index('status');
                $table->index('type');
                $table->index('prioritas');
                $table->index('assigned_to');
            });
        }

        if (!Schema::hasTable('ticket_logs')) {
            Schema::create('ticket_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_id');
                $table->string('user_id', 50)->nullable();
                $table->string('user_name', 150)->nullable();
                $table->string('action');
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50)->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_logs');
        Schema::dropIfExists('tickets');
    }
};
