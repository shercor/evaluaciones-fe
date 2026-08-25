import { Component, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ThemeService } from './core/theme/theme.service';

@Component({
  imports: [RouterOutlet],
  selector: 'app-root',
  styleUrl: './app.scss',
  templateUrl: './app.html',
})
export class App {
  /**
   * Se inyecta acá para que la apariencia guardada se aplique al arrancar.
   *
   * Si solo la instanciara el selector, el tema no existiría hasta entrar a
   * una pantalla que lo muestre, y el login se vería siempre con el tema por
   * defecto aunque la persona hubiera elegido otro.
   */
  private readonly apariencia = inject(ThemeService);

  protected readonly title = signal('frontend');
}
