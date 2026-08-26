<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Nada de esto es funcionalidad: es lo que evita que las tablas de servicio
| crezcan para siempre. Lo corre el contenedor `scheduler` con
| `php artisan schedule:work`; en producción, un cron llamando a
| `schedule:run` cada minuto.
|
| Lo que **no** está acá y conviene saber por qué:
|
| - Las **sesiones** no necesitan tarea. El driver de base las limpia solo,
|   por lotería: 2 de cada 100 peticiones borran las que pasaron su
|   `SESSION_LIFETIME`. Está en `config/session.php`.
| - La tabla **cache** tampoco, hoy: el código de la aplicación no guarda nada
|   en caché —solo la usa el framework para un puñado de llaves internas— así
|   que no crece. Si algún día se empieza a cachear de verdad, Laravel no borra
|   las entradas vencidas del driver de base y va a hacer falta algo acá.
|
*/

// Los trabajos fallidos se guardan para poder mirarlos; un mes alcanza.
Schedule::command('queue:prune-failed --hours=720')->daily();

// Los lotes terminados, dos días: son el rastro de un envío que ya ocurrió.
Schedule::command('queue:prune-batches --hours=48')->daily();

// Planillas subidas para homologar que nadie importó, y contraseñas
// temporales viejas. El comando explica el porqué de cada plazo.
Schedule::command('importaciones:limpiar')->hourly();
