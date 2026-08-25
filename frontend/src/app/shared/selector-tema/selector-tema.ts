import { Component, ElementRef, HostListener, inject, signal } from '@angular/core';
import { Modo, TEMAS, ThemeService } from '../../core/theme/theme.service';

/**
 * Botón de apariencia y su panel.
 *
 * El panel se cierra al pulsar fuera y con Escape, porque un panel que solo
 * se cierra con su propio botón obliga a apuntar a un blanco chico para salir
 * de algo que se abrió sin querer.
 */
@Component({
  selector: 'app-selector-tema',
  templateUrl: './selector-tema.html',
  host: { class: 'relative' },
})
export class SelectorTema {
  private readonly anfitrion = inject(ElementRef<HTMLElement>);
  protected readonly apariencia = inject(ThemeService);

  protected readonly temas = TEMAS;
  protected readonly abierto = signal(false);

  protected readonly modos: { id: Modo; nombre: string }[] = [
    { id: 'claro', nombre: 'Claro' },
    { id: 'oscuro', nombre: 'Oscuro' },
    { id: 'sistema', nombre: 'Sistema' },
  ];

  protected alternar(): void {
    this.abierto.update((a) => !a);
  }

  protected cerrar(): void {
    this.abierto.set(false);
  }

  @HostListener('document:click', ['$event'])
  protected alPulsarFuera(evento: MouseEvent): void {
    if (this.abierto() && !this.anfitrion.nativeElement.contains(evento.target)) {
      this.cerrar();
    }
  }

  @HostListener('document:keydown.escape')
  protected alEscapar(): void {
    this.cerrar();
  }
}
