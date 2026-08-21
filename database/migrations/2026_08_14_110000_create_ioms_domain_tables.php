<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_odps', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('odc_id');
            $table->string('region');
            $table->unsignedSmallInteger('total_ports')->default(8);
            $table->unsignedSmallInteger('used_ports')->default(0);
            $table->string('splitter_ratio')->default('1:8');
            $table->string('olt_host');
            $table->string('pon_slot');
            $table->string('fiber_core_color');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('address');
            $table->timestamps();
        });

        Schema::create('network_odp_ports', function (Blueprint $table) {
            $table->id();
            $table->string('network_odp_id');
            $table->unsignedSmallInteger('port_number');
            $table->string('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('pppoe_username')->nullable();
            $table->decimal('optical_power_dbm', 5, 2)->nullable();
            $table->string('status', 16)->default('empty');
            $table->timestamps();

            $table->foreign('network_odp_id')->references('id')->on('network_odps')->cascadeOnDelete();
            $table->unique(['network_odp_id', 'port_number']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('nik', 32);
            $table->string('phone', 32);
            $table->text('address');
            $table->string('region');
            $table->string('package_plan');
            $table->unsignedBigInteger('monthly_fee');
            $table->string('pppoe_username')->unique();
            $table->string('pppoe_password');
            $table->string('ip_address')->nullable();
            $table->string('ont_brand')->nullable();
            $table->string('ont_model')->nullable();
            $table->string('ont_serial_number')->nullable();
            $table->string('odc_id')->nullable();
            $table->string('odp_id');
            $table->unsignedSmallInteger('odp_port');
            $table->string('fiber_core_color')->nullable();
            $table->decimal('optical_power_dbm', 5, 2)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('billing_status', 32)->default('pending');
            $table->date('billing_due_date')->nullable();
            $table->string('ktp_image')->nullable();
            $table->date('installed_date')->nullable();
            $table->string('assigned_technician_id')->nullable();
            $table->date('last_payment_date')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('odp_id')->references('id')->on('network_odps');
            $table->foreign('assigned_technician_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('billing_records', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id');
            $table->string('status', 32);
            $table->unsignedBigInteger('amount');
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::create('trouble_tickets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('customer_id');
            $table->string('customer_name');
            $table->string('customer_phone', 32);
            $table->text('customer_address');
            $table->string('region');
            $table->string('odp_id');
            $table->string('category', 64);
            $table->string('title');
            $table->text('description');
            $table->string('priority', 16)->default('medium');
            $table->string('status', 32)->default('open');
            $table->string('created_by');
            $table->string('assigned_to')->nullable();
            $table->string('assigned_tech_name')->nullable();
            $table->boolean('can_be_resolved_remotely')->default(false);
            $table->text('noc_diagnostic_notes')->nullable();
            $table->json('field_work_report')->nullable();
            $table->json('lead_tech_approval')->nullable();
            $table->json('noc_final_verification')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type', 32);
            $table->string('customer_id');
            $table->string('customer_name');
            $table->string('customer_phone', 32);
            $table->text('address');
            $table->string('region');
            $table->string('odp_id');
            $table->string('assigned_lead');
            $table->string('assigned_tech_id')->nullable();
            $table->string('assigned_tech_name')->nullable();
            $table->string('ticket_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('scheduled_date')->nullable();
            $table->string('package_plan')->nullable();
            $table->json('required_materials')->nullable();
            $table->json('used_materials')->nullable();
            $table->json('photos')->nullable();
            $table->boolean('sop_verified_by_lead')->default(false);
            $table->boolean('noc_activated')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('ticket_id')->references('id')->on('trouble_tickets')->nullOnDelete();
            $table->foreign('assigned_tech_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('stock_available')->default(0);
            $table->unsignedInteger('stock_in_use')->default(0);
            $table->unsignedInteger('stock_reserved')->default(0);
            $table->unsignedInteger('min_threshold')->default(0);
            $table->string('unit', 32);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->string('location_rack')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_item_id');
            $table->string('sn');
            $table->string('status', 32)->default('available');
            $table->string('current_cust_id')->nullable();
            $table->string('assigned_tech')->nullable();
            $table->timestamps();

            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->unique(['inventory_item_id', 'sn']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_item_id');
            $table->string('movement_type', 32);
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
        });

        Schema::create('procurement_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('item_code');
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->string('unit', 32);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_amount');
            $table->text('reason');
            $table->string('requested_by');
            $table->timestamp('requested_at');
            $table->string('status', 32)->default('pending_finance');
            $table->json('finance_approval')->nullable();
            $table->json('management_approval')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inter_division_tasks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->text('description');
            $table->string('from_division');
            $table->string('to_division');
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('todo');
            $table->string('related_customer_id')->nullable();
            $table->string('related_ticket_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->string('created_by');
            $table->string('assigned_to')->nullable();
            $table->text('resolution_notes')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->timestamp('timestamp');
            $table->string('actor_name');
            $table->string('actor_role', 32);
            $table->string('action');
            $table->string('target');
            $table->text('details');
            $table->string('type', 16)->default('info');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inter_division_tasks');
        Schema::dropIfExists('procurement_requests');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('trouble_tickets');
        Schema::dropIfExists('billing_records');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('network_odp_ports');
        Schema::dropIfExists('network_odps');
    }
};
