import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { debounceTime } from 'rxjs';
import { DirectoryService, ElementoCatalogo } from '../../../../../core/api/directory.service';
import { Participante, WizardService } from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';

/**
 * Paso 3 · Depurar participantes.
 *
 * La pantalla más densa del asistente. Permite excluir personas —arrastrando o
 * no a toda su cadena de supervisados— y corregir cargo, sucursal y supervisor
 * dentro del proceso, sin tocar el directorio real.
 */
@Component({
  selector: 'app-step-participantes',
  imports: [ReactiveFormsModule],
  templateUrl: './step-participantes.html',
})
export class StepParticipantes {
  private readonly api = inject(WizardService);
  private readonly directorio = inject(DirectoryService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly participantes = signal<Participante[]>([]);
  protected readonly meta = signal<{ current_page: number; last_page: number; total: number; participando: number } | null>(null);
  protected readonly cambiosPendientes = signal(0);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);
  protected readonly ocupado = signal<number | null>(null);

  protected readonly sucursales = signal<ElementoCatalogo[]>([]);
  protected readonly cargos = signal<ElementoCatalogo[]>([]);

  /** Confirmación al excluir a alguien que tiene gente a cargo. */
  protected readonly confirmandoCascada = signal<Participante | null>(null);

  protected readonly editando = signal<Participante | null>(null);
  protected readonly supervisoresPosibles = signal<{ id: number; nombre: string }[]>([]);
  protected readonly guardando = signal(false);
  protected readonly errorFormulario = signal<string | null>(null);

  protected readonly filtros = this.fb.nonNullable.group({
    search: [''],
    branch_office_id: [''],
    job_position_id: [''],
    participate: [''],
  });

  protected readonly formulario = this.fb.nonNullable.group({
    branch_office_id: [''],
    job_position_id: [''],
    supervisor_id: [''],
  });

  private pagina = 1;

  constructor() {
    this.cargarCatalogos();
    this.buscar();

    this.filtros.valueChanges.pipe(debounceTime(350)).subscribe(() => {
      this.pagina = 1;
      this.buscar();
    });
  }

  protected buscar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.api
      .participantes(this.id, { ...this.filtros.getRawValue(), page: this.pagina })
      .subscribe({
        next: (r) => {
          this.participantes.set(r.data);
          this.meta.set(r.meta);
          this.cambiosPendientes.set(r.cambios_pendientes);
          this.cargando.set(false);
        },
        error: (e) => {
          this.error.set(mensajeDeError(e, 'No se pudo cargar el padrón.'));
          this.cargando.set(false);
        },
      });
  }

  protected irAPagina(n: number): void {
    this.pagina = n;
    this.buscar();
  }

  protected limpiarFiltros(): void {
    this.filtros.reset({ search: '', branch_office_id: '', job_position_id: '', participate: '' });
  }

  // -- Participación ------------------------------------------------

  /**
   * Al desactivar a alguien con gente a cargo hay que preguntar: dejar a sus
   * supervisados sin jefe dentro del proceso los volvería huérfanos.
   */
  protected alternarParticipacion(p: Participante): void {
    if (p.participate && p.supervisados > 0) {
      this.confirmandoCascada.set(p);
      return;
    }

    this.aplicarParticipacion(p, !p.participate, false);
  }

  protected confirmarCascada(conSupervisados: boolean): void {
    const p = this.confirmandoCascada();
    if (!p) return;

    this.confirmandoCascada.set(null);
    this.aplicarParticipacion(p, false, conSupervisados);
  }

  protected cancelarCascada(): void {
    this.confirmandoCascada.set(null);
  }

  private aplicarParticipacion(p: Participante, participar: boolean, conSupervisados: boolean): void {
    this.ocupado.set(p.user_id);
    this.error.set(null);

    this.api.cambiarParticipacion(this.id, p.user_id, participar, conSupervisados).subscribe({
      next: (r) => {
        this.ocupado.set(null);
        this.aviso.set(r.message);
        this.cambiosPendientes.set(r.cambios_pendientes);
        this.buscar();
      },
      error: (e) => {
        this.ocupado.set(null);
        this.error.set(mensajeDeError(e));
      },
    });
  }

  // -- Edición ------------------------------------------------------

  protected abrirEdicion(p: Participante): void {
    this.editando.set(p);
    this.errorFormulario.set(null);
    this.formulario.reset({
      branch_office_id: String(p.sucursal?.id ?? ''),
      job_position_id: String(p.cargo?.id ?? ''),
      supervisor_id: String(p.supervisor?.id ?? ''),
    });

    this.buscarSupervisores('');
  }

  protected buscarSupervisores(termino: string): void {
    const p = this.editando();
    if (!p) return;

    this.api.buscarSupervisores(this.id, termino, p.user_id).subscribe({
      next: (r) => this.supervisoresPosibles.set(r.data),
    });
  }

  protected alBuscarSupervisor(evento: Event): void {
    this.buscarSupervisores((evento.target as HTMLInputElement).value);
  }

  protected cerrarEdicion(): void {
    this.editando.set(null);
  }

  protected guardarEdicion(): void {
    const p = this.editando();
    if (!p || this.guardando()) return;

    this.guardando.set(true);
    this.errorFormulario.set(null);

    const v = this.formulario.getRawValue();

    this.api
      .editarParticipante(this.id, {
        user_id: p.user_id,
        branch_office_id: v.branch_office_id ? Number(v.branch_office_id) : null,
        job_position_id: v.job_position_id ? Number(v.job_position_id) : null,
        supervisor_id: v.supervisor_id ? Number(v.supervisor_id) : null,
      })
      .subscribe({
        next: (r) => {
          this.guardando.set(false);
          this.editando.set(null);
          this.aviso.set(r.message);
          this.cambiosPendientes.set(r.cambios_pendientes);
          this.buscar();
        },
        error: (e) => {
          this.guardando.set(false);
          this.errorFormulario.set(mensajeDeError(e, 'No se pudo guardar.'));
        },
      });
  }

  // -- Navegación ---------------------------------------------------

  protected continuar(): void {
    this.router.navigate(['/admin/evaluaciones/asistente', this.id, 'previsualizacion']);
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones/asistente', this.id, 'sucursales']);
  }

  protected descartarAviso(): void {
    this.aviso.set(null);
  }

  private cargarCatalogos(): void {
    this.directorio.listarCatalogo('sucursales').subscribe({ next: (r) => this.sucursales.set(r.data) });
    this.directorio.listarCatalogo('cargos').subscribe({ next: (r) => this.cargos.set(r.data) });
  }
}
