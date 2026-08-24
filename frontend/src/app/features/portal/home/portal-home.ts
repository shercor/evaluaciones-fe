import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';
import {
  EvaluacionEnCurso,
  EvaluacionFinalizada,
  PortalService,
} from '../../../core/api/portal.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Inicio del portal: mis evaluaciones.
 *
 * Lo primero es la que está abierta ahora, porque es lo único que requiere
 * acción. Las finalizadas quedan debajo, para consulta.
 */
@Component({
  selector: 'app-portal-home',
  imports: [RouterLink],
  templateUrl: './portal-home.html',
})
export class PortalHome {
  private readonly auth = inject(AuthService);
  private readonly api = inject(PortalService);

  protected readonly user = this.auth.user;
  protected readonly enCurso = signal<EvaluacionEnCurso | null>(null);
  protected readonly finalizadas = signal<EvaluacionFinalizada[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    this.api.misEvaluaciones().subscribe({
      next: (r) => {
        this.enCurso.set(r.en_curso);
        this.finalizadas.set(r.finalizadas);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar tus evaluaciones.'));
        this.cargando.set(false);
      },
    });
  }
}
