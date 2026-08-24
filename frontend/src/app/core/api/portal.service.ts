import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

export interface EvaluacionEnCurso {
  id: number;
  titulo: string | null;
  year: number | null;
  periodo: number | null;
  tareas_completadas: boolean;
}

export interface EvaluacionFinalizada {
  id: number | null;
  participacion_id: number | null;
  titulo: string | null;
  year: number | null;
  periodo: number | null;
  publicado: boolean;
  tiene_supervisados: boolean;
}

export interface Evaluado {
  tarea_id: number;
  nombre: string | null;
  realizado: boolean;
}

export interface FormularioTareas {
  form_id: number | null;
  nombre: string;
  evaluados: Evaluado[];
}

export interface Pregunta {
  id: number;
  nombre: string;
  /** `selection` es una escala; el resto se responde escribiendo. */
  tipo: string;
  rango: number | null;
  opcional: boolean;
  respuesta: string | number | null;
}

export interface CategoriaPreguntas {
  id: number;
  nombre: string;
  descripcion: string | null;
  preguntas: Pregunta[];
}

export interface DetalleTarea {
  tarea_id: number;
  estado_evaluacion: string;
  nombre: string;
  descripcion: string | null;
  evaluado: { id: number; user: { import_id: number; name: string } };
  categorias: CategoriaPreguntas[];
}

/**
 * El portal del colaborador.
 *
 * Ninguna de estas llamadas manda un identificador de persona: quién responde
 * lo decide la sesión en el servidor. Es la diferencia de fondo con la
 * intranet, donde el `user_id` viajaba por POST y la ruta era pública.
 */
@Injectable({ providedIn: 'root' })
export class PortalService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/portal';

  misEvaluaciones(): Observable<{
    en_curso: EvaluacionEnCurso | null;
    finalizadas: EvaluacionFinalizada[];
  }> {
    return this.http.get<{ en_curso: EvaluacionEnCurso | null; finalizadas: EvaluacionFinalizada[] }>(
      `${this.base}/evaluaciones`,
    );
  }

  tareas(evaluationId: number): Observable<{
    evaluacion: { id: number; titulo: string | null; descripcion: string | null; estado: string | null; grupo: string | null };
    formularios: FormularioTareas[];
    resumen: { total: number; completadas: number; pendientes: number; hay_pendientes: boolean };
  }> {
    return this.http.get<never>(`${this.base}/evaluaciones/${evaluationId}/tareas`);
  }

  tarea(taskId: number): Observable<{ data: DetalleTarea; cerrada: boolean }> {
    return this.http.get<{ data: DetalleTarea; cerrada: boolean }>(`${this.base}/tareas/${taskId}`);
  }

  responder(
    taskId: number,
    respuestas: { pregunta_id: number; respuesta: string | number }[],
  ): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/tareas/${taskId}/respuestas`, {
      respuestas,
    });
  }
}
