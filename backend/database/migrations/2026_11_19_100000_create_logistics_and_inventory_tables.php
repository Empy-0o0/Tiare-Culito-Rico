<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5: inventario (CLAUDE.md §5.7) y logística (§5.6, §7.4).
 * Movimientos y eventos son inmutables; el saldo se mantiene en inventory_items dentro de la misma transacción.
 * Primero coordinación con operadores aliados: no se modela flota propia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 24);
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->unsignedTinyInteger('region_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // Saldo y parámetros de reposición por producto y bodega.
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('min_quantity', 18, 4)->nullable();
            $table->decimal('max_quantity', 18, 4)->nullable();
            $table->string('location', 60)->nullable();          // pasillo / estante
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);                           // IN | OUT | ADJUST_IN | ADJUST_OUT | TRANSFER_IN | TRANSFER_OUT
            $table->decimal('quantity', 18, 4);                   // siempre positiva; el tipo define el signo
            $table->decimal('balance_after', 18, 4);
            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->string('lot', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('reference_type', 40)->nullable();     // goods_receipt | transfer | manual
            $table->string('reference_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('rut', 16)->nullable();
            $table->json('service_types')->nullable();            // EXPRESS | STANDARD | FREIGHT | COURIER | PICKUP
            $table->json('coverage_regions')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('tracking_url_template', 255)->nullable(); // https://…{code}
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24);
            $table->foreignId('internal_purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type', 16)->nullable();
            $table->string('status', 16)->index();                // PLANNED | IN_TRANSIT | DELIVERED | FAILED | RETURNED | CANCELLED
            $table->string('origin', 255);
            $table->string('destination', 255);
            $table->unsignedTinyInteger('destination_region')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('tracking_code', 80)->nullable();
            $table->unsignedSmallInteger('packages')->nullable();
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->date('planned_pickup_on')->nullable();
            $table->date('planned_delivery_on')->nullable()->index();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('estimated_cost', 18, 2)->nullable();  // CLP
            $table->decimal('actual_cost', 18, 2)->nullable();
            $table->string('received_by_name', 120)->nullable();
            $table->string('delivery_notes', 1000)->nullable();
            $table->string('evidence_path', 255)->nullable();      // disco privado
            $table->string('evidence_mime', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->nullable();             // nuevo estado, si el evento lo cambia
            $table->string('description', 500);
            $table->string('location', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('logistics_incidents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                           // DELAY | DAMAGE | LOSS | WRONG_ADDRESS | REJECTED | OTHER
            $table->string('description', 1000);
            $table->string('status', 16)->default('OPEN');        // OPEN | RESOLVED
            $table->string('resolution', 1000)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['logistics_incidents', 'shipment_events', 'shipments', 'carriers', 'inventory_movements', 'inventory_items', 'warehouses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
