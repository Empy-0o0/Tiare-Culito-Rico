<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos públicos de Mercado Público (CLAUDE.md §7.3). No contienen datos privados de tenants
 * ni el ticket. Fechas en UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_procurement_opportunities', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('source_system', 32);            // mercado_publico
            $table->string('source_type', 32)->index();      // compra_agil | licitacion
            $table->string('source_api', 32);                // compra_agil_v2 | licitaciones_v1
            $table->string('source_api_version', 16);
            $table->string('external_code', 64);
            $table->string('title', 500);
            $table->text('description')->nullable();

            $table->string('status_code', 40)->nullable()->index();   // código original
            $table->string('status_label', 120)->nullable();          // glosa original
            $table->string('status_source_id', 16)->nullable();
            $table->unsignedTinyInteger('call_number')->nullable();   // 1 = primer llamado, 2 = segundo

            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('closes_at')->nullable()->index();
            $table->timestamp('first_call_closes_at')->nullable();
            $table->timestamp('second_call_closes_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('source_updated_at')->nullable()->index();

            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_rut', 16)->nullable()->index();
            $table->string('buyer_unit', 255)->nullable();
            $table->unsignedTinyInteger('region_code')->nullable()->index();
            $table->string('region_name', 120)->nullable();

            $table->char('currency', 3)->nullable();
            $table->string('budget_type', 32)->nullable();
            $table->decimal('estimated_budget', 18, 2)->nullable();
            $table->decimal('available_amount', 18, 2)->nullable();
            $table->decimal('available_amount_clp', 18, 2)->nullable()->index();
            $table->decimal('exchange_rate', 18, 6)->nullable();

            $table->string('delivery_address', 500)->nullable();
            $table->unsignedInteger('delivery_days')->nullable();
            $table->unsignedInteger('offers_count')->nullable();

            // Indicador documentado: id_orden_compra != null.
            $table->boolean('has_purchase_order')->default(false)->index();
            $table->string('purchase_order_source_id', 32)->nullable();
            $table->string('purchase_order_code', 64)->nullable();

            $table->string('cancellation_reason', 1000)->nullable();
            $table->string('deserted_reason', 1000)->nullable();
            $table->string('selection_reason', 1000)->nullable();

            $table->char('list_payload_hash', 64)->nullable();
            $table->char('detail_payload_hash', 64)->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('detail_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'external_code'], 'ppo_source_external_unique');
        });

        Schema::create('public_opportunity_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('public_procurement_opportunities')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('product_code', 32)->nullable()->index();
            $table->string('name', 500)->nullable();
            $table->text('description')->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('unit_of_measure', 32)->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'line_number']);
        });

        Schema::create('public_opportunity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('public_procurement_opportunities')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('name', 500)->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'external_id']);
        });

        // Payload original. Acceso restringido; retención pendiente (PEND-T05). Uno por tipo.
        Schema::create('public_procurement_raw_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('public_procurement_opportunities')->cascadeOnDelete();
            $table->string('kind', 16); // list | detail
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['opportunity_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_procurement_raw_payloads');
        Schema::dropIfExists('public_opportunity_documents');
        Schema::dropIfExists('public_opportunity_items');
        Schema::dropIfExists('public_procurement_opportunities');
    }
};
