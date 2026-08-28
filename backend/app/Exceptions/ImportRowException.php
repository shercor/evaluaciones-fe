<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Esta fila no se puede guardar, y el motivo se le puede contar a una persona.
 *
 * Separa los dos fracasos que puede tener una fila y que hasta ahora se
 * mezclaban. Uno es un dato mal puesto —dos personas con el mismo correo— y su
 * explicación es lo único que hace falta para arreglarlo en la planilla. El
 * otro es una falla del sistema, y lo que corresponde ahí es registrarla en el
 * log y mostrar algo que no sea el SQL.
 *
 * El caso que la motivó llegaba a la pantalla así:
 *
 *   Error al guardar: SQLSTATE[23000]: Integrity constraint violation: 1062
 *   Duplicate entry 'ana@flippy.cl' for key 'users.users_email_unique'
 *   (Connection: mysql, Host: mysql, Port: 3306, Database: flippy, SQL: ...)
 *
 * Cinco líneas que no dicen qué hacer, con el nombre de la base y del host
 * adentro.
 */
class ImportRowException extends \RuntimeException {}
