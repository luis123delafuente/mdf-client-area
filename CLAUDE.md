# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS, PHP 8.4.24, WordPress 7.1
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror` vía `deploy.sh` (rsync no funciona en este hosting) — nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta. La cuenta SFTP de despliegue tiene contraseña propia, distinta de la de wp-admin — no las confundas
- Fecha límite de desarrollo: 8 de noviembre de 2026. Demo al cliente: 8 de octubre de 2026. **Fase 3 debe cerrar entre el 3 y el 5 de octubre**, con margen antes de la demo

## Reglas no negociables

Estas reglas no se negocian ni se posponen por velocidad. Cualquier código que las viole no está terminado, aunque "funcione".

1. **Nunca un enlace directo a un documento privado.** Toda descarga pasa por un script PHP del plugin que valida el permiso en el momento de servir el fichero.
2. **La visibilidad se resuelve en servidor**, nunca solo ocultando algo en el front. Una única función centralizada decide si una farmacia puede ver algo, tanto en catálogo como en documentos.
3. **Nada sensible vive fuera de la carpeta propia del WordPress.** La carpeta padre del hosting (`httpdocs`) es el docroot público de mediformplus.com.
4. **Las credenciales nunca van en el repo, en el código ni en los chats.** Gestor de contraseñas, referenciadas solo por nombre.
5. **Nunca se genera ni se envía una contraseña por email.** Altas por enlace de invitación con token caducable.
6. **El saldo de tokens (fase 4, fuera de alcance ahora) es un libro de movimientos de solo-añadido**, nunca un número editable.
7. **No se despliega sobre producción sin pasar antes por el entorno local.**
8. **La seguridad no es una fase, es un criterio de cierre.** Ninguna tarea se da por terminada sin pasar, cuando aplique, la batería de comprobaciones de cierre de fase.

## Arquitectura acordada

- **Farmacia** es una entidad propia (con CIF), separada del Usuario de WordPress, aunque hoy la relación sea 1:1
- Rol de cliente propio (`mdf_cliente`), **sin capacidades de backend**, con redirección forzada al área privada — nunca acceso a `wp-admin`
- **Planes** como entidad de datos, no hardcodeados — **es la primera tarea de Fase 3**: sustituir la columna `plan` (VARCHAR) placeholder de `wp_mdf_ca_farmacias` por una entidad real
- Toda comprobación de permiso pasa por una única función centralizada (`Permissions::puede_ver_documento()`, `puede_ver_item_catalogo()`, `puede_ver_bloque_por_plan()`), para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo compartido por plan (Herramientas, Formación) vs documentos privados por farmacia, vinculados por CIF, visibles solo por su titular (no dependen del plan)
- Documentos: carpeta propia dentro de `Clientes/` (`Clientes/private-docs/`), bloqueada con `.htaccess` deny-all, servida exclusivamente por script PHP del plugin que valida permiso antes de leer el fichero

## Decisiones técnicas ya tomadas

### Fase 1

- Sin `FOREIGN KEY` real en `documentos.farmacia_id` (`dbDelta()` no las gestiona de forma fiable); integridad a nivel de aplicación
- Bloqueo de `wp-admin` para `mdf_cliente` enganchado en `init`, no `admin_init` (con capacidades vacías, WordPress corta la petición antes de `admin_init`)
- Rol `mdf_cliente` registrado de forma idempotente (`remove_role` + `add_role` en cada activación)
- Endpoint de documentos vía `add_rewrite_rule` + `template_redirect`, no `admin-post.php` (evita el mismo gate de capacidad que bloquea `wp-admin`)
- Endpoint responde siempre 404 (nunca 403) para documento ajeno o inexistente, para no facilitar enumeración
- `flush_rewrite_rules()` en el hook de activación, después de registrar la rewrite rule — **cualquier rewrite rule nueva necesita el mismo cuidado**, o el mecanismo de versión de abajo
- Validación de ruta con `realpath()` + comprobación de prefijo antes de leer cualquier fichero servido, como defensa en profundidad
- `Activator::maybe_upgrade()`: compara `mdf_ca_db_version` guardada contra la constante del plugin y repite `dbDelta()` si difieren — **patrón obligatorio para cualquier cambio de esquema**, incluidos los de Fase 3

### Fase 2

- Tres shortcodes (`mdf_ca_listado_documentos`, `mdf_ca_catalogo_herramientas`, `mdf_ca_si_plan`) en vez de widgets nativos de Elementor, porque Elementor no está activo en el entorno de desarrollo
- Las cuatro páginas del área privada (Documentación, Herramientas, Formación, Cuota) se crean por código en la activación, identificadas por ID guardado en una `option` (no por slug, para no romperse cuando MKT las maquete y cambie URLs)
- Protección de esas páginas con `is_user_logged_in()` + `wp_safe_redirect()` en `template_redirect` (`auth_redirect()` descartado: valida cookie de scope `/wp-admin`, no llega en peticiones al front)
- Columna `plan` (VARCHAR(50) NULL) en `wp_mdf_ca_farmacias`: placeholder de Fase 2, **a sustituir en Fase 3** por la entidad real de Planes
- Catálogo de herramientas de prueba: array hardcodeado, **a sustituir en Fase 3** por tabla propia
- Barra de administración de WordPress ocultada en el front-end solo para `mdf_cliente` (filtro `show_admin_bar`) — puramente visual
- Hallazgo de cierre de Fase 2: las cuatro páginas del área privada quedaban expuestas por defecto en `/wp-json/wp/v2/pages` sin autenticar. Corregido con `rest_page_query` (`post__not_in`, cuidado porque `post__in` y `post__not_in` son excluyentes en `WP_Query`) para el listado, y `rest_pre_dispatch` para el acceso individual por ID (`rest_prepare_page` descartado: provocaba 500 por un `WP_Error` sin comprobar antes de `link_header()`). Ambos condicionados a `is_user_logged_in()`

## Completado: Fase 0, Fase 1 y Fase 2

Las tres fases cerradas, batería de seguridad pasada en las tres. El plugin está desplegado y activo en producción, con farmacias y documentos reales sirviéndose correctamente con validación de permiso, y con los tres shortcodes de Fase 2 entregados a MKT (con datos de prueba, catálogo y planes aún no reales).

## Fase actual: Fase 3 — Documentación y catálogo completos

Objetivo: dejar el área de clientes funcional con datos reales, lista para la demo del 8 de octubre. Cierre objetivo: 3-5 de octubre.

Tareas, en este orden de dependencia (Plan primero: bloquea catálogo y piloto; Invitación/login antes del piloto):

1. **Entidad Plan como dato (tabla propia)** — sustituye la columna placeholder por `wp_mdf_ca_planes`, con migración de `farmacias.plan` a referencia real y CRUD básico en backoffice
2. **Subida de documentos desde backoffice con asignación por farmacia**
3. **Catálogo por plan para Herramientas y Formación** — tabla real, sustituye el array hardcodeado; actualizar los shortcodes de Fase 2 para leer de la tabla
4. **Visor de documentos sin descarga ni impresión directas** — render en canvas, marca de agua dinámica con CIF/nombre de farmacia
5. **Sección de Cuota en modo lectura**
6. **Invitación por token, login, recuperación de contraseña, política de sesión**
7. **Alta de 3-5 farmacias piloto con documentos reales** — depende de las tareas 1, 2 y 6
8. **Batería de cierre de Fase 3**

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores. Debe fallar siempre.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo.
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa.
- Decisiones de arquitectura de la fase documentadas.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico (fase 4+)
- Pasarela de pago online
- Integración con CRM
- SSO con "la Academia" (viabilidad pendiente de datos de MDF; alternativa si no es viable: credenciales sincronizadas en el alta, no sesión compartida)
- Importador CSV, alta manual como formulario que alimenta la misma lógica, parser de facturas SAGE, Modelo 347, notificaciones al cliente, automatización "robotito" — son de Fase 4
