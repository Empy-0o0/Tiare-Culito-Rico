<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: compras privadas (CLAUDE.md §5.4 y §7.2).
 * Flujo: solicitud → cotización → comparación → propuesta → aprobación → orden interna → recepción.
 * Montos DECIMAL con moneda explícita. Aprobaciones, comparaciones y recepciones son registros inmutables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Correlativos por empresa, tipo y año (SC-2026-0001, OCI-2026-0001).
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['tenant_id', 'kind', 'year']);
        });

        // Límite de aprobación por persona y empresa (CLP). Null = sin límite.
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->decimal('approval_limit_clp', 18, 2)->nullable()->after('is_active');
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); // cliente final si TIARE compra por encargo
            $table->string('cost_center', 80)->nullable();
            $table->string('delivery_address', 255)->nullable();
            $table->date('needed_by')->nullable();
            $table->string('priority', 16)->default('NORMAL');
            $table->string('status', 24)->index();
            $table->char('currency', 3)->default('CLP');
            $table->decimal('estimated_total', 18, 2)->nullable();
            $table->unsignedInteger('version')->default(1);       // aumenta con cada edición: la aprobación registra la versión
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('selected_quotation_id')->nullable(); // FK lógica (tabla creada después)
            $table->text('proposal_justification')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 18, 4);
            $table->string('unit_code', 16);
            $table->decimal('estimated_unit_price', 18, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'line_number']);
        });

        Schema::create('purchase_approvals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 16);                       // APPROVED | REJECTED
            $table->text('comment')->nullable();
            $table->unsignedInteger('request_version');
            $table->unsignedBigInteger('quotation_id')->nullable();
            $table->decimal('amount_clp', 18, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'purchase_request_id']);
        });

        Schema::create('quotation_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('REQUESTED');   // REQUESTED | RECEIVED | DECLINED
            $table->date('due_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'supplier_id']);
        });

        Schema::create('supplier_quotations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 80)->nullable();          // número de cotización del proveedor
            $table->char('currency', 3)->default('CLP');
            $table->decimal('exchange_rate_to_clp', 18, 6)->nullable(); // obligatorio si la moneda no es CLP
            $table->string('exchange_rate_source', 120)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->boolean('prices_include_tax')->default(false);
            $table->decimal('shipping_cost', 18, 2)->default(0);  // neto
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal_net', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('total_clp', 18, 2);
            $table->string('status', 16)->default('RECEIVED');    // RECEIVED | SELECTED | DISCARDED
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('received_on');
            $table->timestamps();
            $table->index(['tenant_id', 'purchase_request_id']);
        });

        Schema::create('supplier_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);                 // en la moneda de la cotización
            $table->decimal('line_total', 18, 2);
            $table->string('offered_brand', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['supplier_quotation_id', 'purchase_request_item_id'], 'sqi_quotation_item_unique');
        });

        // Instantánea inmutable: metodología, supuestos, valores de entrada y resultado.
        Schema::create('quotation_comparisons', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('methodology');
            $table->json('results');
            $table->unsignedBigInteger('recommended_quotation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('internal_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24);
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->index();               // ISSUED | SENT | PARTIALLY_RECEIVED | RECEIVED | CANCELLED
            $table->char('currency', 3);
            $table->decimal('exchange_rate_to_clp', 18, 6)->nullable();
            $table->decimal('subtotal_net', 18, 2);
            $table->decimal('shipping_cost', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            // Responsables (CLAUDE.md §5.4): quién paga, a nombre de quién se factura, quién compra y quién recibe.
            $table->string('payer_name', 200);
            $table->string('invoice_holder_name', 200);
            $table->string('invoice_holder_rut', 16)->nullable();
            $table->string('contractual_buyer', 200);
            $table->string('receiver_name', 200);
            $table->string('delivery_address', 255)->nullable();
            $table->date('expected_delivery_on')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique('purchase_request_id');               // una OC por solicitud
        });

        Schema::create('internal_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500);
            $table->string('unit_code', 16);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internal_purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('received_on');
            $table->string('document_reference', 80)->nullable(); // guía de despacho
            $table->boolean('has_incident')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internal_purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_received', 18, 4);
            $table->decimal('quantity_rejected', 18, 4)->default(0);
            $table->string('incident_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['goods_receipt_items', 'goods_receipts', 'internal_purchase_order_items', 'internal_purchase_orders', 'quotation_comparisons', 'supplier_quotation_items', 'supplier_quotations', 'quotation_requests', 'purchase_approvals', 'purchase_request_items', 'purchase_requests', 'document_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn('approval_limit_clp');
        });
    }
};
