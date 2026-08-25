import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ResultsService } from '../../../core/api/results.service';
import { PanelResultados, ResultsPanel } from '../../../shared/results-panel/results-panel';
import { mensajeDeError } from '../../../core/http/api-error';

/** Resultados de una persona, vistos por administración. */
@Component({
  selector: 'app-person-results',
  imports: [ResultsPanel],
  template: `
    <header class="encabezado">
      <div>
        <h1 class="pagina-titulo">Resultados individuales</h1>
        <p class="pagina-intro">El detalle de una persona dentro del proceso.</p>
      </div>
      <div class="acciones-encabezado">
        <button type="button" class="btn btn-quiet" (click)="volver()">Volver</button>
      </div>
    </header>

    @if (cargando()) {
      <p class="vacio">Cargando…</p>
    } @else if (error()) {
      <p class="alert alert-error" role="alert">{{ error() }}</p>
    } @else if (datos(); as d) {
      <app-results-panel [datos]="d" />
    }
  `,
})
export class PersonResults {
  private readonly api = inject(ResultsService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));
  private readonly userId = Number(this.ruta.snapshot.paramMap.get('userId'));

  protected readonly datos = signal<PanelResultados | null>(null);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    this.api.personaAdmin(this.id, this.userId).subscribe({
      next: (d) => { this.datos.set(d); this.cargando.set(false); },
      error: (e) => { this.error.set(mensajeDeError(e, 'No se pudieron cargar los resultados.')); this.cargando.set(false); },
    });
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones', this.id, 'monitoreo']);
  }
}
