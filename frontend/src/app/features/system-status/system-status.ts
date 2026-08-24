import { Component, inject, signal } from '@angular/core';
import { HealthService } from '../../core/api/health.service';

type Check = {
  label: string;
  detail: string;
  state: 'ok' | 'pending' | 'fail';
};

/**
 * Pantalla de estado del andamiaje (hito 1).
 *
 * Existe para que la infraestructura sea verificable a simple vista: consulta
 * el BFF de verdad, así que si se ve en verde es porque la cadena completa
 * —SPA, proxy, nginx, php-fpm, Laravel— está funcionando.
 *
 * Se reemplaza por el login cuando entre el hito 2.
 */
@Component({
  selector: 'app-system-status',
  templateUrl: './system-status.html',
  styleUrl: './system-status.scss',
})
export class SystemStatus {
  private readonly health = inject(HealthService);

  protected readonly checks = signal<Check[]>([]);
  protected readonly loading = signal(true);

  constructor() {
    this.refresh();
  }

  protected refresh(): void {
    this.loading.set(true);

    this.health.check().subscribe({
      next: (health) => {
        this.checks.set([
          {
            label: 'Aplicación Angular',
            detail: 'El SPA compiló y se está ejecutando.',
            state: 'ok',
          },
          {
            label: 'Conexión con el backend',
            detail: 'La petición llegó al BFF a través del proxy y volvió.',
            state: 'ok',
          },
          {
            label: 'Backend (BFF)',
            detail: `Respondió «${health.status}» desde ${health.service}.`,
            state: 'ok',
          },
          {
            label: 'Credenciales de Evaluación 360',
            detail: health.e360.configured
              ? 'Configuradas. Verificá la conexión real con: artisan e360:ping'
              : 'Sin configurar. Completá las variables E360_* en backend/.env cuando quieras conectar.',
            state: health.e360.configured ? 'ok' : 'pending',
          },
        ]);
        this.loading.set(false);
      },
      error: () => {
        this.checks.set([
          {
            label: 'Aplicación Angular',
            detail: 'El SPA compiló y se está ejecutando.',
            state: 'ok',
          },
          {
            label: 'Conexión con el backend',
            detail:
              'No hubo respuesta del BFF. Revisá que los contenedores php y nginx estén arriba: docker compose ps',
            state: 'fail',
          },
        ]);
        this.loading.set(false);
      },
    });
  }
}
