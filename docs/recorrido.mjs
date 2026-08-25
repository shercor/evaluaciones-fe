/**
 * Recorrido automatizado: pulsa cada control de cada pantalla y reporta
 * los que no producen ningún efecto observable.
 *
 * El bug que busca ya apareció tres veces: una pantalla construida a la que
 * nunca se le puso el enlace. Verificar por API no lo detecta, porque la API
 * responde perfecto; lo que falta es el clic.
 *
 * Cómo correrlo, con el proyecto levantado:
 *
 *   npm i puppeteer && node docs/recorrido.mjs
 *
 * No modifica datos: los controles que escriben están en la lista NO_TOCAR,
 * para que el recorrido se pueda repetir tantas veces como haga falta.
 */
import puppeteer from 'puppeteer';

const B = 'http://localhost:4200';

// Nada que modifique datos: el recorrido tiene que poder repetirse.
const NO_TOCAR = /cerrar sesi|^salir$|^crear |^armar |continuar|deshacer|^excluir |^incluir |dejarlas fuera|^abrir$|^cerrar$|^monitorear$|eliminar|borrar|desactivar|^activar|guardar|enviar|importar|invitar|^clave|confirmar|publicar|reabrir|finalizar|generar/i;

const PANTALLAS = {
  'patricia.soto@empresa.test': [
    ['Inicio admin', '/admin'],
    ['Evaluaciones', '/admin/evaluaciones'],
    ['Asistente · definir', '/admin/evaluaciones/asistente/definir'],
    ['Asistente · sucursales', '/admin/evaluaciones/asistente/3/sucursales'],
    ['Asistente · participantes', '/admin/evaluaciones/asistente/3/participantes'],
    ['Asistente · revisar', '/admin/evaluaciones/asistente/3/previsualizacion'],
    ['Tablero', '/admin/evaluaciones/2/tablero'],
    ['Monitoreo', '/admin/evaluaciones/2/monitoreo'],
    ['Formularios de evaluación', '/admin/evaluaciones/2/formularios'],
    ['Directorio', '/admin/directorio'],
    ['Sucursales', '/admin/directorio/sucursales'],
    ['Cargos', '/admin/directorio/cargos'],
    ['Importar nómina', '/admin/directorio/importar'],
    ['Grupos', '/admin/grupos'],
  ],
  'valentina.rojas@empresa.test': [
    ['Portal', '/portal'],
    ['__seguir_enlaces__', '/portal'],
  ],
};

async function entrar(p, correo) {
  await p.goto(`${B}/login`, { waitUntil: 'networkidle2' });
  await p.type('#email', correo);
  await p.type('#password', 'password');
  await Promise.all([
    p.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
    p.click('button[type=submit]'),
  ]);
  await new Promise((r) => setTimeout(r, 1500));
}

const nav = await puppeteer.launch({
  args: ['--no-sandbox', '--disable-gpu'],
  defaultViewport: { width: 1400, height: 1000 },
});

const hallazgos = [];
const errores = [];

for (const [correo, rutas] of Object.entries(PANTALLAS)) {
  const contexto = await nav.createBrowserContext();
  const p = await contexto.newPage();
  let errorPagina = null;
  p.on('pageerror', (e) => { errorPagina = String(e).slice(0, 160); });

  await entrar(p, correo);

  console.log(`\n╔═ ${correo}`);

  const pendientes = [...rutas];

  for (let n = 0; n < pendientes.length; n++) {
    let [titulo, ruta] = pendientes[n];

    if (titulo === '__seguir_enlaces__') {
      await p.goto(B + ruta, { waitUntil: 'networkidle2' });
      await new Promise((r) => setTimeout(r, 2500));
      const enlaces = await p.evaluate(() =>
        [...new Set(
          [...document.querySelectorAll('a[href]')]
            .map((a) => new URL(a.href, location.origin).pathname)
            .filter((h) => h.startsWith('/portal/')),
        )],
      );
      enlaces.forEach((h) => pendientes.push([h, h]));
      console.log(`║ (enlaces descubiertos: ${enlaces.length})`);
      continue;
    }

    await p.goto(B + ruta, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 2500));

    // Los controles se identifican por posición dentro de la lista, porque
    // hay que volver a la pantalla entre clic y clic y los nodos se recrean.
    const controles = await p.evaluate((saltar) => {
      const re = new RegExp(saltar, 'i');
      return [...document.querySelectorAll('button:not([disabled]), a[href], .mini, [role=button]')]
        .map((e) => {
          const t = (e.innerText || e.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ');
          const inerte =
            e.disabled ||
            e.getAttribute('aria-disabled') === 'true' ||
            getComputedStyle(e).pointerEvents === 'none';
          const yaActivo =
            e.classList.contains('activa') ||
            e.classList.contains('activo') ||
            e.classList.contains('elegida') ||
            e.getAttribute('aria-current') === 'step' ||
            e.getAttribute('aria-current') === 'page' ||
            (e.tagName === 'A' && new URL(e.href, location.origin).pathname === location.pathname);
          return { t, saltar: !t || re.test(t) || inerte || yaActivo };
        })
        .map((c, i) => ({ ...c, i }));
    }, NO_TOCAR.source);

    const activos = controles.filter((c) => !c.saltar);
    console.log(`║ ${titulo}  (${activos.length} controles)`);

    for (const ctrl of activos) {
      await p.goto(B + ruta, { waitUntil: 'networkidle2' });
      await new Promise((r) => setTimeout(r, 2200));
      errorPagina = null;

      let peticiones = 0;
      const contar = () => { peticiones++; };
      p.on('request', contar);

      const antes = await p.evaluate(() => ({
        url: location.pathname + location.search,
        texto: document.body.innerText.length,
        firma: document.body.innerText.slice(0, 4000),
        modales: document.querySelectorAll('.modal-fondo').length,
        scroll: [...document.querySelectorAll('*')]
          .reduce((n, e) => n + e.scrollTop, window.scrollY),
      }));

      const seClickeo = await p.evaluate((i) => {
        const e = [...document.querySelectorAll('button:not([disabled]), a[href], .mini, [role=button]')][i];
        if (!e) return false;
        e.click();
        return true;
      }, ctrl.i);

      await new Promise((r) => setTimeout(r, 1800));
      p.off('request', contar);

      if (!seClickeo) continue;

      const despues = await p.evaluate(() => ({
        url: location.pathname + location.search,
        texto: document.body.innerText.length,
        firma: document.body.innerText.slice(0, 4000),
        modales: document.querySelectorAll('.modal-fondo').length,
        scroll: [...document.querySelectorAll('*')]
          .reduce((n, e) => n + e.scrollTop, window.scrollY),
      }));

      const cambio =
        antes.url !== despues.url ||
        antes.modales !== despues.modales ||
        antes.firma !== despues.firma ||
        antes.scroll !== despues.scroll;

      if (despues.url === '/login' && antes.url !== '/login') {
        console.log(`║   ✗ «${ctrl.t}» cerró la sesión; se vuelve a entrar`);
        await entrar(p, correo);
        continue;
      }

      if (errorPagina) {
        errores.push(`${titulo} › «${ctrl.t}» → ${errorPagina}`);
        console.log(`║   ✗ «${ctrl.t}» rompe: ${errorPagina.slice(0, 90)}`);
      } else if (!cambio && peticiones === 0) {
        hallazgos.push(`${titulo} › «${ctrl.t}»`);
        console.log(`║   ⚠ «${ctrl.t}» no hace nada`);
      }
    }
  }
  await contexto.close();
}

console.log('\n════════ RESUMEN ════════');
console.log(`Sin efecto: ${hallazgos.length}`);
hallazgos.forEach((h) => console.log('  ⚠ ' + h));
console.log(`Con error: ${errores.length}`);
errores.forEach((h) => console.log('  ✗ ' + h));

await nav.close();
