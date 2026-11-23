<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2b: evaluación de desempeño y documentos de proveedores (CLAUDE.md §5.3).
 * Ambos son datos privados del tenant. Las evaluaciones son inmutables (se corrigen con una nueva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_evaluations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('evaluated_on');
            $table->string('reference', 120)->nullable();       // OC, cotización o pedido evaluado
            // Escala 1 a 5. Criterios de CLAUDE.md §5.3: precio, calidad, cumplimiento, plazo y reclamos/servicio.
            $table->unsignedTinyInteger('price_score');
            $table->unsignedTinyInteger('quality_score');
            $table->unsignedTinyInteger('delivery_score');
            $table->unsignedTinyInteger('compliance_score');
            $table->unsignedTinyInteger('service_score');
            $table->decimal('overall_score', 3, 2);               // promedio simple de los cinco criterios
            $table->boolean('had_incident')->default(false);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();        // append-only, sin updated_at
            $table->index(['tenant_id', 'supplier_id', 'evaluated_on']);
        });

        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->index();
            $table->string('name', 200);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('storage_path', 255);                   // disco privado; nunca expuesto al cliente
            $table->string('original_filename', 200);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
        Schema::dropIfExists('supplier_evaluations');
    }
};
