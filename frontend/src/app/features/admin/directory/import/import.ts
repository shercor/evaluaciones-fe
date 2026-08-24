import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  DirectoryService,
  FilaImportacion,
  ResumenImportacion,
} from '../../../../core/api/directory.service';
import { mensajeDeError } from '../../../../core/http/api-error';

/**
 * Carga de la nómina desde planilla.
 *
 * Lo importante de esta pantalla no es subir el archivo, es **explicar el
 * resultado**: qué filas entraron, cuáles se rechazaron y por qué, y de dónde
 * bajar las contraseñas de quien no tiene correo.
 */
@Component({
  selector: 'app-import',
  imports: [RouterLink],
  templateUrl: './import.html',
})
export class Import {
  private readonly directorio = inject(DirectoryService);

  protected readonly archivo = signal<File | null>(null);
  protected readonly enviarInvitaciones = signal(true);
  protected readonly subiendo = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly resultado = signal<ResumenImportacion | null>(null);
  protected readonly mensaje = signal<string | null>(null);
  protected readonly filas = signal<FilaImportacion[]>([]);
  protected readonly filtroFilas = signal<'todas' | 'failed'>('todas');

  protected readonly historial = signal<ResumenImportacion[]>([]);

  constructor() {
    this.cargarHistorial();
  }

  protected seleccionar(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    this.archivo.set(input.files?.[0] ?? null);
    this.error.set(null);
  }

  protected alternarInvitaciones(evento: Event): void {
    this.enviarInvitaciones.set((evento.target as HTMLInputElement).checked);
  }

  protected subir(): void {
    const archivo = this.archivo();
    if (!archivo || this.subiendo()) return;

    this.subiendo.set(true);
    this.error.set(null);
    this.resultado.set(null);
    this.filas.set([]);

    this.directorio.importar(archivo, this.enviarInvitaciones()).subscribe({
      next: (r) => {
        this.subiendo.set(false);
        this.resultado.set(r.data);
        this.mensaje.set(r.message);
        this.cargarDetalle(r.data.id);
        this.cargarHistorial();
      },
      error: (e) => {
        this.subiendo.set(false);
        this.error.set(mensajeDeError(e, 'No se pudo procesar la planilla.'));
      },
    });
  }

  protected cargarDetalle(id: number): void {
    const filtro = this.filtroFilas() === 'failed' ? 'failed' : undefined;

    this.directorio.detalleImportacion(id, filtro).subscribe({
      next: (r) => {
        this.resultado.set(r.import);
        this.filas.set(r.rows);
      },
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected cambiarFiltro(filtro: 'todas' | 'failed'): void {
    this.filtroFilas.set(filtro);
    const actual = this.resultado();
    if (actual) this.cargarDetalle(actual.id);
  }

  protected verImportacion(resumen: ResumenImportacion): void {
    this.filtroFilas.set('todas');
    this.cargarDetalle(resumen.id);
    this.mensaje.set(null);
  }

  protected urlPlantilla(): string {
    return this.directorio.urlPlantilla();
  }

  protected urlContrasenas(id: number): string {
    return this.directorio.urlContrasenas(id);
  }

  protected etiquetaResultado(fila: FilaImportacion): string {
    return { created: 'Creada', updated: 'Actualizada', failed: 'Rechazada' }[fila.outcome];
  }

  private cargarHistorial(): void {
    this.directorio.listarImportaciones().subscribe({
      next: (r) => this.historial.set(r.data),
    });
  }
}
