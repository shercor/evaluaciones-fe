import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

/**
 * Respuesta de GET /api/health en el BFF.
 *
 * `configured` dice si están las credenciales de Evaluación 360, nunca sus
 * valores: el token del tenant no sale del backend.
 */
export interface Health {
  service: string;
  status: string;
  e360: {
    configured: boolean;
  };
}

@Injectable({ providedIn: 'root' })
export class HealthService {
  private readonly http = inject(HttpClient);

  /**
   * La ruta es relativa a propósito. En desarrollo el proxy del servidor de
   * Angular la reenvía a nginx; en producción el SPA y la API van detrás del
   * mismo origen. Así no hay una URL de backend escrita en el bundle.
   */
  check(): Observable<Health> {
    return this.http.get<Health>('/api/health', { withCredentials: true });
  }
}
