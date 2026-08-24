<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePasswordResetLinks();
    }

    /**
     * El enlace del correo tiene que abrir el SPA, no una vista de Laravel.
     *
     * Sin esto Laravel arma la URL contra su propio dominio (`APP_URL`) y la
     * persona aterriza en un 404: en este proyecto las pantallas viven en
     * Angular.
     *
     * El mismo enlace sirve para recuperar y para la invitación inicial —
     * mecanismo idéntico, distinto destino.
     */
    private function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            $ruta = $user->must_set_password ? '/definir-contrasena' : '/restablecer-contrasena';

            return $frontend.$ruta.'?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
