<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Invitación a definir la primera contraseña.
 *
 * Reutiliza el mecanismo de recuperación —un token con vencimiento— porque es
 * exactamente el mismo problema: probar que quien abre el enlace controla esa
 * casilla de correo. Lo único distinto es el texto.
 *
 * Va por la cola: importar 800 personas no puede quedarse esperando 800 SMTP.
 */
class DirectoryInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $token = Password::createToken($notifiable);

        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/definir-contrasena?'
            .http_build_query(['token' => $token, 'email' => $notifiable->email]);

        $horas = (int) (config('auth.passwords.users.expire', 60) / 60);

        return (new MailMessage)
            ->subject('Tu acceso al portal de Evaluación de Personal')
            ->greeting('Hola '.$notifiable->name.',')
            ->line('Te damos acceso al portal donde vas a responder tus evaluaciones de desempeño y consultar tus resultados.')
            ->line('Para entrar, definí una contraseña propia:')
            ->action('Definir mi contraseña', $url)
            ->line("El enlace vence en {$horas} hora(s). Si se te pasa, podés pedir uno nuevo desde «¿Olvidaste tu contraseña?» en la pantalla de acceso.")
            ->salutation('Equipo de Recursos Humanos');
    }
}
