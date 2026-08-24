import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
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

export interface ResumenImportacion {
  id: number;
  filename: string;
  status: string;
  rows_total: number;
  rows_created: number;
  rows_updated: number;
  rows_failed: number;
  error: string | null;
  has_passwords: boolean;
  created_at: string | null;
  user: string | null;
}

export interface FilaImportacion {
  line: number;
  outcome: 'created' | 'updated' | 'failed';
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
  ): Observable<{ message: string; data: ResumenImportacion }> {
    const cuerpo = new FormData();
    cuerpo.append('file', archivo);
    cuerpo.append('send_invitations', enviarInvitaciones ? '1' : '0');

    return this.http.post<{ message: string; data: ResumenImportacion }>(
      `${this.base}/importaciones`,
      cuerpo,
    );
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
