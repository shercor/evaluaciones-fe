# Handoff — estado del proyecto

Bitácora viva del avance. Se actualiza al cerrar cada hito, para que el estado
no dependa de la memoria de nadie.

**Última actualización:** 2026-08-26 · los 8 hitos terminados · avisos por correo y fotos de perfil.

---

## Qué es esto

Reemplazo autónomo del módulo de Evaluación de Personal de la intranet
(`cl_i1_intranet_modular`). App Angular sobre un BFF Laravel con base de datos
propia. **No depende de la intranet** y **no modifica la API de Evaluación 360**
(`ideauno-evaluacion360-backend-laravel`).

Documentos de referencia:

- **Plan de construcción** — <https://claude.ai/code/artifact/e7ff8542-5d6b-4a31-904d-309ebba32942>
- **Auditoría del módulo que se reemplaza** — <https://claude.ai/code/artifact/e5feb67e-4e74-40f9-bfe6-e934682940c9>

La auditoría es la referencia para verificar que no se pierda ninguna regla de
negocio. Conviene tenerla abierta al construir cada hito.

---

## Dónde quedó (25 de agosto de 2026)

Los ocho hitos están cerrados. Lo que vino después fue **auditar contra la
intranet y endurecer**, no agregar funcionalidad:

- Se repasaron paso a paso el asistente de creación, la depuración de
  participantes y la revisión final contra el módulo de la intranet. Salieron
  una docena de diferencias de comportamiento —el período corrido en uno, el
  padrón editable con el proceso terminado, «Deshacer cambios» sin botón que
  lo llamara— y están todas corregidas y anotadas en *Decisiones tomadas*.
- Se pasó el sistema a **ocho temas elegibles** con modo claro/oscuro
  (`docs/TEMAS.md`).
- Se sembró una **empresa de 7.245 personas** para medir bajo carga. El
  resultado está más abajo, en *Qué aguanta y qué no*.

Lo que sigue sin hacerse está en *Deuda y pendientes conocidos*.

---

## Avance

| # | Hito | Estado |
|---|------|--------|
| 1 | Andamiaje y Docker | ✅ Terminado |
| 2 | Acceso | ✅ Terminado |
| 3 | Directorio | ✅ Terminado |
| 4 | Listado de evaluaciones | 🔨 Listado y transiciones hechos |
| 5 | Asistente de creación | ✅ Terminado |
| 6 | Portal del colaborador | ✅ Terminado |
| 7 | Resultados y tableros | ✅ Terminado |
| 8 | Cierre | ✅ Terminado |

### Hito 1 — Andamiaje ✅

Siete servicios en Docker: `angular`, `nginx`, `php`, `mysql`, `queue`,
`mailpit` —la bandeja de correo de desarrollo— y `scheduler`, que corre las
tareas de limpieza.
Cliente de Evaluación 360 portado en `backend/app/Support/E360/`, con el
comando `php artisan e360:ping` que diagnostica las tres capas por separado
(configuración, plano central, plano tenant). Pantalla de verificación en
`/estado`.

**Verificado:** SPA 200, `/api/health` directo y por el proxy, migraciones
contra MySQL, worker consumiendo un job real.

**Conexión con E360 verificada (2026-08-24).** Tenant de pruebas
`portal-pruebas` registrado y sembrado con `php artisan e360:register-tenant`,
y `php artisan e360:ping` confirma las tres capas. El tenant nace con 1
plantilla («Template 0», 5 formularios) y 0 evaluaciones.

### Hito 2 — Acceso ✅

Login con límite de intentos, recuperación por correo, definición de la primera
contraseña por los dos caminos, cambio de contraseña, tres roles, guards y los
dos portales con su navegación.

**Verificado por HTTP:** login de los tres roles con su redirección, colaborador
→ 403 en zona admin, admin y super admin → 200, límite de intentos (5×422 →
429), y el ciclo completo de recuperación de contraseña.

### Hito 3 — Directorio ✅

Personas, sucursales, cargos, organigrama e importación de nómina.

**Importación.** Acepta CSV y XLSX. Una fila mala no voltea el archivo: cada
fila se valida y se registra por separado, y al final se informa qué se creó,
qué se actualizó y qué se rechazó con su motivo exacto. `external_code` es la
identidad, así que reimportar la misma planilla es idempotente. Las sucursales
y los cargos que no existan se crean solos. La jerarquía se resuelve al final,
para que una fila pueda nombrar como supervisor a alguien que aparece más abajo
en el archivo.

**Homologar una planilla con otro formato** (2026-08-26). El camino de arriba
exige que los encabezados sean los que el sistema espera. El segundo camino no
exige ninguno: la planilla llega como la tenía Recursos Humanos —«N° Ficha»,
«Nombre del Trabajador», «Local Asignado», «Jefe Directo»— y es una persona la
que conecta cada campo del sistema con una columna del archivo. Lo único
obligatorio es que la primera fila traiga nombres de columna.

Son tres pasos separados porque entre medio hay trabajo humano: se sube el
archivo y se leen sus encabezados, se homologa, y se revisa un resumen **antes**
de tocar el directorio. El archivo queda mientras tanto en el disco privado del
servidor, anotado en `import_drafts`; se borra al importar, al descartar, o solo
a las 24 horas si alguien abandona a mitad de camino —son nóminas completas de
la empresa y no pueden quedar ahí para siempre—.

- **La sugerencia inicial** mira los nombres de los encabezados contra una lista
  de sinónimos. En la planilla de prueba acertó las ocho columnas. Prioriza la
  coincidencia exacta sobre la parcial y no ofrece dos veces la misma columna:
  con «supervisor» y «codigo_supervisor» juntas hay que elegir la segunda, y una
  planilla que trae el código del jefe pero no el de la persona no puede
  terminar con la columna del jefe puesta en «código interno».
- **El resumen es el corazón de la función**, no un trámite: muestra las
  primeras filas con los datos reales del archivo puestos debajo del campo del
  sistema al que se conectaron, y en cada encabezado dice de qué columna salen.
  Es el único lugar donde alguien puede notar que conectó el *nombre* del jefe
  donde va su *código*. Además adelanta los números —cuántas se crean, cuántas
  se actualizan, cuántas se rechazan y por qué— consultando el directorio, sin
  escribir nada.
- **Lo que se rechaza** se rechaza fila por fila, como en el otro camino. La
  homologación no agrega un modo «todo o nada».
- **Todo se homologa como texto.** Ninguna columna del directorio guarda números
  ni fechas, así que traducir es renombrar claves. El lector pasa toda celda a
  texto, lo que además arregla un problema viejo del otro camino: una ficha que
  Excel guardó como número llegaba como `123.0` y no coincidía con el `123` que
  ya estaba en el directorio, duplicando a la persona.

**Los dos caminos para la contraseña.** Con correo se envía invitación; sin
correo se genera una temporal que el administrador descarga en CSV y entrega en
mano. Los dos terminan en `must_set_password`.

**Jerarquía.** `SupervisionChain` reemplaza a `getAllSubordinates()` de la
intranet, que lanzaba una consulta por nodo dentro de un bucle y no se protegía
de ciclos —con datos malos entraba en recursión infinita—. Acá cada recorrido
es **una sola consulta recursiva** con tope de profundidad, y hay una variante
que cuenta los supervisados de toda una página de golpe.

**Verificado:** importación de una planilla con 7 filas y 3 errores a propósito
(sin nombre, correo inválido, sucursal inexistente) → 4 creadas y 3 rechazadas, cada
una con su motivo; contraseña temporal solo para quien no tenía correo; aviso de
supervisor inexistente; y la detección de ciclos rechazando tanto «ser jefe de
uno mismo» como «poner a la nieta de jefa».

**Corregido en el camino:** el lector de CSV del paquete autodetectaba mal el
separador y partía las filas por los espacios. Ahora el separador se detecta
entre coma, punto y coma, tabulación y barra — importante porque **Excel en
español exporta con punto y coma**, y dar por sentada la coma habría roto la
mitad de los archivos que llegan de Recursos Humanos.

### Hito 4 — Listado de evaluaciones 🔨

Hecho: el listado con filtros (nombre, año, período, estado), los seis estados
con su color y descripción, y las transiciones **abrir, cerrar, publicar,
desactivar y reactivar**.

**Las acciones las decide el backend.** `EvaluationActions` calcula qué se
puede hacer con cada evaluación según su estado, y la respuesta las manda en
`acciones`. La vista solo dibuja lo que recibe, y el backend **vuelve a
comprobarlo** antes de ejecutar. En la intranet esa lógica estaba dentro de
`index.ctp` como condiciones encadenadas alrededor de cada botón, repetidas
nueve veces.

**La consulta de procesos «preparando» refresca solo esas filas**, en vez de
recargar la página entera cada 10 segundos y restaurar el scroll desde
`localStorage` como hace la intranet.

Falta de este hito: crear y editar evaluaciones, que dependen del asistente del
hito 5. Los botones de participantes, monitoreo y resultados aparecen apagados
con su hito indicado.

### Hito 5 — Asistente de creación ✅

Cuatro pantallas encadenadas que cubren los seis pasos del flujo original.
El paso lo determina la ruta, no una bandera: la `?creating=1` que en la
intranet viajaba por toda la secuencia desapareció.

| Pantalla | Cubre |
|---|---|
| `definir` | Paso 1: plantilla, grupo, año, período y qué formularios se incluyen |
| `sucursales` | Pasos 2 y 3: elegir sucursales y materializar el padrón |
| `participantes` | Paso 4: excluir gente, con o sin su cadena, y corregir cargo/sucursal/supervisor |
| `previsualizacion` | Pasos 5 y 6: grupos por supervisor, huérfanos y envío |

Se entra desde **Evaluaciones → Crear evaluación**, y las filas del listado
enlazan a «Continuar» y «Participantes» según su estado.

Prueba real contra `portal-pruebas`: se creó la evaluación «Evaluación anual de
desempeño» (id 2), se eligieron las 3 sucursales, el padrón quedó en 10
personas —el super administrador excluido correctamente—, se desactivó a una
supervisora arrastrando su cadena (3 afectados), quedaron 4 grupos por
supervisor sin huérfanos, y el envío dejó el proceso en «preparando». La API generó **32 tareas** y el
proceso pasó a «Lista para abrir», ofreciendo entonces abrir, editar,
participantes, previsualizar y desactivar.

También verificado el camino de **edición posterior**: con la evaluación ya
creada, excluir a alguien dejó 1 cambio pendiente, y deshacer lo revirtió y
limpió la bitácora.

Tablas: `evaluation_users` (el padrón, con sucursal/cargo/supervisor
**congelados** al armar el proceso) y `evaluation_user_changes` (bitácora para
deshacer, un registro por persona con su estado original).

Servicios, todos portados de la intranet con sus reglas intactas:

| Servicio | Porta | Diferencia |
|---|---|---|
| `ParticipantRoster` | `updatePersonalEvaluationUsers()` | Una consulta para toda la nómina en vez de una por persona; ya no hacen falta los `set_time_limit(300)` ni `memory_limit 512M` |
| `SupervisorGroups` | `getSupervisorGroups()` | Indexa una vez en lugar de un `array_filter` anidado por supervisor (cuadrático) |
| `ParticipationEditor` | `updateUserParticipation()` + `editParticipantInfo()` | La cascada de supervisados es una consulta recursiva con tope de profundidad, no recursión PHP sin protección de ciclos |
| `ParticipationSubmission` | `getParticipationsToSend()` | Igual, incluidos los supervisores que no participan con `activo: false` |
| `ParticipantChanges` | `ParticipantsChangesBackupsTable` | Igual, en transacción |

### ⚠️ El stack local de E360 no levanta su worker de colas

`ParticipantService::createTasks()` encola las tareas con `Bus::batch()`, y
E360 usa **Horizon** sobre Redis. Pero su `docker-compose.yml` solo define
`app`, `webserver`, `db` y `redis`: **no hay ningún proceso que consuma la
cola**.

Consecuencia: al enviar el padrón, la evaluación se queda en «preparando» para
siempre. Los participantes sí se crean —el job inicial corre con
`dispatchAfterResponse`, dentro del propio proceso de php-fpm— pero las tareas
nunca se generan.

Para desbloquearlo en local, dentro del proyecto de E360:

```bash
docker compose exec -d app php artisan queue:work redis --queue=default,heavy --tries=1 --timeout=600
```

Con eso las 32 tareas se generaron en segundos y el proceso pasó a «Lista para
abrir». Conviene agregar un servicio de worker a su compose; mientras tanto,
**hay que acordarse de arrancarlo a mano cada vez que se levanta el stack**.

---

## ⚠️ Defecto encontrado en la API de Evaluación 360

**Desactivar una evaluación la hace desaparecer para siempre.** No se puede
listar ni reactivar; sus datos siguen en la base pero quedan inalcanzables.

La causa está en `app/Traits/HasActiveStatus.php` del backend de E360:

```php
// El scope se registra con la clave 'active'…
static::addGlobalScope('active', function ($query) { … });

// …pero se intenta quitar por nombre de clase, que nunca se registró.
public static function withInactive(): Builder
{
    return static::withoutGlobalScope(ActiveScope::class);   // no-op
}
```

Como la clave no coincide, `withInactive()` no quita nada y `onlyInactive()`
termina consultando `active = 1 AND active = 0`, que nunca devuelve nada. Por
eso `EvaluationController@restore` responde «Id no encontrado» y el listado
—que sí llama a `withInactive()`— tampoco las muestra.

**El arreglo es de una línea:** `withoutGlobalScope('active')`.

Verificado el 2026-08-24: tras desactivar la evaluación de prueba, la fila
seguía en la tabla con `active = 0` pero la API devolvía cero resultados.

Como acordamos **no modificar la API de E360**, este proyecto no lo corrige:
solo avisa. El diálogo de confirmación de «Desactivar» explica la consecuencia
real en vez de prometer que se puede deshacer.

### Estilos: Tailwind v4 y sistema visual

Todo el CSS propio se reemplazó por **Tailwind v4** (PostCSS). Los tokens de
color no se tiraron: viven ahora como `@theme` de Tailwind, así que
`bg-surface`, `text-ink-2` o `border-rule` significan lo mismo en toda la
aplicación y el tema se cambia en un solo archivo.

**El modo oscuro tiene tres estados, no dos** — elección explícita, elección
clara y «seguir al sistema», que no marca nada. Eso no lo cubre el `dark:` que
trae Tailwind por defecto, así que hay una `@custom-variant dark` propia que
maneja los tres y deja que la elección del usuario gane sobre la del sistema.

**Qué va en `@layer components` y qué en la plantilla:** lo que se repite en
decenas de vistas —botones, tarjetas, tablas, campos, distintivos— se define
una vez como clase; lo que aparece una o dos veces va como utilidades sueltas.
Copiar quince clases en cada uso garantiza que se desincronicen.

Se eliminaron los siete `.scss` por componente.

**Tipografía.** Dos familias con trabajos distintos: *Bricolage Grotesque* para
los títulos —una grotesca con carácter, de anchos variables— e *Instrument
Sans* para el cuerpo y la interfaz, que abre bien en los tamaños chicos de las
tablas. Se cargan desde Google Fonts en `index.html`.

**Acento secundario.** Un índigo (`--color-indigo`) que hace contrapunto al
verde. Está reservado para lo que merece una segunda voz —el paso activo del
asistente, el degradado de los medidores y los avatares— y nunca para relleno.
La distinción es deliberada: el acento principal marca *qué se puede tocar*, el
secundario marca *dónde estoy*.

**Movimiento.** Entrada de contenido, entrada escalonada en las grillas y
esqueletos de carga en lugar de «Cargando…». Todo tiene un trabajo: guiar la
mirada a lo que apareció o dar señal de que algo está en curso. El bloque de
`prefers-reduced-motion` lo apaga entero.

**Gráficos de una sola serie en color de marca.** La paleta categórica existe
para distinguir series entre sí; con una sola no hay nada que distinguir, y
usar el primer color de esa paleta solo lograba que el gráfico se viera ajeno
al resto. Los de varias series siguen con la paleta validada.

**Cuidado al borrar un `.scss`:** hay que quitar también su `styleUrl` del
componente. Un `styleUrl` apuntando a un archivo inexistente hace que Angular
falle con «JIT compilation failed» **en tiempo de ejecución** —la pantalla
queda en blanco— sin ningún error de compilación que lo delate.

---

## Decisiones tomadas

| Tema | Decisión | Cuándo |
|------|----------|--------|
| Arquitectura | Angular + BFF Laravel con BD propia. El BFF no es opcional: el token del tenant no puede vivir en el navegador, la API E360 no tiene noción de usuario, y no hay directorio de empleados en ninguna parte. | Hito 0 |
| Directorio | Propio, por importación CSV/Excel más ABM. Sin sincronizar con la intranet. | Hito 0 |
| Autenticación | Usuarios propios del BFF. Ni SSO ni FusionAuth. | Hito 0 |
| Gráficos | ECharts (`ngx-echarts`), no Highcharts, para no gestionar licencias. Los envoltorios van en `shared/charts/` para que la biblioteca sea reemplazable. | Hito 0 |
| Estilos | Tailwind v4, con los tokens de color como `@theme`. Sin Bootstrap ni bibliotecas de componentes. | 2026-08-25 |
| Correo transaccional | Sí, desde el hito 2: invitación y recuperación. | Hito 0 |
| Idioma de los correos | `APP_LOCALE=es` con `lang/es.json`. Solo afecta a las cadenas que trae el framework —el andamiaje del correo y todo el de recuperación de contraseña, que lo arma Laravel—: el código de la app escribe en español directo y no usa traducciones. Antes el correo de recuperación salía entero en inglés. | 2026-08-26 |
| Quién «no tiene correo» | `users.email` es NOT NULL y único, así que a quien no tiene casilla el importador le inventa `sin-correo.{codigo}@interno.local`. La regla vive en `User::scopeWithMailbox()` y `hasMailbox()`; nada debe mandar correo a ese dominio. | 2026-08-26 |
| Íconos | En línea, sin biblioteca: `viewBox` de 24, `fill="none"`, `stroke="currentColor"`, trazo 1,8 y esquinas redondeadas. En las tarjetas de atajo van en una placa de acento que se enciende con la tarjeta; en las cifras van chicos y apagados en la esquina, porque ahí el contenido es el número. Grupos usa un organigrama y no siluetas humanas, para no confundirse con Directorio. | 2026-08-26 |
| Filtrar por supervisor | Se puede en el padrón y en el directorio. En el directorio no existía: la caja de búsqueda mira nombre, correo y código **de la propia persona**, nunca el de su jefe. | 2026-08-26 |
| Búsqueda de personas | `<app-buscador-personas>`, en los cuatro sitios donde se elige a alguien —filtro y editor de participantes, filtro y formulario del directorio—: mínimo 3 caracteres, 300 ms de teclado quieto, sugerencias del servidor con el código al lado. Reemplaza desplegables que no escalan. El del padrón cargaba **7.078 filas y 56 MB en cada página** del listado para llenar un `<select>` de 527 opciones; ahora son 12–16 ms y solo viajan las coincidencias. El código va junto al nombre porque hay homónimos: 527 supervisores con 434 nombres distintos. En un formulario de edición se le pasa `[inicial]` para que abra mostrando a quien ya está asignado: en blanco se leería como «sin supervisor» y guardar sin tocarlo lo borraría. Las coincidencias las arma `PersonSuggestions`; cada pantalla decide **dónde** buscar. En el formulario del directorio quedan fuera la propia persona y toda su cadena de supervisados: elegir a un subordinado crearía un ciclo y el guardado lo rechazaría, así que ni se ofrece. | 2026-08-26 |
| Diálogos de dos caminos | Cuando un modal ofrece dos acciones afirmativas —«Abrir y notificar» / «Abrir sin notificar», y lo mismo al publicar— los botones **se apilan a lo ancho**, con el principal arriba. En una línea no entran: medido en navegador, piden 443 px y el modal ofrece 390. Se apilan en vez de ensanchar el modal para que todas las confirmaciones conserven el mismo ancho, y para que la fila no dependa del largo de las etiquetas. El orden del DOM es el visual, así el foco del teclado no va al revés. | 2026-08-26 |
| Avisos de negocio por correo | **Los tres, hechos**: apertura, recordatorio y resultados. Ninguno se manda solo: los tres los dispara el administrador desde el listado, y apertura y publicación ofrecen «hacerlo avisando» o «hacerlo en silencio». | 2026-08-26 |
| Correo en desarrollo | Mailpit, en el `docker-compose.yml`. Sin cuota y no entrega afuera: un envío a padrón completo no puede escaparse a casillas reales. Bandeja en <http://localhost:8025>. | 2026-08-26 |
| Proveedor de correo en producción | **Sin definir.** Mailtrap gratuito (50 correos al mes) sirve solo para controlar cómo se ve el correo en clientes reales, no para envíos de verdad. Decisión bloqueante del hito de avisos. | Hito 2 |
| Fotos de perfil | Todas nuevas. No se migra ninguna imagen de la intranet. | Hito 0 |
| Qué se guarda de una foto | **Nunca el archivo original.** Se endereza según el EXIF, se recorta cuadrada y se reescribe como WebP de 256 px —unos 20 KB—. Un solo tamaño para todas las pantallas: no hay que elegir versión en cada vista. Guardar el original serviría 4 MB para un círculo de 36 px y arrastraría los metadatos de la cámara, que incluyen dónde se tomó la foto. Todo en `AvatarStorage`. | 2026-08-26 |
| El recorte no va por el centro | En una foto vertical se toma a un tercio de la altura sobrante, no a la mitad: el encuadre de un retrato siempre deja aire de torso abajo, y recortar por el medio corta las cabezas. | 2026-08-26 |
| Cuándo se guarda la foto | Al elegirla, no al pulsar «Guardar». Es un archivo, no un campo: atarla al formulario obligaría a mandarlo entero para cambiar una foto. El formulario lo dice. La excepción es la persona que todavía no existe: ahí se muestra la elegida y se sube apenas se crea, que es cuando hay un id. | 2026-08-26 |
| Límites de subida de PHP | `docker/php/php.ini`, que antes no existía: PHP acepta 2 MB de fábrica y con eso no entra ni una foto de teléfono ni una planilla de nómina grande. nginx ya aceptaba 20 MB, así que el techo real lo ponía un archivo que nadie había escrito. | 2026-08-26 |
| Sesiones y espacio | Las **sesiones** van en la base y se limpian solas: el driver hace recolección por lotería —2 de cada 100 peticiones borran las vencidas—, así que la tabla se mantiene en decenas de filas y no necesita tarea. Lo que sí crecía sin techo era otra cosa, y por eso existe el contenedor `scheduler`: los trabajos fallidos, las planillas subidas a medias y las contraseñas temporales. La tabla `cache` no crece hoy porque el código de la aplicación no cachea nada; si algún día lo hace, el driver de base no borra lo vencido y va a hacer falta una tarea más. | 2026-08-26 |
| Las contraseñas temporales caducan | Se guardan **en claro** —hay que poder descargarlas y entregarlas en mano— y hasta ahora se guardaban para siempre. Ahora se olvidan a los 90 días: después de que la persona entró y la cambió no sirven para nada, y una tabla con miles de contraseñas de gente real es de lo que no conviene tener. La fila del registro no se borra: la auditoría de qué pasó con cada línea se queda. | 2026-08-26 |
| Los archivos huérfanos se barren por edad | Borrar un borrador borra su archivo, pero la fila puede irse sin él por tres caminos: el borrado en cascada de quien lo subió, un cambio de base de datos —la carpeta es una sola y las filas viven en cada base— y un respaldo restaurado. Por eso la limpieza mira la **edad del archivo** y no si alguien lo referencia. Apareció barriendo a mano y encontrando uno de 85 KB sin dueño. | 2026-08-26 |
| Sucursal y cargo: la regla | **Si la planilla trae el nombre, se crea lo que falte; si trae solo el código, tiene que existir.** Es la diferencia entre un dato que la planilla puede inventar y una clave que apunta a otro lado: un código suelto que no existe no se puede crear —no habría con qué nombrarlo— y dejarlo pasar significaba, hasta ahora, crear una sucursal **llamada** «S-14», sin fallar ni avisar. Buscar es más permisivo que crear: un valor se busca entre los códigos y entre los nombres sin importar en qué columna venía, así una planilla que pone el código donde va el nombre encuentra la fila en vez de duplicarla. Todo en `CatalogResolver`. | 2026-08-26 |
| Nada de adivinar si es código o nombre | Se evaluó detectarlo mirando los valores —«si son números, son ids»— y se descartó: una sucursal puede llamarse «2024» y un código puede ser «Casa Matriz». No hace falta, porque la homologación ya permite **decirlo**: hay un campo «Sucursal» y otro «Código de la sucursal», y conectar una columna a uno u otro es la declaración. Lo que sí se adivina es la **sugerencia** inicial, que se puede corregir y no importa nada por su cuenta. | 2026-08-26 |
| El catálogo entero en memoria | `CatalogResolver` carga las sucursales y los cargos una vez por importación. Antes era un `firstOrCreate` por fila: en una nómina de 7.000 personas, 14.000 consultas para resolver 129 sucursales y 16 cargos. | 2026-08-26 |
| Variantes de escritura | El resumen previo **lista qué sucursales y cargos se van a crear**, ordenados alfabéticamente para que «Suc. Norte» quede al lado de «Sucursal Norte» y se vea que son la misma escrita distinto. El cotejo de MySQL ya perdona mayúsculas y acentos; lo que no perdona son las redacciones. | 2026-08-26 |
| Otro cliente es otra base | El sistema atiende a una empresa por instalación, así que probar con otra empresa es otra base entera y no un filtro por columna. Cambiar de una a otra mueve tres valores del `.env` —la base y el par del tenant de E360—, y el par va junto a propósito: con la base nueva y el tenant viejo, la empresa nueva estaría mirando las evaluaciones de la anterior. | 2026-08-26 |
| Un solo importador para los dos caminos | La homologación traduce las claves de cada fila y le entrega el resultado a `DirectoryImportService`, que es el mismo de siempre. Un segundo importador en paralelo sería la manera más rápida de que los dos caminos se comporten distinto —uno idempotente y el otro no, uno que arma la jerarquía al final y el otro no—. | 2026-08-26 |
| El borrador es de quien lo subió | No alcanza con que las dos personas sean administradoras: mientras el borrador existe, la nómina completa de la empresa está en el disco del servidor y su id es la única llave. | 2026-08-26 |
| Dos campos del sistema no pueden compartir columna | Se rechaza con nombre y apellido: «la columna X está conectada a "Nombre" y "Apellido"». Es siempre un descuido de quien elige en dos desplegables seguidos, y si pasara en silencio se descubriría con el directorio ya cargado. Se avisa en el navegador mientras se elige, y el servidor lo vuelve a comprobar. | 2026-08-26 |
| El rol no se importa | Se probó traducirlo del castellano —«Administrador», «Empleado»— y funcionaba, pero el dato no es universal: cada planilla llama de otra manera a algo que en el sistema son tres valores, y equivocarse ahí reparte permisos de administración. Ahora **todo el mundo entra como colaborador** y los administradores se nombran a mano en el directorio, que son dos o tres por empresa. La contrapartida está cubierta: al **actualizar**, la importación no toca el rol, así que volver a cargar la nómina no devuelve a los administradores a colaborador. | 2026-08-26 |
| Códigos repetidos dentro del archivo | No son un error —la última fila actualiza a la anterior— pero casi siempre son un descuido, y en silencio se pierde una persona. El resumen los lista antes de importar. | 2026-08-26 |
| `/storage` en el proxy de Angular | Laravel arma la dirección de la foto con el host de la petición, que en desarrollo es el del servidor de Angular (4200) y no el de nginx. Sin ese reenvío en `proxy.conf.json`, la foto se guarda perfecta y **se ve rota**: el servidor de desarrollo responde el `index.html` en vez de la imagen. Apareció recién al mirar la pantalla; por API todo daba 200. | 2026-08-26 |
| El círculo de la persona | Un solo componente, `<app-avatar>`, en las seis pantallas donde aparece. Antes cada una escribía las iniciales por su cuenta; con la foto de por medio, eso serían seis `@if` iguales y basta olvidar uno para que la misma persona salga con foto en una vista y sin ella en otra. El respaldo son las iniciales y no una silueta gris: las iniciales dicen a quién se está mirando. El componente lleva `:host { display: contents }`: sin eso su envoltorio se cuela como un elemento más de la fila, encogible, y la foto sale ovalada —82 px de ancho por 96 de alto, medido—. | 2026-08-26 |
| Roles | Tres, no dos: `super_admin`, `admin`, `collaborator`. El super admin **no es evaluable**, igual que en la intranet. | Hito 2 |
| Versiones | Laravel 13 y Angular 22 (los `latest`). El plan decía Laravel 12, que es lo que corre la API E360. **Sin confirmar** si conviene fijar a 12. | Hito 1 |
| API E360 | No se modifica en ningún hito. | Hito 0 |
| Jefe excluido que igual encabeza equipo | Un supervisor apartado del proceso **sigue formando su grupo**, atenuado en pantalla, y viaja a la API como `activo: false`. Es lo que hace la intranet, y lo que ya hacía `ParticipationSubmission`: exigir que el jefe participara dejaba a su equipo entero como «suelto» y la pantalla proponía echar gente sana. | 2026-08-25 |
| Cambios sin enviar, en el listado | El estado late en ámbar («sirena») con ⚠, «Abrir» queda deshabilitado con el motivo en el tooltip, y arriba hay un aviso con enlace directo a terminar la edición. La intranet usa un modal al cargar; acá es un aviso persistente, porque un modal se cierra y se olvida. | 2026-08-25 |
| Estado deshabilitado visible | `.mini` no tenía estilo `:disabled`: un botón bloqueado se veía idéntico a uno activo y solo se descubría al pulsarlo. Ahora va con borde punteado, fondo apagado y `cursor: not-allowed`. Vale para toda la app. | 2026-08-25 |
| Volver al paso 1 | El asistente ahora tiene ruta `definir` también bajo `:id`, así que se puede volver al primer hito desde cualquier otro. Antes no existía y caía en el comodín, que sacaba del asistente. El mismo paso sirve para crear y para corregir. | 2026-08-25 |
| Qué se puede editar de la definición | Depende del estado: **en creación** y **nunca publicada**, todo; **en proceso**, solo título y descripción (la API recibe `PATCH` en vez de `PUT`); **preparando**, **finalizada** y **cancelada**, nada. El recorte se hace en la pantalla y **otra vez en el servidor**: que un campo esté deshabilitado no impide que llegue por la API. Es la regla de la intranet, que en `updateEvaluation` quitaba del payload año, grupo, período, plantilla y formularios. | 2026-08-25 |
| Apariencia | Dos ejes independientes: `data-tema` (ocho paletas) y `data-theme` (claro/oscuro/sistema). Se eligen desde el botón de la barra superior y se guardan en `localStorage`. «Sistema» **quita** el atributo para que mande `prefers-color-scheme` en vivo, en vez de congelar el valor que hubiera al cargar. | 2026-08-25 |
| Cómo se generan los temas | No a mano: `docs/temas.mjs` deriva los 14 tokens de cada tema desde un tono y un croma en OKLCH, y valida los 11 pares críticos contra WCAG AA en los dos modos. Agregar un tema es elegir un tono y volver a correr la validación. Elegir 176 colores a ojo garantiza que alguno quede fuera de contraste. | 2026-08-25 |
| Colores de los gráficos | `chart-theme.ts` **lee las variables del tema activo**, no lleva hexadecimales fijos: con ocho paletas, un color escrito hace que el gráfico se vea ajeno en siete de las ocho. La paleta categórica de 5 series sí es fija, porque está validada para daltonismo y no debe seguir a la marca. | 2026-08-25 |
| Línea de tiempo | `.linea-tiempo` + `.hito`, reutilizable. Cuatro estados que se distinguen **por forma y no solo por color** (tilde, halo, número, candado), hilo sólido detrás y punteado delante. Se usa en el asistente y en el inicio de administración. | 2026-08-25 |
| Cambios de participación | Se aplican **en el lugar**, sin volver a pedir el listado: la respuesta trae a quiénes alcanzó el cambio y el total recalculado. Un girito junto al interruptor marca la fila que se está guardando, y el resto queda deshabilitado hasta que termina. Recargar la lista vaciaba la tabla y se sentía como una recarga de página. Editar a alguien sí relee —cambiar su jefe altera los conteos «a cargo» de otras filas— pero en silencio. | 2026-08-25 |
| Quién queda «suelto» | Hace falta **al menos una relación de evaluación real**: que su jefatura participe, que tenga gente a cargo, o que tenga pares bajo la misma jefatura. La autoevaluación no cuenta. La intranet solo pregunta si alguien figura como tu jefe, sin mirar si ese jefe participa: con una jefatura apartada del proceso deja pasar gente a la que nadie evalúa y que no evalúa a nadie. | 2026-08-25 |
| Bloqueo del envío | No se envía con huérfanos (igual que la intranet), ni sobre un proceso cuyo estado no admite cambios, ni cuando se corrige un proceso ya creado y no hay cambios pendientes. El motivo se muestra junto al botón en vez de dejarlo deshabilitado sin explicación. | 2026-08-25 |
| Sucursales a escala | La lista se filtra y ordena en el cliente, sin paginar: buscador por nombre, orden por personal o alfabético, «solo las elegidas», y la lista dentro de un marco con altura máxima. Las acciones masivas actúan sobre **lo filtrado**, sumando o restando sin pisar el resto de la selección. Probado con 123 sucursales y 611 personas: `availableBranchOffices()` en 6,8 ms. La intranet resolvía lo mismo con DataTables paginando de a 10. | 2026-08-25 |
| Grupo del proceso | Selector visible, con el primer grupo ya elegido: no hay que tocarlo para seguir. **No es un dato inerte** —de él dependen el período y el alcance del proceso, y la API lo exige— así que no se puede omitir, solo dar por defecto. La intranet lo esconde (`d-none`) y toma el primero; acá se muestra porque el proyecto tiene pantalla de Grupos propia. | 2026-08-25 |
| Plantilla del proceso | Se puede cambiar. La intranet la tiene `disabled` con «está predefinida», que es un candado de ese despliegue, no una regla del dominio. | 2026-08-25 |
| Período del proceso | Lo impone la API, no la persona. `GET /api/periodo` ya devuelve el **siguiente** al último usado: no hay que sumarle nada. Solo cuando responde `null` —el grupo nunca tuvo evaluaciones— el campo se abre y se puede elegir. Mismo comportamiento que la intranet. | 2026-08-25 |

---

## Trampa del entorno: la evaluación se queda en «preparando»

E360 despacha los trabajos que arman las tareas a la cola **`heavy`**
(`->onQueue('heavy')` en `ParticipantService`), no a `default`. Un
`php artisan queue:work` a secas escucha solo `default`, así que los trabajos
quedan encolados para siempre y el proceso no sale de «preparando». Tampoco
hay servicio de worker en el `docker-compose.yml` de E360: hay que levantarlo
a mano.

```bash
cd ~/Escritorio/proyectos/ideauno-evaluacion360-backend-laravel
docker compose exec app php artisan queue:work --queue=heavy,default
# o, equivalente a producción:
docker compose exec app php artisan horizon
```

Para ver si hay algo atascado —ojo con el prefijo, no es `queues:`—:

```bash
docker compose exec redis redis-cli LLEN evaluacion360_database_queues:heavy
```

---

## Código sin uso: cómo se busca

Ya aparecieron cinco veces cadenas completas construidas sin pantalla que las
llamara —«Deshacer cambios», el filtro por supervisor, `EvaluationsApi::update`,
el listado de resultados por persona—. Vale la pena repetir el barrido cada
tanto. Los tres cortes que lo encuentran:

1. **Métodos de `app/Support/E360/Resources` que nadie invoca.** Buscar
   `->metodo(` en todo `app/` menos su propia definición.
2. **Endpoints implementados dos veces.** Normalizar la URL de cada método y
   agrupar: dos métodos con la misma ruta son un duplicado esperando a que
   alguien elija el equivocado.
3. **Rutas del BFF que ningún servicio de Angular llama**, y métodos de
   `core/api/*.service.ts` sin quien los llame. Ese último corte es el que
   destapa las pantallas que faltan.

Cuando aparezca uno, la pregunta no es «¿lo borro?» sino **qué hace su
equivalente en la intranet**: la mitad de las veces es un duplicado y se quita,
y la otra mitad es una pantalla que falta.

---

## Qué aguanta y qué no

Medido sobre 7.245 personas en 124 sucursales, con la base en la misma
máquina. Los números son de la aplicación, no estimaciones:

| Operación | Tiempo |
|---|---|
| Sembrar 7.245 personas | 0,9 s |
| Armar el padrón (7.092) | 0,41 s · 18 consultas |
| Listar participantes paginado | 31 ms |
| Cascada de supervisados del gerente general (7.070) | 879 ms |
| Consulta recursiva de toda la cadena | 19 ms |
| Previsualización de 527 equipos | 1,0 s · 3.792 nodos |
| Payload del envío a E360 | 0,92 MB |

Dos cosas que hubo que cambiar para llegar a esos números, y que conviene no
deshacer sin querer:

- **El padrón se arma por lotes.** Antes era una consulta por persona: 7.095
  viajes a la base. Con la base en otro servidor, cada viaje suma latencia.
- **La previsualización es maestro-detalle.** Antes pintaba todos los equipos
  con toda su gente: 25.611 nodos y una página que crecía sin fin. Ahora se
  elige un equipo y solo se pinta ése.

---

## Deuda y pendientes conocidos

- **Laravel 13 vs 12** — decisión abierta.
- **Los tres avisos por correo están hechos.** Comparten `NotifyRoster`, que
  recorre el padrón por lotes de 500, y `EvaluationAudience`, que decide a
  quién le toca cada uno. Los tres informan el alcance en el momento —«se avisa
  a 120, 8 se quedan afuera»— con dos consultas con índice, antes de encolar.
  - **Apertura** y **resultados** van a todo el padrón con casilla, y cada uno
    ofrece los dos caminos: «Abrir y notificar» / «Abrir sin notificar», y lo
    mismo al publicar.
  - **Recordatorio**, con su botón «Recordar» en las evaluaciones abiertas, va
    solo a quienes todavía no terminaron. Se puede repetir las veces que haga
    falta.
  - **Lo que hay que saber del recordatorio**: quién está pendiente sale de
    `evaluation_users.tasks_completed`, que es una caché refrescada cuando la
    persona abre su lista de tareas. Miente en dos casos —quien nunca entró al
    portal, y quien respondió por fuera (`dev:responder`)— y por eso el texto
    del correo no acusa a nadie: dice que *figuran* tareas sin responder y
    ofrece ignorarlo. La alternativa, preguntarle a Evaluación 360 por cada
    persona, son cientos de peticiones remotas por envío. El detalle está en
    `EvaluationAudience::pendientesConCorreo()`.
- **Casi sin tests automatizados.** Hay dos, y los dos cubren lo mismo: lo que
  **no se ve mirando la pantalla**. `buscador-personas.spec.ts` cubre el
  contrato de tecleo del buscador —mínimo de caracteres, espera, cancelación de
  la consulta vieja, fallo de red—. `AvatarStorageTest` cubre el tratamiento de
  la foto: que quede cuadrada de 256 en WebP, que el recorte de un retrato tire
  hacia arriba, que una foto de teléfono acostada se enderece por su EXIF, y
  que la anterior no quede ocupando disco. `ImportMappingTest` cubre la
  homologación: qué columna se sugiere para cuál, qué homologaciones se
  rechazan y qué dice el resumen antes de tocar nada. Las tres pruebas de
  prioridad de la sugerencia nacieron de fallos que encontraron ellas mismas.
  `ImportHousekeepingTest` cubre la limpieza, que es trabajo invisible: si un
  día deja de correr, no se nota hasta que molesta. Corre con `ng test`
  (vitest, ya configurado). Se borró `app.spec.ts`, que era andamiaje de
  `ng new` comprobando un `Hello, frontend` que no existe desde hace meses.
  El resto de la verificación sigue siendo por navegador: `docs/recorrido.mjs`
  pulsa cada control de cada pantalla y reporta los que no producen efecto.
- **Las fotos de perfil ya se pueden subir**, desde el formulario de persona
  del directorio. Lo que queda pendiente es que **cada quien pueda cambiar la
  suya**: hoy solo la carga un administrador, y con una nómina de miles de
  personas eso no escala. Falta decidir si el colaborador puede, que es una
  decisión de política y no de código: el endpoint sería el mismo.
- **El padrón del asistente no muestra fotos en la lista de sucursales ni en el
  tablero**, porque esas consultas no traen la columna. Donde sí aparecen es en
  el directorio, las dos cabeceras, el padrón, la previsualización y «mi
  equipo» del portal.

---

## Fuera de alcance

Los agujeros de permisos y el código muerto del módulo actual de la intranet
quedan documentados en la auditoría, pero **no se corrigen desde este
proyecto**. El módulo sigue funcionando como está: esto es un reemplazo
paralelo, no una modificación.

---

## Cómo retomar

```bash
cd ~/Escritorio/proyectos/evaluacion-persona-frontend
docker compose up -d
docker compose exec php php artisan migrate --seed   # solo la primera vez
docker compose exec php php artisan storage:link     # solo la primera vez
```

Frontend en <http://localhost:4200>, API en <http://localhost:8081>,
bandeja de correo en <http://localhost:8025>.

Para probar con otra empresa —una base vacía, para cargar una nómina desde
cero— está `docs/CLIENTES.md`: `./docker/cliente.sh flippy` y de vuelta con
`./docker/cliente.sh demo`.
Las cuentas de prueba y su organigrama están en el `README.md` de la raíz —
todas con la contraseña `password`.

### Lo que hace falta además del repositorio

Nada de esto viaja en git y sin ello la aplicación arranca pero no sirve:

1. **La API de Evaluación 360 levantada**, en el proyecto vecino
   `ideauno-evaluacion360-backend-laravel`. El BFF le consulta en cada
   petición del asistente.
2. **Un worker de colas de E360**, o los procesos se quedan en «preparando»
   para siempre. Ojo con la cola: es `heavy`, no `default` — está explicado
   más arriba en *Trampa del entorno*.
3. **El archivo `.env` del BFF**, con el token del tenant. Sin él el asistente
   responde 502 en todo.

### Estado de los datos de prueba

| Evaluación | Estado | Padrón |
|---|---|---|
| 1 | en creación | vacía |
| 2 | finalizada | 7 de 10 participan, resultados publicados |
| 3 | en creación | 10 personas, las 3 sucursales originales |
| 4 | nunca publicada | 7092 personas (siembra de carga) |

El directorio tiene **7256 personas**: las 11 del proyecto más 7245 de
`LargeCompanySeeder`. Para volver al tamaño chico:

```sql
DELETE FROM users WHERE email LIKE '%@corp.test';
DELETE FROM branch_offices WHERE external_code LIKE 'X-%';
```

### Herramientas que conviene conocer

| Comando | Para qué |
|---|---|
| `node docs/recorrido.mjs` | Pulsa cada control de cada pantalla y reporta los que no hacen nada. Necesita `npm i puppeteer`. |
| `node docs/temas.mjs validar` | Comprueba el contraste de los 8 temas. Ver `docs/TEMAS.md`. |
| `php artisan db:seed --class=LargeCompanySeeder` | Siembra la empresa de 7.245 personas. |

**Cuidado con `recorrido.mjs`**: no está pensado para pantallas con cientos de
controles. Sobre una previsualización de 527 equipos re-navega 527 veces y no
termina. Usalo contra las evaluaciones chicas.
