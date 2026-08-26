import { Component, computed, inject, signal, viewChild } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { debounceTime, map } from 'rxjs';
import { DirectoryService, ElementoCatalogo } from '../../../../../core/api/directory.service';
import {
  ListadoParticipantes,
  Participante,
  WizardService,
} from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';
import { Avatar } from '../../../../../shared/avatar/avatar';
import {
  BuscadorPersonas,
  PersonaSugerida,
} from '../../../../../shared/buscador-personas/buscador-personas';

/**
 * Paso 3 · Depurar participantes.
 *
 * La pantalla más densa del asistente. Permite excluir personas —arrastrando o
 * no a toda su cadena de supervisados— y corregir cargo, sucursal y supervisor
 * dentro del proceso, sin tocar el directorio real.
 */
@Component({
  selector: 'app-step-participantes',
  imports: [ReactiveFormsModule, BuscadorPersonas, Avatar],
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
  /** El mismo tipo que devuelve el servicio; no conviene repetirlo a mano. */
  protected readonly meta = signal<ListadoParticipantes['meta'] | null>(null);
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
  protected readonly guardando = signal(false);
  protected readonly errorFormulario = signal<string | null>(null);

  protected readonly filtros = this.fb.nonNullable.group({
    search: [''],
    branch_office_id: [''],
    job_position_id: [''],
    supervisor_id: [''],
    participate: [''],
  });

  /**
   * La consulta que alimenta el buscador del filtro.
   *
   * Va como propiedad y no como método porque el componente la recibe como
   * entrada: si fuera `this.buscarSupervisoresDelPadron.bind(this)` en la
   * plantilla, se crearía una función nueva en cada detección de cambios.
   */
  protected readonly consultarSupervisores = (termino: string) =>
    this.api.buscarSupervisoresDelPadron(this.id, termino).pipe(map((r) => r.data));

  /** Columna y sentido del orden. */
  protected readonly orden = signal<{ campo: string; desc: boolean }>({
    campo: 'nombre',
    desc: false,
  });

  protected ordenarPor(campo: string): void {
    // Volver a pulsar la misma columna invierte; cambiar de columna arranca
    // ascendente, que es lo que se espera al ordenar por primera vez.
    this.orden.update((a) =>
      a.campo === campo ? { campo, desc: !a.desc } : { campo, desc: false },
    );
    this.pagina = 1;
    this.buscar();
  }

  /** Flecha de la cabecera: solo la columna activa la muestra. */
  protected senal(campo: string): string {
    const a = this.orden();
    return a.campo === campo ? (a.desc ? ' ↓' : ' ↑') : '';
  }

  protected deshacerCambios(): void {
    this.error.set(null);

    this.api.deshacerCambios(this.id).subscribe({
      next: (r) => {
        this.aviso.set(r.message);
        this.buscar(true);
      },
      error: (e) => this.error.set(mensajeDeError(e, 'No se pudieron deshacer los cambios.')),
    });
  }

  protected readonly formulario = this.fb.nonNullable.group({
    branch_office_id: [''],
    job_position_id: [''],
    supervisor_id: [''],
  });

  /** Para poder vaciarlo desde «Limpiar»: el control guarda su propio texto. */
  private readonly filtroSupervisor = viewChild<BuscadorPersonas>('filtroSupervisor');

  private pagina = 1;

  constructor() {
    this.cargarCatalogos();
    this.buscar();

    this.filtros.valueChanges.pipe(debounceTime(350)).subscribe(() => {
      this.pagina = 1;
      this.buscar();
    });
  }

  /**
   * @param  silencioso  no vacía la tabla mientras llega la respuesta. Se usa
   *   después de editar a alguien, donde la lista ya está en pantalla y
   *   parpadear se siente como una recarga.
   */
  protected buscar(silencioso = false): void {
    if (!silencioso) {
      this.cargando.set(true);
    }
    this.error.set(null);

    this.api
      .participantes(this.id, {
        ...this.filtros.getRawValue(),
        page: this.pagina,
        sort: this.orden().campo,
        direction: this.orden().desc ? 'desc' : 'asc',
      })
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

  /**
   * El buscador entrega la persona; el filtro viaja como id.
   *
   * No hace falta llamar a `buscar()`: tocar el control dispara el
   * `valueChanges` del formulario, que es por donde se relee siempre.
   */
  protected filtrarPorSupervisor(persona: PersonaSugerida | null): void {
    this.filtros.patchValue({ supervisor_id: persona ? String(persona.id) : '' });
  }

  protected limpiarFiltros(): void {
    this.filtros.reset({
      search: '',
      branch_office_id: '',
      job_position_id: '',
      supervisor_id: '',
      participate: '',
    });

    this.filtroSupervisor()?.limpiar();
  }

  // -- Participación ------------------------------------------------

  /**
   * Con gente a cargo siempre se pregunta, en los dos sentidos.
   *
   * Al excluir, porque sus supervisados quedarían sin jefe dentro del proceso.
   * Al incluir, porque si se los sacó en cascada hay que poder devolverlos
   * igual de rápido: uno por uno son tantos clics como personas tenga.
   */
  protected alternarParticipacion(p: Participante): void {
    if (p.supervisados > 0) {
      this.confirmandoCascada.set(p);
      return;
    }

    this.aplicarParticipacion(p, !p.participate, false);
  }

  protected confirmarCascada(conSupervisados: boolean): void {
    const p = this.confirmandoCascada();
    if (!p) return;

    this.confirmandoCascada.set(null);
    this.aplicarParticipacion(p, !p.participate, conSupervisados);
  }

  protected cancelarCascada(): void {
    this.confirmandoCascada.set(null);
  }

  /**
   * Aplica el cambio y **parchea la tabla en el lugar**.
   *
   * Antes se volvía a pedir el listado entero, lo que vaciaba la tabla y se
   * sentía como una recarga de página. La respuesta ya trae todo lo necesario
   * —a quiénes alcanzó y el total recalculado—, así que no hay motivo para
   * volver al servidor: la fila cambia sola cuando termina el guardado.
   */
  private aplicarParticipacion(
    p: Participante,
    participar: boolean,
    conSupervisados: boolean,
  ): void {
    this.ocupado.set(p.user_id);
    this.error.set(null);

    this.api.cambiarParticipacion(this.id, p.user_id, participar, conSupervisados).subscribe({
      next: (r) => {
        const alcanzados = new Set(r.afectados);

        this.participantes.update((filas) =>
          filas.map((f) => (alcanzados.has(f.user_id) ? { ...f, participate: participar } : f)),
        );

        this.meta.update((m) => (m ? { ...m, participando: r.participando } : m));
        this.cambiosPendientes.set(r.cambios_pendientes);
        this.aviso.set(r.message);
        this.ocupado.set(null);
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
  }

  /**
   * El supervisor que ya tiene, para que el buscador abra mostrándolo.
   *
   * El listado no trae el código de esa persona —no lo necesita para pintar la
   * tabla—, así que va en `null`: el nombre alcanza para reconocer a quién
   * está asignado, y en cuanto se escribe algo las sugerencias sí lo traen.
   */
  protected readonly supervisorActual = computed<PersonaSugerida | null>(() => {
    const s = this.editando()?.supervisor;

    return s ? { id: s.id, nombre: s.nombre, codigo: null } : null;
  });

  /**
   * Candidatos para el editor: gente del padrón, sin la persona que se edita
   * —nadie puede ser su propio supervisor—.
   */
  protected readonly consultarCandidatos = (termino: string) =>
    this.api
      .buscarSupervisores(this.id, termino, this.editando()?.user_id ?? 0)
      .pipe(map((r) => r.data));

  protected asignarSupervisor(persona: PersonaSugerida | null): void {
    this.formulario.patchValue({ supervisor_id: persona ? String(persona.id) : '' });
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
          // Cambiar el supervisor de alguien altera los conteos «a cargo» de
          // otras filas, así que acá sí hace falta releer; en silencio.
          this.buscar(true);
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
    this.directorio
      .listarCatalogo('sucursales')
      .subscribe({ next: (r) => this.sucursales.set(r.data) });
    this.directorio.listarCatalogo('cargos').subscribe({ next: (r) => this.cargos.set(r.data) });
  }
}
