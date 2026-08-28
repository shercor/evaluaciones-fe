<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se cargó con esa planilla.
 *
 * Hasta acá una importación era siempre la nómina. Ahora también se pueden
 * cargar las sucursales y los cargos por el mismo camino, y las tres cosas
 * comparten el registro fila por fila.
 *
 * Va con `default('nomina')` porque es lo que eran todas las que ya están: sin
 * eso, el historial del directorio —que filtra por este campo— se vaciaría de
 * golpe en cualquier instalación que ya tenga cargas hechas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->string('destino')->default('nomina')->after('filename');
            $table->index(['destino', 'created_at']);
        });

        Schema::table('import_drafts', function (Blueprint $table) {
            // El borrador se acuerda de para qué se subió. Elegirlo de nuevo
            // en cada paso permitiría aprobar el resumen de una cosa e
            // importar otra.
            $table->string('destino')->default('nomina')->after('filename');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropIndex(['destino', 'created_at']);
            $table->dropColumn('destino');
        });

        Schema::table('import_drafts', function (Blueprint $table) {
            $table->dropColumn('destino');
        });
    }
};
