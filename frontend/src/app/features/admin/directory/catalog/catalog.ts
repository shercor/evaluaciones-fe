import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  DirectoryService,
  ElementoCatalogo,
  TipoCatalogo,
} from '../../../../core/api/directory.service';
import { mensajeDeError } from '../../../../core/http/api-error';

/**
 * Sucursales y cargos.
 *
 * Un solo componente para los dos: tienen la misma forma —código, nombre,
 * activo— y duplicarlo solo garantizaría que se desincronicen. El tipo llega
 * por la ruta.
 */
@Component({
  selector: 'app-catalog',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './catalog.html',
})
export class Catalog {
  private readonly directorio = inject(DirectoryService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  protected readonly tipo: TipoCatalogo =
    (this.ruta.snapshot.data['tipo'] as TipoCatalogo) ?? 'sucursales';

  protected readonly elementos = signal<ElementoCatalogo[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);

  protected readonly editando = signal<ElementoCatalogo | null>(null);
  protected readonly guardando = signal(false);
  protected readonly errorFormulario = signal<string | null>(null);

  protected readonly formulario = this.fb.nonNullable.group({
    name: ['', [Validators.required]],
    external_code: [''],
  });

  constructor() {
    this.cargar();
  }

  protected get titulo(): string {
    return this.tipo === 'sucursales' ? 'Sucursales' : 'Cargos';
  }

  protected get singular(): string {
    return this.tipo === 'sucursales' ? 'sucursal' : 'cargo';
  }

  protected get descripcion(): string {
    return this.tipo === 'sucursales'
      ? 'Las sucursales definen qué personas entran en cada evaluación: el proceso arranca eligiéndolas.'
      : 'Los cargos describen la función de cada persona y aparecen en los informes de resultados.';
  }

  protected cargar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.directorio.listarCatalogo(this.tipo).subscribe({
      next: (r) => {
        this.elementos.set(r.data);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar el listado.'));
        this.cargando.set(false);
      },
    });
  }

  protected abrirNuevo(): void {
    this.errorFormulario.set(null);
    this.editando.set({ id: 0 } as ElementoCatalogo);
    this.formulario.reset({ name: '', external_code: '' });
  }

  protected abrirEdicion(elemento: ElementoCatalogo): void {
    this.errorFormulario.set(null);
    this.editando.set(elemento);
    this.formulario.reset({
      name: elemento.name,
      external_code: elemento.external_code ?? '',
    });
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

    const valores = this.formulario.getRawValue();
    const datos = { name: valores.name, external_code: valores.external_code || null };

    const elemento = this.editando();
    const peticion =
      !elemento || elemento.id === 0
        ? this.directorio.crearEnCatalogo(this.tipo, datos)
        : this.directorio.actualizarEnCatalogo(this.tipo, elemento.id, datos);

    peticion.subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.editando.set(null);
        this.aviso.set(r.message);
        this.cargar();
      },
      error: (e) => {
        this.guardando.set(false);
        this.errorFormulario.set(mensajeDeError(e, 'No se pudo guardar.'));
      },
    });
  }

  protected alternar(elemento: ElementoCatalogo): void {
    this.directorio.alternarCatalogo(this.tipo, elemento.id).subscribe({
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
