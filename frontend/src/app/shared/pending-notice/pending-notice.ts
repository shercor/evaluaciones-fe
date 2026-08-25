import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * Aviso de tareas pendientes.
 *
 * Va en los dos portales, porque un administrador también responde su propia
 * evaluación y desde el panel no vería el recordatorio.
 *
 * Si la consulta falla no muestra nada: un aviso que no se puede cargar no
 * justifica romper la pantalla que lo contiene.
 */
@Component({
  selector: 'app-pending-notice',
  imports: [RouterLink],
  template: `
    @if (pendiente(); as p) {
      <aside
        class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-card
               border-l-4 border-accent bg-accent-soft px-5 py-4 text-sm"
        role="status"
      >
        <span class="text-ink">
          Tenés tareas sin responder en <b>{{ p.titulo }}</b>.
        </span>
        <a class="btn btn-primary" [routerLink]="['/portal/evaluacion', p.evaluacion_id]">
          Responder
        </a>
      </aside>
    }
  `,
})
export class PendingNotice {
  private readonly http = inject(HttpClient);

  protected readonly pendiente = signal<{ evaluacion_id: number; titulo: string | null } | null>(
    null,
  );

  constructor() {
    this.http
      .get<{ pendiente: { evaluacion_id: number; titulo: string | null } | null }>(
        '/api/portal/aviso',
      )
      .subscribe({
        next: (r) => this.pendiente.set(r.pendiente),
        error: () => this.pendiente.set(null),
      });
  }
}
