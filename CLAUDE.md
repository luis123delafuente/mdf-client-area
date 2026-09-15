# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS, PHP 8.4.24, WordPress 7.1
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror` vía `deploy.sh` (rsync no funciona en este hosting, la cuenta SFTP está restringida por rssh sin shell remota) — nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta
- Fecha límite de desarrollo: 8 de noviembre de 2026. Demo al cliente: 8 de octubre de 2026

## Reglas no negociables

Estas reglas no se negocian ni se posponen por velocidad. Cualquier código que las viole no está terminado, aunque "funcione".

1. **Nunca un enlace directo a un documento privado.** Toda descarga pasa por un script PHP del plugin que valida el permiso en el momento de servir el fichero.
2. **La visibilidad se resuelve en servidor**, nunca solo ocultando algo en el front. Una única función centralizada decide si una farmacia puede ver algo, tanto en catálogo como en documentos.
3. **Nada sensible vive fuera de la carpeta propia del WordPress.** La carpeta padre del hosting (`httpdocs`) es el docroot público de mediformplus.com — confirmado con prueba real que cualquier fichero ahí es accesible públicamente.
4. **Las credenciales nunca van en el repo, en el código ni en los chats.** Gestor de contraseñas, referenciadas solo por nombre. Nada de contraseñas, tokens de API ni credenciales de BD hardcodeadas ni en comentarios.
5. **Nunca se genera ni se envía una contraseña por email.** Altas por enlace de invitación con token caducable para que el titular la establezca.
6. **El saldo de tokens (fase 2, fuera de alcance ahora) es un libro de movimientos de solo-añadido**, nunca un número editable directamente.
7. **No se despliega sobre producción sin pasar antes por el entorno local.**
8. **La seguridad no es una fase, es un criterio de cierre.** Ninguna tarea se da por terminada si no pasa, cuando aplique, la batería de comprobaciones de cierre de fase (ver abajo).

## Arquitectura acordada

- **Farmacia** es una entidad propia (con CIF), separada del Usuario de WordPress, aunque hoy la relación sea 1:1
- Rol de cliente propio, **sin capacidades de backend**, con redirección forzada al área privada — nunca acceso a `wp-admin`
- **Planes** como entidad de datos, no hardcodeados (se usan a partir de Fase 3, pero el modelo debe preverlos)
- Confirmado por el cliente: la visibilidad de herramientas/formación es solo por plan, sin excepciones por farmacia — pero **toda comprobación de permiso pasa por una única función centralizada**, para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo compartido por plan (Herramientas, Formación) vs documentos privados por farmacia, vinculados por CIF, visibles solo por su titular (no dependen del plan)
- Alta de farmacias: un único camino (importador CSV + alta manual que alimenta la misma lógica), CIF validado con el algoritmo de dígito de control español, importación idempotente — pero esto es de Fase 4, no de Fase 1
- Documentos: carpeta propia dentro de `Clientes/` (ej. `Clientes/private-docs/`), bloqueada con `.htaccess` deny-all, servida exclusivamente por script PHP del plugin que valida permiso antes de leer el fichero

## Fase actual: Fase 1 — Rebanada vertical

Objetivo: demostrar que el camino completo (farmacia → documento → descarga validada) funciona en este hosting concreto, en producción, antes de invertir en volumen. Sin importadores, sin catálogo, sin planes todavía. Una farmacia, un documento, extremo a extremo.

Tareas (en Productividad, proyecto "Web MDF", #227–#234, en este orden por dependencias):

1. Esqueleto del plugin `mdf-client-area` y tablas de base de datos (dbDelta en activación)
2. Entidad Farmacia separada del Usuario de WordPress
3. Rol de cliente sin acceso a `wp-admin`, con redirección al área privada
4. Función centralizada de comprobación de permisos
5. Subida y almacenamiento de un documento de prueba
6. Script PHP de servido de documentos con validación de permiso real en servidor
7. Despliegue en producción y prueba end-to-end ahí (no solo en local)
8. Batería de cierre de Fase 1

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores en URL y peticiones. Debe fallar siempre.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo.
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa.
- Decisiones de arquitectura de la fase documentadas.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico
- Pasarela de pago online
- Integración con CRM
- SSO con "la Academia" — si resulta inviable, la alternativa es credenciales sincronizadas en el alta, no sesión compartida
- Importador CSV, catálogo por plan, visor de documentos con marca de agua — son de fases 3 y 4
