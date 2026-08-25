<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Support\E360\Resources\TasksApi;
use Illuminate\Console\Command;

/**
 * Responde automáticamente las tareas de una evaluación.
 *
 * Es una herramienta **de desarrollo**: sirve para tener tableros con datos
 * reales sin responder decenas de formularios a mano. No tiene sentido en
 * producción, y por eso se niega a correr fuera de entornos locales.
 */
class SeedAnswers extends Command
{
    protected $signature = 'dev:responder {evaluacion : id de la evaluación en Evaluación 360}
                            {--min=3 : nota mínima}
                            {--max=5 : nota máxima}';

    protected $description = 'Responde las tareas pendientes de una evaluación con datos de prueba';

    public function handle(TasksApi $tasks): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->components->error('Este comando no corre en producción.');

            return self::FAILURE;
        }

        $e360Id = (int) $this->argument('evaluacion');
        $min = (int) $this->option('min');
        $max = (int) $this->option('max');

        $evaluation = Evaluation::where('e360_id', $e360Id)->first();

        if (! $evaluation) {
            $this->components->error("No hay evaluación local con e360_id {$e360Id}.");

            return self::FAILURE;
        }

        $padron = EvaluationUser::where('evaluation_id', $evaluation->id)
            ->where('participate', true)
            ->with('user:id,name,lastname')
            ->get();

        $this->components->info("Respondiendo por {$padron->count()} participantes…");
        $this->newLine();

        $totales = ['tareas' => 0, 'ya' => 0, 'nuevas' => 0, 'fallidas' => 0];

        $barra = $this->output->createProgressBar($padron->count());
        $barra->start();

        foreach ($padron as $fila) {
            $this->responderPor($tasks, $e360Id, $fila, $min, $max, $totales);
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Tareas en total', (string) $totales['tareas']);
        $this->components->twoColumnDetail('Ya estaban respondidas', (string) $totales['ya']);
        $this->components->twoColumnDetail('Respondidas ahora', (string) $totales['nuevas']);

        if ($totales['fallidas'] > 0) {
            $this->components->warn("{$totales['fallidas']} tareas fallaron.");
        }

        return self::SUCCESS;
    }

    private function responderPor(
        TasksApi $tasks,
        int $e360Id,
        EvaluationUser $fila,
        int $min,
        int $max,
        array &$totales,
    ): void {
        $respuesta = $tasks->forParticipant($e360Id, $fila->user_id);

        if ($respuesta->failed()) {
            return;
        }

        foreach ($respuesta->collect('tareas') as $grupo) {
            foreach ($grupo->evaluados ?? [] as $evaluado) {
                $totales['tareas']++;

                if ($evaluado->realizado ?? false) {
                    $totales['ya']++;

                    continue;
                }

                $preguntas = $tasks->questions($evaluado->tarea_id, $fila->user_id);

                if ($preguntas->failed()) {
                    $totales['fallidas']++;

                    continue;
                }

                $respuestas = [];

                foreach ($preguntas->data->categorias ?? [] as $categoria) {
                    foreach ($categoria->preguntas ?? [] as $pregunta) {
                        $respuestas[] = [
                            'pregunta_id' => $pregunta->id,
                            // Notas al azar dentro del rango: con todo en el
                            // mismo valor los promedios salen planos y los
                            // gráficos no dicen nada.
                            'respuesta' => $pregunta->tipo === 'selection'
                                ? random_int($min, min($max, $pregunta->rango ?? $max))
                                : 'Comentario de prueba generado automáticamente.',
                        ];
                    }
                }

                if ($respuestas === []) {
                    continue;
                }

                $guardado = $tasks->saveAnswers($evaluado->tarea_id, $fila->user_id, $respuestas);

                $guardado->ok ? $totales['nuevas']++ : $totales['fallidas']++;
            }
        }
    }
}
