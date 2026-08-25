import { Component, input } from '@angular/core';

/**
 * Esqueleto de carga.
 *
 * Reemplaza al «Cargando…» porque anticipa la forma de lo que viene: la
 * página no salta cuando llegan los datos, y la espera se siente más corta
 * aunque dure lo mismo.
 */
@Component({
  selector: 'app-skeleton',
  template: `
    @switch (tipo()) {
      @case ('tabla') {
        <div class="tabla-scroll p-4">
          @for (fila of filas(); track fila) {
            <div class="flex items-center gap-4 border-b border-rule py-3 last:border-b-0">
              <div class="esqueleto size-8 shrink-0 rounded-full"></div>
              <div class="esqueleto h-3 flex-1" [style.max-width.%]="fila % 2 ? 42 : 30"></div>
              <div class="esqueleto h-3 w-24"></div>
              <div class="esqueleto h-3 w-16"></div>
            </div>
          }
        </div>
      }
      @case ('cifras') {
        <div class="cifras">
          @for (fila of filas(); track fila) {
            <div class="rounded-card border border-rule bg-surface p-5">
              <div class="esqueleto mb-3 h-2.5 w-24"></div>
              <div class="esqueleto mb-3 h-9 w-20"></div>
              <div class="esqueleto h-1.5 w-full"></div>
            </div>
          }
        </div>
      }
      @default {
        <div class="bloque">
          <div class="esqueleto mb-4 h-4 w-48"></div>
          @for (fila of filas(); track fila) {
            <div class="esqueleto mb-3 h-3" [style.max-width.%]="fila % 3 ? 88 : 62"></div>
          }
        </div>
      }
    }
  `,
})
export class Skeleton {
  readonly tipo = input<'tabla' | 'cifras' | 'texto'>('texto');
  readonly cantidad = input<number>(4);

  protected filas() {
    return Array.from({ length: this.cantidad() }, (_, i) => i + 1);
  }
}
