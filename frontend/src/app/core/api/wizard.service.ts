import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { PersonaSugerida } from '../../shared/buscador-personas/buscador-personas';

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
  /** Foto de perfil; `null` en quien no cargó ninguna: ahí van las iniciales. */
  foto: string | null;
  participate: boolean;
  cargo: { id: number; nombre: string } | null;
  sucursal: { id: number; nombre: string } | null;
  supervisor: { id: number; nombre: string } | null;
  /** Cuántas personas dependen de esta, contando toda la cadena. */
  supervisados: number;
}

export interface CambioParticipacion {
  message: string;
  /** Ids que quedaron en el nuevo estado, incluida la cadena si se arrastró. */
  afectados: number[];
  cambios_pendientes: number;
  /** Total del padrón ya recalculado: evita recargar el listado. */
  participando: number;
}

export interface ListadoParticipantes {
  data: Participante[];
  meta: {
    current_page: number;
    last_page: number;
    /** Cuántas filas devolvió el filtro. */
    total: number;
    /** Cuántas personas hay en el padrón, sin filtrar. */
    total_padron: number;
    participando: number;
  };
  cambios_pendientes: number;
}

export interface IntegranteGrupo {
  user_id: number;
  nombre: string;
  iniciales: string;
  foto: string | null;
  cargo: string | null;
  sucursal: string | null;
}

export interface GrupoSupervisor {
  /** El jefe puede encabezar el equipo sin participar: se muestra atenuado. */
  supervisor: IntegranteGrupo & { participa: boolean };
  integrantes: IntegranteGrupo[];
}

export interface Previsualizacion {
  grupos: GrupoSupervisor[];
  huerfanos: IntegranteGrupo[];
  total_participantes: number;
  /** Jefes que encabezan un equipo sin participar ellos mismos. */
  jefes_excluidos: number;
  /** true si es el alta del proceso; false si se corrige uno ya creado. */
  es_alta: boolean;
  estado: string | null;
  /** false cuando el estado ya no admite tocar el padrón. */
  permite_editar: boolean;
  cambios_pendientes: number;
}

/**
 * El asistente de creación, paso a paso.
 *
 * Cada paso persiste apenas se completa: se puede abandonar y retomar sin
 * perder lo hecho.
 */
export interface DefinicionProceso {
  titulo: string;
  descripcion: string;
  year: number;
  periodo: number;
  group_id: number;
  template_id: number;
  formularios: number[];
  estado: string | null;
  /** false cuando el estado ya no admite tocar la definición. */
  editable: boolean;
  /** true cuando solo se admiten título y descripción. */
  solo_textos: boolean;
}

export interface PeriodoSugerido {
  /** `null` cuando el grupo nunca tuvo evaluaciones. */
  periodo: number | null;
  /** Si es true el número lo determinan las evaluaciones que ya existen. */
  forzado: boolean;
}

@Injectable({ providedIn: 'root' })
export class WizardService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/admin/asistente';

  // -- Paso 1 -------------------------------------------------------

  opciones(): Observable<OpcionesAsistente> {
    return this.http.get<OpcionesAsistente>(`${this.base}/opciones`);
  }

  /**
   * Período que le toca a este año y grupo.
   *
   * `periodo` ya es el **siguiente** al último usado: no hay que sumarle nada.
   * Viene `null` solo cuando el grupo nunca tuvo evaluaciones, y ahí `forzado`
   * queda en false, que es el único caso en que se puede elegir el número.
   */
  periodoSugerido(year: number, groupId: number): Observable<PeriodoSugerido> {
    const params = new HttpParams().set('year', String(year)).set('group_id', String(groupId));

    return this.http.get<PeriodoSugerido>(`${this.base}/periodo`, { params });
  }

  /** La definición de un proceso ya creado, y qué se puede tocar de ella. */
  definicion(id: number): Observable<DefinicionProceso> {
    return this.http.get<DefinicionProceso>(`${this.base}/${id}/definicion`);
  }

  guardarDefinicion(id: number, datos: Record<string, unknown>): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/${id}/definicion`, datos);
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

  participantes(
    id: number,
    filtros: Record<string, unknown> = {},
  ): Observable<ListadoParticipantes> {
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
  ): Observable<CambioParticipacion> {
    return this.http.post<CambioParticipacion>(`${this.base}/${id}/participantes/participacion`, {
      user_id: userId,
      participate,
      with_supervisees: withSupervisees,
    });
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

  /**
   * Candidatos a supervisor para el editor: gente del padrón que participa,
   * sin contar a la persona que se está editando.
   */
  buscarSupervisores(
    id: number,
    search: string,
    excluir: number,
  ): Observable<{ data: PersonaSugerida[] }> {
    const params = new HttpParams().set('search', search).set('exclude', String(excluir));

    return this.http.get<{ data: PersonaSugerida[] }>(
      `${this.base}/${id}/participantes/supervisores`,
      { params },
    );
  }

  /**
   * Quiénes figuran como supervisor en el padrón, para el filtro del listado.
   *
   * Es un conjunto distinto del anterior —acá solo aparece quien tiene gente a
   * cargo— y por eso son dos consultas y no una con un parámetro: ofrecer a
   * todo el padrón daría opciones que no devuelven ninguna fila.
   */
  buscarSupervisoresDelPadron(id: number, search: string): Observable<{ data: PersonaSugerida[] }> {
    return this.http.get<{ data: PersonaSugerida[] }>(
      `${this.base}/${id}/participantes/supervisores-del-padron`,
      { params: new HttpParams().set('search', search) },
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
