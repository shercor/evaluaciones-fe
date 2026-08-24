import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

/** Acciones que puede ofrecer una evaluación. Las decide el backend. */
export type AccionEvaluacion =
  | 'open'
  | 'close'
  | 'publish'
  | 'delete'
  | 'restore'
  | 'edit'
  | 'participants'
  | 'continue_creation'
  | 'preview_forms'
  | 'monitor'
  | 'dashboard';

export interface Evaluacion {
  id: number;
  titulo: string | null;
  year: number | null;
  periodo: number | null;
  fecha_inicio: string | null;
  fecha_fin: string | null;
  fecha_creacion: string | null;
  estado: string | null;
  estado_label: string | null;
  estado_descripcion: string | null;
  estado_color: string | null;
  /** La API está trabajando: no se ofrece ninguna acción y conviene consultar. */
  en_transicion: boolean;
  activo: boolean;
  publicado: boolean;
  acciones: AccionEvaluacion[];
}

export interface EstadoEvaluacion {
  valor: string;
  label: string;
  color: string;
}

export interface FiltrosEvaluaciones {
  nombre?: string;
  year?: string;
  periodo?: string;
  estado?: string;
  page?: number;
}

export interface ListadoEvaluaciones {
  data: Evaluacion[];
  meta: Record<string, unknown>;
  statuses: EstadoEvaluacion[];
}

/**
 * Procesos de evaluación.
 *
 * Los datos son de Evaluación 360, pero pasan por el BFF: el navegador nunca
 * habla con esa API.
 */
@Injectable({ providedIn: 'root' })
export class EvaluationsService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/admin/evaluaciones';

  listar(filtros: FiltrosEvaluaciones = {}): Observable<ListadoEvaluaciones> {
    let params = new HttpParams();

    for (const [clave, valor] of Object.entries(filtros)) {
      if (valor !== undefined && valor !== null && valor !== '') {
        params = params.set(clave, String(valor));
      }
    }

    return this.http.get<ListadoEvaluaciones>(this.base, { params });
  }

  /**
   * Estado de una sola evaluación.
   *
   * Sirve para refrescar la fila de un proceso que está «preparando», sin
   * recargar el listado entero.
   */
  estado(id: number): Observable<{ data: Evaluacion }> {
    return this.http.get<{ data: Evaluacion }>(`${this.base}/${id}/estado`);
  }

  abrir(id: number) {
    return this.transicion(id, 'abrir');
  }

  cerrar(id: number) {
    return this.transicion(id, 'cerrar');
  }

  publicar(id: number) {
    return this.transicion(id, 'publicar');
  }

  desactivar(id: number) {
    return this.transicion(id, 'desactivar');
  }

  reactivar(id: number) {
    return this.transicion(id, 'reactivar');
  }

  private transicion(id: number, accion: string): Observable<{ message: string; data: Evaluacion }> {
    return this.http.post<{ message: string; data: Evaluacion }>(
      `${this.base}/${id}/${accion}`,
      {},
    );
  }
}
