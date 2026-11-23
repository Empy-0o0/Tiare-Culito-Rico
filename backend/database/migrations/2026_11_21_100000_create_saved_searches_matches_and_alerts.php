<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Búsquedas guardadas por empresa, coincidencias con oportunidades públicas y
 * alertas internas (CLAUDE.md §5.5, §7.3). Todo es privado por tenant; las
 * oportunidades siguen siendo datos oficiales compartidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_public_searches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('criteria');                         // q, regions, min/max_amount, buyer_rut, open_only, use_catalog
            $table->boolean('alerts_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_match_count')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('public_opportunity_matches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_search_id')->constrained('saved_public_searches')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('public_procurement_opportunities')->cascadeOnDelete();
            $table->json('reasons');                          // por qué coincide (palabras, catálogo, región, monto)
            $table->string('status', 16)->default('NEW');     // NEW | SEEN | DISMISSED
            // useCurrent: MariaDB 10.4 no acepta timestamp NOT NULL sin valor por defecto.
            $table->timestamp('first_matched_at')->useCurrent();
            $table->timestamp('last_matched_at')->useCurrent();
            $table->timestamps();
            $table->unique(['saved_search_id', 'opportunity_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('public_alerts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                       // NEW_MATCHES | CLOSING_SOON
            $table->foreignId('saved_search_id')->nullable()->constrained('saved_public_searches')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('public_procurement_opportunities')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->json('payload')->nullable();              // códigos de procesos, conteos
            $table->string('dedupe_key', 120);                // evita repetir la misma alerta
            $table->timestamps();
            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('public_alert_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('public_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->unique(['alert_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_alert_reads');
        Schema::dropIfExists('public_alerts');
        Schema::dropIfExists('public_opportunity_matches');
        Schema::dropIfExists('saved_public_searches');
    }
};
