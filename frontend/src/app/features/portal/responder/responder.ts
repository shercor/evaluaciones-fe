import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { DetalleTarea, PortalService, Pregunta } from '../../../core/api/portal.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Responder una tarea.
 *
 * Las preguntas llegan agrupadas por categoría. Las de tipo `selection` son
 * una escala de 1 a `rango`; el resto se responden escribiendo.
 *
 * Las respuestas se guardan todas juntas al final, que es como las recibe la
 * API. Por eso se avisa antes de salir con cambios sin guardar.
 */
@Component({
  selector: 'app-portal-responder',
  templateUrl: './responder.html',
})
export class PortalResponder {
  private readonly api = inject(PortalService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly taskId = Number(this.ruta.snapshot.paramMap.get('id'));

  /**
   * De qué evaluación viene, para poder devolverla al listado de tareas. La
   * tarea sola no lo dice, así que el enlace la trae en la URL.
   */
  protected readonly evaluacionId = this.ruta.snapshot.queryParamMap.get('evaluacion');

  protected readonly tarea = signal<DetalleTarea | null>(null);
  protected readonly cerrada = signal(false);
  protected readonly cargando = signal(true);
  protected readonly guardando = signal(false);
  protected readonly error = signal<string | null>(null);

  /** pregunta_id → respuesta elegida. */
  protected readonly respuestas = signal<Map<number, string | number>>(new Map());

  protected readonly totalPreguntas = computed(
    () => this.tarea()?.categorias.reduce((n, c) => n + c.preguntas.length, 0) ?? 0,
  );

  protected readonly obligatoriasSinResponder = computed(() => {
    const t = this.tarea();
    if (!t) return 0;

    let faltan = 0;
    for (const categoria of t.categorias) {
      for (const p of categoria.preguntas) {
        const valor = this.respuestas().get(p.id);
        if (!p.opcional && (valor === undefined || valor === '')) faltan++;
      }
    }
    return faltan;
  });

  constructor() {
    this.api.tarea(this.taskId).subscribe({
      next: (r) => {
        this.tarea.set(r.data);
        this.cerrada.set(r.cerrada);

        // Si ya había respondido, se precargan sus respuestas para que pueda
        // revisarlas o corregirlas.
        const previas = new Map<number, string | number>();
        for (const c of r.data.categorias) {
          for (const p of c.preguntas) {
            if (p.respuesta !== null && p.respuesta !== undefined) {
              previas.set(p.id, p.respuesta);
            }
          }
        }
        this.respuestas.set(previas);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar la tarea.'));
        this.cargando.set(false);
      },
    });
  }

  protected escala(p: Pregunta): number[] {
    return Array.from({ length: p.rango ?? 5 }, (_, i) => i + 1);
  }

  protected esSeleccion(p: Pregunta): boolean {
    return p.tipo === 'selection';
  }

  protected valorDe(p: Pregunta): string | number | undefined {
    return this.respuestas().get(p.id);
  }

  protected elegir(p: Pregunta, valor: string | number): void {
    this.respuestas.update((m) => new Map(m).set(p.id, valor));
  }

  protected alEscribir(p: Pregunta, evento: Event): void {
    this.elegir(p, (evento.target as HTMLTextAreaElement).value);
  }

  protected guardar(): void {
    if (this.guardando() || this.cerrada()) return;

    if (this.obligatoriasSinResponder() > 0) {
      this.error.set(
        `Te faltan ${this.obligatoriasSinResponder()} respuestas obligatorias.`,
      );
      return;
    }

    this.guardando.set(true);
    this.error.set(null);

    const payload = [...this.respuestas()].map(([pregunta_id, respuesta]) => ({
      pregunta_id,
      respuesta,
    }));

    this.api.responder(this.taskId, payload).subscribe({
      next: () => {
        this.guardando.set(false);
        this.volver();
      },
      error: (e) => {
        this.guardando.set(false);
        this.error.set(mensajeDeError(e, 'No se pudieron guardar tus respuestas.'));
      },
    });
  }

  protected volver(): void {
    this.router.navigate(
      this.evaluacionId ? ['/portal/evaluacion', this.evaluacionId] : ['/portal'],
    );
  }
}
