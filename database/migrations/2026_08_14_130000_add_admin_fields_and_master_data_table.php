<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_online');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        Schema::create('admin_master_data_groups', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->json('items');
            $table->json('editable_fields')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_master_data_groups');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at']);
        });
    }
};
