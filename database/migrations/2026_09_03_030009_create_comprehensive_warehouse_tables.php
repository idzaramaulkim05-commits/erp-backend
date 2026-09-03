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
        if (!Schema::hasTable('warehouse_items')) {
            Schema::create('warehouse_items', function (Blueprint $table) {
                $table->id();
                $table->string('kode_barang')->unique();
                $table->string('nama_barang');
                $table->string('kategori'); // onu_modem, kabel_dropcore, aksesoris_optik, perangkat_aktif, tools_perkakas
                $table->string('kondisi')->default('baru'); // baru, second, rusak
                $table->string('satuan')->default('Unit'); // Unit, Meter, Roll, Pcs, Kotak
                $table->integer('stok')->default(0);
                $table->integer('min_stok')->default(5);
                $table->decimal('harga_estimasi', 14, 2)->nullable()->default(0);
                $table->string('lokasi_rak')->nullable();
                $table->text('spesifikasi')->nullable();
                $table->string('foto')->nullable();
                $table->string('status')->default('aktif'); // aktif, nonaktif
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('warehouse_requests')) {
            Schema::create('warehouse_requests', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_request')->unique();
                $table->string('tipe_request'); // restock_procurement, divisi_operational, psb_package, swap_replacement
                $table->string('kategori_kebutuhan')->nullable();
                $table->unsignedBigInteger('ticket_id')->nullable();
                $table->string('user_id', 50)->nullable(); // pemohon
                $table->string('divisi')->default('teknisi'); // teknisi, noc, cs, finance, gudang
                $table->text('alasan')->nullable();
                $table->string('alokasi_aset')->nullable();
                $table->string('target_lokasi')->nullable();
                $table->unsignedBigInteger('replaced_asset_id')->nullable();
                $table->string('serial_number_lama')->nullable();
                $table->string('lampiran_foto')->nullable();
                $table->unsignedBigInteger('warehouse_return_id')->nullable();
                $table->string('status')->default('pending_gudang'); 
                // Statuses: pending_finance, approved, rejected, pending_gudang, completed
                $table->string('action_pengerjaan')->nullable();
                $table->timestamp('action_done_at')->nullable();
                $table->string('action_by_user_id', 50)->nullable();
                $table->unsignedBigInteger('linked_action_ticket_id')->nullable();
                $table->string('approved_by_finance_id', 50)->nullable();
                $table->timestamp('approved_by_finance_at')->nullable();
                $table->string('confirmed_by_gudang_id', 50)->nullable();
                $table->timestamp('confirmed_by_gudang_at')->nullable();
                $table->text('catatan_finance')->nullable();
                $table->text('catatan_gudang')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('warehouse_request_items')) {
            Schema::create('warehouse_request_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_request_id');
                $table->unsignedBigInteger('warehouse_item_id');
                $table->integer('jumlah_diminta')->default(1);
                $table->integer('jumlah_disetujui')->default(1);
                $table->string('satuan')->default('Unit');
                $table->string('catatan')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('warehouse_returns')) {
            Schema::create('warehouse_returns', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_retur')->unique();
                $table->unsignedBigInteger('ticket_id')->nullable();
                $table->string('teknisi_id', 50)->nullable();
                $table->string('pelanggan_nama')->nullable();
                $table->unsignedBigInteger('warehouse_item_id')->nullable();
                $table->string('nama_barang')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('mac_address')->nullable();
                $table->string('kondisi')->default('layak_pakai'); // layak_pakai, rusak_total, perlu_servis
                $table->string('foto_barang')->nullable();
                $table->string('status')->default('pending_gudang'); // pending_gudang, received, rejected
                $table->string('received_by_gudang_id', 50)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->text('catatan_teknisi')->nullable();
                $table->text('catatan_gudang')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('warehouse_stock_mutations')) {
            Schema::create('warehouse_stock_mutations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_item_id');
                $table->string('tipe'); // in_restock, in_return, out_request, adjustment
                $table->integer('jumlah');
                $table->integer('stok_sebelum');
                $table->integer('stok_sesudah');
                $table->string('referensi_type')->nullable(); // request, return, manual
                $table->unsignedBigInteger('referensi_id')->nullable();
                $table->string('user_id', 50)->nullable();
                $table->text('catatan')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('inventory_assets')) {
            Schema::create('inventory_assets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_item_id')->nullable();
                $table->string('kode_barang')->nullable();
                $table->string('nama_barang');
                $table->string('kategori')->default('Perangkat Jaringan');
                $table->integer('jumlah')->default(1);
                $table->string('satuan')->default('Unit');
                $table->decimal('harga_satuan', 15, 2)->default(0);
                $table->decimal('total_nilai', 15, 2)->default(0);
                $table->string('lokasi_aset');
                $table->string('pic_user_id', 50)->nullable();
                $table->unsignedBigInteger('warehouse_request_id')->nullable();
                $table->unsignedBigInteger('ticket_id')->nullable();
                $table->string('nomor_referensi')->nullable();
                $table->date('tanggal_pasang')->nullable();
                $table->string('status')->default('terpasang_aktif'); // terpasang_aktif, pemeliharaan, rusak, cadangan
                $table->text('catatan')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_assets');
        Schema::dropIfExists('warehouse_stock_mutations');
        Schema::dropIfExists('warehouse_returns');
        Schema::dropIfExists('warehouse_request_items');
        Schema::dropIfExists('warehouse_requests');
        Schema::dropIfExists('warehouse_items');
    }
};
