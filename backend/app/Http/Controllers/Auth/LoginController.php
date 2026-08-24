<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Inicio y cierre de sesión.
 *
 * Autenticación por cookie de sesión (Sanctum en modo SPA), no por token en
 * `localStorage`: la cookie es `HttpOnly`, así que un XSS no puede robarla.
 */
class LoginController extends Controller
{
    /**
     * Intentos permitidos antes de bloquear, por combinación de correo e IP.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $key = $this->throttleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Probá de nuevo en {$seconds} segundos.",
            ])->status(429);
        }

        $attempted = Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        );

        if (! $attempted) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // Mismo mensaje exista o no la cuenta: decir «este correo no está
            // registrado» le confirma a un atacante qué direcciones existen.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // Una cuenta desactivada no entra, aunque la contraseña sea correcta.
        if (! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Esta cuenta está desactivada. Contactá al administrador.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => new UserResource($user->load(['branchOffice', 'jobPosition', 'supervisor'])),
            // Angular usa esto para mandar a la persona al portal que le toca
            // sin tener que conocer las reglas de rol.
            'redirect_to' => $user->must_set_password ? '/definir-contrasena' : $user->role->home(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * La persona autenticada. Angular la pide al arrancar para saber si hay
     * sesión viva y qué portal mostrar.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['branchOffice', 'jobPosition', 'supervisor']);

        return response()->json(['user' => new UserResource($user)]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.mb_strtolower($email).'|'.$request->ip();
    }
}
