<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6: registro de cada ejecución de un agente IA (CLAUDE.md §10.3):
 * versión del prompt, modelo, entrada usada, resultado, latencia, tokens,
 * usuario que la inició y revisión humana persistida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('agent_id', 64)->index();
            $table->string('prompt_version', 16);
            $table->string('subject_type', 40);            // compra_agil | purchase_request
            $table->string('subject_ref', 64);              // código externo o public_id
            $table->string('subject_label', 255)->nullable();
            $table->string('mode', 16);                     // deterministic | llm
            $table->string('status', 16)->index();          // COMPLETED | PARTIAL | FAILED
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->char('input_hash', 64);
            $table->json('input_snapshot');                 // hechos enviados (sin secretos)
            $table->json('facts');                          // resultado determinista
            $table->json('analysis')->nullable();           // resultado del modelo (estimación IA)
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_status', 16)->default('PENDING'); // PENDING | USEFUL | DISCARDED
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_comment', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_ref']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_runs');
    }
};
