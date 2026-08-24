<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte la tabla `users` de Laravel en el directorio de personas.
 *
 * Persona y credencial viven juntas, igual que en la tabla `users` de la
 * intranet. `supervisor_id` apunta a esta misma tabla: esa auto-referencia
 * **es** el organigrama, y de ella salen la cascada de supervisados y la
 * detección de ciclos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identidad
            $table->string('external_code')->nullable()->unique()->after('id');
            $table->string('lastname')->nullable()->after('name');

            // Acceso
            $table->string('role')->default(Role::COLLABORATOR->value)->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->boolean('must_set_password')->default(false)->after('active');
            $table->timestamp('last_login_at')->nullable()->after('must_set_password');

            // Perfil. Las fotos son todas nuevas: no se migra ninguna imagen
            // de la intranet, y a falta de foto se dibujan las iniciales.
            $table->string('avatar_path')->nullable()->after('last_login_at');

            // Organigrama
            $table->foreignId('branch_office_id')->nullable()->after('avatar_path')
                ->constrained('branch_offices')->nullOnDelete();
            $table->foreignId('job_position_id')->nullable()->after('branch_office_id')
                ->constrained('job_positions')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->after('job_position_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['active', 'role']);
        });

        // La contraseña puede no existir todavía: una persona importada sin
        // correo espera a que un administrador le entregue la temporal.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_office_id']);
            $table->dropForeign(['job_position_id']);
            $table->dropForeign(['supervisor_id']);

            $table->dropIndex(['active', 'role']);

            $table->dropColumn([
                'external_code',
                'lastname',
                'role',
                'active',
                'must_set_password',
                'last_login_at',
                'avatar_path',
                'branch_office_id',
                'job_position_id',
                'supervisor_id',
            ]);
        });
    }
};
