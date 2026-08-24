<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales y cargos.
 *
 * Llegan antes que el hito del directorio porque `users` los referencia: una
 * persona tiene sucursal y cargo desde que existe. Lo que trae el hito 3 es la
 * importación desde planilla y su administración, no estas tablas.
 *
 * `external_code` es la clave con la que la planilla vuelve a encontrar la
 * misma fila entre una importación y la siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_offices', function (Blueprint $table) {
            $table->id();
            $table->string('external_code')->nullable()->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'name']);
        });

        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->string('external_code')->nullable()->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_positions');
        Schema::dropIfExists('branch_offices');
    }
};
