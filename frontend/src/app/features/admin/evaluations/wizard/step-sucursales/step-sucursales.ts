import { Component, computed, inject, signal } from '@angular/core';
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

  protected todas(): void {
    this.elegidas.set(new Set(this.disponibles().map((s) => s.id)));
  }

  protected ninguna(): void {
    this.elegidas.set(new Set());
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
