<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedInteger('installation_fee_actual')->nullable()->after('package_plan');
            $table->string('installation_payment_method', 32)->nullable()->after('installation_fee_actual');
            $table->string('installation_payment_status', 32)->nullable()->after('installation_payment_method');
            $table->boolean('installation_payment_customer_paid')->default(false)->after('installation_payment_status');
            $table->timestamp('installation_payment_confirmed_at')->nullable()->after('installation_payment_customer_paid');
            $table->string('installation_payment_confirmed_by')->nullable()->after('installation_payment_confirmed_at');
            $table->text('installation_payment_notes')->nullable()->after('installation_payment_confirmed_by');
            $table->boolean('customer_biodata_confirmed')->default(false)->after('installation_payment_notes');
            $table->string('router_sn')->nullable()->after('customer_biodata_confirmed');
            $table->string('pppoe_request_status', 32)->default('not_requested')->after('router_sn');
            $table->timestamp('pppoe_requested_at')->nullable()->after('pppoe_request_status');
            $table->string('pppoe_requested_by')->nullable()->after('pppoe_requested_at');
            $table->timestamp('pppoe_approved_at')->nullable()->after('pppoe_requested_by');
            $table->string('pppoe_approved_by')->nullable()->after('pppoe_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'installation_fee_actual',
                'installation_payment_method',
                'installation_payment_status',
                'installation_payment_customer_paid',
                'installation_payment_confirmed_at',
                'installation_payment_confirmed_by',
                'installation_payment_notes',
                'customer_biodata_confirmed',
                'router_sn',
                'pppoe_request_status',
                'pppoe_requested_at',
                'pppoe_requested_by',
                'pppoe_approved_at',
                'pppoe_approved_by',
            ]);
        });
    }
};
