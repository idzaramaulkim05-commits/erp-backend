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
        if (!Schema::hasTable('master_wilayah')) {
            Schema::create('master_wilayah', function (Blueprint $table) {
                $table->id();
                $table->string('provinsi_kode', 10)->default('18');
                $table->string('provinsi_nama', 100)->default('Lampung');
                $table->string('kabupaten_kode', 10)->default('01');
                $table->string('kabupaten_nama', 100)->default('Lampung Selatan');
                $table->string('kecamatan_kode', 10)->default('05');
                $table->string('kecamatan_nama', 100)->default('Sidomulyo');
                $table->string('desa_kode', 10)->default('02');
                $table->string('desa_nama', 100)->default('Sidorejo');
                $table->string('kode_wilayah_full', 20)->index(); // e.g. 18010502
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_wilayah');
    }
};
