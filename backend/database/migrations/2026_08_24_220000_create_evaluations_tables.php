<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espejo local de las evaluaciones.
 *
 * La evaluación **vive en Evaluación 360**: título, fechas, estado y
 * resultados son suyos. Acá se guarda solo lo que la API no sabe y este
 * servicio necesita para armar el proceso:
 *
 *  - `e360_id`, el puntero a la evaluación real;
 *  - qué sucursales se eligieron;
 *  - más adelante, el padrón en borrador y la bitácora para deshacer.
 *
 * Dos diferencias deliberadas con la intranet:
 *
 * 1. Las relaciones internas van contra la clave primaria propia, no contra el
 *    id externo. En la intranet `personal_evaluation_id` guardaba el id de la
 *    API y todas las asociaciones usaban `bindingKey => 'id_import'`; eso fue
 *    lo que permitió el cruce silencioso de datos entre clientes.
 * 2. Las sucursales van en una tabla pivote y no en una columna JSON, que allá
 *    había que decodificar a mano en cinco lugares distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();

            // El id en Evaluación 360. Es lo único que se intercambia con la API.
            $table->unsignedBigInteger('e360_id')->unique();

            $table->string('name')->nullable();

            // Copia del estado que devolvió la API la última vez. Es una caché
            // para la vista del colaborador: la fuente de verdad es la API.
            $table->string('status')->nullable();
            $table->timestamp('status_synced_at')->nullable();

            $table->timestamps();
        });

        Schema::create('evaluation_branch_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();

            // Nulo representa «Sin Sucursal»: en la intranet eso era una
            // pseudo-sucursal con id 0, que obligaba a tratarla aparte en cada
            // consulta. Acá es simplemente la ausencia de sucursal.
            $table->foreignId('branch_office_id')->nullable()
                ->constrained('branch_offices')->cascadeOnDelete();

            $table->unique(['evaluation_id', 'branch_office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_branch_offices');
        Schema::dropIfExists('evaluations');
    }
};
