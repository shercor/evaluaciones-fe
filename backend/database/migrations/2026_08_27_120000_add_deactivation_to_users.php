<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La baja de una persona, con su motivo y su fecha.
 *
 * El directorio se sincroniza contra la nómina: quien deja de venir en el
 * archivo —o viene marcado como inactivo en el origen— tiene que dejar de
 * estar activo acá. Lo que **no** se hace es borrarlo. Una persona borrada se
 * lleva por delante su historial de evaluaciones, la jefatura de quienes le
 * reportan y cualquier respuesta que haya dado; y la mitad de las veces
 * reaparece en la nómina del mes siguiente porque estaba con licencia.
 *
 * Por eso la baja es `active = false`, que es el estado que el sistema ya
 * entiende —`scopeEvaluable` excluye a los inactivos desde el primer día— más
 * estas tres columnas, que son las que faltaban para poder responder «¿por qué
 * esta persona está inactiva?» sin adivinar:
 *
 *  - `deactivated_at`: cuándo. Distingue una baja de hoy de una de hace un año.
 *  - `deactivated_reason`: por qué. Ausente del archivo, marcada inactiva en el
 *    origen, o dada de baja a mano desde el directorio.
 *  - `deactivated_import_id`: con qué carga. Es el enlace al detalle fila por
 *    fila de esa importación, que es donde está la explicación completa.
 *
 * Las tres se limpian al reactivar: quien vuelve a la nómina vuelve sin
 * historia de baja encima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('active');
            $table->string('deactivated_reason')->nullable()->after('deactivated_at');

            // Sin `constrained()`: la referencia es informativa y una llave
            // foránea acá obligaría a decidir qué pasa con la baja cuando se
            // borra la importación que la produjo. La respuesta es «nada»: la
            // persona sigue inactiva aunque el registro de la carga ya no esté.
            $table->unsignedBigInteger('deactivated_import_id')->nullable()->after('deactivated_reason');

            // Las bajas se consultan siempre juntas —«mostrame quién quedó
            // fuera en la última carga»— y son un puñado sobre miles de filas.
            $table->index(['active', 'deactivated_at']);
        });

        Schema::table('imports', function (Blueprint $table) {
            // Ni las bajas ni las omisiones son creaciones o actualizaciones,
            // y una baja por ausencia ni siquiera sale de una línea del
            // archivo. Se cuentan aparte para que el resultado sume: leídas =
            // creadas + actualizadas + rechazadas + omitidas.
            $table->unsignedInteger('rows_skipped')->default(0)->after('rows_failed');
            $table->unsignedInteger('rows_deactivated')->default(0)->after('rows_skipped');
            $table->unsignedInteger('rows_reactivated')->default(0)->after('rows_deactivated');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['active', 'deactivated_at']);
            $table->dropColumn(['deactivated_at', 'deactivated_reason', 'deactivated_import_id']);
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['rows_skipped', 'rows_deactivated', 'rows_reactivated']);
        });
    }
};
