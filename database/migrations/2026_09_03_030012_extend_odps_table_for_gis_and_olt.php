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
        if (!Schema::hasTable('odps')) {
            Schema::create('odps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->nullable();
                $table->string('nama_odp', 100)->index();
                $table->string('kode_odp', 50)->nullable();
                $table->string('lokasi', 150)->nullable();
                $table->integer('pon_port')->default(1);
                $table->integer('kapasitas')->default(8);
                $table->integer('total_pelanggan')->default(0);
                $table->integer('online_pelanggan')->default(0);
                $table->integer('offline_pelanggan')->default(0);
                $table->string('status', 30)->default('normal'); // normal, fiber_cut, power_off, mati_lampu, warning_redaman
                $table->text('keterangan_gangguan')->nullable();
                $table->string('parent_odc', 100)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('ports_data')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odps');
    }
};
