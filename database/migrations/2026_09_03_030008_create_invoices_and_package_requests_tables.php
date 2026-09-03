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
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_invoice', 50)->unique();
                $table->string('id_customer', 50)->nullable()->index();
                $table->string('pelanggan_username', 100)->index();
                $table->string('pelanggan_nama', 150);
                $table->string('pelanggan_telepon', 50)->nullable();
                $table->text('pelanggan_alamat')->nullable();
                $table->string('kategori_pelanggan', 50)->default('regular');
                $table->string('marketing_pic', 100)->nullable();
                $table->string('teknisi_pic', 100)->nullable();
                $table->string('paket_nama', 100);
                $table->decimal('harga_paket', 15, 2)->default(0);
                $table->decimal('biaya_pasang', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('potongan', 15, 2)->default(0);
                $table->decimal('total_tagihan', 15, 2)->default(0);
                $table->decimal('total_dibayar', 15, 2)->default(0);
                $table->decimal('sisa_piutang', 15, 2)->default(0);
                $table->unsignedTinyInteger('periode_bulan')->index(); // 1 - 12
                $table->unsignedSmallInteger('periode_tahun')->index(); // 2026, 2027...
                $table->date('tanggal_invoice')->index();
                $table->date('tanggal_jatuh_tempo')->nullable();
                $table->string('status', 30)->default('belum_lunas')->index(); // 'belum_lunas', 'lunas', 'dibatalkan', 'isolir'
                $table->boolean('status_isolir')->default(false);
                $table->string('metode_pembayaran', 50)->nullable(); // 'CASH', 'TRANSFER_BCA', 'TRANSFER_BRI', 'TRANSFER_MANDIRI', 'QRIS', dll
                $table->dateTime('tanggal_bayar')->nullable();
                $table->string('bukti_bayar')->nullable();
                $table->text('keterangan')->nullable();
                $table->unsignedBigInteger('ticket_id')->nullable()->index();
                $table->timestamp('wa_sent_at')->nullable();
                $table->string('wa_status', 50)->nullable();
                $table->string('created_by', 50)->nullable();
                $table->string('verified_by', 50)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_package_requests')) {
            Schema::create('customer_package_requests', function (Blueprint $table) {
                $table->id();
                $table->string('nomor_pengajuan')->unique();
                $table->string('pelanggan_username');
                $table->string('id_customer')->nullable();
                $table->string('pelanggan_nama');
                $table->string('paket_lama');
                $table->string('paket_baru');
                $table->decimal('harga_lama', 12, 2)->default(0);
                $table->decimal('harga_baru', 12, 2)->default(0);
                $table->text('alasan')->nullable();
                $table->string('requested_by', 50)->nullable();
                $table->string('status', 30)->default('pending_finance'); // pending_finance, approved, rejected
                $table->string('approved_by', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('catatan_finance')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customer_package_requests');
    }
};
