# Guía de temas

Cómo cambiar, agregar o quitar un tema de la aplicación.

La regla de fondo: **los colores no se eligen a mano**. Cada tema se define por
un tono y un croma, y las catorce fichas salen de una fórmula en OKLCH que se
valida contra WCAG AA antes de emitir una sola línea de CSS. Ocho temas por dos
modos son 176 colores: elegirlos a ojo garantiza que alguno quede ilegible.

---

## Dónde vive cada cosa

| Archivo | Qué contiene |
|---|---|
| `docs/temas.mjs` | El generador: la lista de temas, la fórmula y la validación. **Acá se edita.** |
| `frontend/src/styles.scss` | El bloque `@layer theme` con los temas ya generados. **No editar a mano.** |
| `frontend/src/app/core/theme/theme.service.ts` | La lista que ve el panel, con las muestras de color. |
| `frontend/src/app/shared/selector-tema/` | El botón de la barra y su panel. |

---

## Cambiar el color de un tema

Abrí `docs/temas.mjs` y buscá la lista `TEMAS`:

```js
export const TEMAS = [
  { id:'violeta',  nombre:'Violeta',  h:300, h2:340, c:0.11 },
  { id:'azul',     nombre:'Azul',     h:250, h2:210, c:0.11 },
  ...
];
```

- **`h`** — el tono del acento, de 0 a 360. Es lo único que hay que mover para
  cambiar el color de un tema.
- **`h2`** — el tono de la segunda voz (el paso activo del asistente, el realce
  de una cifra). Suele ir entre 30 y 60 grados del principal.
- **`c`** — cuánta saturación. `0.11` es vivo; `0.03` da un tema casi neutro,
  como *Grafito*.

Referencia rápida de tonos:

```
  0 rojo      60 amarillo     120 verde     180 turquesa
210 celeste  250 azul        275 índigo    300 violeta    340 magenta
```

Después, **regenerá** (los tres pasos, en orden):

```bash
cd ~/Escritorio/proyectos/evaluacion-persona-frontend

# 1. Validar. Si algo no pasa, el paso 2 se niega a emitir.
node docs/temas.mjs validar

# 2. Emitir el CSS y reemplazar el bloque en styles.scss (ver abajo).
node docs/temas.mjs css > /tmp/temas.scss

# 3. Actualizar las muestras del panel.
node docs/temas.mjs muestras
```

Para el paso 2: en `frontend/src/styles.scss`, reemplazá el bloque que empieza
con `/* ---…--- Temas.` y termina en el `}` que cierra su `@layer theme` por el
contenido de `/tmp/temas.scss`.

Para el paso 3: pegá la salida dentro de `TEMAS` en `theme.service.ts`. Esas
muestras están escritas y no leídas del CSS a propósito: el panel tiene que
poder dibujar la ficha de un tema **sin aplicarlo**, y las variables solo
existen para el que está puesto.

---

## Agregar un tema

Una línea más en `TEMAS`, con un `id` sin acentos ni espacios:

```js
{ id:'oceano', nombre:'Océano', h:220, h2:190, c:0.10 },
```

Y regenerar con los tres pasos de arriba. No hace falta tocar nada más: el
panel arma su lista desde `TEMAS`.

---

## Si la validación falla

La salida dice exactamente qué par y cuánto le falta:

```
✗ oceano/claro  ink-3/surface  4.20 < 4.5
```

Casi siempre se arregla moviendo la **luminosidad** de esa ficha en la función
`receta()`, no el tono. El primer número de `oklchAHex(L, C, H)` es la
luminosidad, de 0 (negro) a 1 (blanco): bajarlo oscurece.

Ojo con algo que ya pasó: **si una ficha falla, suele fallar en los ocho temas**,
porque todos usan la misma fórmula. Se corrige una vez y se arregla en todos.

---

## Qué NO sigue al tema

**La paleta categórica de los gráficos** (`chart-theme.ts`, `SERIES_CLARO` y
`SERIES_OSCURO`) es fija a propósito. Esos cinco colores están validados para
daltonismo, separación de croma y contraste; volverlos variaciones del tema
los haría indistinguibles justo donde importa distinguirlos.

Lo que sí sigue al tema es el color de **serie única** y las tintas de los ejes,
que se leen de las variables CSS en el momento de dibujar.

---

## Cómo se elige desde la aplicación

Botón en la barra superior. Dos ejes independientes:

- **Modo** → atributo `data-theme` en `<html>`: `dark`, `light`, o **sin
  atributo** cuando es «Sistema», para que mande `prefers-color-scheme` en vivo.
- **Color** → atributo `data-tema` en `<html>`.

Ambos se guardan en `localStorage` (`ep-modo` y `ep-tema`). Si el navegador
bloquea el almacenamiento, la app funciona igual: pierde la memoria entre
sesiones, no el tema de la sesión actual.
