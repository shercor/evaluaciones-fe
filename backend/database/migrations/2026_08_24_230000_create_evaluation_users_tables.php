<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El padrón de una evaluación, y la bitácora para deshacer cambios.
 *
 * `evaluation_users` guarda **una copia congelada** de la sucursal, el cargo y
 * el supervisor de cada persona al momento de armar el proceso. La copia es
 * deliberada: si alguien cambia de jefe a mitad de una evaluación, esa
 * evaluación mantiene la estructura con la que empezó. Por eso son columnas
 * propias y no se leen de `users`.
 *
 * `evaluation_user_changes` guarda el estado **anterior** de un participante
 * la primera vez que se lo toca con la evaluación ya abierta. Es lo que
 * permite deshacer, y lo que hace que el listado marque los procesos con
 * cambios sin aplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Si participa o quedó excluido del proceso. A nadie se le borra
            // la fila: se lo marca, para poder volver atrás.
            $table->boolean('participate')->default(true);

            // Lo llena el portal del colaborador cuando ya no le quedan tareas.
            $table->boolean('tasks_completed')->default(false);

            // La foto congelada del organigrama.
            $table->foreignId('branch_office_id')->nullable()
                ->constrained('branch_offices')->nullOnDelete();
            $table->foreignId('job_position_id')->nullable()
                ->constrained('job_positions')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['evaluation_id', 'user_id']);
            $table->index(['evaluation_id', 'participate']);
            $table->index(['evaluation_id', 'supervisor_id']);
        });

        Schema::create('evaluation_user_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Los valores que tenía antes del cambio.
            $table->boolean('participate');
            $table->unsignedBigInteger('branch_office_id')->nullable();
            $table->unsignedBigInteger('job_position_id')->nullable();
            $table->unsignedBigInteger('supervisor_id')->nullable();

            $table->timestamps();

            // Solo se guarda el primer cambio de cada persona: lo que interesa
            // es el estado original, no cada paso intermedio.
            $table->unique(['evaluation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_user_changes');
        Schema::dropIfExists('evaluation_users');
    }
};
