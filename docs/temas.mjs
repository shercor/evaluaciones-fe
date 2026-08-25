/**
 * Genera los ocho temas.
 *
 * Cada uno se define por un tono (H) y un croma (C); el resto sale de una
 * fórmula fija en OKLCH. Elegir 176 colores a mano garantiza que alguno
 * quede fuera de contraste; con una fórmula, la única variable es el tono.
 */

// --- OKLCH → sRGB (fórmulas estándar de Björn Ottosson) --------------
const f = (x) => (x <= 0.0031308 ? 12.92*x : 1.055*Math.pow(x, 1/2.4) - 0.055);
function oklchAHex(L, C, H) {
  const h = H * Math.PI / 180;
  const a = C * Math.cos(h), b = C * Math.sin(h);
  const l_ = L + 0.3963377774*a + 0.2158037573*b;
  const m_ = L - 0.1055613458*a - 0.0638541728*b;
  const s_ = L - 0.0894841775*a - 1.2914855480*b;
  const l = l_**3, m = m_**3, s = s_**3;
  const rgb = [
    +4.0767416621*l - 3.3077115913*m + 0.2309699292*s,
    -1.2684380046*l + 2.6097574011*m - 0.3413193965*s,
    -0.0041960863*l - 0.7034186147*m + 1.7076147010*s,
  ].map(v => Math.round(Math.min(1, Math.max(0, f(v))) * 255));
  return '#' + rgb.map(v => v.toString(16).padStart(2,'0')).join('');
}

// --- Contraste WCAG --------------------------------------------------
const lum = (hex) => {
  const c = [1,3,5].map(i => parseInt(hex.slice(i,i+2),16)/255)
    .map(v => v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4));
  return 0.2126*c[0] + 0.7152*c[1] + 0.0722*c[2];
};
const ratio = (a,b) => { const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p); return (x+0.05)/(y+0.05); };

// --- Los ocho temas: tono principal, tono de la segunda voz, croma ---
export const TEMAS = [
  { id:'violeta',  nombre:'Violeta',  h:300, h2:340, c:0.11 },
  { id:'azul',     nombre:'Azul',     h:250, h2:210, c:0.11 },
  { id:'teal',     nombre:'Verde azulado', h:180, h2:265, c:0.09 },
  { id:'bosque',   nombre:'Bosque',   h:150, h2:100, c:0.09 },
  { id:'borgona',  nombre:'Borgoña',  h:15,  h2:330, c:0.11 },
  { id:'cobre',    nombre:'Cobre',    h:55,  h2:25,  c:0.10 },
  { id:'indigo',   nombre:'Índigo',   h:275, h2:230, c:0.13 },
  { id:'grafito',  nombre:'Grafito',  h:270, h2:270, c:0.03 },
];

// Luminosidad y croma de cada ficha. Los neutros llevan una pizca del tono
// para que no se lean fríos al lado del acento.
const receta = (t) => ({
  claro: {
    paper:      oklchAHex(0.972, t.c*0.045, t.h),
    surface:    '#ffffff',
    'surface-2':oklchAHex(0.945, t.c*0.075, t.h),
    ink:        oklchAHex(0.245, t.c*0.30,  t.h),
    'ink-2':    oklchAHex(0.450, t.c*0.22,  t.h),
    'ink-3':    oklchAHex(0.540, t.c*0.17,  t.h),
    rule:       oklchAHex(0.885, t.c*0.10,  t.h),
    accent:        oklchAHex(0.455, t.c, t.h),
    'accent-hover':oklchAHex(0.390, t.c, t.h),
    'accent-ink':  '#ffffff',
    'accent-soft': oklchAHex(0.935, t.c*0.16, t.h),
    realce:        oklchAHex(0.500, t.c*0.95, t.h2),
    'realce-soft': oklchAHex(0.940, t.c*0.16, t.h2),
    'realce-ink':  '#ffffff',
  },
  oscuro: {
    paper:      oklchAHex(0.175, t.c*0.16, t.h),
    surface:    oklchAHex(0.225, t.c*0.16, t.h),
    'surface-2':oklchAHex(0.275, t.c*0.18, t.h),
    ink:        oklchAHex(0.945, t.c*0.10, t.h),
    'ink-2':    oklchAHex(0.780, t.c*0.12, t.h),
    'ink-3':    oklchAHex(0.635, t.c*0.13, t.h),
    rule:       oklchAHex(0.360, t.c*0.20, t.h),
    accent:        oklchAHex(0.800, t.c*0.80, t.h),
    'accent-hover':oklchAHex(0.865, t.c*0.65, t.h),
    'accent-ink':  oklchAHex(0.215, t.c*0.55, t.h),
    'accent-soft': oklchAHex(0.310, t.c*0.35, t.h),
    realce:        oklchAHex(0.815, t.c*0.75, t.h2),
    'realce-soft': oklchAHex(0.315, t.c*0.32, t.h2),
    'realce-ink':  oklchAHex(0.225, t.c*0.50, t.h2),
  },
});

const CLAVES = [
  'paper', 'surface', 'surface-2', 'ink', 'ink-2', 'ink-3', 'rule',
  'accent', 'accent-hover', 'accent-ink', 'accent-soft',
  'realce', 'realce-soft', 'realce-ink',
];

/** Comprueba los once pares críticos de cada tema, en los dos modos. */
function validar(aStderr = false) {
  const decir = aStderr ? console.error : console.log;
  let fallos = 0;

  for (const t of TEMAS) {
    const r = receta(t);

    for (const [modo, p] of [['claro', r.claro], ['oscuro', r.oscuro]]) {
      const pares = [
        ['ink/surface', p.ink, p.surface, 4.5],
        ['ink-2/surface', p['ink-2'], p.surface, 4.5],
        ['ink-3/surface', p['ink-3'], p.surface, 4.5],
        ['ink-3/paper', p['ink-3'], p.paper, 4.5],
        ['accent/surface', p.accent, p.surface, 4.5],
        ['accent-ink/accent', p['accent-ink'], p.accent, 4.5],
        ['accent/accent-soft', p.accent, p['accent-soft'], 4.5],
        ['realce/surface', p.realce, p.surface, 4.5],
        ['realce/realce-soft', p.realce, p['realce-soft'], 4.5],
        ['realce-ink/realce', p['realce-ink'], p.realce, 4.5],
        ['rule/surface', p.rule, p.surface, 1.4],
      ];

      for (const [q, a, b, min] of pares) {
        const v = ratio(a, b);
        if (v < min) {
          fallos++;
          decir(`  ✗ ${t.id}/${modo}  ${q.padEnd(20)} ${v.toFixed(2)} < ${min}`);
        }
      }
    }
  }

  decir(fallos === 0
    ? `\n  los ${TEMAS.length} temas pasan en claro y oscuro`
    : `\n  ${fallos} pares por debajo del mínimo`);

  return fallos;
}

/** Escribe el bloque de CSS listo para pegar en `styles.scss`. */
function emitirCss() {
  let out = `/* ------------------------------------------------------------------
   Temas.

   GENERADO POR docs/temas.mjs — no editar a mano.

   Cada tema se define por un tono y un croma; el resto de los valores sale
   de una fórmula fija en OKLCH y está validado contra WCAG AA en claro y
   en oscuro.

   Dos ejes independientes: \`data-tema\` (qué paleta) y \`data-theme\`
   (claro u oscuro). Cada paleta guarda sus dos juegos —\`--t-*\` para el
   claro, \`--td-*\` para el oscuro— y el mapeo de abajo decide cuál rige.
   ------------------------------------------------------------------ */

@layer theme {
`;

  for (const t of TEMAS) {
    const r = receta(t);
    out += `\n  /* ${t.nombre} */\n  :root[data-tema='${t.id}'] {\n`;
    for (const k of CLAVES) out += `    --t-${k}: ${r.claro[k]};\n`;
    out += '\n';
    for (const k of CLAVES) out += `    --td-${k}: ${r.oscuro[k]};\n`;
    out += '  }\n';
  }

  const mapa = (pref, sangria) =>
    CLAVES.map((k) => `${sangria}--color-${k}: var(--${pref}-${k});`).join('\n');

  out += `
  /* Claro: el juego \`--t-*\`. */
  :root[data-tema] {
${mapa('t', '    ')}
  }

  /* Oscuro por preferencia del sistema, salvo que se haya forzado el claro. */
  @media (prefers-color-scheme: dark) {
    :root[data-tema]:not([data-theme='light']) {
${mapa('td', '      ')}
    }
  }

  /* Oscuro forzado desde la aplicación. */
  :root[data-tema][data-theme='dark'] {
${mapa('td', '    ')}
  }
}
`;

  console.log(out);
}

/** Las muestras que el panel dibuja sin aplicar el tema. */
function emitirMuestras() {
  for (const t of TEMAS) {
    const r = receta(t);
    console.log(`  { id: '${t.id}', nombre: '${t.nombre}', acento: '${r.claro.accent}', realce: '${r.claro.realce}' },`);
  }
}

const orden = process.argv[2];

if (orden === 'validar') {
  process.exitCode = validar() === 0 ? 0 : 1;
} else if (orden === 'css') {
  if (validar(true) !== 0) {
    console.error('\n  no se emite CSS con pares fuera de contraste');
    process.exitCode = 1;
  } else {
    emitirCss();
  }
} else if (orden === 'muestras') {
  emitirMuestras();
} else {
  console.log('uso: node docs/temas.mjs validar | css | muestras');
}

export { receta };
