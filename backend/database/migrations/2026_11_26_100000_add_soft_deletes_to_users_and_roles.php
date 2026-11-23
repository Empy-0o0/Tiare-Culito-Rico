<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-031: eliminar cuentas y roles sin perder el historial.
 * Una cuenta eliminada deja de existir para la aplicación (no inicia sesión, no aparece en listas),
 * pero su nombre se conserva para que aprobaciones, órdenes y auditoría sigan mostrando quién actuó.
 * Un rol del sistema eliminado queda marcado para que el seeder no lo vuelva a crear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
