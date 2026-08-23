<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_heads', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_navigation_modules', function (Blueprint $table) {
            $table->string('module_key')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('route_target');
            $table->string('navigation_head_key');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('quick_action')->nullable();
            $table->json('view_formats')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table
                ->foreign('navigation_head_key')
                ->references('key')
                ->on('navigation_heads')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        Schema::create('role_module_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('module_key');
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('order_override')->nullable();
            $table->timestamps();

            $table->unique(['role', 'module_key']);
            $table
                ->foreign('module_key')
                ->references('module_key')
                ->on('app_navigation_modules')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_mappings');
        Schema::dropIfExists('app_navigation_modules');
        Schema::dropIfExists('navigation_heads');
    }
};
