<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-028: acceso al portal de clientes y proveedores.
 * Liga una cuenta (con rol customer_portal / supplier_portal en la empresa) a SU cliente o proveedor:
 * el portal solo muestra la información de ese registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('party_type', 16);                                 // customer | supplier
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accesses');
    }
};
