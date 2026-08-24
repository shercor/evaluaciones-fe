<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de las cargas de nómina.
 *
 * Cada importación queda auditada y, sobre todo, guarda el detalle **fila por
 * fila**. Sin eso, un archivo de 800 personas con 12 errores solo puede decir
 * «falló»: con esto se puede mostrar qué línea, qué campo y por qué.
 *
 * `temporary_password` guarda la contraseña generada para quien no tiene
 * correo — el administrador la descarga y la entrega en mano. Es de un solo
 * uso: la persona queda con `must_set_password` y la cambia al primer ingreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename');
            $table->string('status')->default('pending');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->string('outcome');           // created | updated | failed
            $table->json('payload')->nullable(); // la fila cruda, para poder revisarla
            $table->text('error')->nullable();
            $table->string('temporary_password')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
