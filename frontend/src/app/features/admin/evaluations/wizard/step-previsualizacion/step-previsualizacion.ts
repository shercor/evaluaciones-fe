import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { Previsualizacion, WizardService } from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';

/**
 * Paso 4 · Revisar los grupos y enviar.
 *
 * Muestra cómo quedó armada la estructura: quién evalúa a quién. Los
 * **huérfanos** —los que no tienen jefe dentro del proceso y tampoco gente a
 * cargo— quedarían sin evaluar a nadie y sin ser evaluados, así que se listan
 * aparte para excluirlos.
 *
 * Sin ningún grupo formado no se deja enviar: una evaluación 360 sin
 * estructura no tiene sentido.
 */
@Component({
  selector: 'app-step-previsualizacion',
  templateUrl: './step-previsualizacion.html',
})
export class StepPrevisualizacion {
  private readonly api = inject(WizardService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly datos = signal<Previsualizacion | null>(null);
  protected readonly cargando = signal(true);
  protected readonly enviando = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);
  protected readonly confirmandoEnvio = signal(false);

  constructor() {
    this.cargar();
  }

  protected cargar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.api.previsualizacion(this.id).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo armar la previsualización.'));
        this.cargando.set(false);
      },
    });
  }

  protected excluirHuerfanos(): void {
    const huerfanos = this.datos()?.huerfanos ?? [];
    if (huerfanos.length === 0) return;

    this.api.excluirHuerfanos(this.id, huerfanos.map((h) => h.user_id)).subscribe({
      next: (r) => {
        this.aviso.set(r.message);
        this.cargar();
      },
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected pedirEnvio(): void {
    this.confirmandoEnvio.set(true);
  }

  protected cancelarEnvio(): void {
    this.confirmandoEnvio.set(false);
  }

  protected enviar(): void {
    this.confirmandoEnvio.set(false);
    this.enviando.set(true);
    this.error.set(null);

    this.api.enviar(this.id).subscribe({
      next: (r) => {
        this.enviando.set(false);
        this.router.navigate(['/admin/evaluaciones'], {
          state: { mensaje: r.message },
        });
      },
      error: (e) => {
        this.enviando.set(false);
        this.error.set(mensajeDeError(e, 'No se pudo enviar el padrón.'));
      },
    });
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones/asistente', this.id, 'participantes']);
  }

  protected descartarAviso(): void {
    this.aviso.set(null);
  }
}
