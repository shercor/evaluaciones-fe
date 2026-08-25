import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormularioPrevisualizado, GroupsService } from '../../../core/api/groups.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Qué se le va a preguntar a la gente.
 *
 * Sirve para dos cosas con la misma pantalla: revisar una plantilla antes de
 * elegirla, y revisar una evaluación ya configurada. El tipo llega por la ruta.
 */
@Component({
  selector: 'app-forms-preview',
  templateUrl: './forms-preview.html',
})
export class FormsPreview {
  private readonly api = inject(GroupsService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly tipo: 'evaluacion' | 'plantilla' =
    (this.ruta.snapshot.data['tipo'] as 'evaluacion' | 'plantilla') ?? 'evaluacion';
  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly formularios = signal<FormularioPrevisualizado[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  /** Qué formulario se está mirando. Son varios y se ven de a uno. */
  protected readonly activo = signal(0);

  constructor() {
    const peticion =
      this.tipo === 'plantilla'
        ? this.api.previsualizarPlantilla(this.id)
        : this.api.previsualizarEvaluacion(this.id);

    peticion.subscribe({
      next: (r) => {
        this.formularios.set(r.formularios);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar la previsualización.'));
        this.cargando.set(false);
      },
    });
  }

  protected get titulo(): string {
    return this.tipo === 'plantilla' ? 'Preguntas de la plantilla' : 'Preguntas de la evaluación';
  }

  protected elegir(indice: number): void {
    this.activo.set(indice);
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones']);
  }

  protected totalPreguntas(): number {
    return this.formularios().reduce((n, f) => n + f.total_preguntas, 0);
  }
}
