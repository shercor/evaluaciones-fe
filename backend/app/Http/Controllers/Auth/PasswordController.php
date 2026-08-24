<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Recuperación y cambio de contraseña.
 *
 * Cubre los tres caminos: olvidé la mía, la estoy definiendo por primera vez
 * desde una invitación, y la estoy cambiando teniendo sesión abierta.
 */
class PasswordController extends Controller
{
    /**
     * Envía el enlace de recuperación.
     *
     * Responde siempre lo mismo, exista o no la cuenta: si dijera «ese correo
     * no está registrado» serviría para averiguar qué direcciones existen.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si el correo corresponde a una cuenta, te llegará un enlace para restablecer la contraseña.',
        ]);
    }

    /**
     * Define la contraseña a partir del enlace recibido por correo.
     *
     * Sirve tanto para recuperar como para la invitación inicial: son el mismo
     * mecanismo, y por eso limpia `must_set_password` al terminar.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'must_set_password' => false,
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'El enlace no es válido o ya venció. Pedí uno nuevo.',
            ]);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya podés iniciar sesión.']);
    }

    /**
     * Cambio de contraseña con la sesión abierta.
     *
     * También es el camino de quien entró con una contraseña temporal: pide la
     * actual, así que la temporal funciona como credencial de un solo uso.
     */
    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->toString(), $user->password ?? '')) {
            throw ValidationException::withMessages([
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'must_set_password' => false,
        ])->save();

        // Cerrar las demás sesiones: si la contraseña se cambió porque estaba
        // comprometida, dejar las otras vivas anularía el cambio.
        Auth::logoutOtherDevices($request->string('password')->toString());

        return response()->json([
            'message' => 'Contraseña actualizada.',
            'redirect_to' => $user->role->home(),
        ]);
    }
}
