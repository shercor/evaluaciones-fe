import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
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
  templateUrl: './admin-home.html',
})
export class AdminHome {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  protected readonly user = this.auth.user;
  protected readonly permisoConfirmado = signal<boolean | null>(null);

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
