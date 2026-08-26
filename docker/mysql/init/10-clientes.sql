-- Bases de datos de clientes ficticios, para probar el sistema con nóminas
-- distintas sin ensuciar la de desarrollo.
--
-- Cada cliente es una base entera y separada: el sistema no es multiempresa
-- —una instalación atiende a una empresa— así que probar «otro cliente» es
-- exactamente eso, otra base. Cambiar de una a otra es cambiar `DB_DATABASE`
-- en `backend/.env`; está explicado en `docs/CLIENTES.md`.
--
-- OJO: MySQL corre este archivo **solo la primera vez** que se crea el
-- volumen de datos. En un entorno que ya está andando hay que crear la base a
-- mano, con estas mismas dos líneas:
--
--   docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE ..."
--
-- Para agregar otro cliente, copiar el par de líneas y cambiar el nombre.

CREATE DATABASE IF NOT EXISTS `flippy`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `flippy`.* TO 'ep_user'@'%';

FLUSH PRIVILEGES;
