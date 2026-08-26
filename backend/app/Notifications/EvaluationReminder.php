<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio de que quedan formularios sin responder.
 *
 * El texto **no acusa a nadie**, y es a propósito. Quién está pendiente sale
 * de una caché local que puede estar desactualizada
 * ([EvaluationAudience::pendientesConCorreo]), así que el correo dice que
 * *figuran* tareas sin responder y ofrece la salida de ignorarlo. Un
 * recordatorio que le echa la culpa a alguien que ya terminó es peor que no
 * mandar ninguno: enseña a ignorar los correos del sistema.
 *
 * Tampoco dice cuántas tareas faltan. Saberlo exigiría preguntarle a
 * Evaluación 360 por cada persona, y el número estaría viejo para cuando la
 * cola llegue a mandar el correo.
 */
class EvaluationReminder extends Notification implements \Illuminate\Contracts\Queue\ShouldQueue
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
            ->subject('Te quedan evaluaciones por responder: '.$this->evaluacionNombre)
            ->greeting('Hola '.$notifiable->name.',')
            ->line('El proceso de evaluación **'.$this->evaluacionNombre.'** sigue abierto, y todavía figuran tareas sin responder a tu nombre.')
            ->action('Ver mis evaluaciones', $url)
            ->line('No hace falta responder todo de una vez: lo que vayas guardando queda registrado.')
            ->line('Si ya las completaste, podés ignorar este mensaje.')
            ->salutation('Equipo de Recursos Humanos');
    }
}
