import { Component, computed, inject, input, output, signal } from '@angular/core';
import {
  BorradorHomologacion,
  ColumnaSistema,
  DirectoryService,
  Homologacion as Mapa,
  ResumenHomologacion,
  ResumenImportacion,
} from '../../../../../core/api/directory.service';
import { mensajeDeError } from '../../../../../core/http/api-error';

/**
 * Importar una planilla que no viene con el formato del sistema.
 *
 * Tres momentos, y el orden importa: se sube el archivo y se leen sus
 * columnas, se conecta cada campo del sistema con una de ellas, y se revisa
 * un resumen **antes** de tocar el directorio. El resumen no es un trámite:
 * es el único lugar donde alguien puede darse cuenta de que conectó
 * «Jefe Directo» con el nombre del jefe en vez de con su código, y ahí se ve
 * porque los datos que muestra son los del archivo de verdad.
 *
 * La homologación se puede corregir cuantas veces haga falta: el resumen se
 * puede pedir de nuevo sin volver a subir nada, porque el archivo ya está en
 * el servidor.
 */
@Component({
  selector: 'app-homologacion',
  templateUrl: './homologacion.html',
})
export class Homologacion {
  private readonly directorio = inject(DirectoryService);

  /** Lo decide la pantalla que lo contiene, con la misma casilla que la otra carga. */
  readonly enviarInvitaciones = input(true);

  readonly importada = output<{ resumen: ResumenImportacion; mensaje: string }>();

  protected readonly fase = signal<'archivo' | 'mapa' | 'resumen'>('archivo');
  protected readonly archivo = signal<File | null>(null);
  protected readonly borrador = signal<BorradorHomologacion | null>(null);
  protected readonly columnas = signal<ColumnaSistema[]>([]);
  protected readonly mapa = signal<Mapa>({});
  protected readonly resumen = signal<ResumenHomologacion | null>(null);
  protected readonly sinUsar = signal<string[]>([]);
  protected readonly ocupada = signal(false);
  protected readonly error = signal<string | null>(null);

  /** Obligatorias todavía sin conectar. Mientras haya, no se puede seguir. */
  protected readonly faltantes = computed(() =>
    this.columnas()
      .filter((c) => c.obligatoria && !this.mapa()[c.clave])
      .map((c) => c.etiqueta),
  );

  /**
   * Columnas del archivo conectadas a más de un campo.
   *
   * Se avisa acá y no solo en el servidor porque es un descuido que se comete
   * mientras se elige —dos desplegables seguidos con la misma opción— y verlo
   * al instante evita rehacer el camino entero.
   */
  protected readonly repetidas = computed(() => {
    const usos = new Map<string, string[]>();

    for (const columna of this.columnas()) {
      const origen = this.mapa()[columna.clave];
      if (!origen) continue;

      usos.set(origen, [...(usos.get(origen) ?? []), columna.etiqueta]);
    }

    return [...usos.entries()]
      .filter(([, campos]) => campos.length > 1)
      .map(([origen, campos]) => ({ columna: this.etiquetaDeOrigen(origen), campos }));
  });

  protected readonly puedeSeguir = computed(
    () => this.faltantes().length === 0 && this.repetidas().length === 0,
  );

  /** Solo los campos que se conectaron: son las columnas del resumen. */
  protected readonly columnasMapeadas = computed(() =>
    this.columnas().filter((c) => this.mapa()[c.clave]),
  );

  /**
   * Sucursales y cargos que no existen y va a crear la importación.
   *
   * Se recortan para mostrarlos: en la primera carga de una empresa son la
   * lista entera de sucursales —129 en la nómina de prueba— y ahí el aviso
   * deja de ser un aviso. Lo que importa ver es que no haya variantes del
   * mismo nombre, y para eso alcanza con una muestra más el total.
   */
  private readonly tope = 24;

  protected readonly catalogoNuevo = computed(() => {
    const r = this.resumen();
    if (!r) return null;

    const armar = (nombres: string[]) => ({
      total: nombres.length,
      muestra: nombres.slice(0, this.tope),
      restantes: Math.max(0, nombres.length - this.tope),
    });

    if (r.sucursales_nuevas.length === 0 && r.cargos_nuevos.length === 0) {
      return null;
    }

    return { sucursales: armar(r.sucursales_nuevas), cargos: armar(r.cargos_nuevos) };
  });

  /**
   * Códigos de sucursal o cargo que la planilla usa y no están cargados.
   *
   * Van aparte de los demás avisos porque son los únicos que **bloquean**:
   * con un código suelto no hay con qué crear la sucursal, así que esas filas
   * no entran hasta que se carguen o hasta que la planilla traiga también la
   * columna del nombre.
   */
  protected readonly codigosFaltantes = computed(() => {
    const r = this.resumen();
    if (!r) return null;

    if (r.sucursales_faltantes.length === 0 && r.cargos_faltantes.length === 0) {
      return null;
    }

    return { sucursales: r.sucursales_faltantes, cargos: r.cargos_faltantes };
  });

  protected readonly codigosRepetidos = computed(() =>
    Object.entries(this.resumen()?.codigos_repetidos ?? {}).map(([codigo, veces]) => ({
      codigo,
      veces,
    })),
  );

  // -- Paso 1: el archivo --------------------------------------------

  protected seleccionar(evento: Event): void {
    this.archivo.set((evento.target as HTMLInputElement).files?.[0] ?? null);
    this.error.set(null);
  }

  protected analizar(): void {
    const archivo = this.archivo();
    if (!archivo || this.ocupada()) return;

    this.ocupada.set(true);
    this.error.set(null);

    this.directorio.analizarPlanilla(archivo).subscribe({
      next: (r) => {
        this.ocupada.set(false);
        this.borrador.set(r.data);
        this.columnas.set(r.columnas_sistema);
        this.mapa.set(r.sugerencia);
        this.fase.set('mapa');
      },
      error: (e) => {
        this.ocupada.set(false);
        this.error.set(mensajeDeError(e, 'No se pudo leer la planilla.'));
      },
    });
  }

  // -- Paso 2: conectar las columnas ---------------------------------

  protected conectar(campo: string, evento: Event): void {
    const valor = (evento.target as HTMLSelectElement).value;

    this.mapa.update((m) => ({ ...m, [campo]: valor || null }));
  }

  /** Los ejemplos de la columna elegida, para comprobar que es la correcta. */
  protected ejemplosDe(campo: string): string[] {
    const origen = this.mapa()[campo];
    if (!origen) return [];

    return this.borrador()?.headers.find((h) => h.clave === origen)?.ejemplos ?? [];
  }

  protected etiquetaDeOrigen(clave: string | null): string {
    if (!clave) return '';

    return this.borrador()?.headers.find((h) => h.clave === clave)?.etiqueta ?? clave;
  }

  protected verResumen(): void {
    const borrador = this.borrador();
    if (!borrador || !this.puedeSeguir() || this.ocupada()) return;

    this.ocupada.set(true);
    this.error.set(null);

    this.directorio.resumenHomologacion(borrador.id, this.mapa()).subscribe({
      next: (r) => {
        this.ocupada.set(false);
        this.resumen.set(r.resumen);
        this.sinUsar.set(r.sin_usar);
        this.fase.set('resumen');
      },
      error: (e) => {
        this.ocupada.set(false);
        this.error.set(mensajeDeError(e, 'No se pudo armar el resumen.'));
      },
    });
  }

  // -- Paso 3: importar ----------------------------------------------

  protected volverAlMapa(): void {
    this.fase.set('mapa');
    this.error.set(null);
  }

  protected importar(): void {
    const borrador = this.borrador();
    if (!borrador || this.ocupada()) return;

    this.ocupada.set(true);
    this.error.set(null);

    this.directorio
      .importarHomologada(borrador.id, this.mapa(), this.enviarInvitaciones())
      .subscribe({
        next: (r) => {
          this.ocupada.set(false);
          this.importada.emit({ resumen: r.data, mensaje: r.message });
          this.reiniciar();
        },
        error: (e) => {
          this.ocupada.set(false);
          this.error.set(mensajeDeError(e, 'No se pudo importar la planilla.'));
        },
      });
  }

  /**
   * Tira la planilla subida.
   *
   * Se avisa al servidor a propósito: mientras el borrador exista, la nómina
   * de la empresa está guardada en su disco.
   */
  protected descartar(): void {
    const borrador = this.borrador();

    if (borrador) {
      this.directorio.descartarBorrador(borrador.id).subscribe({
        error: (e) => this.error.set(mensajeDeError(e)),
      });
    }

    this.reiniciar();
  }

  private reiniciar(): void {
    this.fase.set('archivo');
    this.archivo.set(null);
    this.borrador.set(null);
    this.mapa.set({});
    this.resumen.set(null);
    this.sinUsar.set([]);
  }
}
