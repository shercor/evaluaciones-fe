import { Component, input } from '@angular/core';

/**
 * El círculo de una persona: su foto, o sus iniciales si no tiene.
 *
 * Existe porque el mismo círculo aparece en seis pantallas y hasta ahora cada
 * una escribía las iniciales por su cuenta. Al agregar las fotos eso obligaba
 * a repetir el mismo `@if` seis veces, y basta que uno se olvide para que la
 * misma persona salga con foto en una vista y con iniciales en otra.
 *
 * El respaldo son las iniciales y no una silueta gris: distinguen a quién se
 * está mirando, que es justo lo que una silueta no hace.
 */
@Component({
  selector: 'app-avatar',
  // El envoltorio del componente tiene que desaparecer de la maquetación: sin
  // esto queda como un elemento más de la fila —encogible, y sin las clases de
  // tamaño, que van en la foto— y la foto sale ovalada, 82 px de ancho por 96
  // de alto. Con `contents`, quien se acomoda en la fila es la foto misma.
  styles: ':host { display: contents; }',
  template: `
    @if (foto()) {
      <!-- Texto alternativo vacío a propósito: el nombre siempre está
           escrito al lado, y repetirlo obliga al lector de pantalla a
           oírlo dos veces. -->
      <img [class]="'avatar-mini avatar-foto ' + clases()" [src]="foto()" alt="" />
    } @else {
      <span [class]="'avatar-mini ' + clases()" aria-hidden="true">{{ iniciales() }}</span>
    }
  `,
})
export class Avatar {
  readonly foto = input<string | null>(null);
  readonly iniciales = input('');

  /** Variantes del mismo círculo: `avatar-chico`, `avatar-grande`, `avatar-tenue`. */
  readonly clases = input('');
}
