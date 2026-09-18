# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS, PHP 8.4.24, WordPress 7.1
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror` vía `deploy.sh` (rsync no funciona en este hosting) — nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta. La cuenta SFTP de despliegue tiene contraseña propia, distinta de la de wp-admin — no las confundas
- Fecha límite de desarrollo: 8 de noviembre de 2026. Demo al cliente: 8 de octubre de 2026

## Reglas no negociables

Estas reglas no se negocian ni se posponen por velocidad. Cualquier código que las viole no está terminado, aunque "funcione".

1. **Nunca un enlace directo a un documento privado.** Toda descarga pasa por un script PHP del plugin que valida el permiso en el momento de servir el fichero.
2. **La visibilidad se resuelve en servidor**, nunca solo ocultando algo en el front. Una única función centralizada decide si una farmacia puede ver algo, tanto en catálogo como en documentos.
3. **Nada sensible vive fuera de la carpeta propia del WordPress.** La carpeta padre del hosting (`httpdocs`) es el docroot público de mediformplus.com.
4. **Las credenciales nunca van en el repo, en el código ni en los chats.** Gestor de contraseñas, referenciadas solo por nombre.
5. **Nunca se genera ni se envía una contraseña por email.** Altas por enlace de invitación con token caducable.
6. **El saldo de tokens (fase 2, fuera de alcance ahora) es un libro de movimientos de solo-añadido**, nunca un número editable.
7. **No se despliega sobre producción sin pasar antes por el entorno local.**
8. **La seguridad no es una fase, es un criterio de cierre.** Ninguna tarea se da por terminada sin pasar, cuando aplique, la batería de comprobaciones de cierre de fase.

## Arquitectura acordada

- **Farmacia** es una entidad propia (con CIF), separada del Usuario de WordPress, aunque hoy la relación sea 1:1
- Rol de cliente propio (`mdf_cliente`), **sin capacidades de backend**, con redirección forzada al área privada — nunca acceso a `wp-admin`
- **Planes** como entidad de datos, no hardcodeados (se usan a partir de Fase 3, pero el modelo debe preverlos)
- Toda comprobación de permiso pasa por una única función centralizada (`Permissions::puede_ver_documento()`), para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo compartido por plan (Herramientas, Formación) vs documentos privados por farmacia, vinculados por CIF, visibles solo por su titular (no dependen del plan)
- Documentos: carpeta propia dentro de `Clientes/` (`Clientes/private-docs/`), bloqueada con `.htaccess` deny-all, servida exclusivamente por script PHP del plugin que valida permiso antes de leer el fichero

## Decisiones técnicas ya tomadas (Fase 1)

- Sin `FOREIGN KEY` real en `documentos.farmacia_id` (`dbDelta()` no las gestiona de forma fiable); integridad a nivel de aplicación
- Bloqueo de `wp-admin` para `mdf_cliente` enganchado en `init`, no `admin_init` (con capacidades vacías, WordPress corta la petición antes de `admin_init`)
- Rol `mdf_cliente` registrado de forma idempotente (`remove_role` + `add_role` en cada activación)
- Endpoint de documentos vía `add_rewrite_rule` + `template_redirect`, no `admin-post.php` (evita el mismo gate de capacidad que bloquea `wp-admin`)
- Endpoint responde siempre 404 (nunca 403) para documento ajeno o inexistente, para no facilitar enumeración
- `flush_rewrite_rules()` en el hook de activación, después de registrar la rewrite rule — **cualquier rewrite rule nueva (catálogo de Fase 2 incluido) necesita el mismo cuidado**, o un mecanismo de versión que fuerce el flush en cada carga
- Validación de ruta con `realpath()` + comprobación de prefijo antes de leer cualquier fichero servido, como defensa en profundidad

## Completado: Fase 0 y Fase 1

Fase 0 (fundaciones) y Fase 1 (rebanada vertical) cerradas, batería de seguridad pasada en ambas. El plugin está desplegado y activo en producción, con una farmacia y un documento reales sirviéndose correctamente con validación de permiso.

## Fase actual: Fase 2 — Desbloquear a marketing

Objetivo: MKT no puede maquetar hasta que existan las piezas que van a colocar. Esta fase quita ese cuello de botella; es la de mayor valor por hora invertida. A partir de aquí MKT trabaja en paralelo mientras continúa el desarrollo de la lógica.

Tareas (~1 semana):

1. Widgets/shortcodes de Elementor con datos de prueba: listado de documentos, catálogo de herramientas, bloque condicionado por plan
2. Estructura de las cuatro secciones: Documentación, Herramientas, Formación, Cuota
3. Kit de estilos global con identidad de MDF (logo, tipografías, colores)
4. Entrega a MKT con instrucciones de uso

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores. Debe fallar siempre.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo.
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa.
- Decisiones de arquitectura de la fase documentadas.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico
- Pasarela de pago online
- Integración con CRM
- SSO con "la Academia"
- Importador CSV, catálogo por plan con datos reales, visor de documentos con marca de agua, subida de documentos desde backoffice — son de fases 3 y 4