import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { BarChart, SerieBarra } from '../../../shared/charts/bar-chart';
import { ResultsService, Tablero } from '../../../core/api/results.service';
import { mensajeDeError } from '../../../core/http/api-error';
import { Skeleton } from '../../../shared/skeleton/skeleton';

/**
 * Tablero general de una evaluación.
 *
 * Las cifras de cabecera son números, no gráficos: un promedio único se lee
 * mejor escrito que dibujado. Los gráficos aparecen donde hay algo que
 * comparar — entre perspectivas, y entre categorías.
 */
@Component({
  selector: 'app-dashboard',
  imports: [BarChart, Skeleton],
  templateUrl: './dashboard.html',
})
export class Dashboard {
  private readonly api = inject(ResultsService);
  private readonly ruta = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly datos = signal<Tablero | null>(null);
  protected readonly cargando = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly notaMaxima = computed(() => this.datos()?.contexto.nota_maxima ?? 5);

  /** Promedio general y sus desgloses, como cifras. */
  protected readonly cifras = computed(() => {
    const r = this.datos()?.promedios.resultado;
    if (!Array.isArray(r)) return [];
    return (r as Record<string, unknown>[]).map((x) => ({
      titulo: String(x['titulo'] ?? ''),
      valor: typeof x['valor'] === 'number' ? x['valor'] : null,
      descripcion: (x['descripcion'] as string) ?? null,
    }));
  });

  protected readonly participacion = computed(() => {
    const r = this.datos()?.participacion.resultado as Record<string, unknown> | undefined;
    if (!r) return null;
    return {
      participantes: Number(r['participantes'] ?? 0),
      respondieron: Number(r['participacion'] ?? 0),
      porcentaje: Math.round(Number(r['porcentaje'] ?? 0) * 100),
    };
  });

  // -- Promedio por fuente: una sola serie, sin leyenda ---------------

  protected readonly fuentesCategorias = computed(() =>
    this.listaSimple().map((x) => x.titulo),
  );

  protected readonly fuentesSeries = computed<SerieBarra[]>(() => [
    { nombre: 'Promedio', valores: this.listaSimple().map((x) => x.valor) },
  ]);

  // -- Promedio por categoría y evaluador: varias series --------------

  protected readonly categorias = computed<string[]>(() => {
    const r = this.datos()?.por_categoria.resultado;
    if (!Array.isArray(r)) return [];
    return (r as Record<string, unknown>[]).map((c) => String(c['titulo'] ?? ''));
  });

  protected readonly seriesCategorias = computed<SerieBarra[]>(() => {
    const r = this.datos()?.por_categoria.resultado;
    if (!Array.isArray(r) || r.length === 0) return [];

    const nombres: string[] = [];
    for (const c of r as Record<string, unknown>[]) {
      for (const v of (c['valores'] as Record<string, unknown>[]) ?? []) {
        const n = String(v['titulo'] ?? '');
        if (n && !nombres.includes(n)) nombres.push(n);
      }
    }

    return nombres.map((nombre) => ({
      nombre,
      valores: (r as Record<string, unknown>[]).map((c) => {
        const v = ((c['valores'] as Record<string, unknown>[]) ?? []).find(
          (x) => String(x['titulo'] ?? '') === nombre,
        );
        return typeof v?.['valor'] === 'number' ? (v['valor'] as number) : null;
      }),
    }));
  });

  // -- Encuesta de clima laboral --------------------------------------

  /**
   * Las preguntas del clima laboral son **numéricas**, no de texto: la API
   * devuelve el promedio de cada una. Se dibujan como barras, que es lo que
   * permite ver de un vistazo cuál quedó más baja.
   */
  protected readonly climaCategorias = computed<string[]>(() =>
    this.listaClima().map((x) => x.titulo),
  );

  protected readonly climaSeries = computed<SerieBarra[]>(() => {
    const lista = this.listaClima();
    if (lista.length === 0) return [];
    return [{ nombre: 'Promedio', valores: lista.map((x) => x.valor) }];
  });

  /** La pregunta peor evaluada, que es la que hay que mirar primero. */
  protected readonly climaMasBaja = computed(() => {
    const conDato = this.listaClima().filter((x) => x.valor !== null);
    if (conDato.length === 0) return null;
    return conDato.reduce((peor, x) => (x.valor! < peor.valor! ? x : peor));
  });

  constructor() {
    this.api.tablero(this.id).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.cargando.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar el tablero.'));
        this.cargando.set(false);
      },
    });
  }

  protected proporcion(valor: number | null): number {
    if (valor === null) return 0;
    return Math.max(0, Math.min(100, (valor / this.notaMaxima()) * 100));
  }

  protected volver(): void {
    this.router.navigate(['/admin/evaluaciones']);
  }

  private listaClima(): { titulo: string; valor: number | null }[] {
    const r = this.datos()?.respuestas_abiertas.resultado;
    if (!Array.isArray(r)) return [];
    return (r as Record<string, unknown>[]).map((x) => ({
      titulo: String(x['titulo'] ?? ''),
      valor: typeof x['valor'] === 'number' ? x['valor'] : null,
    }));
  }

  private listaSimple(): { titulo: string; valor: number | null }[] {
    const r = this.datos()?.por_fuente.resultado;
    if (!Array.isArray(r)) return [];
    return (r as Record<string, unknown>[]).map((x) => ({
      titulo: String(x['titulo'] ?? ''),
      valor: typeof x['valor'] === 'number' ? x['valor'] : null,
    }));
  }
}
