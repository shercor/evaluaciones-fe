<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que un proceso de evaluación se abrió y ya se puede responder.
 *
 * Porta el correo que la intranet manda desde
 * `QueuedProcessesTable::__personal_evaluation_beginning_notifications()`,
 * con una diferencia: allá el aviso salía por tres canales —notificación
 * interna, correo y cartel en la portada— porque había una intranet donde
 * ponerlos. Acá solo existe el correo, así que tiene que bastarse solo: por
 * eso nombra el proceso y lleva el enlace directo a las tareas.
 *
 * Va por la cola, como [DirectoryInvitation]: abrir un proceso de 7.092
 * personas no puede quedarse esperando 7.092 conexiones SMTP.
 */
class EvaluationOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $evaluacionId,
        private readonly string $evaluacionNombre,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/portal/evaluacion/'.$this->evaluacionId;

        return (new MailMessage)
            ->subject('Ya podés responder: '.$this->evaluacionNombre)
            ->greeting('Hola '.$notifiable->name.',')
            ->line('Se abrió el proceso de evaluación **'.$this->evaluacionNombre.'** y ya podés completar lo que te toca.')
            ->line('Puede que tengas que evaluarte a vos mismo, a tu equipo o a tus pares: al entrar vas a ver tu lista de tareas pendientes.')
            ->action('Ver mis evaluaciones', $url)
            ->line('No hace falta responder todo de una vez: lo que vayas guardando queda registrado.')
            ->salutation('Equipo de Recursos Humanos');
    }
}
