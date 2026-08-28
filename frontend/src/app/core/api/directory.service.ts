import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { PersonaSugerida } from '../../shared/buscador-personas/buscador-personas';
import { Role, User } from '../auth/user.model';

export interface Paginacion {
  current_page: number;
  last_page: number;
  per_page?: number;
  total: number;
}

export interface ListadoPersonas {
  data: User[];
  /** id de la persona => cuántas dependen de ella en toda la cadena. */
  supervisees_count: Record<string, number>;
  meta: Paginacion;
}

export interface FiltrosPersonas {
  search?: string;
  branch_office_id?: number | '';
  job_position_id?: number | '';
  supervisor_id?: number | '';
  role?: Role | '';
  active?: '' | '1' | '0';
  page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
}

export interface ElementoCatalogo {
  id: number;
  external_code: string | null;
  name: string;
  active: boolean;
  users_count?: number;
}

export type TipoCatalogo = 'sucursales' | 'cargos';

/**
 * Qué se está cargando con una planilla.
 *
 * Los tres pasan por la misma pantalla de homologación y por el mismo
 * endpoint: lo único que cambia es qué columnas pide el sistema y qué cuenta
 * el resumen.
 */
export type DestinoImportacion = 'nomina' | TipoCatalogo;

export interface ResumenImportacion {
  id: number;
  filename: string;
  destino: DestinoImportacion;
  status: string;
  rows_total: number;
  rows_created: number;
  rows_updated: number;
  rows_failed: number;
  /** Filas inactivas sin nada que hacer: ni entran ni son un rechazo. */
  rows_skipped: number;
  /** Personas que quedaron inactivas: por venir de baja o por no venir. */
  rows_deactivated: number;
  /** Personas que estaban de baja y volvieron a aparecer en la nómina. */
  rows_reactivated: number;
  error: string | null;
  has_passwords: boolean;
  created_at: string | null;
  user: string | null;
  /** Con qué homologación se cargó, o `null` si vino con el formato propio. */
  mapping: Record<string, string> | null;
}

/** Una columna tal como viene en la planilla de quien la subió. */
export interface ColumnaArchivo {
  clave: string;
  etiqueta: string;
  /** Hasta tres valores de esa columna, para reconocerla de un vistazo. */
  ejemplos: string[];
}

/** Una columna de las que el sistema necesita. */
export interface ColumnaSistema {
  clave: string;
  etiqueta: string;
  obligatoria: boolean;
  ayuda: string;
}

/** La planilla subida, esperando a que le digan qué columna es cuál. */
export interface BorradorHomologacion {
  id: number;
  filename: string;
  destino: DestinoImportacion;
  rows_total: number;
  headers: ColumnaArchivo[];
}

/** Campo del sistema => columna del archivo. `null` es «no la trae». */
export type Homologacion = Record<string, string | null>;

export interface FilaDeMuestra extends Record<string, string | number> {
  linea: number;
}

/**
 * El resumen previo, antes de tocar nada.
 *
 * Lo que sale en los dos destinos está arriba; lo de más abajo lo trae solo la
 * nómina o solo un catálogo, y por eso es opcional. La pantalla es la misma y
 * muestra lo que haya.
 */
export interface ResumenHomologacion {
  filas_totales: number;
  filas_validas: number;
  filas_con_problemas: number;
  se_crearan: number;
  se_actualizaran: number;
  /** Código repetido dentro del archivo => cuántas veces aparece. */
  codigos_repetidos: Record<string, number>;
  muestra: FilaDeMuestra[];
  problemas: { linea: number; codigo: string; nombre: string; motivos: string[] }[];
  problemas_omitidos: number;

  // -- Solo la nómina ----------------------------------------------
  sin_correo?: number;
  /** Personas que entran sin sucursal o sin cargo: casi siempre, una columna sin conectar. */
  sin_sucursal?: number;
  sin_cargo?: number;
  /** Sucursales y cargos que la planilla va a crear porque todavía no existen. */
  sucursales_nuevas?: string[];
  cargos_nuevos?: string[];
  /** Códigos que la planilla usa y no están cargados: bloquean su fila. */
  sucursales_faltantes?: string[];
  cargos_faltantes?: string[];

  // -- Lo que sale del directorio ----------------------------------
  /** Personas de baja que vuelven a quedar activas por venir en el archivo. */
  se_reactivaran?: number;
  /** Si se conectó la columna de estado. Sin ella no hay bajas por origen. */
  columna_activo_conectada?: boolean;
  /** Filas con la columna de estado conectada pero vacía: se toman como activas. */
  activo_vacio?: number;
  /** Valor que no se entiende como estado => cuántas filas lo traen. */
  activo_ilegible?: Record<string, number>;
  /** Se dan de baja porque la planilla las trae inactivas. */
  bajas_por_origen?: number;
  /** Venían inactivas pero no había a quién dar de baja. */
  omitidas_por_inactivas?: number;
  /** Se darían de baja por no venir en el archivo, si se sincroniza. */
  bajas_por_ausencia?: number;
  muestra_ausentes?: { codigo: string; nombre: string }[];
  /**
   * Qué parte del directorio cubre este archivo.
   *
   * Es el par de números con el que se decide si corresponde sincronizar las
   * bajas: una planilla que nombra a 40 de 900 personas casi nunca es la
   * nómina completa, y sincronizarla desactivaría a las otras 860.
   */
  cobertura?: { nombradas_en_archivo: number; sincronizables: number };

  // -- Solo un catálogo --------------------------------------------
  /** Nombre repetido dentro del archivo => cuántas veces aparece. */
  nombres_repetidos?: Record<string, number>;
}

export type ResultadoFila = 'created' | 'updated' | 'failed' | 'deactivated' | 'skipped';

export interface FilaImportacion {
  /** Línea del archivo. Va en 0 en una baja por ausencia: no sale de ninguna. */
  line: number;
  outcome: ResultadoFila;
  error: string | null;
  payload: Record<string, string | null>;
  has_temporary_password: boolean;
}

/**
 * Directorio: personas, catálogos e importación de nómina.
 *
 * Todo pasa por el BFF. Los filtros, el orden y la paginación los resuelve el
 * servidor, no el navegador: la nómina puede tener miles de filas.
 */
@Injectable({ providedIn: 'root' })
export class DirectoryService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/admin';

  // -- Personas -----------------------------------------------------

  /**
   * Quiénes supervisan a alguien, para el filtro del listado.
   *
   * Es un conjunto distinto del de [buscarPosiblesSupervisores]: acá solo
   * aparece gente con al menos un supervisado, porque el resto daría filtros
   * sin resultados.
   */
  buscarSupervisores(search: string): Observable<{ data: PersonaSugerida[] }> {
    return this.http.get<{ data: PersonaSugerida[] }>(`${this.base}/users/supervisores`, {
      params: new HttpParams().set('search', search),
    });
  }

  /**
   * Candidatos a supervisor de alguien, para el formulario.
   *
   * `excluir` deja fuera a la propia persona y a toda su cadena de
   * supervisados, que crearían un ciclo.
   */
  buscarPosiblesSupervisores(
    search: string,
    excluir?: number,
  ): Observable<{ data: PersonaSugerida[] }> {
    let params = new HttpParams().set('search', search);

    if (excluir) {
      params = params.set('exclude', String(excluir));
    }

    return this.http.get<{ data: PersonaSugerida[] }>(`${this.base}/users/posibles-supervisores`, {
      params,
    });
  }

  listarPersonas(filtros: FiltrosPersonas = {}): Observable<ListadoPersonas> {
    let params = new HttpParams();

    for (const [clave, valor] of Object.entries(filtros)) {
      if (valor !== undefined && valor !== null && valor !== '') {
        params = params.set(clave, String(valor));
      }
    }

    return this.http.get<ListadoPersonas>(`${this.base}/users`, { params });
  }

  crearPersona(datos: Partial<User> & { role: Role }): Observable<{ data: User; message: string }> {
    return this.http.post<{ data: User; message: string }>(`${this.base}/users`, datos);
  }

  actualizarPersona(
    id: number,
    datos: Record<string, unknown>,
  ): Observable<{ data: User; message: string }> {
    return this.http.put<{ data: User; message: string }>(`${this.base}/users/${id}`, datos);
  }

  alternarActiva(id: number): Observable<{ data: User; message: string }> {
    return this.http.post<{ data: User; message: string }>(
      `${this.base}/users/${id}/toggle-active`,
      {},
    );
  }

  generarContrasenaTemporal(
    id: number,
  ): Observable<{ temporary_password: string; message: string }> {
    return this.http.post<{ temporary_password: string; message: string }>(
      `${this.base}/users/${id}/reset-password`,
      {},
    );
  }

  /**
   * Cambia la foto de perfil.
   *
   * Viaja el archivo tal como salió de la cámara: recortarlo en el navegador
   * ahorraría subida, pero dejaría el resultado a merced de qué navegador se
   * use, y el servidor tendría que rehacer el trabajo igual para no confiar en
   * lo que le mandan.
   */
  subirFoto(id: number, archivo: File): Observable<{ data: User; message: string }> {
    const cuerpo = new FormData();
    cuerpo.append('foto', archivo);

    return this.http.post<{ data: User; message: string }>(
      `${this.base}/users/${id}/avatar`,
      cuerpo,
    );
  }

  quitarFoto(id: number): Observable<{ data: User; message: string }> {
    return this.http.delete<{ data: User; message: string }>(`${this.base}/users/${id}/avatar`);
  }

  reenviarInvitacion(id: number): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.base}/users/${id}/resend-invitation`, {});
  }

  // -- Catálogos ----------------------------------------------------

  listarCatalogo(tipo: TipoCatalogo): Observable<{ data: ElementoCatalogo[] }> {
    return this.http.get<{ data: ElementoCatalogo[] }>(`${this.base}/catalogos/${tipo}`);
  }

  crearEnCatalogo(
    tipo: TipoCatalogo,
    datos: { name: string; external_code?: string | null },
  ): Observable<{ data: ElementoCatalogo; message: string }> {
    return this.http.post<{ data: ElementoCatalogo; message: string }>(
      `${this.base}/catalogos/${tipo}`,
      datos,
    );
  }

  actualizarEnCatalogo(
    tipo: TipoCatalogo,
    id: number,
    datos: { name: string; external_code?: string | null },
  ): Observable<{ data: ElementoCatalogo; message: string }> {
    return this.http.put<{ data: ElementoCatalogo; message: string }>(
      `${this.base}/catalogos/${tipo}/${id}`,
      datos,
    );
  }

  alternarCatalogo(
    tipo: TipoCatalogo,
    id: number,
  ): Observable<{ data: ElementoCatalogo; message: string }> {
    return this.http.post<{ data: ElementoCatalogo; message: string }>(
      `${this.base}/catalogos/${tipo}/${id}/toggle-active`,
      {},
    );
  }

  // -- Importación --------------------------------------------------

  listarImportaciones(): Observable<{ data: ResumenImportacion[]; meta: Paginacion }> {
    return this.http.get<{ data: ResumenImportacion[]; meta: Paginacion }>(
      `${this.base}/importaciones`,
    );
  }

  importar(
    archivo: File,
    enviarInvitaciones: boolean,
    sincronizarBajas = false,
  ): Observable<{ message: string; data: ResumenImportacion }> {
    const cuerpo = new FormData();
    cuerpo.append('file', archivo);
    cuerpo.append('send_invitations', enviarInvitaciones ? '1' : '0');
    cuerpo.append('sincronizar_bajas', sincronizarBajas ? '1' : '0');

    return this.http.post<{ message: string; data: ResumenImportacion }>(
      `${this.base}/importaciones`,
      cuerpo,
    );
  }

  // -- Homologación de una planilla con otro formato ----------------

  /**
   * Paso 1: subir la planilla y ver qué columnas trae.
   *
   * El archivo queda guardado en el servidor mientras se homologa. Por eso
   * después solo viajan las decisiones, no el archivo de nuevo: así lo que
   * alguien aprueba en el resumen y lo que termina importándose son lo mismo.
   */
  analizarPlanilla(
    archivo: File,
    destino: DestinoImportacion = 'nomina',
  ): Observable<{
    data: BorradorHomologacion;
    columnas_sistema: ColumnaSistema[];
    sugerencia: Homologacion;
  }> {
    const cuerpo = new FormData();
    cuerpo.append('file', archivo);
    cuerpo.append('destino', destino);

    return this.http.post<{
      data: BorradorHomologacion;
      columnas_sistema: ColumnaSistema[];
      sugerencia: Homologacion;
    }>(`${this.base}/importaciones/homologacion`, cuerpo);
  }

  /**
   * Paso 2: qué pasaría con esta homologación, sin importar nada.
   *
   * `sincronizarBajas` viaja también acá y no solo al importar: el resumen
   * tiene que estar calculado con **las mismas** opciones con las que después
   * se confirma, o la frase «se van a dar de baja 34 personas» no vale.
   */
  resumenHomologacion(
    id: number,
    mapping: Homologacion,
    sincronizarBajas = false,
  ): Observable<{
    data: BorradorHomologacion;
    mapping: Record<string, string>;
    sin_usar: string[];
    resumen: ResumenHomologacion;
  }> {
    return this.http.post<{
      data: BorradorHomologacion;
      mapping: Record<string, string>;
      sin_usar: string[];
      resumen: ResumenHomologacion;
    }>(`${this.base}/importaciones/homologacion/${id}/resumen`, {
      mapping,
      sincronizar_bajas: sincronizarBajas,
    });
  }

  /** Paso 3: importar de verdad. */
  importarHomologada(
    id: number,
    mapping: Homologacion,
    enviarInvitaciones: boolean,
    sincronizarBajas = false,
  ): Observable<{ message: string; data: ResumenImportacion }> {
    return this.http.post<{ message: string; data: ResumenImportacion }>(
      `${this.base}/importaciones/homologacion/${id}/importar`,
      {
        mapping,
        send_invitations: enviarInvitaciones,
        sincronizar_bajas: sincronizarBajas,
      },
    );
  }

  /** Tirar la planilla subida sin importarla. */
  descartarBorrador(id: number): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.base}/importaciones/homologacion/${id}`);
  }

  detalleImportacion(
    id: number,
    resultado?: string,
  ): Observable<{ import: ResumenImportacion; rows: FilaImportacion[]; meta: Paginacion }> {
    let params = new HttpParams();
    if (resultado) params = params.set('outcome', resultado);

    return this.http.get<{ import: ResumenImportacion; rows: FilaImportacion[]; meta: Paginacion }>(
      `${this.base}/importaciones/${id}`,
      { params },
    );
  }

  /**
   * Las descargas van por navegación directa y no por `HttpClient`: así el
   * navegador maneja el archivo con su propio diálogo de guardado.
   */
  urlPlantilla(): string {
    return `${this.base}/importaciones/plantilla`;
  }

  urlContrasenas(id: number): string {
    return `${this.base}/importaciones/${id}/contrasenas`;
  }
}
