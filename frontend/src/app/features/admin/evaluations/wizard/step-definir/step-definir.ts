import {
  Component,
  ElementRef,
  HostListener,
  afterNextRender,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { Subject, switchMap } from 'rxjs';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Injector } from '@angular/core';
import { Router } from '@angular/router';
import { FormularioPrevisualizado, GroupsService } from '../../../../../core/api/groups.service';
import {
  OpcionesAsistente,
  Plantilla,
  WizardService,
} from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';
import { Skeleton } from '../../../../../shared/skeleton/skeleton';

/**
 * Paso 1 · Definir el proceso.
 *
 * Elegir plantilla, grupo, año y período, y qué formularios de la plantilla se
 * usan. Al guardar, la evaluación queda creada en Evaluación 360 y el
 * asistente pasa a las sucursales.
 */
@Component({
  selector: 'app-step-definir',
  imports: [ReactiveFormsModule, Skeleton],
  templateUrl: './step-definir.html',
})
export class StepDefinir {
  private readonly api = inject(WizardService);
  private readonly previsualizacion = inject(GroupsService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);
  private readonly inyector = inject(Injector);

  protected readonly opciones = signal<OpcionesAsistente | null>(null);
  protected readonly cargando = signal(true);
  protected readonly guardando = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly formulario = this.fb.nonNullable.group({
    titulo: ['', [Validators.required, Validators.maxLength(255)]],
    descripcion: ['', [Validators.required, Validators.maxLength(255)]],
    year: [new Date().getFullYear(), [Validators.required]],
    // El período es un mes: del 1 al 12, igual que en la intranet. Arranca
    // vacío porque hasta no elegir grupo y año no hay período que mostrar.
    periodo: this.fb.control<number | null>(null, [
      Validators.required,
      Validators.min(1),
      Validators.max(12),
    ]),
    group_id: [0, [Validators.required, Validators.min(1)]],
    template_id: [0, [Validators.required, Validators.min(1)]],
  });

  /**
   * El período lo determinan las evaluaciones que ya existen, así que por
   * defecto no se toca. Solo se abre cuando el grupo nunca tuvo ninguna: ahí
   * no hay nada con qué chocar y la primera la elige quien crea el proceso.
   */
  protected readonly periodoForzado = signal(true);
  protected readonly errorPeriodo = signal<string | null>(null);
  private readonly consultasDePeriodo = new Subject<{ year: number; group_id: number }>();

  /**
   * Espejo del formulario como señal.
   *
   * `getRawValue()` dentro de un `computed` no reacciona: los controles de
   * Angular no son señales y el cálculo quedaría congelado en el valor
   * inicial.
   */
  private readonly valores = toSignal(this.formulario.valueChanges, {
    initialValue: this.formulario.getRawValue(),
  });

  /**
   * Carril de plantillas.
   *
   * Las flechas se calculan a partir del scroll real y no de la cantidad de
   * plantillas: el mismo número entra o no entra según el ancho de la ventana.
   */
  private readonly carril = viewChild<ElementRef<HTMLElement>>('carril');
  protected readonly hayIzquierda = signal(false);
  protected readonly hayDerecha = signal(false);

  /**
   * Si nada se sale del carril, las flechas no se dibujan.
   *
   * Deshabilitadas seguirían ocupando su lugar y dejarían las plantillas
   * desalineadas del resto de la sección.
   */
  protected readonly hayCarril = computed(() => this.hayIzquierda() || this.hayDerecha());

  @HostListener('window:resize')
  protected alRedimensionar(): void {
    this.revisarCarril();
  }

  protected revisarCarril(): void {
    const el = this.carril()?.nativeElement;

    if (!el) {
      return;
    }

    // El margen de 4px evita que el redondeo subpíxel deje una flecha
    // encendida sin nada que mostrar.
    this.hayIzquierda.set(el.scrollLeft > 4);
    this.hayDerecha.set(el.scrollLeft + el.clientWidth < el.scrollWidth - 4);
  }

  protected desplazarCarril(direccion: -1 | 1): void {
    const el = this.carril()?.nativeElement;
    el?.scrollBy({ left: direccion * el.clientWidth * 0.8, behavior: 'smooth' });
  }

  /** Qué formularios de la plantilla elegida se incluyen. */
  protected readonly formulariosElegidos = signal<Set<number>>(new Set());

  /**
   * Qué se va a crear, en una frase.
   *
   * Va en la barra de acción porque el grupo y el período quedan lejos del
   * botón al desplazarse, y son justo los dos que no se pueden deshacer
   * después de crear el proceso.
   */
  protected readonly resumen = computed<string | null>(() => {
    const { titulo, year, periodo, group_id } = this.valores();
    const grupo = this.opciones()?.groups.find((g) => g.id === group_id);

    if (!titulo || !grupo || periodo === null) {
      return null;
    }

    return `«${titulo}» para ${grupo.nombre}, período ${periodo} de ${year}`;
  });

  protected readonly plantillaElegida = computed<Plantilla | null>(() => {
    const id = this.valores().template_id;
    return this.opciones()?.templates.find((t) => t.id === id) ?? null;
  });

  constructor() {
    this.escucharPeriodo();

    this.api.opciones().subscribe({
      next: (o) => {
        this.opciones.set(o);
        this.cargando.set(false);

        // El grupo viene elegido de entrada. No es un dato inerte —de él
        // dependen el período y el alcance del proceso— pero tiene un valor
        // razonable por defecto, así que no hace falta tocarlo para seguir.
        if (o.groups.length > 0) {
          this.formulario.controls.group_id.setValue(o.groups[0].id);
        }
        if (o.templates.length === 1) {
          this.elegirPlantilla(o.templates[0].id);
        }
        this.consultarPeriodo();

        // Las plantillas todavía no están en el DOM en este punto: hay que
        // esperar a que Angular las pinte para medir el carril.
        afterNextRender(() => this.revisarCarril(), { injector: this.inyector });
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar las plantillas y grupos.'));
        this.cargando.set(false);
      },
    });

    // El período depende del año y del grupo, así que se vuelve a consultar
    // cuando cambia cualquiera de los dos.
    this.formulario.controls.year.valueChanges.subscribe(() => this.consultarPeriodo());
    this.formulario.controls.group_id.valueChanges.subscribe(() => this.consultarPeriodo());
  }

  /**
   * Previsualización de las preguntas de una plantilla.
   *
   * Va en un modal y no en otra pantalla a propósito: navegar fuera del
   * asistente perdería lo que ya se escribió en el formulario.
   */
  protected readonly viendoPlantilla = signal<Plantilla | null>(null);
  protected readonly formulariosPlantilla = signal<FormularioPrevisualizado[]>([]);
  protected readonly cargandoPreview = signal(false);
  protected readonly formularioActivo = signal(0);

  protected verPreguntas(plantilla: Plantilla, evento: Event): void {
    // El botón vive dentro de la tarjeta que elige la plantilla; sin esto,
    // mirar las preguntas la seleccionaría de paso.
    evento.stopPropagation();

    this.viendoPlantilla.set(plantilla);
    this.formulariosPlantilla.set([]);
    this.formularioActivo.set(0);
    this.cargandoPreview.set(true);

    this.previsualizacion.previsualizarPlantilla(plantilla.id).subscribe({
      next: (r) => {
        this.formulariosPlantilla.set(r.formularios);
        this.cargandoPreview.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar las preguntas.'));
        this.cargandoPreview.set(false);
        this.viendoPlantilla.set(null);
      },
    });
  }

  protected cerrarPreguntas(): void {
    this.viendoPlantilla.set(null);
  }

  protected elegirFormulario(indice: number): void {
    this.formularioActivo.set(indice);
  }

  /**
   * Salto desde el índice a una categoría.
   *
   * El destello es lo que hace legible el salto: sin él, la pantalla cambia y
   * no queda claro a cuál de las siete secciones se llegó.
   */
  protected readonly resaltada = signal(-1);

  protected irACategoria(indice: number): void {
    document
      .getElementById('cat-' + indice)
      ?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    this.resaltada.set(indice);
    setTimeout(() => this.resaltada.set(-1), 1600);
  }

  /** a, b, c… como en la intranet; números si alguna vez pasan de 26. */
  protected letra(indice: number): string {
    return indice < 26 ? String.fromCharCode(97 + indice) : String(indice + 1);
  }

  /** ¿Este formulario usa alguno de los distintivos? Si no, sobra la leyenda. */
  protected tieneMarcas(f: FormularioPrevisualizado): boolean {
    return this.tieneCondicionales(f) || this.tieneExcluidas(f);
  }

  protected tieneCondicionales(f: FormularioPrevisualizado): boolean {
    return f.categorias.some((c) => c.condicional);
  }

  protected tieneExcluidas(f: FormularioPrevisualizado): boolean {
    return f.categorias.some((c) => !c.en_promedio);
  }

  protected totalPreguntas(): number {
    return this.formulariosPlantilla().reduce((n, f) => n + f.total_preguntas, 0);
  }

  protected elegirPlantilla(id: number): void {
    this.formulario.controls.template_id.setValue(id);

    // Por defecto se incluyen todos los formularios de la plantilla: es lo que
    // se quiere casi siempre, y quitar es más fácil que agregar.
    const plantilla = this.opciones()?.templates.find((t) => t.id === id);
    this.formulariosElegidos.set(new Set(plantilla?.formularios.map((f) => f.id) ?? []));
  }

  protected alternarFormulario(id: number): void {
    this.formulariosElegidos.update((actuales) => {
      const copia = new Set(actuales);
      copia.has(id) ? copia.delete(id) : copia.add(id);
      return copia;
    });
  }

  protected estaElegido(id: number): boolean {
    return this.formulariosElegidos().has(id);
  }

  protected guardar(): void {
    if (this.formulario.invalid || this.guardando()) {
      this.formulario.markAllAsTouched();
      return;
    }

    if (this.formulariosElegidos().size === 0) {
      this.error.set('Elegí al menos un formulario para la evaluación.');
      return;
    }

    this.guardando.set(true);
    this.error.set(null);

    this.api
      .crear({
        ...this.formulario.getRawValue(),
        // El formulario es válido, así que el período ya tiene número: la
        // validación `required` no deja llegar hasta acá con null.
        periodo: this.formulario.controls.periodo.value!,
        formularios: [...this.formulariosElegidos()],
      })
      .subscribe({
        next: (r) => {
          this.guardando.set(false);
          this.router.navigate(['/admin/evaluaciones/asistente', r.data.id, 'sucursales']);
        },
        error: (e) => {
          this.guardando.set(false);
          this.error.set(mensajeDeError(e, 'No se pudo crear la evaluación.'));
        },
      });
  }

  /**
   * Consulta el período que le toca a este año y grupo.
   *
   * Cambiar el año y el grupo casi seguido dispara dos consultas, y la que
   * responde última no es necesariamente la última que se pidió: `switchMap`
   * cancela la anterior para que no gane una respuesta vieja.
   */
  private consultarPeriodo(): void {
    const { year, group_id } = this.formulario.getRawValue();

    if (!year || !group_id) {
      this.formulario.controls.periodo.setValue(null);
      this.periodoForzado.set(true);
      this.errorPeriodo.set(null);
      return;
    }

    this.consultasDePeriodo.next({ year, group_id });
  }

  private escucharPeriodo(): void {
    this.consultasDePeriodo
      .pipe(switchMap(({ year, group_id }) => this.api.periodoSugerido(year, group_id)))
      .subscribe({
        next: (r) => {
          // Ojo: `periodo` ya es el siguiente al último usado. Sumarle uno
          // salteaba un número en cada proceso.
          this.periodoForzado.set(r.forzado);
          this.formulario.controls.periodo.setValue(r.periodo ?? 1);
          this.errorPeriodo.set(null);
        },
        error: (e) => {
          // Sin período confirmado no se deja escribir uno a mano: elegirlo a
          // ciegas choca con los que ya existen y la API rechaza el proceso
          // recién al guardar.
          this.periodoForzado.set(true);
          this.errorPeriodo.set(
            mensajeDeError(e, 'No se pudo consultar el período de este grupo y año.'),
          );
          this.escucharPeriodo();
        },
      });
  }
}
