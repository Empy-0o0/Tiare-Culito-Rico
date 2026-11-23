<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes contratados por empresa (TD-024) y alcance de tokens API por empresa.
 * No integra cobros: el plan solo habilita módulos y límites (CLAUDE.md §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            // Módulos habilitados (claves de App\Support\PlanModules).
            $table->json('modules');
            // Máximo de personas con acceso activo; null = sin límite.
            $table->unsignedInteger('max_users')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            // active | suspended | cancelled
            $table->string('status', 16)->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'starts_on']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Empresa a la que queda limitado el token; el tenant nunca se toma del cliente.
            $table->foreignId('tenant_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('plans');
    }
};
