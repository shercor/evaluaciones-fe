<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationUser;
use App\Models\User;
use App\Support\E360\E360Response;
use App\Support\E360\Resources\ParticipantsApi;
use Illuminate\Support\Facades\Log;

/**
 * Arma y envía el padrón a Evaluación 360.
 *
 * Porta `getParticipationsToSend()` + `finishProcessCreation()` de la intranet.
 *
 * La regla menos obvia, y la que hay que conservar sí o sí: se envían también
 * los **supervisores que no participan**, marcados `activo: false`. La API los
 * necesita como usuarios para poder colgar de ellos la estructura de
 * evaluación, aunque ellos mismos no vayan a ser evaluados.
 */
class ParticipationSubmission
{
    public function __construct(private readonly ParticipantsApi $api) {}

    /**
     * Construye el payload sin enviarlo. Sirve para previsualizar y para medir.
     */
    public function build(Evaluation $evaluation): array
    {
        $padron = EvaluationUser::query()
            ->where('evaluation_id', $evaluation->id)
            ->participating()
            ->with(['user:id,name,lastname', 'jobPosition:id,name', 'branchOffice:id,name'])
            ->get();

        $participantes = [];
        $incluidos = [];

        foreach ($padron as $fila) {
            $participantes[] = [
                'usuario_id' => $fila->user_id,
                'supervisor_id' => $fila->supervisor_id,
                'nombre' => $fila->user?->fullName() ?? 'Sin nombre',
                'cargo' => $fila->jobPosition?->name ?? '',
                'sucursal' => $fila->branchOffice?->name ?? '',
                'activo' => true,
            ];
            $incluidos[$fila->user_id] = true;
        }

        // Los supervisores referenciados que no están en la lista: se mandan
        // como usuarios inactivos para que la API pueda crearlos.
        $supervisoresFaltantes = $padron
            ->pluck('supervisor_id')
            ->filter()
            ->unique()
            ->reject(fn ($id) => isset($incluidos[$id]));

        if ($supervisoresFaltantes->isNotEmpty()) {
            $datos = User::whereIn('id', $supervisoresFaltantes)
                ->get(['id', 'name', 'lastname']);

            foreach ($datos as $supervisor) {
                $participantes[] = [
                    'usuario_id' => $supervisor->id,
                    'supervisor_id' => null,
                    'nombre' => $supervisor->fullName(),
                    'cargo' => null,
                    'sucursal' => null,
                    'activo' => false,
                ];
            }
        }

        return [
            'evaluacion_id' => $evaluation->e360_id,
            'participantes' => $participantes,
        ];
    }

    /**
     * Envía el padrón.
     *
     * `POST` cuando es el alta del proceso, `PUT` cuando se están corrigiendo
     * los participantes de un proceso ya creado.
     */
    public function submit(Evaluation $evaluation, bool $esAlta): E360Response
    {
        $payload = $this->build($evaluation);

        // La intranet medía esto porque el envío es de lo más pesado del
        // módulo; conviene poder verlo en el log si algo se cae.
        $tamano = strlen(json_encode($payload) ?: '');
        Log::debug(sprintf(
            '[padrón] evaluación %d: %d participantes, %.1f KB',
            $evaluation->e360_id,
            count($payload['participantes']),
            $tamano / 1024,
        ));

        return $esAlta
            ? $this->api->create($payload)
            : $this->api->update($payload);
    }

    public function countParticipating(Evaluation $evaluation): int
    {
        return EvaluationUser::where('evaluation_id', $evaluation->id)
            ->participating()
            ->count();
    }
}
