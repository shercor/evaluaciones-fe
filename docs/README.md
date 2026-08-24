# Documentación

## Plan de construcción

Arquitectura, modelo de datos del BFF, mapa de módulos de Angular, la lógica de
la intranet que hay que replicar y los ocho hitos:

<https://claude.ai/code/artifact/e7ff8542-5d6b-4a31-904d-309ebba32942>

## Auditoría del módulo que se reemplaza

Cómo funciona hoy el módulo de Evaluación de Personal en la intranet: los siete
controladores, las veintiséis vistas, el mapa de los cincuenta y cinco métodos
de API contra las rutas de Laravel, la máquina de estados, la matriz de
permisos y el código muerto detectado.

<https://claude.ai/code/artifact/e5feb67e-4e74-40f9-bfe6-e934682940c9>

Es la referencia para verificar que el portal nuevo no pierde ninguna regla de
negocio. Vale la pena tenerla abierta al construir cada hito.

## Decisiones tomadas

| Tema | Decisión |
|------|----------|
| Directorio de empleados | Propio, cargado por importación CSV/Excel más ABM. Sin sincronizar con la intranet. |
| Autenticación | Usuarios propios del BFF. Ni SSO ni FusionAuth. |
| Gráficos | ECharts, no Highcharts, para no gestionar licencias. |
| Correo transaccional | Sí, desde el hito 2: invitación y recuperación de contraseña. |
| Avisos de negocio por correo | Diferidos: apertura, recordatorio y resultados. |
| Proveedor de correo | Sin definir. Mailtrap en desarrollo, con credenciales propias. |
| Fotos de perfil | Todas nuevas. No se migra ninguna imagen de la intranet. |
| API de Evaluación 360 | No se modifica en ningún hito. |

## Fuera de alcance

Los agujeros de permisos y el código muerto del módulo actual de la intranet
quedan documentados en la auditoría, pero no se corrigen desde este proyecto.
El módulo sigue funcionando como está: esto es un reemplazo paralelo.
