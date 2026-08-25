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
      <aside class="aviso-pendiente" role="status">
        <span>
          Tenés tareas sin responder en <b>{{ p.titulo }}</b>.
        </span>
        <a class="btn btn-primary" [routerLink]="['/portal/evaluacion', p.evaluacion_id]">
          Responder
        </a>
      </aside>
    }
  `,
  styles: `
    .aviso-pendiente {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
      background: var(--accent-soft);
      border-left: 3px solid var(--accent);
      border-radius: var(--radius);
      padding: 0.85rem 1.1rem;
      margin-bottom: 1.4rem;
      font-size: 0.92rem;
    }
    .aviso-pendiente .btn {
      text-decoration: none;
      font-size: 0.85rem;
      padding: 0.4rem 0.9rem;
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
