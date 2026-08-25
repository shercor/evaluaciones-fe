import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ResultsService } from '../../../core/api/results.service';
import { PanelResultados, ResultsPanel } from '../../../shared/results-panel/results-panel';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Mis resultados.
 *
 * Mismo componente de panel que usa administración: cambia quién mira, no lo
 * que se dibuja.
 */
@Component({
  selector: 'app-mis-resultados',
  imports: [ResultsPanel, RouterLink],
  template: `
    <header class="encabezado">
      <div>
        <h1 class="pagina-titulo">Mis resultados</h1>
        <p class="pagina-intro">
          Así te evaluaron. Los comentarios que recibiste son anónimos.
        </p>
      </div>
      <div class="acciones-encabezado">
        <a class="btn btn-quiet" routerLink="/portal">Volver</a>
      </div>
    </header>

    @if (cargando()) {
      <p class="vacio">Cargando tus resultados…</p>
    } @else if (error()) {
      <p class="alert alert-error" role="alert">{{ error() }}</p>
    } @else if (datos(); as d) {
      <app-results-panel [datos]="d" />
    }
  `,
})
export class MisResultados {
  private readonly api = inject(ResultsService);
  private readonly ruta = inject(ActivatedRoute);

  protected readonly datos = signal<PanelResultados | null>(null);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    const id = Number(this.ruta.snapshot.paramMap.get('id'));
    this.api.misResultados(id).subscribe({
      next: (d) => { this.datos.set(d); this.cargando.set(false); },
      error: (e) => { this.error.set(mensajeDeError(e, 'No se pudieron cargar tus resultados.')); this.cargando.set(false); },
    });
  }
}
