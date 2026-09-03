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
        if (!Schema::hasTable('pakets')) {
            Schema::create('pakets', function (Blueprint $table) {
                $table->id();
                $table->string('nama_paket', 150);
                $table->integer('kecepatan')->default(10); // in Mbps
                $table->string('allow_upgrade_downgrade', 10)->default('YA');
                $table->string('allow_online_register', 10)->default('YA');
                $table->decimal('harga_dasar', 15, 2)->default(0);
                $table->decimal('ppn', 5, 2)->default(0);
                $table->decimal('tarif_bulanan', 15, 2)->default(0);
                $table->decimal('komisi_agen', 15, 2)->default(0);
                $table->unsignedBigInteger('router_id')->nullable();
                $table->string('mikrotik_profile', 100)->default('default');
                $table->text('keterangan')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pakets');
    }
};
