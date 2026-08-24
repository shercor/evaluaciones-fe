import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { debounceTime } from 'rxjs';
import {
  DirectoryService,
  ElementoCatalogo,
  FiltrosPersonas,
  Paginacion,
} from '../../../../core/api/directory.service';
import { User } from '../../../../core/auth/user.model';
import { mensajeDeError } from '../../../../core/http/api-error';

/**
 * Listado y edición de las personas del directorio.
 *
 * Filtros, orden y paginación los resuelve el backend: la nómina puede tener
 * miles de filas y traerlas todas al navegador para filtrar sería insostenible.
 */
@Component({
  selector: 'app-people',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './people.html',
  styleUrl: './people.scss',
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

  protected readonly filtros = this.fb.nonNullable.group({
    search: [''],
    branch_office_id: [''],
    job_position_id: [''],
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

  private pagina = 1;

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
      role: '',
      active: '',
    });
  }

  // -- Edición ------------------------------------------------------

  protected abrirNueva(): void {
    this.errorFormulario.set(null);
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
    this.editando.set(null);
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
        this.guardando.set(false);
        this.editando.set(null);
        this.aviso.set(respuesta.message);
        this.buscar();
      },
      error: (e) => {
        this.guardando.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo guardar.'));
      },
    });
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
