import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Previsualizacion, WizardService } from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';
import { Avatar } from '../../../../../shared/avatar/avatar';

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
  imports: [FormsModule, Avatar],
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

  /**
   * ¿Hay algo que enviar?
   *
   * Con huérfanos no se envía: son personas que no evalúan a nadie y a las que
   * nadie evalúa, así que el proceso saldría con tareas vacías. La intranet
   * también deshabilita el botón en ese caso.
   *
   * Corrigiendo un proceso ya creado, además hace falta que haya cambios: sin
   * ellos se reenviaría un padrón idéntico al que la API ya tiene.
   */
  /**
   * Estado del tablero de equipos.
   *
   * `equipoElegido` no guarda el equipo sino su jefatura: la lista se
   * reordena y se filtra, y un índice apuntaría a otro equipo en cuanto eso
   * pasa.
   */
  protected readonly busquedaEquipo = signal('');
  protected readonly ordenEquipos = signal<'tamano' | 'nombre'>('tamano');
  private readonly jefeElegido = signal<number | null>(null);

  protected readonly equiposVisibles = computed(() => {
    const texto = this.busquedaEquipo().trim().toLocaleLowerCase('es');
    const orden = this.ordenEquipos();

    const lista = (this.datos()?.grupos ?? []).filter(
      (g) => !texto || g.supervisor.nombre.toLocaleLowerCase('es').includes(texto),
    );

    return [...lista].sort((a, b) =>
      orden === 'nombre'
        ? a.supervisor.nombre.localeCompare(b.supervisor.nombre, 'es')
        : b.integrantes.length - a.integrantes.length ||
          a.supervisor.nombre.localeCompare(b.supervisor.nombre, 'es'),
    );
  });

  /** El elegido, o el primero visible si el anterior quedó fuera del filtro. */
  protected readonly equipoElegido = computed(() => {
    const visibles = this.equiposVisibles();
    const jefe = this.jefeElegido();

    return visibles.find((g) => g.supervisor.user_id === jefe) ?? visibles[0] ?? null;
  });

  protected elegirEquipo(g: { supervisor: { user_id: number } }): void {
    this.jefeElegido.set(g.supervisor.user_id);
  }

  protected readonly motivoBloqueo = computed<string | null>(() => {
    const d = this.datos();

    if (!d) {
      return null;
    }

    if (!d.permite_editar) {
      // Sin la etiqueta entre paréntesis habría que concordar en género con
      // el estado («finalizada», «cancelado»), y no siempre coincide.
      return `Este proceso ya no admite cambios en el padrón (estado: ${d.estado?.toLocaleLowerCase('es')}).`;
    }

    if (d.grupos.length === 0) {
      return 'No se formó ningún grupo. Volvé al paso anterior y revisá que los participantes tengan supervisores dentro del proceso.';
    }

    if (d.huerfanos.length > 0) {
      return 'Hay participantes sin ninguna relación de evaluación. Resolvelos arriba antes de enviar.';
    }

    if (!d.es_alta && d.cambios_pendientes === 0) {
      return 'No hay cambios que enviar: el padrón está igual que la última vez.';
    }

    return null;
  });

  protected readonly textoEnvio = computed(() => {
    const d = this.datos();
    return d?.es_alta ? 'Enviar y crear el proceso' : 'Guardar los cambios';
  });

  protected deshacerCambios(): void {
    this.api.deshacerCambios(this.id).subscribe({
      next: (r) => {
        this.aviso.set(r.message);
        this.cargar();
      },
      error: (e) => this.error.set(mensajeDeError(e, 'No se pudieron deshacer los cambios.')),
    });
  }

  protected excluirHuerfanos(): void {
    const huerfanos = this.datos()?.huerfanos ?? [];
    if (huerfanos.length === 0) return;

    this.api
      .excluirHuerfanos(
        this.id,
        huerfanos.map((h) => h.user_id),
      )
      .subscribe({
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
