import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { PanelResultados } from '../../shared/results-panel/results-panel';

export interface BloqueTablero {
  titulo: string | null;
  promedio: number | null;
  resultado: unknown;
  error: string | null;
}

export interface Tablero {
  contexto: { evaluacion: string | null; nota_maxima: number; grupo: string | null };
  promedios: BloqueTablero;
  participacion: BloqueTablero;
  por_fuente: BloqueTablero;
  por_categoria: BloqueTablero;
  respuestas_abiertas: BloqueTablero;
}

export interface MetricaMonitoreo {
  titulo: string;
  realizadas: number;
  total: number;
  porcentaje: number;
}

export interface PersonaMonitoreo {
  id: number;
  nombre: string | null;
  cargo: string | null;
  sucursal: string | null;
  completados: string | null;
  ultima_actividad: string | null;
  estado: string | null;
}

/**
 * Tableros, monitoreo y resultados.
 *
 * El panel de una persona tiene la misma forma la pida quien la pida: por eso
 * `personaAdmin`, `misResultados` y `resultadosDeSupervisado` devuelven todos
 * `PanelResultados`.
 */
export interface PersonaResultado {
  id: number;
  nombre: string;
  cargo: string | null;
  sucursal: string | null;
  promedio_general: number | null;
}

export interface ListadoPersonas {
  data: { resultado: PersonaResultado[] };
  meta: Record<string, unknown>;
}

@Injectable({ providedIn: 'root' })
export class ResultsService {
  private readonly http = inject(HttpClient);

  // -- Administración -----------------------------------------------

  tablero(id: number): Observable<Tablero> {
    return this.http.get<Tablero>(`/api/admin/evaluaciones/${id}/tablero`);
  }

  personaAdmin(id: number, userId: number): Observable<PanelResultados> {
    return this.http.get<PanelResultados>(
      `/api/admin/evaluaciones/${id}/tablero/persona/${userId}`,
    );
  }

  /** Resultados individuales de todos, para elegir a quién mirar. */
  personas(id: number, filtros: Record<string, unknown> = {}): Observable<ListadoPersonas> {
    return this.http.get<ListadoPersonas>(`/api/admin/evaluaciones/${id}/tablero/personas`, {
      params: this.params(filtros),
    });
  }

  monitoreo(id: number): Observable<{
    evaluacion: string | null;
    grupo: string | null;
    metricas: MetricaMonitoreo[];
  }> {
    return this.http.get<never>(`/api/admin/evaluaciones/${id}/monitoreo`);
  }

  monitoreoPersonas(
    id: number,
    filtros: Record<string, unknown> = {},
  ): Observable<{ data: PersonaMonitoreo[]; meta: Record<string, unknown> }> {
    return this.http.get<{ data: PersonaMonitoreo[]; meta: Record<string, unknown> }>(
      `/api/admin/evaluaciones/${id}/monitoreo/personas`,
      { params: this.params(filtros) },
    );
  }

  // -- Portal del colaborador ---------------------------------------

  misResultados(id: number): Observable<PanelResultados> {
    return this.http.get<PanelResultados>(`/api/portal/evaluaciones/${id}/resultados`);
  }

  misSupervisados(
    id: number,
  ): Observable<{
    data: { user_id: number; nombre: string; iniciales: string; cargo: string | null }[];
  }> {
    return this.http.get<never>(`/api/portal/evaluaciones/${id}/supervisados`);
  }

  resultadosDeSupervisado(id: number, userId: number): Observable<PanelResultados> {
    return this.http.get<PanelResultados>(`/api/portal/evaluaciones/${id}/supervisados/${userId}`);
  }

  private params(filtros: Record<string, unknown>): HttpParams {
    let p = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== undefined && v !== null && v !== '') p = p.set(k, String(v));
    }
    return p;
  }
}
