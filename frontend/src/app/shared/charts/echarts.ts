/**
 * Carga solo lo que se usa de ECharts.
 *
 * Importar la biblioteca entera son cientos de kilobytes de gráficos que este
 * proyecto no dibuja. Acá se registran únicamente las barras y los componentes
 * que las acompañan.
 *
 * **Se reexportan los nombres, no un `default`.** `ngx-echarts` resuelve el
 * módulo y desestructura `init` de él: con `export default` recibía
 * `{ default: … }`, `init` quedaba indefinido y el gráfico no se dibujaba
 * nunca — sin ningún error visible, solo un hueco en blanco.
 */
import * as echarts from 'echarts/core';
import { BarChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([BarChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

export * from 'echarts/core';
