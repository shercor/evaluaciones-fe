import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { debounceTime } from 'rxjs';
import {
  MetricaMonitoreo,
  PersonaMonitoreo,
  ResultsService,
} from '../../../core/api/results.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Avance del proceso: quién respondió y quién no.
 *
 * Las métricas van como medidores y no como gráfico: son proporciones
 * independientes entre sí, no una serie que se compare.
 */
@Component({
  selector: 'app-monitoring',
  imports: [ReactiveFormsModule],
  templateUrl: './monitoring.html',
})
export class Monitoring {
  private readonly api = inject(ResultsService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly evaluacion = signal<string | null>(null);
  protected readonly metricas = signal<MetricaMonitoreo[]>([]);
  protected readonly personas = signal<PersonaMonitoreo[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly filtros = this.fb.nonNullable.group({
    nombre: [''],
    estado: [''],
  });

  constructor() {
    this.api.monitoreo(this.id).subscribe({
      next: (d) => {
        this.evaluacion.set(d.evaluacion);
        this.metricas.set(d.metricas);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar el monitoreo.'));
        this.cargando.set(false);
      },
    });

    this.buscarPersonas();
    this.filtros.valueChanges.pipe(debounceTime(350)).subscribe(() => this.buscarPersonas());
  }

  protected buscarPersonas(): void {
    this.api.monitoreoPersonas(this.id, this.filtros.getRawValue()).subscribe({
      next: (r) => this.personas.set(r.data),
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected verResultados(p: PersonaMonitoreo): void {
    this.router.navigate(['/admin/evaluaciones', this.id, 'persona', p.id]);
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones']);
  }

  protected completo(p: PersonaMonitoreo): boolean {
    return (p.estado ?? '').toLowerCase().startsWith('complet');
  }
}
