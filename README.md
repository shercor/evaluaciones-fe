# Evaluación de Personal — Portal

Reemplazo autónomo del módulo de Evaluación de Personal de la intranet.
Aplicación Angular sobre un backend propio (BFF) en Laravel, con su propia base
de datos.

**No depende de la intranet** — ni para arrancar ni en ejecución — y **no
modifica la API de Evaluación 360**, que se consume tal cual está.

---

## Por qué hay un backend

La API de Evaluación 360 se autentica con un token estático por empresa y no
tiene noción de usuario: no sabe quién pide qué. Además no guarda un directorio
de empleados — su tabla `import_users` solo tiene `import_id`, `name` y
`active`, mientras que el cargo, la sucursal y el supervisor viven por
evaluación.

El BFF cubre exactamente eso:

- Guarda el token del tenant, que nunca llega al navegador.
- Autentica personas y decide si son administradores o responden evaluaciones.
- Es dueño del directorio de empleados y del organigrama.
- Es dueño del padrón de participantes, los estados y la bitácora para deshacer.
- Corre las colas de correo.

Angular habla solo con el BFF. El BFF es lo único que habla con Evaluación 360.

---

## Puesta en marcha

Requiere Docker y Docker Compose. No hace falta PHP, Composer ni Node en el host.

```bash
cp .env.example .env                    # puertos y datos del contenedor mysql
cp backend/.env.example backend/.env    # configuración de la aplicación

docker compose up -d
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
```

| Servicio  | URL                     |
|-----------|-------------------------|
| Frontend  | http://localhost:4200   |
| API (BFF) | http://localhost:8081   |
| MySQL     | localhost:13306         |

Los puertos se cambian en el `.env` de la raíz si chocan con otro proyecto.

---

## Cuentas de prueba

Las crea `php artisan db:seed`. **Todas tienen la contraseña `password`** y están
activas, sin cambio obligatorio. Son para desarrollo: en producción no se
siembra nada.

| Correo | Rol | Cargo |
|--------|-----|-------|
| `super@evaluacion.test` | Super administrador | — |
| `patricia.soto@empresa.test` | Administrador | Jefa de RRHH |
| `rodrigo.fuentes@empresa.test` | Colaborador | Gerente General |
| `marcela.rivas@empresa.test` | Colaborador | Jefa de Tienda (Norte) |
| `andres.lagos@empresa.test` | Colaborador | Jefe de Tienda (Sur) |
| `camila.nunez@empresa.test` | Colaborador | Supervisora (Norte) |
| `felipe.cortes@empresa.test` | Colaborador | Supervisor (Sur) |
| `javiera.munoz@empresa.test` | Colaborador | Vendedora (Norte) |
| `diego.araya@empresa.test` | Colaborador | Vendedor (Norte) |
| `valentina.rojas@empresa.test` | Colaborador | Vendedora (Sur) |
| `tomas.vergara@empresa.test` | Colaborador | Administrativo |

El organigrama tiene cuatro niveles a propósito: la cascada de supervisados y
la detección de ciclos son de lo más delicado que hay que portar de la
intranet, y con un árbol plano no se pueden probar.

```
Rodrigo (Gerente General)
├── Patricia (RRHH, administradora)
│   └── Tomás
├── Marcela (Tienda Norte)
│   └── Camila
│       ├── Javiera
│       └── Diego
└── Andrés (Tienda Sur)
    └── Felipe
        └── Valentina
```

**El super administrador no es evaluable.** Replica la regla de la intranet,
que arma el padrón con `user_type_id IS NOT SUPER_ADMINISTRATOR`. De las 11
cuentas, 10 pueden ser participantes.

---

## Roles

| Rol | Entra a | Puede |
|-----|---------|-------|
| `super_admin` | `/admin` | Todo. Es personal de Idea Uno, externo a la empresa, y por eso nunca es evaluado. |
| `admin` | `/admin` | Administrar evaluaciones y directorio. También responde su propia evaluación. |
| `collaborator` | `/portal` | Responder sus tareas y ver sus resultados. Si tiene gente a cargo, los resultados de sus supervisados directos. |

La autorización se aplica **siempre en el backend**, con el middleware `role`.
Los guards de Angular solo evitan mostrar lo que no corresponde; no son la
defensa. Es justo la distinción que el módulo actual de la intranet no respeta.

---

## Configuración

Hay **dos** archivos de entorno y cumplen funciones distintas:

- `.env` en la raíz — solo lo que lee `docker-compose.yml`: puertos y
  credenciales del contenedor de MySQL.
- `backend/.env` — la configuración de la aplicación Laravel.

### Conexión con Evaluación 360

En `backend/.env`. Los valores se copian desde la intranet; la equivalencia
está documentada en `backend/config/e360.php`:

| Intranet                 | Este proyecto            |
|--------------------------|--------------------------|
| `CFG_E360_URL`           | `E360_BASE_URL` — **ahora con esquema** (`http://`) |
| `CFG_E360_CENTRAL_TOKEN` | `E360_CENTRAL_TOKEN`     |
| `CFG_E360_TENANT_TOKEN`  | `E360_TENANT_TOKEN`      |
| `CFG_INTRANET_CODENAME`  | `E360_TENANT_CODENAME`   |

`E360_BASE_URL` se resuelve **desde dentro del contenedor `php`**, no desde tu
máquina. Si la API corre en el host, `http://172.17.0.1:81` suele funcionar en
Linux.

Para comprobar que la conexión está bien:

```bash
docker compose exec php php artisan e360:ping
```

Verifica las tres capas por separado — configuración, plano central y plano
tenant — para que un fallo diga cuál de las tres es.

### Correo

Por defecto `MAIL_MAILER=log`: los correos se escriben en
`backend/storage/logs/laravel.log` y todo funciona recién clonado el proyecto,
sin credenciales.

Para verlos de verdad, en `backend/.env` poné `MAIL_MAILER=smtp` y completá el
usuario y la contraseña de **Mailtrap**. Las credenciales son propias de este
proyecto: **no reutilizar las de la intranet**. `backend/.env` no se versiona.

Hoy se envían dos correos, los dos de acceso: la invitación para definir la
primera contraseña y la recuperación. Los tres avisos de negocio —apertura,
recordatorio y resultados— están diferidos.

El proveedor definitivo está sin decidir, y no hace falta que lo esté: todo el
envío pasa por la fachada `Mail`, así que cambiarlo es cambiar variables de
entorno — o instalar un paquete y cambiar `MAIL_MAILER`, si termina siendo un
proveedor por API. Ninguna plantilla ni ningún job se toca.

---

## Estructura

```
docker/            imágenes y configuración de nginx
backend/           Laravel — el BFF
  app/Support/E360/    cliente de la API, un servicio por recurso
  config/e360.php      configuración de la conexión
frontend/          Angular — el SPA
docs/              documentación del proyecto
```

### El cliente de Evaluación 360

`app/Support/E360/` porta `App\Utility\ApiEvaluacion360` de la intranet, con
tres diferencias:

- No es estático ni necesita `init()`.
- Distingue un fallo de conexión de un error de la API. El original devolvía lo
  mismo en ambos casos, así que un backend caído se veía igual que un 404.
- Nunca escribe el token en el log.

`E360Client` es el único que conoce las credenciales. Encima van servicios por
recurso (`EvaluationsApi`, `TenantsApi`, …) que exponen métodos con nombre, para
que ningún llamador arme rutas a mano.

---

## Estado

**Hitos 1 a 6 de 8 terminados.**

*Hito 1 — andamiaje.* Levantan los cinco servicios, el backend conecta con
MySQL y el cliente de Evaluación 360 está portado con su comando de
diagnóstico. Queda una pantalla de verificación en `/estado`.

*Hito 2 — acceso.* Login con límite de intentos, recuperación de contraseña por
correo, definición de la primera contraseña por los dos caminos (enlace o
temporal), cambio de contraseña, tres roles, guards de navegación y los dos
portales con su esqueleto de navegación.

*Hito 3 — directorio.* Personas, sucursales, cargos y organigrama, con
importación de la nómina desde planilla. Ver más abajo.

*Hito 4 — evaluaciones.* Listado con filtros, los seis estados, y las acciones
de abrir, cerrar, publicar, desactivar y reactivar.

*Hito 5 — asistente de creación.* Los seis pasos: definir el proceso, elegir
sucursales, armar el padrón, depurar participantes, revisar los grupos por
supervisor y enviar.

*Hito 6 — portal del colaborador.* Ver mis evaluaciones, mis tareas y
responderlas. Con esto el ciclo es utilizable de punta a punta.

Al entrar a <http://localhost:4200> te manda al login; después de entrar, cada
rol aterriza en su portal.

**Conexión con Evaluación 360 verificada.** El tenant de pruebas
`portal-pruebas` está registrado y sembrado. Para dar de alta un tenant nuevo:

```bash
docker compose exec php php artisan e360:register-tenant   # única escritura sobre E360
docker compose exec php php artisan e360:ping              # solo lectura
```

`register-tenant` no vuelve a registrar un tenant que ya existe: hacerlo
recrearía su base y perdería sus datos.

Lo que sigue es el **hito 7**: resultados y tableros — el panel de resultados
en sus tres modos, los tableros de administración y el monitoreo del avance.

El avance detallado, las decisiones y la deuda conocida están en
[`docs/HANDOFF.md`](docs/HANDOFF.md).

El plan completo — arquitectura, modelo de datos, mapa de módulos y los ocho
hitos — está en `docs/`.


---

## Importar la nómina

Está en **Directorio → Importar nómina**, o en `/admin/directorio/importar`.

Acepta **CSV y Excel**. El separador del CSV se detecta solo, así que sirve
tanto el de coma como el de punto y coma que exporta Excel en español. Los
encabezados se normalizan: «Código Supervisor», «codigo supervisor» y
«CODIGO_SUPERVISOR» son la misma columna.

| Columna | Obligatoria | Nota |
|---------|-------------|------|
| `codigo` | Sí | RUT o número de ficha. **Es la identidad**: decide si la fila crea o actualiza. |
| `nombre` | Sí | |
| `apellido` | No | |
| `correo` | No | Sin correo, la persona entra con una contraseña temporal. |
| `cargo` | No | Se crea solo si no existe. |
| `sucursal` | No | Se crea sola si no existe. |
| `codigo_supervisor` | No | El `codigo` de otra persona, que puede aparecer más abajo en el archivo. |
| `rol` | No | `admin` o `collaborator`. Por defecto, `collaborator`. |

Hay una planilla de ejemplo descargable desde la misma pantalla.

**Una fila con errores no voltea el archivo.** Cada fila se valida por
separado: se importa todo lo válido y se lista qué se rechazó, en qué línea y
por qué. Reimportar la misma planilla no duplica a nadie.

**Las dos formas de entregar el acceso:**

- Con correo → se envía la invitación para definir la contraseña.
- Sin correo → se genera una contraseña temporal. Al terminar aparece un enlace
  para **descargarlas en CSV** y entregarlas en mano. No se vuelven a mostrar.

En ambos casos la persona queda obligada a cambiarla al primer ingreso.

**Ciclos en el organigrama.** Si una asignación convertiría a alguien en su
propio jefe —directa o indirectamente— se rechaza y se avisa, tanto al importar
como al editar a mano.
