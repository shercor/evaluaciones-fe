import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Grupo, GroupsService } from '../../../core/api/groups.service';
import { mensajeDeError } from '../../../core/http/api-error';
import { Skeleton } from '../../../shared/skeleton/skeleton';

/**
 * Grupos de evaluación.
 *
 * Un grupo define a qué conjunto de personas apunta un proceso, y de él
 * depende la numeración de períodos: el período 2 de «General» es
 * independiente del período 2 de «Jefaturas».
 */
@Component({
  selector: 'app-groups',
  imports: [ReactiveFormsModule, Skeleton],
  templateUrl: './groups.html',
})
export class Groups {
  private readonly api = inject(GroupsService);
  private readonly fb = inject(FormBuilder);

  protected readonly grupos = signal<Grupo[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);

  protected readonly editando = signal<Grupo | null>(null);
  protected readonly guardando = signal(false);
  protected readonly errorFormulario = signal<string | null>(null);

  protected readonly formulario = this.fb.nonNullable.group({
    nombre: ['', [Validators.required, Validators.maxLength(255)]],
    descripcion: ['', [Validators.maxLength(255)]],
  });

  constructor() {
    this.cargar();
  }

  protected cargar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.api.listar().subscribe({
      next: (r) => {
        this.grupos.set(r.data);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar los grupos.'));
        this.cargando.set(false);
      },
    });
  }

  protected abrirNuevo(): void {
    this.errorFormulario.set(null);
    this.editando.set({ id: 0 } as Grupo);
    this.formulario.reset({ nombre: '', descripcion: '' });
  }

  protected abrirEdicion(g: Grupo): void {
    this.errorFormulario.set(null);
    this.editando.set(g);
    this.formulario.reset({ nombre: g.nombre ?? '', descripcion: g.descripcion ?? '' });
  }

  protected cerrar(): void {
    this.editando.set(null);
  }

  protected guardar(): void {
    if (this.formulario.invalid || this.guardando()) {
      this.formulario.markAllAsTouched();
      return;
    }

    this.guardando.set(true);
    this.errorFormulario.set(null);

    const { nombre, descripcion } = this.formulario.getRawValue();
    const g = this.editando();

    const peticion =
      !g || g.id === 0
        ? this.api.crear(nombre, descripcion || null)
        : this.api.actualizar(g.id, nombre, descripcion || null);

    peticion.subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.editando.set(null);
        this.aviso.set(r.message);
        this.cargar();
      },
      error: (e) => {
        this.guardando.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo guardar el grupo.'));
      },
    });
  }

  protected alternar(g: Grupo): void {
    this.api.alternar(g.id, !g.activo).subscribe({
      next: (r) => {
        this.aviso.set(r.message);
        this.cargar();
      },
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected descartarAviso(): void {
    this.aviso.set(null);
  }
}
