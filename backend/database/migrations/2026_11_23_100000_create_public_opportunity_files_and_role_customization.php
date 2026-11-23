<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-025:
 * - Copias de documentos de Mercado Público que la empresa descargó del portal y sube a TIARE
 *   (la API oficial solo entrega id y nombre de cada adjunto, no el archivo). Privadas por empresa.
 * - Marca de roles cuyos permisos editó un administrador, para que el seeder no los sobrescriba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_opportunity_files', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('public_procurement_opportunities')->cascadeOnDelete();
            // id del adjunto oficial al que corresponde la copia (si la persona lo indicó).
            $table->string('official_document_id', 64)->nullable();
            $table->string('name', 200);
            $table->string('storage_path', 255); // disco privado; nunca expuesto
            $table->string('original_filename', 200);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'opportunity_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->timestamp('permissions_customized_at')->nullable()->after('scope');
            $table->string('description', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['permissions_customized_at', 'description']);
        });
        Schema::dropIfExists('public_opportunity_files');
    }
};
