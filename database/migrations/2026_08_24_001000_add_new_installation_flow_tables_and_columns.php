<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_registrations', function (Blueprint $table) {
            $table->string('entry_source', 32)->nullable()->after('phone');
            $table->string('share_location_url')->nullable()->after('address');
            $table->string('house_photo')->nullable()->after('share_location_url');
            $table->string('validation_status', 32)->default('menunggu_validasi')->after('status');
            $table->text('validation_notes')->nullable()->after('validation_status');
            $table->string('validated_by')->nullable()->after('validation_notes');
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->string('survey_status', 32)->default('draft')->after('validated_at');
            $table->string('survey_result', 32)->nullable()->after('survey_status');
            $table->text('survey_notes')->nullable()->after('survey_result');
            $table->string('surveyed_by')->nullable()->after('survey_notes');
            $table->timestamp('surveyed_at')->nullable()->after('surveyed_by');
            $table->json('survey_data')->nullable()->after('surveyed_at');
            $table->string('installation_material_request_id')->nullable()->after('work_order_id');
            $table->json('activation_report')->nullable()->after('installation_material_request_id');
            $table->json('activation_document')->nullable()->after('activation_report');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('share_location_url')->nullable()->after('address');
            $table->string('house_photo')->nullable()->after('share_location_url');
            $table->json('survey_snapshot')->nullable()->after('package_plan');
            $table->string('installation_material_request_id')->nullable()->after('service_registration_id');
            $table->json('activation_payload')->nullable()->after('installation_material_request_id');
            $table->json('onu_identity')->nullable()->after('activation_payload');
            $table->json('network_credentials')->nullable()->after('onu_identity');
            $table->string('qc_status', 32)->nullable()->after('network_credentials');
            $table->text('qc_notes')->nullable()->after('qc_status');
            $table->timestamp('returned_to_tech_at')->nullable()->after('qc_notes');
        });

        Schema::create('installation_material_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_registration_id');
            $table->string('work_order_id')->nullable();
            $table->string('customer_name');
            $table->string('requested_by');
            $table->string('status', 32)->default('menunggu_persetujuan_gudang');
            $table->json('items');
            $table->text('approval_notes')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('delivered_by')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('service_registration_id')->references('id')->on('service_registrations')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_material_requests');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'share_location_url',
                'house_photo',
                'survey_snapshot',
                'installation_material_request_id',
                'activation_payload',
                'onu_identity',
                'network_credentials',
                'qc_status',
                'qc_notes',
                'returned_to_tech_at',
            ]);
        });

        Schema::table('service_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'entry_source',
                'share_location_url',
                'house_photo',
                'validation_status',
                'validation_notes',
                'validated_by',
                'validated_at',
                'survey_status',
                'survey_result',
                'survey_notes',
                'surveyed_by',
                'surveyed_at',
                'survey_data',
                'installation_material_request_id',
                'activation_report',
                'activation_document',
            ]);
        });
    }
};
