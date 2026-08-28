<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que los resultados de un proceso ya se pueden consultar.
 *
 * Lleva al informe propio y no a la lista de tareas: cuando este correo sale,
 * el proceso ya está cerrado y no queda nada que responder.
 *
 * El enlace es lo único que se manda. Los promedios y el detalle no viajan por
 * correo, y no es un descuido: una nota de desempeño en el cuerpo de un correo
 * queda reenviable, indexada en el buzón y visible en la notificación del
 * teléfono. Para verla hay que entrar y estar autenticado.
 */
class ResultsPublished extends Notification implements ShouldQueue
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
            .'/portal/evaluacion/'.$this->evaluacionId.'/resultados';

        return (new MailMessage)
            ->subject('Ya están tus resultados: '.$this->evaluacionNombre)
            ->greeting('Hola '.$notifiable->name.',')
            ->line('Se publicaron los resultados de **'.$this->evaluacionNombre.'** y ya podés consultar los tuyos.')
            ->line('Vas a ver tus promedios por categoría y el detalle de cada formulario: la autoevaluación, la de tu jefatura y la de tus pares, cada una por separado.')
            ->action('Ver mis resultados', $url)
            ->line('Si tenés gente a cargo, desde el portal también podés ver los resultados de tu equipo.')
            ->salutation('Equipo de Recursos Humanos');
    }
}
