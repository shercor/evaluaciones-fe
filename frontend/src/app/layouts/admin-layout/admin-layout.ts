import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { SelectorTema } from '../../shared/selector-tema/selector-tema';
import { PendingNotice } from '../../shared/pending-notice/pending-notice';
import { AuthService } from '../../core/auth/auth.service';
import { Avatar } from '../../shared/avatar/avatar';

interface ItemMenu {
  ruta: string;
  etiqueta: string;
  /** Todavía no construido: se muestra apagado en vez de esconderse, para
   *  que se vea hacia dónde va el módulo. */
  proximamente?: boolean;
}

/**
 * Portal de administración.
 *
 * El menú ya lista los sectores de los hitos siguientes, marcados como
 * pendientes: es más honesto que un menú que crece de a poco sin avisar.
 */
@Component({
  selector: 'app-admin-layout',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, PendingNotice, SelectorTema, Avatar],
  templateUrl: './admin-layout.html',
})
export class AdminLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly user = this.auth.user;
  protected readonly saliendo = signal(false);

  protected readonly menu: ItemMenu[] = [
    { ruta: '/admin', etiqueta: 'Inicio' },
    { ruta: '/admin/evaluaciones', etiqueta: 'Evaluaciones' },
    { ruta: '/admin/directorio', etiqueta: 'Directorio' },
    { ruta: '/admin/grupos', etiqueta: 'Grupos' },
  ];

  protected salir(): void {
    this.saliendo.set(true);

    this.auth.logout().subscribe({
      next: () => this.router.navigateByUrl('/login'),
      // Aunque el backend falle, la sesión local se limpia igual: dejar a
      // alguien «dentro» tras pedir salir es peor que un error.
      error: () => {
        this.auth.clear();
        this.router.navigateByUrl('/login');
      },
    });
  }
}
