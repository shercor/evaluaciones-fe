import { Component, computed, inject, signal, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  DirectoryService,
  FilaImportacion,
  ResultadoFila,
  ResumenImportacion,
} from '../../../../core/api/directory.service';
import { mensajeDeError } from '../../../../core/http/api-error';
import { Homologacion } from './homologacion/homologacion';

/** Los dos caminos de carga, que son dos asistentes de largo distinto. */
type Modo = 'formato-propio' | 'homologar';

/**
 * Carga de la nómina desde planilla.
 *
 * Dos cosas importan acá y ninguna es subir el archivo.
 *
 * La primera es **saber dónde se está**. Antes esta pantalla numeraba sus
 * propias secciones —«1 · Elegí el archivo», «2 · Resultado»— mientras la
 * homologación, adentro, corría sus tres pasos sin decirlo: dos progresos
 * distintos en la misma vista y ninguno completo. Ahora hay una sola barra de
 * cuatro pasos y los dos caminos la recorren entera.
 *
 * Que la recorran entera es lo segundo que cambió, y no es cosmético. El
 * formato propio importaba de una sola vez, sin resumen previo, y era el
 * único que podía dar de baja gente sin haber mostrado antes a cuánta.
 * Ahora los dos pasan por el mismo ensayo; con el formato del sistema el paso
 * de las columnas se completa solo, porque se llaman igual que las del
 * sistema y no hay nada que preguntar.
 *
 * La segunda es **explicar el resultado**: qué entró, qué se rechazó y por
 * qué, quién quedó de baja, y de dónde bajar las contraseñas de quien no tiene
 * correo.
 */
@Component({
  selector: 'app-import',
  imports: [RouterLink, Homologacion],
  templateUrl: './import.html',
})
export class Import {
  private readonly directorio = inject(DirectoryService);

  /**
   * Los dos caminos de carga. El de homologar es secundario a propósito: si
   * la planilla ya viene con el formato del sistema, homologarla es trabajo
   * de más y una oportunidad más de equivocarse.
   */
  protected readonly modo = signal<Modo>('formato-propio');

  protected readonly enviarInvitaciones = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly resultado = signal<ResumenImportacion | null>(null);
  protected readonly mensaje = signal<string | null>(null);
  protected readonly filas = signal<FilaImportacion[]>([]);
  protected readonly filtroFilas = signal<'todas' | ResultadoFila>('todas');

  protected readonly historial = signal<ResumenImportacion[]>([]);

  /**
   * El componente de homologación, para leerle en qué paso está.
   *
   * La barra de pasos vive acá porque el paso 1 —elegir el archivo— es de los
   * dos caminos, y solo esta pantalla sabe cuál está elegido. La alternativa
   * —que cada uno dibuje su propia barra— es la que había, y daba dos
   * indicadores diciendo cosas distintas.
   */
  private readonly homologador = viewChild(Homologacion);

  /**
   * Los mismos cuatro pasos para los dos caminos.
   *
   * Con el formato del sistema el segundo se completa solo —las columnas se
   * llaman igual que las del sistema, así que no hay nada que preguntar— y la
   * pantalla pasa de largo. Sigue estando en la barra, y marcado como
   * cumplido, porque pasó: lo que no hubo fue trabajo humano.
   */
  protected readonly pasos = ['Planilla', 'Columnas', 'Revisión', 'Resultado'];

  protected readonly pasoActual = computed(() => {
    if (this.resultado()) return this.pasos.length;

    return { archivo: 1, mapa: 2, resumen: 3 }[this.homologador()?.fase() ?? 'archivo'];
  });

  /** Los filtros del detalle, con su cuenta, para no ofrecer los vacíos. */
  protected readonly filtros = computed(() => {
    const r = this.resultado();
    if (!r) return [];

    return [
      { clave: 'todas' as const, etiqueta: 'Todas las filas', cuenta: null },
      { clave: 'created' as const, etiqueta: 'Creadas', cuenta: r.rows_created },
      { clave: 'updated' as const, etiqueta: 'Actualizadas', cuenta: r.rows_updated },
      { clave: 'deactivated' as const, etiqueta: 'Dadas de baja', cuenta: r.rows_deactivated },
      { clave: 'failed' as const, etiqueta: 'Rechazadas', cuenta: r.rows_failed },
      { clave: 'skipped' as const, etiqueta: 'Omitidas', cuenta: r.rows_skipped },
    ].filter((f) => f.cuenta === null || f.cuenta > 0);
  });

  constructor() {
    this.cargarHistorial();
  }

  protected cambiarModo(modo: Modo): void {
    this.modo.set(modo);
    this.error.set(null);
  }

  protected elegirModo(modo: Modo, evento: Event): void {
    if ((evento.target as HTMLInputElement).checked) this.cambiarModo(modo);
  }

  /** Terminó una carga homologada: se muestra igual que cualquier otra. */
  protected recibirImportacion(evento: { resumen: ResumenImportacion; mensaje: string }): void {
    this.resultado.set(evento.resumen);
    this.mensaje.set(evento.mensaje);
    this.filtroFilas.set('todas');
    this.cargarDetalle(evento.resumen.id);
    this.cargarHistorial();
  }

  protected alternarInvitaciones(evento: Event): void {
    this.enviarInvitaciones.set((evento.target as HTMLInputElement).checked);
  }

  /** Volver a empezar sin recargar la pantalla. */
  protected otraCarga(): void {
    this.resultado.set(null);
    this.mensaje.set(null);
    this.filas.set([]);
    this.error.set(null);
  }

  protected cargarDetalle(id: number): void {
    const filtro = this.filtroFilas();

    this.directorio.detalleImportacion(id, filtro === 'todas' ? undefined : filtro).subscribe({
      next: (r) => {
        this.resultado.set(r.import);
        this.filas.set(r.rows);
      },
      error: (e) => this.error.set(mensajeDeError(e)),
    });
  }

  protected cambiarFiltro(filtro: 'todas' | ResultadoFila): void {
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
    return {
      created: 'Creada',
      updated: 'Actualizada',
      failed: 'Rechazada',
      deactivated: 'Dada de baja',
      skipped: 'Omitida',
    }[fila.outcome];
  }

  /**
   * Cómo se pinta cada resultado.
   *
   * Una baja no es un error —el archivo hizo exactamente lo que se le pidió—
   * pero tampoco es un éxito neutro: es lo que hay que revisar. Por eso lleva
   * el ámbar y no el rojo ni el violeta.
   */
  protected chipDe(fila: FilaImportacion): string {
    return {
      created: 'chip-ok',
      updated: 'chip-ok',
      failed: 'chip-error',
      deactivated: 'chip-pendiente',
      skipped: 'chip-neutro',
    }[fila.outcome];
  }

  private cargarHistorial(): void {
    this.directorio.listarImportaciones().subscribe({
      next: (r) => this.historial.set(r.data),
    });
  }
}
