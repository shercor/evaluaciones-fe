import { Component, computed, input } from '@angular/core';
import { BarChart, SerieBarra } from '../charts/bar-chart';

export interface BloqueResultado {
  titulo: string | null;
  promedio: number | null;
  resultado: unknown;
  error: string | null;
}

export interface ResultadoPregunta {
  formulario: string;
  valor: string | null;
}

export interface PreguntaDetalle {
  texto: string;
  tipo: string;
  resultado: ResultadoPregunta[];
}

export interface CategoriaDetalle {
  nombre: string;
  solo_texto: boolean;
  promedio_general: string | null;
  preguntas: PreguntaDetalle[];
}

export interface PanelResultados {
  contexto: { evaluacion: string | null; nota_maxima: number; grupo: string | null };
  participante?: { nombre: string; cargo: string | null; sucursal: string | null } | null;
  promedios: BloqueResultado;
  categorias?: BloqueResultado;
  comentarios_recibidos?: BloqueResultado | null;
  comentarios_enviados?: BloqueResultado | null;
  detalle?: { formularios: unknown[]; categorias: CategoriaDetalle[] } | null;
}

interface Cifra {
  titulo: string;
  valor: number | null;
  descripcion: string | null;
}

/**
 * El panel de resultados de una persona.
 *
 * **Esta es la pieza que justifica el hito.** En la intranet había cuatro
 * vistas casi idénticas para lo mismo — `show_results`, `DashboardPerson/show`,
 * `supervisee_results` y `user_detail_results`, unas 3.500 líneas entre todas —
 * cada una con su copia de los gráficos y de los comentarios. Acá es un
 * componente: cambia quién lo mira, no lo que dibuja.
 *
 * Los comentarios enviados solo se muestran si el backend los manda, que es el
 * caso de «mis resultados»: no corresponde ver lo que otra persona escribió
 * sobre terceros.
 */
@Component({
  selector: 'app-results-panel',
  imports: [BarChart],
  templateUrl: './results-panel.html',
  styleUrl: './results-panel.scss',
})
export class ResultsPanel {
  readonly datos = input.required<PanelResultados>();

  protected readonly notaMaxima = computed(() => this.datos().contexto.nota_maxima ?? 5);

  /** Las cifras de cabecera: promedio general y por perspectiva. */
  protected readonly cifras = computed<Cifra[]>(() => {
    const r = this.datos().promedios.resultado;
    if (!Array.isArray(r)) return [];

    return r.map((x: Record<string, unknown>) => ({
      titulo: String(x['titulo'] ?? ''),
      valor: typeof x['valor'] === 'number' ? x['valor'] : null,
      descripcion: (x['descripcion'] as string) ?? null,
    }));
  });

  /** Nombres de las categorías evaluadas. */
  protected readonly categorias = computed<string[]>(() => {
    const r = this.datos().categorias?.resultado;
    if (!Array.isArray(r)) return [];
    return r.map((c: Record<string, unknown>) => String(c['titulo'] ?? ''));
  });

  /**
   * Una serie por tipo de evaluador, con su valor en cada categoría.
   *
   * La API entrega los datos al revés —categoría con sus valores dentro— así
   * que hay que trasponerlos. Si una categoría no fue evaluada desde cierta
   * perspectiva, el hueco queda en `null` y la barra simplemente no aparece:
   * mejor que dibujar un cero, que se leería como «sacó cero».
   */
  protected readonly series = computed<SerieBarra[]>(() => {
    const r = this.datos().categorias?.resultado;
    if (!Array.isArray(r) || r.length === 0) return [];

    const nombres: string[] = [];

    for (const categoria of r as Record<string, unknown>[]) {
      for (const v of (categoria['valores'] as Record<string, unknown>[]) ?? []) {
        const nombre = String(v['titulo'] ?? '');
        if (nombre && !nombres.includes(nombre)) nombres.push(nombre);
      }
    }

    return nombres.map((nombre) => ({
      nombre,
      valores: (r as Record<string, unknown>[]).map((categoria) => {
        const encontrado = ((categoria['valores'] as Record<string, unknown>[]) ?? []).find(
          (v) => String(v['titulo'] ?? '') === nombre,
        );
        return typeof encontrado?.['valor'] === 'number' ? (encontrado['valor'] as number) : null;
      }),
    }));
  });

  /** Desglose pregunta por pregunta, agrupado por categoría. */
  protected readonly detalle = computed<CategoriaDetalle[]>(
    () => this.datos().detalle?.categorias ?? [],
  );

  /**
   * Las columnas de la tabla de detalle: cada fuente que evaluó, más el
   * promedio final. Se deducen de los datos porque no todas las categorías se
   * evalúan desde las mismas perspectivas.
   */
  protected columnasDe(categoria: CategoriaDetalle): string[] {
    const columnas: string[] = [];

    for (const pregunta of categoria.preguntas ?? []) {
      for (const r of pregunta.resultado ?? []) {
        if (!columnas.includes(r.formulario)) columnas.push(r.formulario);
      }
    }

    return columnas;
  }

  protected valorEn(pregunta: PreguntaDetalle, columna: string): string {
    const encontrado = (pregunta.resultado ?? []).find((r) => r.formulario === columna);
    return encontrado?.valor ?? '—';
  }

  protected esPromedioFinal(columna: string): boolean {
    return columna.toLowerCase().includes('promedio');
  }

  protected readonly comentariosRecibidos = computed(() =>
    this.gruposDeComentarios(this.datos().comentarios_recibidos),
  );

  protected readonly comentariosEnviados = computed(() =>
    this.gruposDeComentarios(this.datos().comentarios_enviados),
  );

  /** Proporción de la escala, para dibujar el medidor de cada cifra. */
  protected proporcion(valor: number | null): number {
    if (valor === null) return 0;
    return Math.max(0, Math.min(100, (valor / this.notaMaxima()) * 100));
  }

  /**
   * Aplana los comentarios a «de quién vinieron → qué se preguntó → qué
   * escribieron».
   *
   * La API los anida en `formulario → preguntas[] → comentarios[]`, con el
   * enunciado en `titulo` y el texto en `texto`. Una pregunta puede tener
   * varios comentarios: cada persona que respondió deja el suyo.
   */
  private gruposDeComentarios(
    bloque: BloqueResultado | null | undefined,
  ): { formulario: string; comentarios: { pregunta: string | null; texto: string }[] }[] {
    const r = bloque?.resultado;
    if (!Array.isArray(r)) return [];

    return (r as Record<string, unknown>[])
      .map((grupo) => {
        const comentarios: { pregunta: string | null; texto: string }[] = [];

        for (const pregunta of (grupo['preguntas'] as Record<string, unknown>[]) ?? []) {
          const enunciado = (pregunta['titulo'] as string) ?? null;

          for (const c of (pregunta['comentarios'] as Record<string, unknown>[]) ?? []) {
            const texto = String(c['texto'] ?? '').trim();
            if (texto !== '') comentarios.push({ pregunta: enunciado, texto });
          }
        }

        return { formulario: String(grupo['formulario'] ?? 'Comentarios'), comentarios };
      })
      .filter((g) => g.comentarios.length > 0);
  }
}
