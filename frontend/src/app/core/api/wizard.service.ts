import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

export interface TipoFormulario {
  id: number;
  nombre: string;
}

export interface Plantilla {
  id: number;
  nombre: string;
  formularios: TipoFormulario[];
}

export interface OpcionesAsistente {
  templates: Plantilla[];
  groups: { id: number; nombre: string }[];
  years: number[];
}

export interface SucursalDisponible {
  /** `null` es «Sin sucursal asignada». */
  id: number | null;
  name: string;
  staff_count: number;
}

export interface Participante {
  user_id: number;
  nombre: string;
  iniciales: string;
  participate: boolean;
  cargo: { id: number; nombre: string } | null;
  sucursal: { id: number; nombre: string } | null;
  supervisor: { id: number; nombre: string } | null;
  /** Cuántas personas dependen de esta, contando toda la cadena. */
  supervisados: number;
}

export interface ListadoParticipantes {
  data: Participante[];
  meta: { current_page: number; last_page: number; total: number; participando: number };
  cambios_pendientes: number;
}

export interface IntegranteGrupo {
  user_id: number;
  nombre: string;
  iniciales: string;
  cargo: string | null;
  sucursal: string | null;
}

export interface GrupoSupervisor {
  supervisor: IntegranteGrupo;
  integrantes: IntegranteGrupo[];
}

export interface Previsualizacion {
  grupos: GrupoSupervisor[];
  huerfanos: IntegranteGrupo[];
  total_participantes: number;
}

/**
 * El asistente de creación, paso a paso.
 *
 * Cada paso persiste apenas se completa: se puede abandonar y retomar sin
 * perder lo hecho.
 */
@Injectable({ providedIn: 'root' })
export class WizardService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/admin/asistente';

  // -- Paso 1 -------------------------------------------------------

  opciones(): Observable<OpcionesAsistente> {
    return this.http.get<OpcionesAsistente>(`${this.base}/opciones`);
  }

  periodoSugerido(year: number, groupId: number): Observable<{ periodo: number | null }> {
    const params = new HttpParams()
      .set('year', String(year))
      .set('group_id', String(groupId));

    return this.http.get<{ periodo: number | null }>(`${this.base}/periodo`, { params });
  }

  crear(datos: {
    titulo: string;
    descripcion: string;
    year: number;
    periodo: number;
    group_id: number;
    template_id: number;
    formularios: number[];
  }): Observable<{ message: string; data: { id: number } }> {
    return this.http.post<{ message: string; data: { id: number } }>(
      `${this.base}/evaluaciones`,
      datos,
    );
  }

  // -- Pasos 2 y 3 --------------------------------------------------

  sucursales(
    id: number,
  ): Observable<{ disponibles: SucursalDisponible[]; seleccionadas: (number | null)[] }> {
    return this.http.get<{ disponibles: SucursalDisponible[]; seleccionadas: (number | null)[] }>(
      `${this.base}/${id}/sucursales`,
    );
  }

  guardarSucursales(
    id: number,
    branchOffices: (number | null)[],
  ): Observable<{ message: string; resumen: Record<string, number> }> {
    return this.http.post<{ message: string; resumen: Record<string, number> }>(
      `${this.base}/${id}/sucursales`,
      { branch_offices: branchOffices },
    );
  }

  // -- Paso 4 -------------------------------------------------------

  participantes(id: number, filtros: Record<string, unknown> = {}): Observable<ListadoParticipantes> {
    let params = new HttpParams();

    for (const [clave, valor] of Object.entries(filtros)) {
      if (valor !== undefined && valor !== null && valor !== '') {
        params = params.set(clave, String(valor));
      }
    }

    return this.http.get<ListadoParticipantes>(`${this.base}/${id}/participantes`, { params });
  }

  cambiarParticipacion(
    id: number,
    userId: number,
    participate: boolean,
    withSupervisees: boolean,
  ): Observable<{ message: string; afectados: number[]; cambios_pendientes: number }> {
    return this.http.post<{ message: string; afectados: number[]; cambios_pendientes: number }>(
      `${this.base}/${id}/participantes/participacion`,
      { user_id: userId, participate, with_supervisees: withSupervisees },
    );
  }

  editarParticipante(
    id: number,
    datos: {
      user_id: number;
      branch_office_id: number | null;
      job_position_id: number | null;
      supervisor_id: number | null;
    },
  ): Observable<{ message: string; cambios_pendientes: number }> {
    return this.http.post<{ message: string; cambios_pendientes: number }>(
      `${this.base}/${id}/participantes/detalle`,
      datos,
    );
  }

  buscarSupervisores(
    id: number,
    search: string,
    excluir: number,
  ): Observable<{ data: { id: number; nombre: string }[] }> {
    const params = new HttpParams().set('search', search).set('exclude', String(excluir));

    return this.http.get<{ data: { id: number; nombre: string }[] }>(
      `${this.base}/${id}/participantes/supervisores`,
      { params },
    );
  }

  // -- Paso 5 -------------------------------------------------------

  previsualizacion(id: number): Observable<Previsualizacion> {
    return this.http.get<Previsualizacion>(`${this.base}/${id}/previsualizacion`);
  }

  excluirHuerfanos(id: number, userIds: number[]): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/${id}/previsualizacion/excluir`, {
      user_ids: userIds,
    });
  }

  // -- Paso 6 -------------------------------------------------------

  enviar(id: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/${id}/enviar`, {});
  }

  deshacerCambios(id: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/${id}/deshacer`, {});
  }
}
