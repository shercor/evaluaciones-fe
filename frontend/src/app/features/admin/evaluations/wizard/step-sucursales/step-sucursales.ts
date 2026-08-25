import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { SucursalDisponible, WizardService } from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';

/**
 * Paso 2 · Sucursales.
 *
 * Elegir de qué sucursales sale la gente. Guardar además **materializa el
 * padrón**: los dos pasos van juntos porque el padrón se deriva de esta
 * elección.
 *
 * Las sucursales sin personal no se ofrecen, y las personas sin sucursal
 * asignada aparecen como una opción más. En la intranet eso era una
 * pseudo-sucursal con id 0 que había que tratar aparte en cada consulta.
 */
@Component({
  selector: 'app-step-sucursales',
  imports: [FormsModule],
  templateUrl: './step-sucursales.html',
})
export class StepSucursales {
  private readonly api = inject(WizardService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly disponibles = signal<SucursalDisponible[]>([]);
  protected readonly elegidas = signal<Set<number | null>>(new Set());
  protected readonly cargando = signal(true);
  protected readonly guardando = signal(false);
  protected readonly error = signal<string | null>(null);

  /**
   * Filtros de la lista.
   *
   * Con tres sucursales sobran; con cien, sin ellos hay que recorrer una
   * pared de casillas para encontrar una. El orden por personal arranca
   * primero porque es el criterio con que se decide a quién incluir.
   */
  protected readonly busqueda = signal('');
  protected readonly orden = signal<'personal' | 'nombre'>('personal');
  protected readonly soloElegidas = signal(false);

  /** Las que se ven ahora mismo, ya filtradas y ordenadas. */
  protected readonly visibles = computed<SucursalDisponible[]>(() => {
    const texto = this.busqueda().trim().toLocaleLowerCase('es');
    const elegidas = this.elegidas();
    const orden = this.orden();

    const lista = this.disponibles().filter((s) => {
      if (this.soloElegidas() && !elegidas.has(s.id)) {
        return false;
      }
      return !texto || s.name.toLocaleLowerCase('es').includes(texto);
    });

    // Copia antes de ordenar: `sort` muta, y la fuente es la señal original.
    return [...lista].sort((a, b) =>
      orden === 'nombre'
        ? a.name.localeCompare(b.name, 'es')
        : b.staff_count - a.staff_count || a.name.localeCompare(b.name, 'es'),
    );
  });

  protected readonly filtrando = computed(
    () => this.busqueda().trim().length > 0 || this.soloElegidas(),
  );

  /** Cuántas de las visibles están elegidas: decide qué acción masiva ofrecer. */
  protected readonly visiblesElegidas = computed(
    () => this.visibles().filter((s) => this.elegidas().has(s.id)).length,
  );

  protected readonly totalPersonas = computed(() =>
    this.disponibles()
      .filter((s) => this.elegidas().has(s.id))
      .reduce((suma, s) => suma + s.staff_count, 0),
  );

  constructor() {
    this.api.sucursales(this.id).subscribe({
      next: (r) => {
        this.disponibles.set(r.disponibles);
        this.elegidas.set(new Set(r.seleccionadas));
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar las sucursales.'));
        this.cargando.set(false);
      },
    });
  }

  protected alternar(id: number | null): void {
    this.elegidas.update((actuales) => {
      const copia = new Set(actuales);
      copia.has(id) ? copia.delete(id) : copia.add(id);
      return copia;
    });
  }

  protected estaElegida(id: number | null): boolean {
    return this.elegidas().has(id);
  }

  /**
   * Las acciones masivas actúan sobre lo que se ve, no sobre todo.
   *
   * Con un filtro puesto, «seleccionar todas» sobre el total entero elegiría
   * sucursales que no están en pantalla: lo contrario de lo que se pidió.
   * Por eso suman o restan sin pisar el resto de la selección.
   */
  protected elegirVisibles(): void {
    this.elegidas.update((actuales) => {
      const copia = new Set(actuales);
      this.visibles().forEach((s) => copia.add(s.id));
      return copia;
    });
  }

  protected quitarVisibles(): void {
    this.elegidas.update((actuales) => {
      const copia = new Set(actuales);
      this.visibles().forEach((s) => copia.delete(s.id));
      return copia;
    });
  }

  protected limpiarFiltros(): void {
    this.busqueda.set('');
    this.soloElegidas.set(false);
  }

  protected continuar(): void {
    if (this.elegidas().size === 0) {
      this.error.set('Elegí al menos una sucursal.');
      return;
    }

    this.guardando.set(true);
    this.error.set(null);

    this.api.guardarSucursales(this.id, [...this.elegidas()]).subscribe({
      next: () => {
        this.guardando.set(false);
        this.router.navigate(['/admin/evaluaciones/asistente', this.id, 'participantes']);
      },
      error: (e) => {
        this.guardando.set(false);
        this.error.set(mensajeDeError(e, 'No se pudo armar el padrón.'));
      },
    });
  }
}
