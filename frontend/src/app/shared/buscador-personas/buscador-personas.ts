import {
  Component,
  ElementRef,
  computed,
  inject,
  input,
  linkedSignal,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  Observable,
  Subject,
  catchError,
  debounce,
  distinctUntilChanged,
  of,
  switchMap,
  timer,
} from 'rxjs';

export interface PersonaSugerida {
  id: number;
  nombre: string;
  codigo: string | null;
}

/**
 * Buscador de personas con sugerencias, contra el servidor.
 *
 * Nace de un desplegable de 527 opciones que además obligaba al backend a
 * cargar el padrón entero —7.078 filas, 56 MB— en cada página del listado. Una
 * lista así no se puede recorrer con la vista ni cargar de una sola vez, así
 * que se busca por lo que se escribe y solo viajan las coincidencias.
 *
 * No conoce ningún servicio: recibe la consulta como entrada. Por eso el mismo
 * control sirve para el filtro del padrón, para el editor de participantes y
 * para el selector de supervisor del directorio, que buscan en conjuntos
 * distintos.
 *
 * Tres decisiones que conviene no deshacer:
 *
 * - **Mínimo de caracteres.** Con menos no se consulta. Con una o dos letras
 *   la respuesta serían cientos de nombres, que es justo el problema del que
 *   se viene.
 * - **`switchMap` y no `mergeMap`.** Al escribir rápido salen varias
 *   consultas; sin cancelar la anterior, una respuesta lenta de «ro» puede
 *   llegar después de la de «rodrigo» y pisar las sugerencias buenas.
 * - **El código va junto al nombre.** Hay homónimos: 527 supervisores con 434
 *   nombres distintos. Cuatro «Rodrigo Fuentes» seguidos no dejan elegir.
 */
@Component({
  selector: 'app-buscador-personas',
  templateUrl: './buscador-personas.html',
})
export class BuscadorPersonas {
  /** Qué consultar. Se recibe de afuera para no atar el control a un servicio. */
  readonly buscar = input.required<(termino: string) => Observable<PersonaSugerida[]>>();

  readonly etiqueta = input('Buscar persona');
  readonly idControl = input('buscador-personas');
  readonly placeholder = input('Nombre o código');
  /** Cuántos caracteres antes de molestar al servidor. */
  readonly minimo = input(3);
  /** Milisegundos de teclado quieto antes de consultar. */
  readonly espera = input(300);
  /** Cuántas devuelve el servidor como mucho; sirve para avisar que hay más. */
  readonly tope = input(15);

  /**
   * Persona ya elegida al abrir el control.
   *
   * La necesita cualquier formulario de edición: si el campo apareciera vacío
   * sobre alguien que **sí** tiene supervisor asignado, se leería como que no
   * tiene, y guardar sin tocarlo lo borraría.
   */
  readonly inicial = input<PersonaSugerida | null>(null);

  /** La persona elegida, o `null` al limpiar. */
  readonly elegida = output<PersonaSugerida | null>();

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly tecleo = new Subject<string>();

  /**
   * `linkedSignal` y no `signal`: se dejan escribir mientras se teclea, pero
   * vuelven solos al valor de partida cuando cambia `inicial`. Es lo que hace
   * que reabrir el editor sobre otra persona no arrastre lo anterior.
   */
  protected readonly texto = linkedSignal(() => this.inicial()?.nombre ?? '');
  protected readonly seleccion = linkedSignal(() => this.inicial());

  protected readonly sugerencias = signal<PersonaSugerida[]>([]);
  protected readonly buscando = signal(false);
  protected readonly abierto = signal(false);
  protected readonly fallo = signal(false);
  /** Índice resaltado con el teclado; -1 es «ninguno». */
  protected readonly resaltada = signal(-1);

  protected readonly faltan = computed(() => this.minimo() - this.texto().length);

  /**
   * Qué está pasando ahora mismo. Un solo lugar que lo decida evita que la
   * plantilla encadene condiciones y que aparezcan dos mensajes a la vez.
   */
  protected readonly estado = computed<'escribiendo' | 'buscando' | 'vacio' | 'fallo' | 'listo'>(
    () => {
      if (this.fallo()) return 'fallo';
      if (this.texto().length > 0 && this.texto().length < this.minimo()) return 'escribiendo';
      if (this.buscando()) return 'buscando';
      if (this.sugerencias().length === 0) return 'vacio';
      return 'listo';
    },
  );

  /**
   * El desplegable se abre con el foco puesto y algo que decir. Sin texto no
   * dice nada: un panel vacío al enfocar es ruido.
   */
  protected readonly desplegado = computed(() => this.abierto() && this.texto().length > 0);

  constructor() {
    this.tecleo
      .pipe(
        // `debounce` con una fábrica y no `debounceTime(this.espera())`: en
        // el constructor las entradas todavía no llegaron del padre, así que
        // un valor distinto del de fábrica se habría perdido en silencio.
        debounce(() => timer(this.espera())),
        distinctUntilChanged(),
        switchMap((termino) => {
          if (termino.length < this.minimo()) {
            this.buscando.set(false);
            return of<PersonaSugerida[]>([]);
          }

          this.buscando.set(true);

          // Que falle una consulta no puede romper el control: se avisa y se
          // sigue escribiendo.
          return this.buscar()(termino).pipe(
            catchError(() => {
              this.fallo.set(true);
              return of<PersonaSugerida[]>([]);
            }),
          );
        }),
        takeUntilDestroyed(),
      )
      .subscribe((personas) => {
        this.buscando.set(false);
        this.sugerencias.set(personas);
        this.resaltada.set(-1);
      });
  }

  // -- Escritura ------------------------------------------------------

  protected alEscribir(evento: Event): void {
    const valor = (evento.target as HTMLInputElement).value;

    this.texto.set(valor);
    this.fallo.set(false);

    // Editar el texto de una persona ya elegida la deselecciona: lo que se ve
    // escrito y lo que está filtrando tienen que ser lo mismo.
    if (this.seleccion() && valor !== this.seleccion()!.nombre) {
      this.seleccion.set(null);
      this.elegida.emit(null);
    }

    if (valor.length < this.minimo()) {
      this.sugerencias.set([]);
    }

    // Queda abierto aunque falten caracteres: el panel es donde se explica
    // cuántos faltan.
    this.abierto.set(true);

    this.tecleo.next(valor);
  }

  protected alEnfocar(): void {
    this.abierto.set(true);
  }

  // -- Selección ------------------------------------------------------

  protected elegir(persona: PersonaSugerida): void {
    this.seleccion.set(persona);
    this.texto.set(persona.nombre);
    this.sugerencias.set([]);
    this.abierto.set(false);
    this.resaltada.set(-1);
    this.elegida.emit(persona);
  }

  /**
   * Vacía el control.
   *
   * Es pública porque el contenedor también limpia: un botón «Limpiar
   * filtros» que borra el filtro pero deja el nombre escrito en la caja le
   * está diciendo a la persona que sigue filtrando por alguien.
   *
   * No mueve el foco, a propósito: cuando limpia el contenedor, la persona
   * pulsó otro botón y robarle el foco sería un salto que no pidió.
   */
  limpiar(): void {
    this.texto.set('');
    this.sugerencias.set([]);
    this.seleccion.set(null);
    this.abierto.set(false);
    this.resaltada.set(-1);
    this.fallo.set(false);
    this.elegida.emit(null);
  }

  /** La × del propio control: limpia y devuelve el foco a la caja. */
  protected limpiarYEnfocar(): void {
    this.limpiar();
    this.campo()?.focus();
  }

  // -- Teclado --------------------------------------------------------

  protected alPulsar(evento: KeyboardEvent): void {
    const total = this.sugerencias().length;

    switch (evento.key) {
      case 'ArrowDown':
        if (total === 0) return;
        evento.preventDefault();
        this.abierto.set(true);
        this.resaltada.update((i) => (i + 1) % total);
        break;

      case 'ArrowUp':
        if (total === 0) return;
        evento.preventDefault();
        this.abierto.set(true);
        this.resaltada.update((i) => (i <= 0 ? total - 1 : i - 1));
        break;

      case 'Enter': {
        const i = this.resaltada();
        if (!this.desplegado() || i < 0) return;
        // Solo se traga el Enter cuando hay algo resaltado: si no, tiene que
        // seguir llegando al formulario que contiene el control.
        evento.preventDefault();
        this.elegir(this.sugerencias()[i]);
        break;
      }

      case 'Escape':
        if (!this.desplegado()) return;
        evento.preventDefault();
        this.abierto.set(false);
        this.resaltada.set(-1);
        break;
    }
  }

  /**
   * Cerrar al irse el foco, sea con el ratón o con el tabulador.
   *
   * Se mira a dónde fue el foco en vez de cerrar a ciegas: si sigue dentro del
   * control —el botón de limpiar, por ejemplo— la lista tiene que quedarse.
   */
  protected alSalirElFoco(evento: FocusEvent): void {
    const destino = evento.relatedTarget as Node | null;

    if (!destino || !(this.host.nativeElement as HTMLElement).contains(destino)) {
      this.abierto.set(false);
      this.resaltada.set(-1);
    }
  }

  // -- Ayudas para la plantilla ---------------------------------------

  private campo(): HTMLInputElement | null {
    return (this.host.nativeElement as HTMLElement).querySelector('input');
  }
}
