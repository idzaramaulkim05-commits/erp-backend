<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_material_requests', function (Blueprint $table) {
            $table->dropForeign(['service_registration_id']);
        });

        DB::statement('ALTER TABLE installation_material_requests ALTER COLUMN service_registration_id DROP NOT NULL');

        Schema::table('trouble_tickets', function (Blueprint $table) {
            $table->json('replacement_context')->nullable()->after('noc_final_verification');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->json('maintenance_payload')->nullable()->after('network_credentials');
            $table->string('warehouse_return_status', 32)->nullable()->after('maintenance_payload');
            $table->string('warehouse_return_request_id')->nullable()->after('warehouse_return_status');
        });

        Schema::table('installation_material_requests', function (Blueprint $table) {
            $table->string('ticket_id')->nullable()->after('work_order_id');
            $table->string('request_purpose', 32)->default('installation')->after('requested_by');

            $table->foreign('ticket_id')->references('id')->on('trouble_tickets')->nullOnDelete();
        });

        Schema::table('installation_material_requests', function (Blueprint $table) {
            $table->foreign('service_registration_id')->references('id')->on('service_registrations')->nullOnDelete();
        });

        DB::table('installation_material_requests')
            ->whereNull('request_purpose')
            ->update(['request_purpose' => 'installation']);

        Schema::create('warehouse_return_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('work_order_id');
            $table->string('ticket_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('customer_name');
            $table->string('submitted_by')->nullable();
            $table->string('status', 32)->default('menunggu_qc_gudang');
            $table->json('items');
            $table->text('qc_notes')->nullable();
            $table->string('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('trouble_tickets')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->unique('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_return_requests');

        Schema::table('installation_material_requests', function (Blueprint $table) {
            $table->dropForeign(['service_registration_id']);
            $table->dropForeign(['ticket_id']);
            $table->dropColumn(['ticket_id', 'request_purpose']);
        });

        DB::statement('ALTER TABLE installation_material_requests ALTER COLUMN service_registration_id SET NOT NULL');

        Schema::table('installation_material_requests', function (Blueprint $table) {
            $table->foreign('service_registration_id')->references('id')->on('service_registrations')->cascadeOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['maintenance_payload', 'warehouse_return_status', 'warehouse_return_request_id']);
        });

        Schema::table('trouble_tickets', function (Blueprint $table) {
            $table->dropColumn('replacement_context');
        });
    }
};
