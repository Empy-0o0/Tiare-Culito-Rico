<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-026:
 * - Contraseña de inicio generada por el administrador: obliga a cambiarla al ingresar.
 * - Oferta de los proveedores (productos/servicios que entregan) y su capacidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        Schema::create('supplier_offerings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                                   // PRODUCT (insumo) | SERVICE
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // vínculo opcional al catálogo
            $table->foreignId('unit_of_measure_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            // Capacidad declarada por el proveedor: cantidad por período (dato del proveedor, no verificado por TIARE).
            $table->decimal('capacity_quantity', 18, 3)->nullable();
            $table->string('capacity_period', 8)->nullable();             // DAY | WEEK | MONTH | YEAR
            $table->decimal('min_order_quantity', 18, 3)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('capacity_notes', 500)->nullable();
            $table->date('capacity_confirmed_on')->nullable();            // cuándo se confirmó la capacidad con el proveedor
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_offerings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
