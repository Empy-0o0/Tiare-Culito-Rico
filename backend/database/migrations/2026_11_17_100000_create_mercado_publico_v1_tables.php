<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de la API legacy V1 (licitaciones, órdenes de compra, organismos y proveedores).
 * Diccionarios oficiales ChileCompra, marzo 2026. Datos públicos: no contienen datos de tenants
 * ni el ticket. Los datos personales de contacto de funcionarios/proveedores no se normalizan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Licitaciones reutilizan public_procurement_opportunities (source_type = licitacion).
        Schema::table('public_procurement_opportunities', function (Blueprint $table) {
            $table->string('procedure_type', 8)->nullable()->index()->after('call_number');   // L1, LE, LP, LQ, LR, E2, CO, B2, H2, I2, LS…
            $table->string('buyer_code', 32)->nullable()->index()->after('buyer_rut');        // CodigoOrganismo
            $table->timestamp('awarded_at')->nullable()->after('cancelled_at');
            $table->json('details')->nullable()->after('selection_reason');                   // atributos sin índice (V1)
        });

        Schema::table('public_opportunity_items', function (Blueprint $table) {
            $table->string('category', 400)->nullable()->after('unit_of_measure');
            $table->string('awarded_supplier_rut', 16)->nullable()->after('category');
            $table->string('awarded_supplier_name', 500)->nullable()->after('awarded_supplier_rut');
            $table->decimal('awarded_quantity', 18, 4)->nullable()->after('awarded_supplier_name');
            $table->decimal('awarded_unit_price', 18, 2)->nullable()->after('awarded_quantity');
        });

        // Órdenes de compra públicas consultadas (no confundir con internal_purchase_orders).
        Schema::create('public_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('source_system', 32);
            $table->string('source_api', 32);
            $table->string('external_code', 64);
            $table->string('title', 500)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->string('status_label', 120)->nullable();
            $table->string('supplier_status_label', 120)->nullable();
            $table->string('tender_code', 64)->nullable()->index();
            $table->string('type_code', 16)->nullable();
            $table->string('type_label', 80)->nullable();
            $table->char('currency', 3)->nullable();

            $table->string('buyer_code', 32)->nullable()->index();
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_unit_rut', 16)->nullable();
            $table->string('buyer_unit', 255)->nullable();
            $table->string('buyer_region', 120)->nullable();

            $table->string('supplier_code', 32)->nullable()->index();
            $table->string('supplier_name', 255)->nullable();
            $table->string('supplier_rut', 16)->nullable()->index();
            $table->string('supplier_region', 120)->nullable();

            $table->decimal('net_total', 18, 2)->nullable();
            $table->decimal('tax_total', 18, 2)->nullable();
            $table->decimal('total', 18, 2)->nullable()->index();
            $table->decimal('tax_rate', 6, 2)->nullable();

            $table->timestamp('issued_at')->nullable()->index();       // Fechas/FechaCreacion
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('source_updated_at')->nullable()->index();

            $table->json('items')->nullable();                         // líneas normalizadas
            $table->json('details')->nullable();                       // despacho, pago, financiamiento…
            $table->json('raw_payload')->nullable();                   // sin datos personales de contacto
            $table->char('list_payload_hash', 64)->nullable();
            $table->char('detail_payload_hash', 64)->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('detail_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'external_code'], 'ppo_orders_source_external_unique');
        });

        // Directorio de organismos compradores (BuscarComprador).
        Schema::create('public_buyers', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 32);
            $table->string('external_code', 32);
            $table->string('name', 500);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'external_code']);
            $table->index('name');
        });

        // Proveedores consultados por RUT (BuscarProveedor).
        Schema::create('public_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 32);
            $table->string('external_code', 32);
            $table->string('rut', 16)->index();
            $table->string('name', 500)->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'external_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_suppliers');
        Schema::dropIfExists('public_buyers');
        Schema::dropIfExists('public_purchase_orders');

        Schema::table('public_opportunity_items', function (Blueprint $table) {
            $table->dropColumn(['category', 'awarded_supplier_rut', 'awarded_supplier_name', 'awarded_quantity', 'awarded_unit_price']);
        });
        Schema::table('public_procurement_opportunities', function (Blueprint $table) {
            $table->dropIndex(['procedure_type']);
            $table->dropIndex(['buyer_code']);
            $table->dropColumn(['procedure_type', 'buyer_code', 'awarded_at', 'details']);
        });
    }
};
