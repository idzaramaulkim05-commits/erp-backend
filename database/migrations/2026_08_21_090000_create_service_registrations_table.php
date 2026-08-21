<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_registrations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('nik', 32);
            $table->string('phone', 32);
            $table->text('address');
            $table->string('region');
            $table->string('package_plan');
            $table->unsignedInteger('monthly_fee');
            $table->string('odp_id');
            $table->unsignedSmallInteger('odp_port_candidate')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('finance_status', 32)->default('pending');
            $table->text('finance_notes')->nullable();
            $table->string('finance_approved_by')->nullable();
            $table->timestamp('finance_approved_at')->nullable();
            $table->string('noc_status', 32)->default('pending');
            $table->text('noc_notes')->nullable();
            $table->string('noc_approved_by')->nullable();
            $table->timestamp('noc_approved_at')->nullable();
            $table->string('pppoe_username')->nullable()->unique();
            $table->string('pppoe_password')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('work_order_id')->nullable();
            $table->string('requested_by_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('requested_by_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('service_registration_id')->nullable()->after('ticket_id');
            $table->json('final_verification')->nullable()->after('photos');

            $table->foreign('service_registration_id')->references('id')->on('service_registrations')->nullOnDelete();
        });

        Schema::table('service_registrations', function (Blueprint $table) {
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->foreign('odp_id')->references('id')->on('network_odps')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['service_registration_id']);
            $table->dropColumn(['service_registration_id', 'final_verification']);
        });

        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropForeign(['work_order_id']);
            $table->dropForeign(['odp_id']);
        });

        Schema::dropIfExists('service_registrations');
    }
};
