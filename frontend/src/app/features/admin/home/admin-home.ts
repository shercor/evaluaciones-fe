import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { DirectoryService } from '../../../core/api/directory.service';
import { EvaluationsService } from '../../../core/api/evaluations.service';
import { Contador } from '../../../shared/contador/contador';

/**
 * Inicio del portal de administración.
 *
 * Confirma contra el backend que el control por rol funciona de verdad: la
 * llamada a `/api/admin/ping` está protegida por el middleware `role`, así que
 * si responde es porque el permiso se validó en el servidor y no solo acá.
 */
@Component({
  selector: 'app-admin-home',
  imports: [RouterLink, Contador],
  templateUrl: './admin-home.html',
})
export class AdminHome {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  private readonly evaluaciones = inject(EvaluationsService);
  private readonly directorio = inject(DirectoryService);

  protected readonly user = this.auth.user;
  protected readonly permisoConfirmado = signal<boolean | null>(null);

  /**
   * Cifras de la portada.
   *
   * Salen de los mismos listados que ya usan las otras pantallas: no hace
   * falta un endpoint de resumen para mostrar cuatro números, y así no hay un
   * segundo cálculo que se pueda desincronizar del primero.
   */
  protected readonly procesos = signal(0);
  protected readonly enCurso = signal(0);
  protected readonly personas = signal(0);
  protected readonly pendientes = signal(0);
  protected readonly cifrasListas = signal(false);

  /** Los pasos del asistente, para quien entra por primera vez. */
  protected readonly pasos = [
    { numero: 1, titulo: 'Definir', detalle: 'Plantilla, grupo, año y período.' },
    { numero: 2, titulo: 'Sucursales', detalle: 'De dónde sale la gente.' },
    { numero: 3, titulo: 'Participantes', detalle: 'Depurar quién entra.' },
    { numero: 4, titulo: 'Enviar', detalle: 'Revisar los grupos y crear.' },
  ];

  constructor() {
    this.verificar();
    this.cargarCifras();
  }

  private cargarCifras(): void {
    this.evaluaciones.listar({}).subscribe({
      next: (r) => {
        // `meta` viene tipado como Record<string, unknown>: el total de la
        // API manda, y la longitud de la página es el respaldo.
        this.procesos.set(Number(r.meta?.['total'] ?? r.data.length));
        this.enCurso.set(r.data.filter((e) => e.estado === 'en_proceso').length);
        this.pendientes.set(r.data.filter((e) => e.cambios_pendientes > 0).length);
        this.cifrasListas.set(true);
      },
      error: () => this.cifrasListas.set(true),
    });

    this.directorio.listarPersonas({ active: '1' }).subscribe({
      next: (r) => this.personas.set(r.meta?.total ?? r.data.length),
    });
  }

  private async verificar(): Promise<void> {
    try {
      await firstValueFrom(this.http.get('/api/admin/ping', { withCredentials: true }));
      this.permisoConfirmado.set(true);
    } catch {
      this.permisoConfirmado.set(false);
    }
  }
}
