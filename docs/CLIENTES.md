# Clientes ficticios

Este sistema **no es multiempresa**: una instalación atiende a una empresa. Por
eso probar «otro cliente» es exactamente eso, otra base de datos entera, y
cambiar de uno a otro es cambiar tres valores del `.env`.

Sirve para lo que no se puede probar sobre la base de desarrollo: una carga de
nómina desde cero, con el directorio realmente vacío y sin las sucursales ni
los cargos que sembró el seeder.

---

## Qué hay hoy

| Cliente | Base de datos | Tenant en E360 | Contenido |
|---------|---------------|----------------|-----------|
| `demo` | `evaluacion_personal` | `portal-pruebas` | La empresa de prueba de siempre |
| `flippy` | `flippy` | `flippy` *(sin registrar)* | Vacía: dos cuentas y nada más |

Las cuentas de Flippy, las dos con la contraseña `password`:

| Correo | Rol |
|--------|-----|
| `admin@cliente.test` | Administrador |
| `super@evaluacion.test` | Super administrador |

---

## Cambiar de cliente

```bash
./docker/cliente.sh            # cuál está puesto y cuáles hay
./docker/cliente.sh flippy     # cambiar
./docker/cliente.sh demo       # volver
```

El script mueve tres valores en `backend/.env` —`DB_DATABASE`,
`E360_TENANT_CODENAME` y `E360_TENANT_TOKEN`—, deja una copia en `.env.bak` y
reinicia el worker de colas.

Dos cosas que conviene saber:

- **Te vas a tener que loguear de nuevo.** Las sesiones viven en la base, así
  que la cookie del navegador deja de resolver contra la base nueva.
- **El worker de colas se reinicia, php-fpm no.** `queue:work` lee la
  configuración una sola vez al arrancar; sin reiniciarlo seguiría mandando los
  correos de la empresa anterior. php-fpm relee el `.env` en cada petición.

Para un comando suelto contra otra base no hace falta cambiar nada, porque las
variables de entorno pisan al `.env`:

```bash
docker compose exec php sh -c 'DB_DATABASE=flippy php artisan migrate:status'
```

---

## Agregar otro cliente

**1. La base.** En un entorno que ya está andando, a mano:

```bash
docker compose exec mysql mysql -uroot -proot -e "
  CREATE DATABASE \`acme\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON \`acme\`.* TO 'ep_user'@'%';
  FLUSH PRIVILEGES;"
```

Y agregar esas dos líneas a `docker/mysql/init/10-clientes.sql`, que es lo que
corre MySQL al crear el volumen por primera vez. Sin eso, quien clone el
proyecto no va a tener la base.

**2. Sus valores**, en `backend/.env.clientes/acme.env`:

```
DB_DATABASE=acme
E360_TENANT_CODENAME=acme
E360_TENANT_TOKEN=algo-de-al-menos-10-caracteres
```

Esa carpeta **no va al repositorio**: lleva el token del tenant.

**3. Cambiar, migrar y sembrar:**

```bash
./docker/cliente.sh acme
docker compose exec php php artisan migrate --force
docker compose exec php php artisan db:seed --class=EmptyCompanySeeder --force
```

`EmptyCompanySeeder` crea las dos cuentas y **nada más**: ni sucursales, ni
cargos, ni personas. La importación crea las sucursales y los cargos que no
existan, así que sembrarlos de antemano ensuciaría la prueba.

---

## La carpeta de planillas es una sola

Las planillas que se suben para homologar viven en el disco del servidor
(`storage/app/private/importaciones/borradores`), **no** en la base. Esa
carpeta la comparten todos los clientes, así que al cambiar de base quedan
archivos cuya fila está en otra.

No hay que hacer nada: la tarea `importaciones:limpiar`, que corre cada hora en
el contenedor `scheduler`, barre por edad todo lo que tenga más de 24 horas
—que es lo que vive un borrador— sin mirar si alguien lo referencia.

---

## El tenant de Evaluación 360

Mientras el tenant no esté registrado, el **directorio y la importación de
nómina funcionan igual** —no tocan esa API—, pero las pantallas de evaluaciones
van a fallar: no hay tenant contra el cual preguntar.

Para darlo de alta, con el cliente ya puesto y su token escrito en el archivo:

```bash
docker compose exec php php artisan e360:ping             # diagnóstico
docker compose exec php php artisan e360:register-tenant  # alta, pide confirmación
```

El alta es la **única** operación de este proyecto que escribe en esa API, y
además siembra el tenant del otro lado: crea su base y sus plantillas. Conviene
no repetirla contra un tenant que ya existe.
