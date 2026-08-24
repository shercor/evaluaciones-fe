import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { OpcionesAsistente, Plantilla, WizardService } from '../../../../../core/api/wizard.service';
import { mensajeDeError } from '../../../../../core/http/api-error';

/**
 * Paso 1 · Definir el proceso.
 *
 * Elegir plantilla, grupo, año y período, y qué formularios de la plantilla se
 * usan. Al guardar, la evaluación queda creada en Evaluación 360 y el
 * asistente pasa a las sucursales.
 */
@Component({
  selector: 'app-step-definir',
  imports: [ReactiveFormsModule],
  templateUrl: './step-definir.html',
})
export class StepDefinir {
  private readonly api = inject(WizardService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  protected readonly opciones = signal<OpcionesAsistente | null>(null);
  protected readonly cargando = signal(true);
  protected readonly guardando = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly formulario = this.fb.nonNullable.group({
    titulo: ['', [Validators.required, Validators.maxLength(255)]],
    descripcion: ['', [Validators.required, Validators.maxLength(255)]],
    year: [new Date().getFullYear(), [Validators.required]],
    periodo: [1, [Validators.required, Validators.min(1)]],
    group_id: [0, [Validators.required, Validators.min(1)]],
    template_id: [0, [Validators.required, Validators.min(1)]],
  });

  /** Qué formularios de la plantilla elegida se incluyen. */
  protected readonly formulariosElegidos = signal<Set<number>>(new Set());

  protected readonly plantillaElegida = computed<Plantilla | null>(() => {
    const id = this.formulario.controls.template_id.value;
    return this.opciones()?.templates.find((t) => t.id === id) ?? null;
  });

  constructor() {
    this.api.opciones().subscribe({
      next: (o) => {
        this.opciones.set(o);
        this.cargando.set(false);

        // Con una sola plantilla o un solo grupo, se eligen solos: obligar a
        // seleccionar lo único disponible no aporta nada.
        if (o.groups.length === 1) {
          this.formulario.controls.group_id.setValue(o.groups[0].id);
        }
        if (o.templates.length === 1) {
          this.elegirPlantilla(o.templates[0].id);
        }
        this.sugerirPeriodo();
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar las plantillas y grupos.'));
        this.cargando.set(false);
      },
    });

    // El período depende del año y del grupo, así que se vuelve a sugerir
    // cuando cambia cualquiera de los dos.
    this.formulario.controls.year.valueChanges.subscribe(() => this.sugerirPeriodo());
    this.formulario.controls.group_id.valueChanges.subscribe(() => this.sugerirPeriodo());
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

  private sugerirPeriodo(): void {
    const { year, group_id } = this.formulario.getRawValue();

    if (!year || !group_id) {
      return;
    }

    this.api.periodoSugerido(year, group_id).subscribe({
      next: (r) => {
        // La API devuelve el último período usado; el nuevo es el siguiente.
        this.formulario.controls.periodo.setValue((r.periodo ?? 0) + 1);
      },
      error: () => undefined,
    });
  }
}
