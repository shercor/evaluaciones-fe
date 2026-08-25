import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

export interface Grupo {
  id: number;
  nombre: string | null;
  descripcion: string | null;
  activo: boolean;
}

export interface PreguntaPrevisualizada {
  texto: string | null;
  tipo: string | null;
}

export interface CategoriaPrevisualizada {
  nombre: string | null;
  descripcion: string | null;
  /** Si no cuenta para el promedio, conviene decirlo al revisar. */
  en_promedio: boolean;
  condicional: boolean;
  /** Qué tiene que cumplir la persona para que esta categoría le aplique. */
  condicion: string | null;
  preguntas: PreguntaPrevisualizada[];
}

export interface FormularioPrevisualizado {
  nombre: string;
  total_preguntas: number;
  categorias: CategoriaPrevisualizada[];
}

/**
 * Grupos de evaluación y previsualización de formularios.
 *
 * Los dos viven enteramente en Evaluación 360; el BFF solo los expone.
 */
@Injectable({ providedIn: 'root' })
export class GroupsService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/admin';

  listar(page = 1): Observable<{ data: Grupo[]; meta: Record<string, unknown> }> {
    const params = new HttpParams().set('page', String(page));
    return this.http.get<{ data: Grupo[]; meta: Record<string, unknown> }>(
      `${this.base}/grupos`,
      { params },
    );
  }

  crear(nombre: string, descripcion: string | null): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/grupos`, { nombre, descripcion });
  }

  actualizar(
    id: number,
    nombre: string,
    descripcion: string | null,
  ): Observable<{ message: string }> {
    return this.http.put<{ message: string }>(`${this.base}/grupos/${id}`, { nombre, descripcion });
  }

  alternar(id: number, activar: boolean): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/grupos/${id}/estado`, { activar });
  }

  // -- Previsualización de formularios ------------------------------

  previsualizarEvaluacion(id: number): Observable<{ formularios: FormularioPrevisualizado[] }> {
    return this.http.get<{ formularios: FormularioPrevisualizado[] }>(
      `${this.base}/previsualizacion/evaluacion/${id}`,
    );
  }

  previsualizarPlantilla(id: number): Observable<{ formularios: FormularioPrevisualizado[] }> {
    return this.http.get<{ formularios: FormularioPrevisualizado[] }>(
      `${this.base}/previsualizacion/plantilla/${id}`,
    );
  }
}
