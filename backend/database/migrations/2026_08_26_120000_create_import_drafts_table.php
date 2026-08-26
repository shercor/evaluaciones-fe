<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planillas subidas que todavía no se importaron.
 *
 * La homologación tiene dos momentos separados por el trabajo de una persona:
 * primero se sube el archivo y se leen sus encabezados, después —minutos más
 * tarde— se decide qué columna es cuál y recién ahí se importa. Entre medio
 * hay que guardar el archivo en alguna parte.
 *
 * Es una tabla aparte de `imports` a propósito: un borrador no es una
 * importación. Si viviera ahí con un estado «borrador», habría que acordarse
 * de excluirlo en el historial, en el detalle y en la descarga de contraseñas.
 * Acá no existe hasta que se confirma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename');
            // Dónde quedó el archivo subido, dentro del disco privado.
            $table->string('stored_path');
            // Los encabezados tal como venían, y una muestra de cada columna
            // para que quien homologa vea qué hay dentro antes de elegir.
            $table->json('headers');
            $table->json('samples');
            $table->unsignedInteger('rows_total')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('imports', function (Blueprint $table) {
            // Con qué homologación se hizo. Null en las cargas con el formato
            // propio del sistema, que no homologan nada. Queda registrado
            // porque, si una carga sale torcida, lo primero que hay que poder
            // mirar es qué columna se conectó con qué.
            $table->json('mapping')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn('mapping');
        });

        Schema::dropIfExists('import_drafts');
    }
};
