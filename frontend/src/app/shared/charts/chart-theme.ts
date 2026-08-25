/**
 * Paleta y ajustes comunes de los gráficos.
 *
 * Los colores salen de una paleta categórica validada: se comprobó la banda de
 * luminosidad, el piso de croma, la separación para daltonismo y el contraste
 * contra la superficie, en los dos modos. **No inventar colores nuevos acá** —
 * cualquier cambio hay que volver a validarlo.
 *
 * En modo claro tres de los cinco quedan por debajo de 3:1 contra el fondo, así
 * que los gráficos llevan **la cifra escrita sobre cada barra**: la identidad
 * nunca depende solo del color.
 */

/** Orden fijo. Nunca se cicla ni se genera un color nuevo para una serie extra. */
export const SERIES_CLARO = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];
export const SERIES_OSCURO = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181'];

export function esModoOscuro(): boolean {
  const stamp = document.documentElement.getAttribute('data-theme');
  if (stamp === 'dark') return true;
  if (stamp === 'light') return false;
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

export function paleta(): string[] {
  return esModoOscuro() ? SERIES_OSCURO : SERIES_CLARO;
}

/**
 * El color de un gráfico de **una sola serie**.
 *
 * La paleta categórica existe para que dos series contiguas se distingan
 * entre sí. Con una sola no hay nada de qué distinguirla, así que usar el
 * primer color de esa paleta solo consigue que el gráfico se vea ajeno al
 * resto de la aplicación. Acá va el acento de la marca, que además tiene
 * contraste de sobra contra la superficie en los dos modos.
 */
export function colorSerieUnica(): string {
  return token('--color-accent', esModoOscuro() ? '#c4a2e0' : '#654a8f');
}

/**
 * Lee un color del tema activo.
 *
 * Los gráficos no pueden llevar el color escrito: con ocho temas elegibles,
 * un hexadecimal fijo hace que el gráfico se vea ajeno al resto de la
 * aplicación en siete de los ocho. El respaldo cubre el caso de que la
 * variable no exista todavía —antes de que el tema se aplique—.
 */
function token(nombre: string, respaldo: string): string {
  const v = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
  return v || respaldo;
}

/** Tinta de los textos. Nunca se usa el color de la serie para escribir. */
export function tinta(): { primaria: string; secundaria: string; retícula: string } {
  const oscuro = esModoOscuro();

  return {
    primaria: token('--color-ink', oscuro ? '#ede6f2' : '#241c2b'),
    secundaria: token('--color-ink-2', oscuro ? '#b9aec4' : '#544a5e'),
    retícula: token('--color-rule', oscuro ? '#413550' : '#ddd2e6'),
  };
}
