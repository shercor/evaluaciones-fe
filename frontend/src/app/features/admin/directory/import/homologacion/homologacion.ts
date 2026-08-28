import { Component, computed, inject, input, output, signal } from '@angular/core';
import {
  BorradorHomologacion,
  ColumnaSistema,
  DestinoImportacion,
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
 *
 * En la nómina el resumen tiene que contar dos cosas y no una: qué entra al
 * directorio y qué sale. Lo segundo es lo que puede hacer daño —una planilla
 * parcial con la sincronización puesta desactiva a media empresa— así que la
 * casilla que lo decide vive en este paso, con el número de a cuántas personas
 * afecta al lado y no en otra pantalla.
 *
 * Sirve para los tres destinos —la nómina, las sucursales y los cargos— porque
 * los tres son lo mismo visto desde acá: columnas del archivo que hay que
 * conectar con columnas del sistema. Lo que cambia son los avisos del resumen,
 * y cada uno se muestra cuando el servidor lo manda.
 */
@Component({
  selector: 'app-homologacion',
  templateUrl: './homologacion.html',
})
export class Homologacion {
  private readonly directorio = inject(DirectoryService);

  /** Qué se está cargando. Lo pone la pantalla que lo contiene. */
  readonly destino = input<DestinoImportacion>('nomina');

  /** Lo decide la pantalla que lo contiene, con la misma casilla que la otra carga. */
  readonly enviarInvitaciones = input(true);

  /**
   * La planilla ya viene con el formato del sistema: saltear el paso 2.
   *
   * Es lo que unifica los dos caminos de carga. Antes el formato propio
   * importaba de una sola vez, **sin resumen previo**, y era el único que
   * podía dar de baja gente sin haber mostrado nunca a cuánta. Ahora los dos
   * pasan por el mismo ensayo; la diferencia es que acá las columnas se
   * conectan solas —se llaman igual que las del sistema— y no hay nada que
   * preguntar, así que no se pregunta.
   *
   * Si la sugerencia no alcanza, no se saltea nada: la pantalla se queda en el
   * paso 2 diciendo qué falta. Es lo que pasa cuando alguien elige este camino
   * con una planilla que no tenía el formato.
   */
  readonly autoAvanzar = input(false);

  readonly importada = output<{ resumen: ResumenImportacion; mensaje: string }>();

  /**
   * En cuál de los tres momentos está.
   *
   * Es pública porque la barra de pasos vive en la pantalla que contiene a
   * este componente: el paso 1 —elegir el archivo— es de los dos caminos de
   * carga, y los otros dos son de este. Con la barra acá adentro habría dos
   * indicadores de progreso en la misma pantalla diciendo cosas distintas.
   */
  readonly fase = signal<'archivo' | 'mapa' | 'resumen'>('archivo');
  protected readonly archivo = signal<File | null>(null);
  protected readonly borrador = signal<BorradorHomologacion | null>(null);
  protected readonly columnas = signal<ColumnaSistema[]>([]);
  protected readonly mapa = signal<Mapa>({});
  protected readonly resumen = signal<ResumenHomologacion | null>(null);
  protected readonly sinUsar = signal<string[]>([]);
  protected readonly ocupada = signal(false);
  protected readonly error = signal<string | null>(null);

  /**
   * Dar de baja a quien la planilla no nombra.
   *
   * Arranca apagada y vive acá, en el paso del resumen, y no en el paso del
   * archivo: es la única opción de esta pantalla que puede sacar gente del
   * directorio, y solo se puede decidir con el número de a cuántas personas
   * afecta delante. Ese número recién existe cuando el servidor ensayó la
   * importación.
   */
  protected readonly sincronizarBajas = signal(false);

  /** Se llegó al resumen sin pasar por el paso 2, porque no hizo falta. */
  protected readonly columnasAutomaticas = signal(false);

  /** Los avisos sobre correos, sucursales y cargos solo tienen sentido acá. */
  protected readonly esNomina = computed(() => this.destino() === 'nomina');

  /** Cómo llamar a lo que trae cada fila, para los textos de la pantalla. */
  protected readonly queEsCadaFila = computed(() => {
    switch (this.destino()) {
      case 'sucursales':
        return 'la sucursal';
      case 'cargos':
        return 'el cargo';
      default:
        return 'la persona';
    }
  });

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

  /**
   * Los campos del sistema, agrupados por para qué sirven.
   *
   * Diez desplegables seguidos son una lista y hay que leerlos todos; tres
   * grupos con título se recorren de un vistazo y se sabe cuál saltear. El
   * orden es el del trabajo real: primero quién es la persona, después dónde
   * trabaja, y al final lo que solo trae quien sincroniza bajas.
   *
   * Lo que no encaje en ningún grupo cae en el último, así que agregar una
   * columna al sistema no la deja fuera de la pantalla.
   */
  private readonly orden: { titulo: string; ayuda: string; claves: string[] }[] = [
    {
      titulo: 'Quién es',
      ayuda: 'Lo que identifica a la persona y por dónde se le avisa.',
      claves: ['codigo', 'nombre', 'apellido', 'correo'],
    },
    {
      titulo: 'Dónde trabaja',
      ayuda: 'Todo opcional, pero sin sucursal no entra en ninguna evaluación.',
      claves: ['cargo', 'cargo_codigo', 'sucursal', 'sucursal_codigo', 'codigo_supervisor'],
    },
    {
      titulo: 'Si sigue en la empresa',
      ayuda: 'Solo si tu planilla marca a quien ya no está.',
      claves: ['activo'],
    },
  ];

  protected readonly grupos = computed(() => {
    const columnas = this.columnas();
    const agrupadas = new Set<string>();

    const grupos = this.orden
      .map((grupo) => {
        const campos = columnas.filter((c) => grupo.claves.includes(c.clave));
        campos.forEach((c) => agrupadas.add(c.clave));

        return { ...grupo, campos };
      })
      .filter((g) => g.campos.length > 0);

    const sueltas = columnas.filter((c) => !agrupadas.has(c.clave));

    if (sueltas.length > 0) {
      grupos.push({ titulo: 'Otros datos', ayuda: '', claves: [], campos: sueltas });
    }

    return grupos;
  });

  /**
   * Si vale la pena mostrar los títulos de grupo.
   *
   * Un catálogo tiene dos columnas: ponerles un encabezado encima es ruido
   * con forma de estructura.
   */
  protected readonly agrupar = computed(() => this.columnas().length > 4);

  /** Cuántas se conectaron, para no tener que contarlas a ojo. */
  protected readonly conectadas = computed(
    () => this.columnas().filter((c) => this.mapa()[c.clave]).length,
  );

  protected readonly avance = computed(() => {
    const total = this.columnas().length;

    return total === 0 ? 0 : Math.round((this.conectadas() / total) * 100);
  });

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

    const sucursales = r.sucursales_nuevas ?? [];
    const cargos = r.cargos_nuevos ?? [];

    if (sucursales.length === 0 && cargos.length === 0) {
      return null;
    }

    return { sucursales: armar(sucursales), cargos: armar(cargos) };
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

    const sucursales = r.sucursales_faltantes ?? [];
    const cargos = r.cargos_faltantes ?? [];

    if (sucursales.length === 0 && cargos.length === 0) {
      return null;
    }

    return { sucursales, cargos };
  });

  protected readonly codigosRepetidos = computed(() =>
    Object.entries(this.resumen()?.codigos_repetidos ?? {}).map(([codigo, veces]) => ({
      codigo,
      veces,
    })),
  );

  /** Personas sin casilla de correo: van a quedar con contraseña temporal. */
  protected readonly sinCorreo = computed(() => this.resumen()?.sin_correo ?? 0);

  /**
   * Nombres que aparecen dos veces en la planilla del catálogo.
   *
   * No rompe nada —la segunda fila no cambia nada— pero es la señal de que el
   * archivo trae dos filas para lo mismo, y casi siempre una de las dos está
   * mal escrita.
   */
  protected readonly nombresRepetidos = computed(() =>
    Object.entries(this.resumen()?.nombres_repetidos ?? {}).map(([nombre, veces]) => ({
      nombre,
      veces,
    })),
  );

  /**
   * Cuántas personas entran sin sucursal o sin cargo.
   *
   * Es válido y no bloquea, pero casi siempre es una columna que se olvidaron
   * de conectar: sin este aviso se descubre semanas después, al armar la
   * primera evaluación con medio directorio sin cargo.
   */
  protected readonly sinCatalogo = computed(() => {
    const r = this.resumen();
    if (!r) return null;

    const sinSucursal = r.sin_sucursal ?? 0;
    const sinCargo = r.sin_cargo ?? 0;

    if (sinSucursal === 0 && sinCargo === 0) return null;

    return { sinSucursal, sinCargo, todas: r.filas_validas };
  });

  // -- Lo que sale del directorio ------------------------------------

  /**
   * Qué personas quedan inactivas, y por cuál de los dos motivos.
   *
   * Van juntas porque para quien mira son lo mismo —gente que sale del
   * directorio— pero se cuentan aparte porque se arreglan distinto: las de
   * origen se corrigen en la planilla, las de ausencia se evitan destildando
   * la casilla.
   */
  protected readonly bajas = computed(() => {
    const r = this.resumen();
    if (!r) return null;

    const porOrigen = r.bajas_por_origen ?? 0;
    const porAusencia = this.sincronizarBajas() ? (r.bajas_por_ausencia ?? 0) : 0;

    if (porOrigen === 0 && porAusencia === 0) return null;

    return { porOrigen, porAusencia, total: porOrigen + porAusencia };
  });

  /** Cuántas se darían de baja si se marcara la casilla. Siempre, esté o no. */
  protected readonly ausentes = computed(() => this.resumen()?.bajas_por_ausencia ?? 0);

  protected readonly muestraAusentes = computed(() => this.resumen()?.muestra_ausentes ?? []);

  /**
   * Qué parte del directorio cubre el archivo.
   *
   * Es el dato que decide si la sincronización corresponde. Una planilla que
   * nombra a 40 de 900 personas casi nunca es la nómina completa: es la de
   * una sucursal, y sincronizarla desactivaría a las otras 860.
   */
  protected readonly cobertura = computed(() => {
    const c = this.resumen()?.cobertura;
    if (!c || c.sincronizables === 0) return null;

    return {
      ...c,
      porcentaje: Math.round((c.nombradas_en_archivo / c.sincronizables) * 100),
    };
  });

  /**
   * Cuando el archivo cubre poco y la casilla está puesta.
   *
   * El umbral es tres cuartos y no la mitad porque una nómina real siempre
   * cubre casi todo: la diferencia entre 98 % y 74 % ya es la diferencia entre
   * «faltan algunos» y «esta planilla no era la del directorio entero».
   */
  protected readonly riesgoDeCobertura = computed(() => {
    const c = this.cobertura();

    return this.sincronizarBajas() && c !== null && this.ausentes() > 0 && c.porcentaje < 75;
  });

  /** Personas de baja que vuelven a quedar activas por venir en el archivo. */
  protected readonly reactivaciones = computed(() => this.resumen()?.se_reactivaran ?? 0);

  /** Filas omitidas: venían de baja y no había a quién dar de baja. */
  protected readonly omitidas = computed(() => this.resumen()?.omitidas_por_inactivas ?? 0);

  /**
   * Valores de la columna de estado que el sistema no supo leer.
   *
   * Se muestran textuales. «2 filas tienen un estado inválido» obliga a abrir
   * el archivo; «LICENCIA ×2» dice qué pasó y qué hay que arreglar.
   */
  protected readonly estadosIlegibles = computed(() =>
    Object.entries(this.resumen()?.activo_ilegible ?? {}).map(([valor, veces]) => ({
      valor,
      veces,
    })),
  );

  /** La columna de estado se conectó pero hay filas con la celda vacía. */
  protected readonly estadoVacio = computed(() => {
    const r = this.resumen();

    return r?.columna_activo_conectada && (r.activo_vacio ?? 0) > 0 ? r.activo_vacio! : 0;
  });

  /**
   * Qué dice el botón que va a hacer.
   *
   * Dice las dos cosas —lo que entra y lo que sale— porque son las dos que
   * van a pasar al pulsarlo, y un botón que dice «Importar 812 filas» mientras
   * además desactiva a 34 personas está contando la mitad.
   */
  protected readonly etiquetaImportar = computed(() => {
    const filas = this.resumen()?.filas_validas ?? 0;
    const entran = `Importar ${filas} ${filas === 1 ? 'fila' : 'filas'}`;
    const bajas = this.bajas();

    if (!bajas) return entran;

    return `${entran} y dar de baja ${bajas.total}`;
  });

  protected alternarSincronizar(evento: Event): void {
    this.sincronizarBajas.set((evento.target as HTMLInputElement).checked);
  }

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

    this.directorio.analizarPlanilla(archivo, this.destino()).subscribe({
      next: (r) => {
        this.ocupada.set(false);
        this.borrador.set(r.data);
        this.columnas.set(r.columnas_sistema);
        this.mapa.set(r.sugerencia);
        this.fase.set('mapa');

        // La planilla decía traer el formato del sistema y lo trae: no hay
        // ninguna decisión que tomar en el paso 2, así que se va derecho al
        // resumen. Si algo no encajó, en cambio, este es exactamente el lugar
        // donde hay que frenar.
        if (this.autoAvanzar() && this.puedeSeguir()) {
          this.columnasAutomaticas.set(true);
          this.verResumen();
        }
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

    this.directorio
      .resumenHomologacion(borrador.id, this.mapa(), this.sincronizarBajas())
      .subscribe({
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
    // Quien vuelve a mirar las columnas ya no está en el camino automático:
    // el paso 2 tiene que quedarse quieto y dejarlo trabajar.
    this.columnasAutomaticas.set(false);
  }

  protected importar(): void {
    const borrador = this.borrador();
    if (!borrador || this.ocupada()) return;

    this.ocupada.set(true);
    this.error.set(null);

    this.directorio
      .importarHomologada(
        borrador.id,
        this.mapa(),
        this.enviarInvitaciones(),
        this.sincronizarBajas(),
      )
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
    this.columnasAutomaticas.set(false);
    // Vuelve a apagarse: que la carga anterior la haya necesitado no dice
    // nada sobre la siguiente, y es la opción que puede vaciar el directorio.
    this.sincronizarBajas.set(false);
  }
}
