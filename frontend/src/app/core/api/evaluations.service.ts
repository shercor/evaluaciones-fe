import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

/** Acciones que puede ofrecer una evaluación. Las decide el backend. */
export type AccionEvaluacion =
  | 'open'
  | 'close'
  | 'remind'
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
  /** Cambios en los participantes que todavía no viajaron a Evaluación 360. */
  cambios_pendientes: number;
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

  /**
   * Abre el proceso. Con `notificar` en true, además avisa por correo a todo
   * el padrón; sin él, se abre en silencio.
   */
  abrir(id: number, notificar = false) {
    return this.transicion(id, 'abrir', { notificar });
  }

  cerrar(id: number) {
    return this.transicion(id, 'cerrar');
  }

  /**
   * Recuerda por correo a quienes todavía no terminaron sus tareas.
   *
   * No cambia el estado del proceso, pero devuelve la fila al día igual que
   * las demás acciones: así el listado se refresca por un solo camino.
   */
  recordar(id: number) {
    return this.transicion(id, 'recordar');
  }

  /**
   * Publica los resultados. Con `notificar` en true, además avisa por correo
   * a todo el padrón; sin él, se publica en silencio.
   */
  publicar(id: number, notificar = false) {
    return this.transicion(id, 'publicar', { notificar });
  }

  desactivar(id: number) {
    return this.transicion(id, 'desactivar');
  }

  reactivar(id: number) {
    return this.transicion(id, 'reactivar');
  }

  private transicion(
    id: number,
    accion: string,
    cuerpo: Record<string, unknown> = {},
  ): Observable<{ message: string; data: Evaluacion }> {
    return this.http.post<{ message: string; data: Evaluacion }>(
      `${this.base}/${id}/${accion}`,
      cuerpo,
    );
  }
}
