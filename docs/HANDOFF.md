# Handoff — estado del proyecto

Bitácora viva del avance. Se actualiza al cerrar cada hito, para que el estado
no dependa de la memoria de nadie.

**Última actualización:** 2026-08-24 · hito 6 cerrado (portal del colaborador).

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

## Avance

| # | Hito | Estado |
|---|------|--------|
| 1 | Andamiaje y Docker | ✅ Terminado |
| 2 | Acceso | ✅ Terminado |
| 3 | Directorio | ✅ Terminado |
| 4 | Listado de evaluaciones | 🔨 Listado y transiciones hechos |
| 5 | Asistente de creación | ✅ Terminado |
| 6 | Portal del colaborador | ✅ Terminado |
| 7 | Resultados y tableros | ⬅️ Siguiente |
| 8 | Cierre | ⬜ Pendiente |

### Hito 1 — Andamiaje ✅

Cinco servicios en Docker: `angular`, `nginx`, `php`, `mysql`, `queue`.
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

**Los dos caminos para la contraseña.** Con correo se envía invitación; sin
correo se genera una temporal que el administrador descarga en CSV y entrega en
mano. Los dos terminan en `must_set_password`.

**Jerarquía.** `SupervisionChain` reemplaza a `getAllSubordinates()` de la
intranet, que lanzaba una consulta por nodo dentro de un bucle y no se protegía
de ciclos —con datos malos entraba en recursión infinita—. Acá cada recorrido
es **una sola consulta recursiva** con tope de profundidad, y hay una variante
que cuenta los supervisados de toda una página de golpe.

**Verificado:** importación de una planilla con 7 filas y 3 errores a propósito
(sin nombre, correo inválido, rol inexistente) → 4 creadas y 3 rechazadas, cada
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

---

## Decisiones tomadas

| Tema | Decisión | Cuándo |
|------|----------|--------|
| Arquitectura | Angular + BFF Laravel con BD propia. El BFF no es opcional: el token del tenant no puede vivir en el navegador, la API E360 no tiene noción de usuario, y no hay directorio de empleados en ninguna parte. | Hito 0 |
| Directorio | Propio, por importación CSV/Excel más ABM. Sin sincronizar con la intranet. | Hito 0 |
| Autenticación | Usuarios propios del BFF. Ni SSO ni FusionAuth. | Hito 0 |
| Gráficos | ECharts (`ngx-echarts`), no Highcharts, para no gestionar licencias. Los envoltorios van en `shared/charts/` para que la biblioteca sea reemplazable. | Hito 0 |
| Correo transaccional | Sí, desde el hito 2: invitación y recuperación. | Hito 0 |
| Avisos de negocio por correo | Diferidos: apertura, recordatorio y resultados. | Hito 0 |
| Proveedor de correo | Sin definir. `MAIL_MAILER=log` por defecto; Mailtrap para pruebas, con credenciales propias del proyecto. | Hito 2 |
| Fotos de perfil | Todas nuevas. No se migra ninguna imagen de la intranet. | Hito 0 |
| Roles | Tres, no dos: `super_admin`, `admin`, `collaborator`. El super admin **no es evaluable**, igual que en la intranet. | Hito 2 |
| Versiones | Laravel 13 y Angular 22 (los `latest`). El plan decía Laravel 12, que es lo que corre la API E360. **Sin confirmar** si conviene fijar a 12. | Hito 1 |
| API E360 | No se modifica en ningún hito. | Hito 0 |

---

## Deuda y pendientes conocidos

- **Laravel 13 vs 12** — decisión abierta.
- **Los tres avisos de negocio por correo** y el botón de recordatorio, junto
  con la opción «abrir y notificar» del listado. La infraestructura de colas ya
  está levantada: es agregar los jobs.
- **Crear y editar evaluaciones** llega con el hito 5; hoy el listado solo opera
  procesos que ya existen.
- **Sin tests automatizados** todavía. La verificación ha sido manual por HTTP.
- **El selector de supervisor** en el formulario de personas solo ofrece a quienes
  están en la página actual del listado. Sirve para el volumen de prueba, pero con
  una nómina grande necesita un buscador con consulta al servidor.
- **Las fotos de perfil** todavía no se pueden subir: el modelo tiene `avatar_path`
  y el respaldo son las iniciales, pero falta la pantalla de carga.

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
```

Frontend en <http://localhost:4200>, API en <http://localhost:8081>.
Las cuentas de prueba y su organigrama están en el `README.md` de la raíz —
todas con la contraseña `password`.
