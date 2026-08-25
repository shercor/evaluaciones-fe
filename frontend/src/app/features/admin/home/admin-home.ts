import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';

/**
 * Inicio del portal de administración.
 *
 * Confirma contra el backend que el control por rol funciona de verdad: la
 * llamada a `/api/admin/ping` está protegida por el middleware `role`, así que
 * si responde es porque el permiso se validó en el servidor y no solo acá.
 */
@Component({
  selector: 'app-admin-home',
  imports: [RouterLink],
  templateUrl: './admin-home.html',
})
export class AdminHome {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  protected readonly user = this.auth.user;
  protected readonly permisoConfirmado = signal<boolean | null>(null);

  /** Los pasos del asistente, para quien entra por primera vez. */
  protected readonly pasos = [
    { numero: 1, titulo: 'Definir', detalle: 'Plantilla, grupo, año y período.' },
    { numero: 2, titulo: 'Sucursales', detalle: 'De dónde sale la gente.' },
    { numero: 3, titulo: 'Participantes', detalle: 'Depurar quién entra.' },
    { numero: 4, titulo: 'Enviar', detalle: 'Revisar los grupos y crear.' },
  ];

  constructor() {
    this.verificar();
  }

  private async verificar(): Promise<void> {
    try {
      await firstValueFrom(
        this.http.get('/api/admin/ping', { withCredentials: true }),
      );
      this.permisoConfirmado.set(true);
    } catch {
      this.permisoConfirmado.set(false);
    }
  }
}
