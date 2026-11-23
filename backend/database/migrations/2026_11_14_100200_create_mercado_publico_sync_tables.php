<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de sincronización y consumo de cuota (CLAUDE.md §6.6). Nunca se guarda el ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercado_publico_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('source_api', 32)->index();
            $table->string('endpoint', 120);
            $table->json('filters')->nullable();
            $table->string('trigger', 32);                    // scheduler | manual | api
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();            // running | succeeded | partial | failed | quota_exhausted
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_total')->nullable();
            $table->unsignedInteger('pages_processed')->default(0);
            $table->unsignedInteger('requests_used')->default(0);
            $table->unsignedInteger('items_received')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('items_unchanged')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_summary', 500)->nullable(); // saneado, sin ticket
            $table->timestamps();
        });

        Schema::create('mercado_publico_sync_state', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();              // compra_agil_v2
            $table->timestamp('last_success_started_at')->nullable();
            $table->timestamp('last_success_finished_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->json('checkpoint')->nullable();
            $table->timestamps();
        });

        Schema::create('mercado_publico_api_usage', function (Blueprint $table) {
            $table->id();
            $table->string('api', 16);                        // v1 | v2
            $table->date('usage_date');                       // día calendario America/Santiago
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('blocked_until')->nullable();   // tras 429
            $table->timestamps();
            $table->unique(['api', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercado_publico_api_usage');
        Schema::dropIfExists('mercado_publico_sync_state');
        Schema::dropIfExists('mercado_publico_sync_runs');
    }
};
