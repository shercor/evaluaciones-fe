import { Component, computed, effect, input, signal } from '@angular/core';

/**
 * Cifra que sube desde cero hasta su valor.
 *
 * No es adorno: el movimiento lleva la vista al número justo cuando la
 * pantalla termina de cargar, que es cuando importa leerlo. Se queda quieto
 * si la persona pidió menos animación en su sistema.
 */
@Component({
  selector: 'app-contador',
  template: '{{ mostrado() }}',
  host: { class: 'tabular-nums' },
})
export class Contador {
  readonly valor = input.required<number>();
  /** Duración del recuento, en milisegundos. */
  readonly duracion = input(900);

  private readonly actual = signal(0);
  protected readonly mostrado = computed(() => this.actual().toLocaleString('es'));

  constructor() {
    effect((onCleanup) => {
      const destino = this.valor();

      const sinMovimiento =
        typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

      if (sinMovimiento || destino <= 0) {
        this.actual.set(destino);
        return;
      }

      const inicio = performance.now();
      const duracion = this.duracion();
      let cuadro = 0;

      const paso = (ahora: number) => {
        const avance = Math.min((ahora - inicio) / duracion, 1);
        // Desaceleración cúbica: arranca rápido y frena al llegar, que es
        // como se lee un número que «aterriza» en su valor.
        const suave = 1 - Math.pow(1 - avance, 3);

        this.actual.set(Math.round(destino * suave));

        if (avance < 1) {
          cuadro = requestAnimationFrame(paso);
        }
      };

      cuadro = requestAnimationFrame(paso);
      onCleanup(() => cancelAnimationFrame(cuadro));
    });
  }
}
