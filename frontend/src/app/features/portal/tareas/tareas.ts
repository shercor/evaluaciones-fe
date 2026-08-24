import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormularioTareas, PortalService } from '../../../core/api/portal.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Mis tareas dentro de una evaluación.
 *
 * Vienen agrupadas por formulario —autoevaluación, jefe directo, pares…— y
 * dentro de cada uno, a quiénes hay que evaluar desde esa perspectiva.
 */
@Component({
  selector: 'app-portal-tareas',
  imports: [RouterLink],
  templateUrl: './tareas.html',
})
export class PortalTareas {
  private readonly api = inject(PortalService);
  private readonly ruta = inject(ActivatedRoute);

  protected readonly evaluacionId = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly evaluacion = signal<{ titulo: string | null; descripcion: string | null; estado: string | null; grupo: string | null } | null>(null);
  protected readonly formularios = signal<FormularioTareas[]>([]);
  protected readonly resumen = signal<{ total: number; completadas: number; pendientes: number } | null>(null);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    this.cargar();
  }

  protected cargar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.api.tareas(this.evaluacionId).subscribe({
      next: (r) => {
        this.evaluacion.set(r.evaluacion);
        this.formularios.set(r.formularios);
        this.resumen.set(r.resumen);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar tus tareas.'));
        this.cargando.set(false);
      },
    });
  }

  protected porcentaje(): number {
    const r = this.resumen();
    return r && r.total > 0 ? Math.round((r.completadas / r.total) * 100) : 0;
  }

  protected cerrada(): boolean {
    return this.evaluacion()?.estado === 'finalizado';
  }
}
