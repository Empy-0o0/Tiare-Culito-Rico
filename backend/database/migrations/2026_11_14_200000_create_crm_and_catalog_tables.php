<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2a (CLAUDE.md §5.2, §5.3, §7.1, §7.2): CRM, proveedores y catálogo.
 * Todas las tablas privadas llevan tenant_id indexado (TD-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('rut', 12)->nullable();             // normalizado: 12.345.678-5
            $table->string('kind', 32)->index();                // OrganizationKind
            $table->string('segment', 80)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 190)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('commune', 80)->nullable();
            $table->unsignedTinyInteger('region_code')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
            $table->unique(['tenant_id', 'rut']);
            $table->index(['tenant_id', 'legal_name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 160);
            $table->string('position', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'organization_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();             // CustomerStatus
            $table->string('plan', 32)->nullable();             // Start | Growth | Managed | Public | SaaS
            $table->string('source', 80)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_action_at')->nullable();
            $table->string('next_action_summary', 255)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'organization_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Nunca "validado" sin criterios definidos (CLAUDE.md §5.3).
            $table->string('status', 32)->index();             // SupplierStatus
            $table->json('coverage_regions')->nullable();       // códigos 1..16
            $table->string('payment_terms', 120)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'organization_id']);
        });

        Schema::create('supplier_categories', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['supplier_id', 'category_id']);
        });

        // Catálogo de referencia global (no contiene datos privados).
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('kind', 16);                         // PRODUCT | SERVICE
            $table->string('sku', 64)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('public_catalog_code', 32)->nullable()->index(); // codigo_producto de Mercado Público, si se conoce
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('supplier_categories');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('organizations');
    }
};
