# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS, PHP 8.4.24, WordPress 7.1
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror` vía `deploy.sh` (rsync no funciona en este hosting) — nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta. La cuenta SFTP de despliegue tiene contraseña propia, distinta de la de wp-admin — no las confundas
- Fecha límite de desarrollo: 8 de noviembre de 2026. Demo al cliente: 8 de octubre de 2026. **Fase 4 debe caber entre la demo y finales de octubre**, dejando la última semana para la Fase 5 (cierre y traspaso)
- Versión del plugin: consulta las constantes `MDF_CA_VERSION` y `MDF_CA_DB_VERSION` en el código antes de subirlas; no asumas su valor

## Reglas no negociables

Estas reglas no se negocian ni se posponen por velocidad. Cualquier código que las viole no está terminado, aunque "funcione".

1. **Nunca un enlace directo a un documento privado.** Toda descarga pasa por un script PHP del plugin que valida el permiso en el momento de servir el fichero.
2. **La visibilidad se resuelve en servidor**, nunca solo ocultando algo en el front. Una única función centralizada decide si una farmacia puede ver algo, tanto en catálogo como en documentos.
3. **Nada sensible vive fuera de la carpeta propia del WordPress.** La carpeta padre del hosting (`httpdocs`) es el docroot público de mediformplus.com.
4. **Las credenciales nunca van en el repo, en el código ni en los chats.** Gestor de contraseñas, referenciadas solo por nombre.
5. **Nunca se genera ni se envía una contraseña por email.** Altas por enlace de invitación con token caducable.
6. **El saldo de tokens (aplazado, fuera del MVP) es un libro de movimientos de solo-añadido**, nunca un número editable.
7. **No se despliega sobre producción sin pasar antes por el entorno local.**
8. **La seguridad no es una fase, es un criterio de cierre.** Ninguna tarea se da por terminada sin pasar, cuando aplique, la batería de comprobaciones de cierre de fase.

## Arquitectura acordada

- **Farmacia** es una entidad propia (con CIF o NIF), separada del Usuario de WordPress, aunque hoy la relación sea 1:1
- Rol de cliente propio (`mdf_cliente`), **sin capacidades de backend**, con redirección forzada al área privada — nunca acceso a `wp-admin`
- **Planes** como entidad de datos (`wp_mdf_ca_planes`), no hardcodeados; cada farmacia tiene `plan_id`
- Toda comprobación de permiso pasa por una única función centralizada (`Permissions::puede_ver_documento()`, `puede_descargar_documento()`, `puede_ver_item_catalogo()`, `puede_ver_bloque_por_plan()`), para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo compartido por plan (Herramientas, Formación) vs documentos privados por farmacia, vinculados por CIF/NIF, visibles solo por su titular (no dependen del plan)
- Documentos: carpeta propia dentro de `Clientes/` (`Clientes/private-docs/`), bloqueada con `.htaccess` deny-all, servida exclusivamente por script PHP del plugin que valida permiso antes de leer el fichero
- Alta de farmacias: un único camino lógico. El importador CSV (Fase 4) y el formulario de alta manual comparten el mismo servicio; CIF/NIF es la clave normalizada y validada; importación idempotente (upsert por CIF/NIF, simulación previa antes de escribir)
- CRM de MDF aún sin identificar: WordPress es el sistema de registro del MVP, preparado para delegar en un CRM más adelante sin reescritura

## Decisiones técnicas ya tomadas

### Fase 1

- Sin `FOREIGN KEY` real en `documentos.farmacia_id` (`dbDelta()` no las gestiona de forma fiable); integridad a nivel de aplicación. Mismo criterio en `farmacias.plan_id` y en la tabla puente del catálogo
- Bloqueo de `wp-admin` para `mdf_cliente` enganchado en `init`, no `admin_init` (con capacidades vacías, WordPress corta la petición antes de `admin_init`)
- Rol `mdf_cliente` registrado de forma idempotente (`remove_role` + `add_role` en cada activación)
- Endpoint de documentos vía `add_rewrite_rule` + `template_redirect`, no `admin-post.php` (evita el mismo gate de capacidad que bloquea `wp-admin`)
- Endpoint responde siempre 404 (nunca 403) para documento ajeno o inexistente, para no facilitar enumeración
- `flush_rewrite_rules()` en el hook de activación, después de registrar la rewrite rule — **cualquier rewrite rule nueva necesita el mismo cuidado**, o el mecanismo de versión de abajo
- Validación de ruta con `realpath()` + comprobación de prefijo antes de leer cualquier fichero servido, como defensa en profundidad
- `Activator::maybe_upgrade()`: compara `mdf_ca_db_version` guardada contra la constante del plugin y repite `dbDelta()` si difieren — **patrón obligatorio para cualquier cambio de esquema**

### Fase 2

- Tres shortcodes (`mdf_ca_listado_documentos`, `mdf_ca_catalogo_herramientas`, `mdf_ca_si_plan`) en vez de widgets nativos de Elementor, porque Elementor no está activo en el entorno de desarrollo
- Las cuatro páginas del área privada (Documentación, Herramientas, Formación, Cuota) se crean por código en la activación, identificadas por ID guardado en una `option` (no por slug, para no romperse cuando MKT las maquete y cambie URLs)
- Protección de esas páginas con `is_user_logged_in()` + `wp_safe_redirect()` en `template_redirect` (`auth_redirect()` descartado: valida cookie de scope `/wp-admin`, no llega en peticiones al front)
- Barra de administración de WordPress ocultada en el front-end solo para `mdf_cliente` (filtro `show_admin_bar`) — puramente visual
- Hallazgo de cierre de Fase 2: las cuatro páginas del área privada quedaban expuestas por defecto en `/wp-json/wp/v2/pages` sin autenticar. Corregido con `rest_page_query` (`post__not_in`, cuidado porque `post__in` y `post__not_in` son excluyentes en `WP_Query`) para el listado, y `rest_pre_dispatch` para el acceso individual por ID (`rest_prepare_page` descartado: provocaba 500 por un `WP_Error` sin comprobar antes de `link_header()`). Ambos condicionados a `is_user_logged_in()`

### Fase 3

**Planes y catálogo**
- Tabla `wp_mdf_ca_planes`: nombre editable, **slug inmutable** fijado al crear (para no romper en silencio los `[mdf_ca_si_plan]` ya publicados por MKT). `Permissions::puede_ver_bloque_por_plan()` es el único punto que resuelve `plan_id` → slug
- `Plan_Service::eliminar()` bloquea el borrado de un plan en uso (por farmacias o por items de catálogo) en vez de depender de un `ON DELETE RESTRICT` que el esquema no puede expresar
- Catálogo: tablas `wp_mdf_ca_catalogo` + `wp_mdf_ca_catalogo_planes` (N:M real). Item sin ningún plan asignado: nadie lo ve. `Catalogo_Repository` es el único acceso a ambas tablas; `set_planes()` sustituye el conjunto completo
- `enlace_url` del catálogo se valida solo por formato (`filter_var` + esquema http/https). `wp_http_validate_url()` descartado: hace resolución DNS real y rechazaba URLs válidas

**Documentos**
- Nombre en disco generado (`{farmacia_id}-{16 bytes hex}.{ext}`), nunca el original; el nombre visible vive solo en `wp_mdf_ca_documentos.nombre`
- Tipos permitidos: PDF, JPG/JPEG, PNG, WEBP y XLSX, validados con `wp_check_filetype_and_ext()` (contenido real, no extensión). Tamaño máximo 20 MB. Se excluyen SVG y los formatos Office con macros
- Tipo/etiqueta de documento (`Documento_Tipos`: contrato, factura, presupuesto, entregable, otro) es una lista fija en código, no una entidad de datos
- Admin de Documentos y de Planes: menú propio, `manage_options` + `admin-post.php` con nonce (aquí sí interesa el gate de capacidad de `admin-post.php`)
- Caso límite documentado: no existe borrado de farmacias; si se añade, la pantalla de documentos ya muestra "Farmacia ya no existe (ID N)"

**Excel (.xlsx)**
- Se admite `.xlsx` a petición de MDF. Se siguen rechazando `.xls`, `.xlsm` y `.xlsb` (no se puede garantizar la ausencia de VBA)
- Un `.xlsx` se valida por contenido (`Documento_Service::validar_xlsx()`): ZIP con `[Content_Types].xml` y `xl/workbook.xml`, libro principal de tipo xlsx (no xlsm), sin `vbaProject.bin` ni `xl/embeddings/`, con límites anti zip-bomb (2000 entradas, 200 MB descomprimido, sin extraer nada a disco). **No se busca "macroEnabled" en todo el `[Content_Types].xml`**: generadores legítimos (SheetJS) lo declaran en un `Default` genérico y daba falsos positivos. Requiere la extensión `zip` de PHP
- **Un Excel no es descargable por defecto.** Columna `documentos.descargable` (`TINYINT(1) NOT NULL DEFAULT 0`, esquema 1.5.0 vía `maybe_upgrade()`), con checkbox en la subida. Solo aplica a Excel; PDF e imágenes siempre van al visor. La decisión vive en `Permissions::puede_descargar_documento()`
- Sin marcar, el Excel se ve en el visor con SheetJS 0.20.3 (`assets/vendor/sheetjs/`, vendorizado), dibujado en canvas con marca de agua, con selector de hojas y paginación por filas (2000 filas x 60 columnas por hoja). Se dibujan fondos, color de texto, negrita, cursiva, alineación y celdas combinadas; **no** bordes, formato condicional, degradados, imágenes ni gráficos (los documentos con gráficos o diseño deben subirse como PDF). SheetJS Pro no se usa

**Visor**
- PDF.js vendorizado (solo `pdf.min.mjs` + `pdf.worker.min.mjs`, sin su viewer), nunca desde CDN. `assets/.htaccess` fuerza `AddType application/javascript .mjs`
- Dos capas: `Documento_Visor` (ruta `/mdf-ca-visor/{id}/`) solo exige sesión; la validación real pasa por `Documento_Endpoint` cuando el JS hace `fetch()`. El listado de documentos enlaza siempre al visor, no al endpoint
- PDF e imágenes se pintan en `<canvas>`; la marca de agua (CIF + nombre de farmacia) va horneada en los mismos píxeles, no como capa CSS. Espaciado calculado con `ctx.measureText()`
- `Ctrl/Cmd+P` y `Ctrl/Cmd+S` bloqueados por `keydown`; `beforeprint` vacía el canvas y `afterprint` lo restaura. PrintScreen del sistema no se puede bloquear
- **Un documento no descargable solo se entrega al visor**: `Documento_Token` emite un token de un solo uso (transient con hash, ligado a usuario + documento, TTL 120 s, consumo atómico) que el `fetch()` manda en `?mdf_t=`. Sin token válido, o con `Sec-Fetch-Dest: document`, el endpoint da el mismo 404 uniforme. Un documento descargable no exige token
- **Límite asumido:** los bytes llegan al navegador por `fetch()`; quien tiene sesión y DevTools puede sacarlos o reproducir la petición. La protección es disuasión y trazabilidad (sin botón, sin texto copiable, marca de agua con CIF y nombre), no DRM

**Invitación, login y sesión**
- Sin sistema de tokens propio: se reutiliza el mecanismo nativo de WordPress (`get_password_reset_key()` / `check_password_reset_key()` / `wp-login.php?action=rp`) para invitación y recuperación
- `Invitacion_Service::invitar()` crea el usuario WP al invitar si la farmacia no tiene uno, con contraseña aleatoria de 64 caracteres que nunca se guarda ni se envía, y lo vincula con `Farmacia_Repository::update_wp_user_id()`. `wp_user_id` es opcional en `Farmacia_Service::crear()`
- Caducidad: 7 días para invitación (filtro `password_reset_expiration` activado solo durante `invitar()`), 24 h para "olvidé mi contraseña" nativo
- Reinvitar una cuenta ya activa está permitido (reset iniciado por el admin, solo cambia el texto del email). `Admin_Invitaciones` deriva el estado (`sin_cuenta` / `usuario_roto` / `pendiente` / `activa`) leyendo `user_activation_key`, sin tabla propia
- Sesión de 8 horas fijas para `mdf_cliente` (`auth_cookie_expiration`), ignorando "Recuérdame"; el administrador no se toca

**Alta de farmacias y NIF**
- `Admin_Farmacias`: alta mínima (nombre, CIF/NIF y plan), reutiliza `Farmacia_Service::crear()`; tras crear redirige a `Admin_Invitaciones`
- `Identificador_Fiscal_Validator` detecta el formato por el primer carácter (letra → CIF con `CIF_Validator`, dígito → NIF con `NIF_Validator`, mod 23). NIE fuera de alcance. La columna sigue llamándose `cif`; `get_cif()` y `find_by_cif()` no cambiaron de nombre

**Cambios hechos directamente por Luis, sin pasar por revisión** (ya verificados): filtro de farmacia en el selector de Documentos MDF, soporte de Excel, checkbox de descargable y botón de logout en el backoffice

## Completado: Fases 0 a 3

Las cuatro fases cerradas con batería de seguridad pasada. Desplegado y activo en producción con 4-5 farmacias piloto reales.

Pendientes sueltos de Fase 3, no bloquean la Fase 4:
- Sección de Cuota en modo lectura (tarea #254): a la espera del contenido de MKT
- Borrar los documentos de prueba subidos a las farmacias piloto
- Espaciado estético de la marca de agua del visor (no bloqueante)

## Fase actual: Fase 4 — Escala y automatización

Objetivo: pasar de un portal que funciona con altas manuales a uno que se mantiene solo. Duración estimada: ~3 semanas, tras la demo del 8 de octubre.

Tareas, en orden de ejecución y dependencia:

1. **Importador CSV de farmacias con simulación previa** (Alta). Columnas mínimas: nombre, CIF/NIF, plan (por slug). La simulación muestra crear / actualizar / rechazada sin escribir nada; la confirmación aplica el upsert por CIF/NIF. Idempotente. Si la farmacia ya existe, actualiza nombre y plan; **no** toca `wp_user_id`, invitación ni documentos. Nunca envía invitaciones ni crea usuarios
2. **Alta manual alimentando la misma lógica que el importador** (Media). `Admin_Farmacias` pasa por el mismo servicio de upsert. Depende de 1
3. **Parser de facturas SAGE con cruce de CIF** (Alta). Formato uniforme confirmado. Extrae CIF/NIF, número, fecha e importe; cruza con `find_by_cif()`. Reutiliza lo aprendido del parser de nóminas. Bloquea 4, 5 y 6
4. **Bandeja de excepciones de facturas** (Media). Lo que el parser no asigna (sin CIF, desconocido, ambiguo) se resuelve a mano. Nada se sube al cliente sin estar resuelto. Depende de 3
5. **Ingesta del histórico de documentos** (Media). Por lotes, idempotente (hash del fichero), con informe final. Depende de 3 y 4
6. **Modelo 347** (Media). Falta confirmar con MDF el formato y el origen. Nuevo tipo en `Documento_Tipos`
7. **Notificaciones al cliente por documentación nueva** (Media). Agrupadas, sin datos sensibles ni enlaces directos al fichero: solo enlazan al área privada. Por el SMTP ya configurado
8. **Diseño de la automatización "robotito"** (Baja). Solo diseño, sin implementación
9. **Batería de cierre de la Fase 4** (Alta)

### Decisiones de Fase 4

- **Robotito:** script Python que reutiliza el parser de nóminas/facturas, con un **endpoint de subida autenticado en el plugin**. Se descarta n8n. El endpoint y sus credenciales (siempre en gestor de contraseñas, nunca en el repo) son la parte sensible del diseño
- El importador, la bandeja de excepciones y la ingesta se construyen siempre con `manage_options` + nonce, sin ninguna vía para `mdf_cliente`
- Los ficheros subidos (CSV, facturas) nunca se guardan en una carpeta pública. Las facturas de las farmacias son documentación fiscal real de terceros: no se suben a chats ni a Git. Para pruebas, usar datos propios o ficticios
- Todo documento ingerido sigue las reglas de la Fase 3: nombre en disco aleatorio, carpeta privada, servido solo por el endpoint, visible solo para su titular

- **Importador CSV (hecho, v0.10.0):** entre simular y confirmar se guardan las filas ya leídas, no el fichero, en un transient ligado al usuario (`mdf_ca_import_{user_id}_{id}`, 15 min). `aplicar()` recalcula contra la BD y compara una huella; si difiere, no escribe nada y muestra la simulación nueva. El lote se consume una sola vez
- Todo o nada con transacción, previa comprobación de InnoDB (si no lo es, se niega a aplicar). Las filas rechazadas se ignoran y no bloquean al resto
- Rechazos: CIF/NIF duplicado en el fichero (todas las apariciones), plan vacío o inexistente, nombre vacío o de más de 255 caracteres. Plan por `sanitize_title()`, igual que `Plan_Service`
- Límites: 1 MB y 1000 filas. Un CSV con etiquetas HTML se rechaza entero (WordPress lo detecta como HTML)
- `Identificador_Fiscal_Validator::normalizar()` es la única normalización de CIF/NIF, usada también por `Farmacia_Service::crear()`
- Permisos: `manage_options` en el controlador, como el resto del backoffice

- **Alta manual (hecho, v0.11.0):** `Admin_Farmacias::gestionar_crear()` solo comprueba capacidad y nonce, sanea los tres campos y llama a `Farmacia_Import_Service::crear_una()` (simular + aplicar de una fila, sin paso de confirmación). La validación vive solo en el servicio
- El formulario solo crea: si el CIF/NIF ya existe, devuelve el duplicado (`Farmacia_Service::error_duplicado()`, único sitio del texto). Actualizar es cosa del importador
- Plan obligatorio también en el formulario (por slug). Ya no se puede crear una farmacia sin plan desde la pantalla; las existentes sin plan no se tocan

- **Avisos por email (hecho, v0.12.0, esquema 1.6.0):** columna `documentos.notificado_en` (UTC, NULL = pendiente). La migración inicial marca todos los documentos existentes como notificados, gateada por `mdf_ca_avisos_backfill_hecho`, para no avisar de lo ya subido
- Evento único de WP-Cron a las 09:00 de `wp_timezone()`, reprogramado en cada ejecución (no el recurrente `daily`, que se desplaza con el cambio horario). Tope de un aviso por farmacia y día natural (usermeta `mdf_ca_ultimo_aviso_documentos`)
- Bloqueo propio en `wp_options` con `INSERT IGNORE` y toma de caducados por `UPDATE` condicionado (no `add_option()`, que pisaría el de otro proceso); se libera siempre en `finally`
- Se marca como notificado solo si `wp_mail()` devuelve true; si falla, se reintenta en la siguiente ejecución. Riesgo asumido: aviso duplicado antes que perdido
- Farmacias sin cuenta activa: sus documentos quedan pendientes y entran en el primer aviso tras activar. El email solo lleva recuentos por tipo (sin tipo = "otro") y un enlace a Documentación; nunca nombres, CIF ni enlaces al fichero
- Interruptor `mdf_ca_avisos_documentos_activos` apagado por defecto; `MDF_CA_AVISOS_DESACTIVADOS` en wp-config.php lo anula. La ingesta masiva usa `subir(..., $notificar = false)`
- Pendiente: opción de baja (preferencia por usuario y enlace en el email); los documentos silenciados son indistinguibles de los notificados

- **Parser de facturas SAGE (hecho, plugin v0.13.0):** vive fuera del plugin, en el repo hermano `Parser_facturas_SAGE` (Python, PyMuPDF); el plugin nunca lee PDFs. El parser devuelve datos y un estado; el cruce lo hace `Factura_Cruce_Service::cruzar()` con `find_by_cif()`, que revalida el CIF y exige número, fecha e importe válidos, sin fiarse del JSON recibido
- Plantilla: 2 páginas por PDF (original y copia), todas deben dar los mismos datos; se lee por coordenadas. El CIF del emisor debe estar en `EMISORES` (config.py, hoy solo Mediformplus); el del cliente es el de la columna "N.I.F"
- Estados: sin texto > formato inesperado > número discrepante > sin CIF > CIF inválido > ok. El parser nunca devuelve "asignada": lo decide el cruce. Abonos con importe negativo y `es_abono`
- Los PDF reales nunca van al repo (`*.pdf` en .gitignore); los tests usan fixtures sintéticas

- **Recepción de documentos (hecho, v0.14.0, esquema 1.7.0):** `POST /wp-json/mdf-ca/v1/facturas` (REST, multipart: `pdf`, `resultado` del parser y `notificar` opcional). Autenticación con Application Passwords sobre el usuario técnico `mdf-robot` (rol `mdf_robot`, una sola capacidad); el `permission_callback` es `Recepcion_Autenticacion::autorizar()`, único punto para cambiar de mecanismo
- El robot solo puede usar el namespace `mdf-ca/v1` (`rest_pre_dispatch`, también `batch`); su login por contraseña está rechazado; las application passwords solo existen para él y solo valen en REST. Errores uniformes: un filtro sustituye cualquier error del namespace por el mismo cuerpo
- Solo el estado "asignada", revalidado en servidor con `Factura_Cruce_Service`, crea documento (nombre visible "Factura A N" o "Abono A N", según el signo del importe revalidado). Idempotencia con UNIQUE global sobre `documentos.hash_sha256` (NULL en documentos de backoffice y anteriores)
- Interruptor `mdf_ca_recepcion_activa` apagado por defecto; `MDF_CA_RECEPCION_DESACTIVADA` lo anula. Tope diario configurable y registro de auditoría solo con recuentos (`wp_mdf_ca_recepcion_registro`). Pantalla: Documentos MDF › Recepción automática (manage_options + nonce)
- La subida de PDF (también la del backoffice) exige ahora el tipo real `application/pdf` con `fileinfo`; en producción hay que comprobar que la extensión existe
- Pendiente: estado "pendiente de publicar" (`documentos.publicado`, condición en `Permissions::puede_ver_documento()`), antes de activar la recepción en producción

- **Envío por lotes (hecho, repo `Parser_facturas_SAGE`, paquete `envio_facturas/`):** recorre una carpeta de PDF, los parsea y los manda a `POST /wp-json/mdf-ca/v1/facturas`. Sin `--enviar` es dry-run y nunca abre conexión; `notificar=0` por defecto. Credenciales solo por variables de entorno (`MDF_ENVIO_*`) o fichero `--config` fuera del repo; URL https obligatoria (http solo en localhost) y TLS nunca desactivado
- Lo que el parser no resuelve nunca se envía: se copia a `excepciones/<estado>/` con un informe CSV sin CIF, nombres ni importes; se resuelve subiéndolo a mano desde Documentos MDF. No hay bandeja de excepciones en el servidor (decisión: se reconsidera solo si el histórico genera muchas)
- 401, 429 y 503 detienen el lote; 400 y 422 se copian a excepciones; 500 o red se reintentan 3 veces con espera creciente. Registro local `registro.jsonl` de solo añadir, con bloqueo contra dos lotes simultáneos

- `EMISORES` es un diccionario de CIF a series admitidas: `{"B82827635": {"A"}, "B88471149": {"SF"}}` (Mediformplus y Mediformplus Formación). La serie identifica al emisor y ninguna se comparte; una serie que no sea de su emisor, o un emisor desconocido, da `formato_inesperado`. El resultado incluye el campo `emisor`
- El total se lee bajo "TOTAL FRA" y la moneda debe aparecer exactamente una vez: el rótulo "(EUR)" bajo el total (plantilla de Mediformplus) o el "€" en el importe (plantilla de Formación). Dos veces o ninguna, `formato_inesperado`

- Facturas de varias páginas: las copias se delimitan por PÁG. = 1 (PÁG. entero, 1..N sin saltos); todas las copias tienen el mismo número de páginas, la misma cabecera en cada página (cliente incluido) y el mismo total. El total se lee solo en la última página de cada copia; las intermedias deben llevar "SUMA Y SIGUE" y la columna del total vacía, y la última no puede llevarlo. Un total en una intermedia o cualquier cosa que no encaje da `formato_inesperado`. Sin OCR
- Pendiente de verificar con facturas largas reales (solo se ha probado una, de Mediformplus): el rótulo de las páginas intermedias de Formación puede ser distinto

- **Documentos organizados (hecho, v0.16.0, esquema 1.9.0):** columna `documentos.fecha_documento DATE NULL` (fecha de la factura, no de subida), rellenada por la recepción con la fecha ya revalidada por el cruce. Un único validador (`Documento_Service::fecha_documento_valida()`: formato `Y-m-d` estricto, del 1-1-2000 a hoy+31 días); la subida manual la exige si el tipo es factura
- Shortcode del listado: atributo `vista` (`agrupada` por defecto, `plana` idéntica al HTML anterior). Agrupado por tipo en orden fijo (Facturas, Contratos, Presupuestos, Entregables, Otros; sin tipo o desconocido, en "Otros"); facturas por año y mes de la fecha de factura, más reciente primero, con "Sin fecha" al final; la fecha de subida nunca sustituye a la de factura. Mes en español con array propio. La visibilidad sigue en Permissions (una consulta, agrupado en memoria)

### Cómo trabajar cada tarea

- Antes de escribir código, resume qué clases tocarás o crearás y espera el visto bueno
- Reutiliza `Farmacia_Service`, `Farmacia_Repository`, `Identificador_Fiscal_Validator`, `Plan_Repository`, `Documento_Service` y `Permissions`; no dupliques lógica ni consultas
- Todo cambio de esquema pasa por `maybe_upgrade()` y sube `MDF_CA_DB_VERSION`; sube `MDF_CA_VERSION` en cada tarea
- Prueba en local con datos reales de las fases anteriores antes de desplegar
- Al terminar, entrega los cambios por archivo con las decisiones de diseño tomadas, para pasarlas a memoria

- **Pendiente de publicar (hecho, v0.15.0, esquema 1.8.0):** columna `documentos.publicado` (DEFAULT 1). La recepción crea con publicado = 0 mientras la option `mdf_ca_recepcion_requiere_aprobacion` esté activa (por defecto activada); la subida de backoffice siempre publica. Apagar la option no publica lo ya pendiente
- `Permissions::puede_ver_documento()` exige misma farmacia y publicado (un único predicado privado, compartido con `filtrar_documentos_visibles()`, que el listado usa con una sola consulta de farmacia); `puede_descargar_documento()` lo hereda. Un documento no publicado da el mismo 404 uniforme que uno inexistente o ajeno (verificado byte a byte). Excepción deliberada: `find_pendientes_aviso()` filtra publicado = 1 en SQL
- Pantalla Documentos MDF › Pendientes de publicar (manage_options + nonce): ver, publicar, publicar todos los de una farmacia y despublicar. Vista previa de admin por `admin-post.php`, sin URL estable al fichero: nonce por documento y token de un solo uso, marca de agua con el login del admin; solo `Permissions::puede_previsualizar_documento()` (administrador)

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores. Debe fallar siempre.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo.
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa.
- Decisiones de arquitectura de la fase documentadas.

Específico de la Fase 4: importador, bandeja de excepciones e ingesta solo accesibles con `manage_options` + nonce; documentos ingeridos sin enlace directo y solo visibles para su titular.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico (aplazado, fuera del MVP)
- Pasarela de pago online y herramientas de pago
- Integración con CRM (MDF aún no lo ha seleccionado)
- SSO con "la Academia" (viabilidad pendiente de datos de MDF; alternativa si no es viable: credenciales sincronizadas en el alta, no sesión compartida)
- Implementación del robotito (en Fase 4 solo se diseña)
- Fase 5: revisión RGPD, documentación de traspaso, plan de mantenimiento, formación a MDF y cuentas individuales de backoffice para cada persona de MDF