import { Component, computed, inject, signal, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { debounceTime, map } from 'rxjs';
import {
  DirectoryService,
  ElementoCatalogo,
  FiltrosPersonas,
  Paginacion,
} from '../../../../core/api/directory.service';
import { User } from '../../../../core/auth/user.model';
import { mensajeDeError } from '../../../../core/http/api-error';
import { Avatar } from '../../../../shared/avatar/avatar';
import {
  BuscadorPersonas,
  PersonaSugerida,
} from '../../../../shared/buscador-personas/buscador-personas';
import { Skeleton } from '../../../../shared/skeleton/skeleton';

/**
 * Listado y edición de las personas del directorio.
 *
 * Filtros, orden y paginación los resuelve el backend: la nómina puede tener
 * miles de filas y traerlas todas al navegador para filtrar sería insostenible.
 */
@Component({
  selector: 'app-people',
  imports: [ReactiveFormsModule, RouterLink, Skeleton, BuscadorPersonas, Avatar],
  templateUrl: './people.html',
})
export class People {
  private readonly directorio = inject(DirectoryService);
  private readonly fb = inject(FormBuilder);

  protected readonly personas = signal<User[]>([]);
  protected readonly supervisados = signal<Record<string, number>>({});
  protected readonly meta = signal<Paginacion | null>(null);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);

  protected readonly sucursales = signal<ElementoCatalogo[]>([]);
  protected readonly cargos = signal<ElementoCatalogo[]>([]);

  /** Contraseña temporal recién generada. Se muestra una sola vez. */
  protected readonly contrasenaTemporal = signal<{ persona: string; clave: string } | null>(null);

  protected readonly editando = signal<User | null>(null);
  protected readonly guardando = signal(false);
  protected readonly errorFormulario = signal<string | null>(null);

  /** Lo que muestra el círculo del formulario: la foto guardada, o la recién
   *  elegida de una persona que todavía no existe en el servidor. */
  protected readonly fotoFormulario = signal<string | null>(null);
  protected readonly subiendoFoto = signal(false);

  /** Foto elegida antes de que la persona exista: se sube al crearla. */
  private fotoPendiente: File | null = null;

  /** `blob:` de la vista previa. Hay que devolverlo o queda ocupando memoria. */
  private vistaPrevia: string | null = null;

  protected readonly filtros = this.fb.nonNullable.group({
    search: [''],
    branch_office_id: [''],
    job_position_id: [''],
    supervisor_id: [''],
    role: [''],
    active: [''],
  });

  protected readonly formulario = this.fb.nonNullable.group({
    external_code: [''],
    name: ['', [Validators.required]],
    lastname: [''],
    email: ['', [Validators.email]],
    role: ['collaborator', [Validators.required]],
    branch_office_id: [''],
    job_position_id: [''],
    supervisor_id: [''],
  });

  /**
   * Iniciales de lo que hay escrito en el formulario, para el círculo.
   *
   * Se calculan acá y no se toman de `editando()` porque en una persona nueva
   * todavía no hay nada del servidor: así el círculo se llena a medida que se
   * teclea el nombre, en vez de quedar vacío hasta guardar.
   */
  private readonly valoresFormulario = toSignal(this.formulario.valueChanges, {
    initialValue: this.formulario.getRawValue(),
  });

  protected readonly inicialesFormulario = computed(() => {
    const { name, lastname } = this.valoresFormulario();

    return ((name?.[0] ?? '') + (lastname?.[0] ?? '')).toUpperCase();
  });

  /** Para poder vaciarlo desde «Limpiar»: el control guarda su propio texto. */
  private readonly filtroSupervisor = viewChild<BuscadorPersonas>('filtroSupervisor');

  private pagina = 1;

  /**
   * Tope de la foto, el mismo que aplica el servidor.
   *
   * Se comprueba también acá y no solo allá porque por encima del techo de
   * PHP —16 MB— el cuerpo de la petición se descarta antes de que Laravel
   * llegue a validarlo, y lo que vuelve es un aviso en HTML que no hay forma
   * de mostrarle a nadie. De paso, evita subir 20 MB para nada.
   */
  private readonly topeFoto = 8 * 1024 * 1024;

  constructor() {
    this.cargarCatalogos();
    this.buscar();

    // Se espera a que la persona deje de escribir antes de consultar, en vez
    // de disparar una petición por tecla.
    this.filtros.valueChanges.pipe(debounceTime(350)).subscribe(() => {
      this.pagina = 1;
      this.buscar();
    });
  }

  protected buscar(): void {
    this.cargando.set(true);
    this.error.set(null);

    const filtros: FiltrosPersonas = {
      ...this.filtros.getRawValue(),
      page: this.pagina,
    } as FiltrosPersonas;

    this.directorio.listarPersonas(filtros).subscribe({
      next: (respuesta) => {
        this.personas.set(respuesta.data);
        this.supervisados.set(respuesta.supervisees_count);
        this.meta.set(respuesta.meta);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar el directorio.'));
        this.cargando.set(false);
      },
    });
  }

  protected irAPagina(numero: number): void {
    this.pagina = numero;
    this.buscar();
  }

  protected limpiarFiltros(): void {
    this.filtros.reset({
      search: '',
      branch_office_id: '',
      job_position_id: '',
      supervisor_id: '',
      role: '',
      active: '',
    });

    this.filtroSupervisor()?.limpiar();
  }

  // -- Supervisor: filtro y formulario -------------------------------

  /** Quiénes supervisan a alguien. Alimenta el filtro del listado. */
  protected readonly consultarSupervisores = (termino: string) =>
    this.directorio.buscarSupervisores(termino).pipe(map((r) => r.data));

  /**
   * Candidatos para el formulario. Excluye a la persona que se edita y a su
   * cadena, que crearían un ciclo; de una persona nueva (`id === 0`) no hay
   * nada que excluir.
   */
  protected readonly consultarPosiblesSupervisores = (termino: string) => {
    const id = this.editando()?.id;

    return this.directorio
      .buscarPosiblesSupervisores(termino, id && id > 0 ? id : undefined)
      .pipe(map((r) => r.data));
  };

  /** El supervisor que ya tiene, para que el buscador abra mostrándolo. */
  protected readonly supervisorActual = computed<PersonaSugerida | null>(() => {
    const s = this.editando()?.supervisor;

    return s ? { id: s.id, nombre: s.full_name, codigo: null } : null;
  });

  protected filtrarPorSupervisor(persona: PersonaSugerida | null): void {
    this.filtros.patchValue({ supervisor_id: persona ? String(persona.id) : '' });
  }

  protected asignarSupervisor(persona: PersonaSugerida | null): void {
    this.formulario.patchValue({ supervisor_id: persona ? String(persona.id) : '' });
  }

  // -- Edición ------------------------------------------------------

  protected abrirNueva(): void {
    this.errorFormulario.set(null);
    this.olvidarFotoElegida();
    this.fotoFormulario.set(null);
    this.editando.set({ id: 0 } as User);
    this.formulario.reset({
      external_code: '',
      name: '',
      lastname: '',
      email: '',
      role: 'collaborator',
      branch_office_id: '',
      job_position_id: '',
      supervisor_id: '',
    });
  }

  protected abrirEdicion(persona: User): void {
    this.errorFormulario.set(null);
    this.olvidarFotoElegida();
    this.fotoFormulario.set(persona.avatar_url);
    this.editando.set(persona);
    this.formulario.reset({
      external_code: persona.email?.endsWith('@interno.local') ? '' : '',
      name: persona.name,
      lastname: persona.lastname ?? '',
      email: persona.email?.endsWith('@interno.local') ? '' : persona.email,
      role: persona.role === 'super_admin' ? 'admin' : persona.role,
      branch_office_id: String(persona.branch_office?.id ?? ''),
      job_position_id: String(persona.job_position?.id ?? ''),
      supervisor_id: String(persona.supervisor?.id ?? ''),
    });
  }

  protected cerrarFormulario(): void {
    this.olvidarFotoElegida();
    this.editando.set(null);
  }

  // -- Foto de perfil -----------------------------------------------

  /**
   * Llega el archivo elegido en el diálogo del sistema.
   *
   * Si la persona ya existe, la foto se sube en el momento y no espera al
   * botón «Guardar»: es un archivo, no un campo del formulario, y guardarla
   * junto al resto obligaría a mandar el formulario entero para cambiarla.
   * Si todavía no existe, se muestra y se sube apenas se cree, que es cuando
   * hay un id contra el cual subirla.
   */
  protected elegirFoto(evento: Event): void {
    const control = evento.target as HTMLInputElement;
    const archivo = control.files?.[0];

    // Se vacía para que elegir dos veces el mismo archivo vuelva a disparar
    // el evento; si no, corregir un error eligiendo lo mismo no hace nada.
    control.value = '';

    if (!archivo) return;

    if (archivo.size > this.topeFoto) {
      this.errorFormulario.set('La foto no puede pesar más de 8 MB.');

      return;
    }

    const persona = this.editando();

    if (!persona || persona.id === 0) {
      this.olvidarFotoElegida();
      this.vistaPrevia = URL.createObjectURL(archivo);
      this.fotoPendiente = archivo;
      this.fotoFormulario.set(this.vistaPrevia);

      return;
    }

    this.subirFoto(persona.id, archivo);
  }

  protected quitarFoto(): void {
    const persona = this.editando();

    if (!persona) return;

    if (persona.id === 0) {
      this.olvidarFotoElegida();
      this.fotoFormulario.set(null);

      return;
    }

    this.subiendoFoto.set(true);
    this.errorFormulario.set(null);

    this.directorio.quitarFoto(persona.id).subscribe({
      next: (r) => {
        this.subiendoFoto.set(false);
        this.fotoFormulario.set(null);
        this.refrescarFila(r.data);
      },
      error: (e) => {
        this.subiendoFoto.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo quitar la foto.'));
      },
    });
  }

  private subirFoto(id: number, archivo: File): void {
    this.subiendoFoto.set(true);
    this.errorFormulario.set(null);

    this.directorio.subirFoto(id, archivo).subscribe({
      next: (r) => {
        this.subiendoFoto.set(false);
        this.fotoFormulario.set(r.data.avatar_url);
        this.refrescarFila(r.data);
      },
      error: (e) => {
        this.subiendoFoto.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo subir la foto.'));
      },
    });
  }

  /**
   * Actualiza la fila del listado sin volver a pedir la página.
   *
   * No se toca `editando()`: reemplazarla haría que el buscador de supervisor
   * recibiera un valor inicial nuevo y perdiera lo que la persona acababa de
   * elegir sin guardar.
   */
  private refrescarFila(persona: User): void {
    this.personas.update((filas) => filas.map((f) => (f.id === persona.id ? persona : f)));
  }

  private olvidarFotoElegida(): void {
    if (this.vistaPrevia) {
      URL.revokeObjectURL(this.vistaPrevia);
      this.vistaPrevia = null;
    }

    this.fotoPendiente = null;
  }

  protected guardar(): void {
    if (this.formulario.invalid || this.guardando()) {
      this.formulario.markAllAsTouched();
      return;
    }

    this.guardando.set(true);
    this.errorFormulario.set(null);

    const valores = this.formulario.getRawValue();
    const datos: Record<string, unknown> = {
      external_code: valores.external_code || null,
      name: valores.name,
      lastname: valores.lastname || null,
      email: valores.email || null,
      role: valores.role,
      branch_office_id: valores.branch_office_id ? Number(valores.branch_office_id) : null,
      job_position_id: valores.job_position_id ? Number(valores.job_position_id) : null,
      supervisor_id: valores.supervisor_id ? Number(valores.supervisor_id) : null,
    };

    const persona = this.editando();
    const esNueva = !persona || persona.id === 0;

    const peticion = esNueva
      ? this.directorio.crearPersona(datos as never)
      : this.directorio.actualizarPersona(persona.id, datos);

    peticion.subscribe({
      next: (respuesta) => {
        // Recién ahora existe el id contra el que subir la foto elegida antes
        // de crear a la persona.
        if (esNueva && this.fotoPendiente) {
          this.directorio.subirFoto(respuesta.data.id, this.fotoPendiente).subscribe({
            next: () => this.terminarGuardado(respuesta.message),
            error: () =>
              this.terminarGuardado(
                `${respuesta.message} La foto no se pudo subir: cargala desde «Editar».`,
              ),
          });

          return;
        }

        this.terminarGuardado(respuesta.message);
      },
      error: (e) => {
        this.guardando.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo guardar.'));
      },
    });
  }

  private terminarGuardado(mensaje: string): void {
    this.guardando.set(false);
    this.cerrarFormulario();
    this.aviso.set(mensaje);
    this.buscar();
  }

  // -- Acciones por fila --------------------------------------------

  protected alternarActiva(persona: User): void {
    this.directorio.alternarActiva(persona.id).subscribe({
      next: (r) => {
        this.aviso.set(r.message);
        this.buscar();
      },
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected generarContrasena(persona: User): void {
    this.directorio.generarContrasenaTemporal(persona.id).subscribe({
      next: (r) =>
        this.contrasenaTemporal.set({ persona: persona.full_name, clave: r.temporary_password }),
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected reenviarInvitacion(persona: User): void {
    this.directorio.reenviarInvitacion(persona.id).subscribe({
      next: (r) => this.aviso.set(r.message),
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected cerrarContrasena(): void {
    this.contrasenaTemporal.set(null);
  }

  protected descartarAviso(): void {
    this.aviso.set(null);
  }

  /** Cuántas personas dependen de alguien, contando toda la cadena. */
  protected cuentaSupervisados(persona: User): number {
    return this.supervisados()[String(persona.id)] ?? 0;
  }

  protected sinCorreoReal(persona: User): boolean {
    return !persona.email || persona.email.endsWith('@interno.local');
  }

  private cargarCatalogos(): void {
    this.directorio.listarCatalogo('sucursales').subscribe({
      next: (r) => this.sucursales.set(r.data),
    });
    this.directorio.listarCatalogo('cargos').subscribe({
      next: (r) => this.cargos.set(r.data),
    });
  }
}
