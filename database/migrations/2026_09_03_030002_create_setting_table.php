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
        if (!Schema::hasTable('setting')) {
            Schema::create('setting', function (Blueprint $table) {
                $table->id();
                $table->string('nama_isp')->default('EONET');
                $table->string('mikrotik_ip')->default('192.168.10.1');
                $table->string('mikrotik_user')->default('helpdesk');
                $table->string('mikrotik_password')->default('helpdesk123');
                $table->integer('mikrotik_port')->default(8728);
                $table->string('wan_interface')->nullable()->default('ether1-ISP');
                $table->string('pppoe_interface')->nullable()->default('all');
                $table->integer('refresh_interval')->default(5);
                $table->string('logo')->nullable();
                $table->string('gateway_ip')->nullable();
                $table->string('ping_dns1')->default('8.8.8.8');
                $table->string('ping_dns2')->default('1.1.1.1');
                $table->boolean('wa_gateway_enabled')->default(true);
                $table->string('wa_provider')->default('fonnte');
                $table->string('wa_api_token')->nullable();
                $table->string('wa_api_url')->default('https://api.fonnte.com/send');
                $table->string('wa_target_phone')->nullable();
                $table->boolean('telegram_enabled')->default(false);
                $table->string('telegram_bot_token')->nullable();
                $table->string('telegram_chat_id')->nullable();
                $table->boolean('notify_pop_down')->default(true);
                $table->boolean('notify_pop_up')->default(true);
                $table->boolean('notify_fiber_cut')->default(true);
                $table->text('google_sheet_url')->nullable();
                $table->text('google_sheet_webhook_url')->nullable();
                $table->string('sheet_tab_pelanggan_fix')->default('PELANGGAN FIX');
                $table->string('gdrive_folder_foto_odp')->nullable();
                $table->string('gdrive_folder_foto_redaman')->nullable();
                $table->string('gdrive_folder_foto_dokumen')->nullable();
                $table->string('gdrive_folder_foto_onu')->nullable();
                $table->string('gdrive_folder_foto_rumah')->nullable();
                $table->string('gdrive_folder_foto_evidence')->nullable();
                $table->string('gdrive_folder_foto_payments')->nullable();
                $table->string('gdrive_folder_foto_label_kabel')->nullable();
                $table->timestamp('sheet_last_synced_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setting');
    }
};
