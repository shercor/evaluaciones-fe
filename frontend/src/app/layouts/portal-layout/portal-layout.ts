import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { PendingNotice } from '../../shared/pending-notice/pending-notice';
import { AuthService } from '../../core/auth/auth.service';

/**
 * Portal del colaborador.
 *
 * Sin menú lateral: son pocas pantallas y se entra a responder, no a navegar.
 */
@Component({
  selector: 'app-portal-layout',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, PendingNotice],
  templateUrl: './portal-layout.html',
})
export class PortalLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly user = this.auth.user;
  protected readonly saliendo = signal(false);

  protected salir(): void {
    this.saliendo.set(true);

    this.auth.logout().subscribe({
      next: () => this.router.navigateByUrl('/login'),
      error: () => {
        this.auth.clear();
        this.router.navigateByUrl('/login');
      },
    });
  }
}
