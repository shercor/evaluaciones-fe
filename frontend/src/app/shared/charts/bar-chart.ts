import { Component, computed, input } from '@angular/core';
import { NgxEchartsDirective } from 'ngx-echarts';
import type { EChartsCoreOption } from 'echarts/core';
import { colorSerieUnica, paleta, tinta } from './chart-theme';

export interface SerieBarra {
  nombre: string;
  valores: (number | null)[];
}

/**
 * Barras horizontales, con una o varias series.
 *
 * Horizontales a propósito: las etiquetas de categoría son frases —«Evaluación
 * al jefe directo», «Comunicación efectiva»— y en vertical habría que rotarlas
 * o recortarlas.
 *
 * Cada barra lleva **su cifra escrita**, que además de ayudar a leer cubre el
 * requisito de la paleta: en modo claro algunos colores no alcanzan 3:1 contra
 * el fondo, así que la identidad no puede depender solo del color.
 */
@Component({
  selector: 'app-bar-chart',
  imports: [NgxEchartsDirective],
  template: `
    <div
      echarts
      [options]="opciones()"
      [style.height.px]="alto()"
      class="grafico"
      role="img"
      [attr.aria-label]="descripcion()"
    ></div>
  `,
  styles: `.grafico { width: 100%; }`,
})
export class BarChart {
  /** Etiquetas del eje: qué se compara. */
  readonly categorias = input.required<string[]>();
  readonly series = input.required<SerieBarra[]>();
  /** Tope de la escala. Fijarlo evita que barras de 4,1 y 4,2 se vean iguales. */
  readonly maximo = input<number>(5);
  readonly titulo = input<string>('');

  protected readonly alto = computed(() => {
    const filas = this.categorias().length;
    const porSerie = Math.max(this.series().length, 1);
    // Alto proporcional al contenido: con pocas categorías, un alto fijo deja
    // barras enormes; con muchas, las aplasta.
    return Math.max(160, 42 + filas * (18 * porSerie + 22));
  });

  protected readonly descripcion = computed(() => {
    const s = this.series();
    return `${this.titulo() || 'Gráfico de barras'}: ${s.length} series sobre ${this.categorias().length} categorías, escala de 0 a ${this.maximo()}.`;
  });

  protected readonly opciones = computed<EChartsCoreOption>(() => {
    const t = tinta();
    const varias = this.series().length > 1;
    const colores = varias ? paleta() : [colorSerieUnica()];

    return {
      color: colores,
      backgroundColor: 'transparent',
      animationDuration: 300,
      grid: { left: 8, right: 44, top: varias ? 34 : 8, bottom: 4, containLabel: true },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        valueFormatter: (v: number) => (v == null ? 'Sin datos' : v.toFixed(1)),
      },
      // Con una sola serie el título ya la nombra: una leyenda de un ítem
      // es ruido.
      legend: varias
        ? { top: 0, left: 0, textStyle: { color: t.secundaria, fontSize: 12 }, icon: 'roundRect', itemWidth: 12, itemHeight: 12 }
        : undefined,
      xAxis: {
        type: 'value',
        min: 0,
        max: this.maximo(),
        splitLine: { lineStyle: { color: t.retícula, type: 'dashed' } },
        axisLabel: { color: t.secundaria, fontSize: 11 },
        axisLine: { show: false },
        axisTick: { show: false },
      },
      yAxis: {
        type: 'category',
        data: this.categorias(),
        inverse: true,
        axisLabel: {
          color: t.primaria,
          fontSize: 12,
          // Sin leyenda sobra ancho, así que las frases largas no se cortan.
          width: varias ? 190 : 300,
          overflow: 'truncate',
        },
        axisLine: { show: false },
        axisTick: { show: false },
      },
      series: this.series().map((s) => ({
        name: s.nombre,
        type: 'bar',
        data: s.valores,
        barMaxWidth: varias ? 12 : 18,
        // 2 px de separación entre barras contiguas, del color de la superficie.
        barCategoryGap: '35%',
        barGap: '15%',
        itemStyle: { borderRadius: [0, 4, 4, 0] },
        label: {
          show: true,
          position: 'right',
          color: t.secundaria,
          fontSize: 11,
          formatter: ({ value }: { value: number | null }) =>
            value == null ? '' : value.toFixed(1),
        },
      })),
    };
  });
}
