import { DatePipe } from '@angular/common';
import { Component, OnDestroy, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { debounceTime } from 'rxjs';
import {
  AccionEvaluacion,
  EstadoEvaluacion,
  Evaluacion,
  EvaluationsService,
} from '../../../../core/api/evaluations.service';
import { mensajeDeError } from '../../../../core/http/api-error';
import { Skeleton } from '../../../../shared/skeleton/skeleton';

interface Confirmacion {
  titulo: string;
  cuerpo: string;
  advertencia?: string;
  etiquetaBoton: string;
  peligrosa: boolean;
  ejecutar: () => void;
}

/**
 * Listado de procesos de evaluación.
 *
 * Las acciones de cada fila **las decide el backend** y llegan en `acciones`.
 * En la intranet esa lógica vivía en la plantilla, como condiciones
 * encadenadas alrededor de cada botón; acá la vista solo dibuja lo que le
 * mandan, y el backend vuelve a comprobarlo antes de ejecutar.
 */
@Component({
  selector: 'app-evaluations-list',
  imports: [ReactiveFormsModule, DatePipe, RouterLink, Skeleton],
  templateUrl: './evaluations-list.html',
})
export class EvaluationsList implements OnDestroy {
  private readonly api = inject(EvaluationsService);
  private readonly fb = inject(FormBuilder);

  protected readonly evaluaciones = signal<Evaluacion[]>([]);
  protected readonly estados = signal<EstadoEvaluacion[]>([]);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly aviso = signal<string | null>(null);
  protected readonly ocupada = signal<number | null>(null);

  protected readonly confirmacion = signal<Confirmacion | null>(null);

  protected readonly filtros = this.fb.nonNullable.group({
    nombre: [''],
    year: [''],
    periodo: [''],
    estado: [''],
  });

  /**
   * Consulta periódica mientras haya procesos preparándose.
   *
   * Solo refresca **esas filas**. La intranet recargaba la página entera cada
   * 10 segundos y guardaba la posición del scroll para disimular el salto.
   */
  private temporizador?: ReturnType<typeof setInterval>;

  constructor() {
    this.buscar();

    this.filtros.valueChanges.pipe(debounceTime(350)).subscribe(() => this.buscar());
  }

  ngOnDestroy(): void {
    this.detenerConsulta();
  }

  protected buscar(): void {
    this.cargando.set(true);
    this.error.set(null);

    this.api.listar(this.filtros.getRawValue()).subscribe({
      next: (r) => {
        this.evaluaciones.set(r.data);
        this.estados.set(r.statuses);
        this.cargando.set(false);
        this.ajustarConsulta();
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar el listado.'));
        this.cargando.set(false);
      },
    });
  }

  protected limpiarFiltros(): void {
    this.filtros.reset({ nombre: '', year: '', periodo: '', estado: '' });
  }

  protected puede(evaluacion: Evaluacion, accion: AccionEvaluacion): boolean {
    return evaluacion.acciones.includes(accion);
  }

  protected descartarAviso(): void {
    this.aviso.set(null);
  }

  // -- Acciones -----------------------------------------------------

  protected pedirAbrir(e: Evaluacion): void {
    this.confirmacion.set({
      titulo: '¿Abrir el proceso de evaluación?',
      cuerpo:
        'Los participantes van a poder empezar a responder sus formularios. ' +
        'Podés volver a cerrarlo mientras no publiques los resultados.',
      advertencia:
        'Todavía no se envían avisos por correo: esa parte está diferida, así que ' +
        'vas a tener que comunicarlo por otro medio.',
      etiquetaBoton: 'Abrir proceso',
      peligrosa: false,
      ejecutar: () => this.ejecutar(e, () => this.api.abrir(e.id)),
    });
  }

  protected pedirCerrar(e: Evaluacion): void {
    this.confirmacion.set({
      titulo: '¿Cerrar el proceso?',
      cuerpo:
        'Los participantes no van a poder enviar más respuestas. ' +
        'Podés reabrirlo mientras no publiques los resultados.',
      etiquetaBoton: 'Cerrar proceso',
      peligrosa: false,
      ejecutar: () => this.ejecutar(e, () => this.api.cerrar(e.id)),
    });
  }

  protected pedirPublicar(e: Evaluacion): void {
    this.confirmacion.set({
      titulo: '¿Publicar los resultados?',
      cuerpo:
        'Cada participante va a poder ver sus resultados, y el proceso queda cerrado ' +
        'definitivamente.',
      advertencia: 'Esta acción no se puede deshacer: después no vas a poder reabrir el proceso.',
      etiquetaBoton: 'Publicar resultados',
      peligrosa: true,
      ejecutar: () => this.ejecutar(e, () => this.api.publicar(e.id)),
    });
  }

  protected pedirDesactivar(e: Evaluacion): void {
    this.confirmacion.set({
      titulo: '¿Desactivar la evaluación?',
      cuerpo: 'La evaluación deja de estar disponible para administrarse.',
      // No es una advertencia de diseño sino un defecto conocido de la API:
      // ver la nota en docs/HANDOFF.md.
      advertencia:
        'Por un error en la API de Evaluación 360, una evaluación desactivada ' +
        'desaparece del listado y no se puede reactivar. Sus datos siguen en la base, ' +
        'pero quedan inalcanzables hasta que ese error se corrija.',
      etiquetaBoton: 'Desactivar de todos modos',
      peligrosa: true,
      ejecutar: () => this.ejecutar(e, () => this.api.desactivar(e.id)),
    });
  }

  protected pedirReactivar(e: Evaluacion): void {
    this.confirmacion.set({
      titulo: '¿Reactivar la evaluación?',
      cuerpo: 'Vuelve a estar disponible para administrarse.',
      etiquetaBoton: 'Reactivar',
      peligrosa: false,
      ejecutar: () => this.ejecutar(e, () => this.api.reactivar(e.id)),
    });
  }

  protected cancelarConfirmacion(): void {
    this.confirmacion.set(null);
  }

  private ejecutar(
    evaluacion: Evaluacion,
    peticion: () => ReturnType<EvaluationsService['abrir']>,
  ): void {
    this.confirmacion.set(null);
    this.ocupada.set(evaluacion.id);
    this.error.set(null);

    peticion().subscribe({
      next: (r) => {
        this.ocupada.set(null);
        this.aviso.set(r.message);
        // Se relee el listado entero: una transición puede cambiar qué
        // acciones corresponden en otras filas.
        this.buscar();
      },
      error: (e) => {
        this.ocupada.set(null);
        this.error.set(mensajeDeError(e, 'No se pudo completar la acción.'));
        this.buscar();
      },
    });
  }

  // -- Consulta de procesos en preparación --------------------------

  private ajustarConsulta(): void {
    const enTransicion = this.evaluaciones().filter((e) => e.en_transicion);

    if (enTransicion.length === 0) {
      this.detenerConsulta();
      return;
    }

    if (this.temporizador) {
      return;
    }

    this.temporizador = setInterval(() => this.refrescarEnTransicion(), 10_000);
  }

  private refrescarEnTransicion(): void {
    const pendientes = this.evaluaciones().filter((e) => e.en_transicion);

    if (pendientes.length === 0) {
      this.detenerConsulta();
      return;
    }

    for (const pendiente of pendientes) {
      this.api.estado(pendiente.id).subscribe({
        next: (r) => {
          // Se reemplaza solo esa fila, sin tocar el scroll ni el resto.
          this.evaluaciones.update((filas) =>
            filas.map((f) => (f.id === r.data.id ? r.data : f)),
          );
          this.ajustarConsulta();
        },
        // Un fallo puntual de la consulta no merece molestar: se reintenta
        // en la vuelta siguiente.
        error: () => undefined,
      });
    }
  }

  private detenerConsulta(): void {
    if (this.temporizador) {
      clearInterval(this.temporizador);
      this.temporizador = undefined;
    }
  }
}
