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
        if (!Schema::hasTable('data_sheets')) {
            Schema::create('data_sheets', function (Blueprint $table) {
                $table->id();
                $table->string('username_pppoe', 100)->index();
                $table->string('nama_pelanggan', 150)->nullable()->index();
                $table->string('nama_odp', 100)->nullable()->index();
                $table->string('port_odp', 50)->nullable();
                $table->string('telepon', 50)->nullable();
                $table->string('nik_ktp', 50)->nullable();
                $table->string('mac_address', 50)->nullable();
                $table->string('pon_sn', 50)->nullable();
                $table->string('serial_number', 50)->nullable();
                $table->string('ip_address', 50)->nullable();
                $table->string('paket', 100)->nullable();
                $table->text('alamat')->nullable();
                $table->string('tanggal_jatuh_tempo', 50)->nullable();
                $table->string('status_langganan', 50)->default('aktif'); // aktif, dismantle, isolir
                $table->text('keterangan')->nullable();
                $table->text('foto_rumah_url')->nullable();
                $table->text('foto_odp_url')->nullable();
                $table->text('foto_ktp_url')->nullable();
                $table->text('foto_modem_url')->nullable();
                $table->text('lokasi_maps')->nullable();
                $table->integer('sheet_row_index')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pelanggan')) {
            Schema::create('pelanggan', function (Blueprint $table) {
                $table->id();
                $table->string('id_customer', 50)->nullable()->index();
                $table->string('nama', 150)->nullable();
                $table->string('kategori_pelanggan', 50)->default('regular');
                $table->string('nama_depan', 50)->nullable();
                $table->string('nama_belakang', 50)->nullable();
                $table->string('provinsi', 100)->nullable();
                $table->string('kabupaten', 100)->nullable();
                $table->string('kecamatan', 100)->nullable();
                $table->string('desa', 100)->nullable();
                $table->string('kode_wilayah', 30)->nullable();
                $table->string('username', 100)->index();
                $table->string('paket', 100)->nullable();
                $table->string('ip', 50)->nullable();
                $table->string('status', 50)->default('aktif');
                $table->string('password_pppoe', 50)->default('1')->nullable();
                $table->string('vlan', 50)->nullable();
                $table->string('mac_address', 50)->nullable();
                $table->string('pon_sn', 50)->nullable();
                $table->string('serial_number', 50)->nullable();
                $table->string('foto_odp')->nullable();
                $table->string('foto_redaman')->nullable();
                $table->string('foto_label_kabel')->nullable();
                $table->string('foto_dokumen')->nullable();
                $table->string('foto_identitas_onu')->nullable();
                $table->decimal('harga_paket', 15, 2)->default(0);
                $table->decimal('biaya_pasang', 15, 2)->default(0);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_sheets');
        Schema::dropIfExists('pelanggan');
    }
};
