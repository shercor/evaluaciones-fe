import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute, Router, RouterLink, RouterOutlet } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

interface Paso {
  numero: number;
  etiqueta: string;
  ruta: string;
  /** Necesita una evaluación ya creada para tener sentido. */
  requiereEvaluacion: boolean;
}

/**
 * Marco del asistente: la barra de pasos y el contenedor de cada uno.
 *
 * El paso actual se deduce de la ruta, no de una variable de estado. En la
 * intranet viajaba una bandera `?creating=1` por toda la secuencia, y cada
 * acción tenía que comprobarla para saber si estaba en alta o en edición.
 */
@Component({
  selector: 'app-wizard-shell',
  imports: [RouterOutlet, RouterLink],
  templateUrl: './wizard-shell.html',
  styleUrl: './wizard-shell.scss',
})
export class WizardShell {
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly pasos: Paso[] = [
    { numero: 1, etiqueta: 'Definir el proceso', ruta: 'definir', requiereEvaluacion: false },
    { numero: 2, etiqueta: 'Sucursales', ruta: 'sucursales', requiereEvaluacion: true },
    { numero: 3, etiqueta: 'Participantes', ruta: 'participantes', requiereEvaluacion: true },
    { numero: 4, etiqueta: 'Revisar y enviar', ruta: 'previsualizacion', requiereEvaluacion: true },
  ];

  /** El id existe desde que se completa el paso 1. */
  protected readonly evaluacionId = toSignal(
    this.ruta.paramMap.pipe(map((p) => (p.get('id') ? Number(p.get('id')) : null))),
    { initialValue: null },
  );

  protected readonly actual = toSignal(
    this.router.events.pipe(map(() => this.rutaActual())),
    { initialValue: this.rutaActual() },
  );

  protected readonly numeroActual = computed(() => {
    const ruta = this.actual();
    return this.pasos.find((p) => p.ruta === ruta)?.numero ?? 1;
  });

  protected enlaceDe(paso: Paso): string[] {
    const id = this.evaluacionId();
    return id ? ['/admin/evaluaciones/asistente', String(id), paso.ruta] : ['/admin/evaluaciones/asistente'];
  }

  protected estaHabilitado(paso: Paso): boolean {
    return !paso.requiereEvaluacion || this.evaluacionId() !== null;
  }

  private rutaActual(): string {
    const partes = this.router.url.split('?')[0].split('/');
    return partes[partes.length - 1];
  }
}
