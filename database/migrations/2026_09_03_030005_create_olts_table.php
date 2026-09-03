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
        if (!Schema::hasTable('olts')) {
            Schema::create('olts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('brand', 50); // HSGQ, Global, ZTE, Huawei
                $table->string('type', 20);  // EPON, GPON
                $table->integer('pon_ports')->default(8); // 4, 8, 16
                $table->string('ip_address', 50);
                $table->integer('snmp_port')->default(161);
                $table->string('snmp_community', 50)->default('public');
                $table->integer('telnet_port')->default(23);
                $table->integer('web_port')->default(80);
                $table->string('username', 50)->nullable()->default('admin');
                $table->string('password', 100)->nullable()->default('admin');
                $table->string('location_name', 100)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('status', 20)->default('online'); // online, warning, offline
                $table->float('temperature')->nullable()->default(42.5);
                $table->integer('cpu_usage')->nullable()->default(15);
                $table->integer('ram_usage')->nullable()->default(35);
                $table->float('voltage')->nullable()->default(12.2);
                $table->integer('total_onu')->default(0);
                $table->integer('online_onu')->default(0);
                $table->integer('offline_onu')->default(0);
                $table->json('pon_data')->nullable(); // detailed data per PON port
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
        Schema::dropIfExists('olts');
    }
};
