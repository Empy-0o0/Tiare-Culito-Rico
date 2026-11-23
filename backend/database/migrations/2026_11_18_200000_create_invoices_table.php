<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3, paso 11 (CLAUDE.md §5.4): facturas asociadas a órdenes internas y pago informado por el cliente.
 * Registro documental/administrativo: no reemplaza software contable ni tributario (§7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('internal_purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);                          // folio del documento tributario
            $table->string('document_type', 24)->default('INVOICE'); // INVOICE | EXEMPT_INVOICE | CREDIT_NOTE
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->char('currency', 3);
            $table->decimal('net_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->string('status', 16)->index();               // REGISTERED | PAID
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('paid_by_label', 200)->nullable();     // quién informó haber pagado (cliente, empresa)
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_informed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payment_informed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'supplier_id', 'document_type', 'number'], 'invoices_folio_unique');
            $table->index(['tenant_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
