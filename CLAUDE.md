# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias (unas 200 farmacias previstas)
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS (servidor dedicado gestionado), PHP 8.4.24 (el plugin exige **PHP 8.4**: `Requires PHP: 8.4`, por OpenSpout), WordPress 7.1, base de datos **MariaDB 11.8** (distinta del MySQL local: cualquier SQL nuevo debe funcionar en los dos)
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror -R --delete` vía `deploy.sh` (repo `deploy-script`; rsync no funciona en este hosting). Siempre ensayar antes con `./deploy.sh --dry-run`. Nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta. El script (`wp-content/plugins/deploy-script/deploy.sh`, carpeta hermana del plugin, **sin control de versiones**) excluye `.git`, `.gitignore`, `.DS_Store`, `node_modules`, `tests`, `*.log`, `CLAUDE.md`, `*.md`, `*.docx` y `docs/`; los ficheros que empiezan por punto (`assets/.htaccess`, `.htaccess` de la raíz) sí se suben. La cuenta SFTP de despliegue tiene contraseña propia, distinta de la de wp-admin
- Fechas: objetivo de entrega el 12 de octubre de 2026; fecha límite el 8 de noviembre de 2026
- Versión del plugin: consulta las constantes `MDF_CA_VERSION` y `MDF_CA_DB_VERSION` en el código antes de subirlas; no asumas su valor. **`MDF_CA_VERSION` y la línea `Version:` de la cabecera de `mdf-client-area.php` se actualizan siempre juntas, en el mismo cambio, con el mismo valor** (la cabecera es lo que muestra WordPress en Plugins; hasta la 0.24.0 se quedó en 0.1.0)

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
- Toda comprobación de permiso pasa por una única función centralizada (`Permissions::puede_ver_documento()`, `puede_descargar_documento()`, `puede_previsualizar_documento()`, `puede_ver_item_catalogo()`, `puede_ver_bloque_por_plan()`), para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo compartido por plan (Herramientas, Formación) vs documentos privados por farmacia, vinculados por CIF/NIF, visibles solo por su titular (no dependen del plan)
- Documentos: carpeta `Clientes/private-docs/` **en la raíz de WordPress** (`ABSPATH`, no en `wp-content`; ruta relativa en `Documento_Endpoint::CARPETA_RELATIVA`), protegida con `.htaccess` de denegación total e `index.php`, que el propio plugin crea si faltan y vigila (`Carpeta_Privada_Proteccion`). Solo se sirven por el script PHP del plugin, que valida permiso antes de leer el fichero
- Alta de farmacias: un único camino lógico. El importador CSV y el formulario de alta manual comparten el mismo servicio; CIF/NIF es la clave normalizada y validada; importación idempotente (upsert por CIF/NIF, simulación previa antes de escribir)
- Facturas: el parser (repo hermano `Parser_facturas_SAGE`, Python) lee los PDF en el Mac de MDF y envía el resultado al endpoint autenticado del plugin; el plugin nunca lee PDF y revalida todo en servidor
- CRM de MDF aún sin identificar: WordPress es el sistema de registro del MVP, preparado para delegar en un CRM más adelante sin reescritura

## Decisiones técnicas ya tomadas

### Fase 1

- Sin `FOREIGN KEY` real en `documentos.farmacia_id` (`dbDelta()` no las gestiona de forma fiable); integridad a nivel de aplicación. Mismo criterio en `farmacias.plan_id`, en la tabla puente del catálogo y en las tablas de la Fase 4
- Bloqueo de `wp-admin` para `mdf_cliente` enganchado en `init`, no `admin_init` (con capacidades vacías, WordPress corta la petición antes de `admin_init`)
- Rol `mdf_cliente` registrado de forma idempotente (`remove_role` + `add_role` en cada activación)
- Endpoint de documentos vía `add_rewrite_rule` + `template_redirect`, no `admin-post.php` (evita el mismo gate de capacidad que bloquea `wp-admin`)
- Endpoint responde siempre 404 (nunca 403) para documento ajeno o inexistente, para no facilitar enumeración
- `flush_rewrite_rules()` en el hook de activación, después de registrar la rewrite rule — **cualquier rewrite rule nueva necesita el mismo cuidado**, o el mecanismo de versión de abajo
- Validación de ruta con `realpath()` + comprobación de prefijo antes de leer cualquier fichero servido, como defensa en profundidad
- `Activator::maybe_upgrade()`: compara `mdf_ca_db_version` guardada contra la constante del plugin y repite `dbDelta()` si difieren — **patrón obligatorio para cualquier cambio de esquema**. Como el plugin ya está activo en producción, todo lo que deba ejecutarse una vez tras desplegar va en `maybe_upgrade()` gateado por su propia option, no solo en `activate()`

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
- Un `.xlsx` se valida por contenido (`Documento_Service::validar_xlsx()`): ZIP con `[Content_Types].xml` y `xl/workbook.xml`, libro principal de tipo xlsx (no xlsm), sin `vbaProject.bin` ni `xl/embeddings/`, con límites anti zip-bomb (2000 entradas, 200 MB descomprimido, sin extraer nada a disco). **No se busca "macroEnabled" en todo el `[Content_Types].xml`**: generadores legítimos (SheetJS) lo declaran en un `Default` genérico y daba falsos positivos. Requiere la extensión `zip` de PHP (confirmada en producción)
- **Un Excel no es descargable por defecto.** Columna `documentos.descargable` (`TINYINT(1) NOT NULL DEFAULT 0`), con checkbox en la subida. Solo aplica a Excel; PDF e imágenes siempre van al visor. La decisión vive en `Permissions::puede_descargar_documento()`
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
- `Admin_Farmacias`: alta mínima (nombre, CIF/NIF y plan); tras crear redirige a `Admin_Invitaciones`
- `Identificador_Fiscal_Validator` detecta el formato por el primer carácter (letra → CIF con `CIF_Validator`, dígito → NIF con `NIF_Validator`, mod 23). NIE fuera de alcance. La columna sigue llamándose `cif`; `get_cif()` y `find_by_cif()` no cambiaron de nombre

**Cambios hechos directamente por Luis, sin pasar por revisión** (ya verificados): filtro de farmacia en el selector de Documentos MDF, soporte de Excel, checkbox de descargable y botón de logout en el backoffice

### Fase 4

**Importador CSV y alta manual** (v0.10.0 y v0.11.0)
- Entre simular y confirmar se guardan las filas ya leídas, no el fichero, en un transient ligado al usuario (`mdf_ca_import_{user_id}_{id}`, 15 min). `aplicar()` recalcula contra la BD y compara una huella; si difiere, no escribe nada y muestra la simulación nueva. El lote se consume una sola vez
- Todo o nada con transacción, previa comprobación de InnoDB (`DB_Schema::tabla_es_innodb()`). Las filas rechazadas se ignoran y no bloquean al resto
- Rechazos: CIF/NIF duplicado en el fichero (todas las apariciones), plan vacío o inexistente, nombre vacío o de más de 255 caracteres. Plan por `sanitize_title()`, igual que `Plan_Service`. Límites: 1 MB y 1000 filas. Un CSV con etiquetas HTML se rechaza entero
- Si la farmacia existe, actualiza nombre y plan; **no** toca `wp_user_id`, invitación ni documentos. Nunca envía invitaciones ni crea usuarios
- `Identificador_Fiscal_Validator::normalizar()` es la única normalización de CIF/NIF, usada también por `Farmacia_Service::crear()`
- Alta manual: `Admin_Farmacias::gestionar_crear()` solo comprueba capacidad y nonce, sanea los tres campos y llama a `Farmacia_Import_Service::crear_una()`. El formulario solo crea: si el CIF/NIF ya existe, devuelve el duplicado (`Farmacia_Service::error_duplicado()`, único sitio del texto). Plan obligatorio también en el formulario

**Avisos por email** (v0.12.0, esquema 1.6.0)
- Columna `documentos.notificado_en` (UTC, NULL = pendiente). La migración inicial marcó todos los documentos existentes como notificados (`mdf_ca_avisos_backfill_hecho`)
- Evento único de WP-Cron a las 09:00 de `wp_timezone()`, reprogramado en cada ejecución (no el recurrente `daily`, que se desplaza con el cambio horario). Tope de un aviso por farmacia y día natural (usermeta `mdf_ca_ultimo_aviso_documentos`). En producción WP-Cron depende de las visitas; alternativa disponible: tareas cron del panel de IONOS
- Bloqueo propio en `wp_options` con `INSERT IGNORE` y toma de caducados por `UPDATE` condicionado (no `add_option()`); se libera siempre en `finally`
- Se marca como notificado solo si `wp_mail()` devuelve true; si falla, se reintenta en la siguiente ejecución. Riesgo asumido: aviso duplicado antes que perdido
- Farmacias sin cuenta activa: sus documentos quedan pendientes y entran en el primer aviso tras activar. El email solo lleva recuentos por tipo y un enlace a Documentación; nunca nombres, CIF ni enlaces al fichero
- Interruptor `mdf_ca_avisos_documentos_activos` apagado por defecto (MDF prefiere no avisar de momento); `MDF_CA_AVISOS_DESACTIVADOS` en wp-config.php lo anula
- Pendiente: opción de baja; los documentos silenciados son indistinguibles de los notificados

**Parser de facturas SAGE** (repo `Parser_facturas_SAGE`; cruce en el plugin desde v0.13.0)
- El parser (Python, PyMuPDF, licencia AGPL a revisar antes de la entrega) devuelve datos y un estado; el cruce lo hace `Factura_Cruce_Service::cruzar()` con `find_by_cif()`, que revalida el CIF y exige emisor, serie, número, fecha e importe válidos, sin fiarse del JSON recibido. El parser nunca devuelve "asignada"
- Emisores: Mediformplus (B82827635, serie `A`) y Mediformplus Formación (B88471149, serie `SF`). SAGE exporta por sociedad. La serie identifica al emisor y ninguna se comparte
- Se lee por coordenadas. Copias delimitadas por PÁG. = 1; todas las copias con el mismo número de páginas, la misma cabecera y el mismo total. El total solo en la última página de cada copia; las intermedias llevan "SUMA Y SIGUE". La moneda aparece exactamente una vez ("(EUR)" bajo el total en Mediformplus, "€" en el importe en Formación). Cualquier cosa que no encaje da `formato_inesperado`. Sin OCR
- Estados: sin texto > formato inesperado > número discrepante > sin CIF > CIF inválido > ok. Abonos con importe negativo
- Pendiente de verificar con cientos de facturas reales de 2026 (solo se han probado unas pocas; el rótulo de las páginas intermedias de Formación no está confirmado)
- Los PDF reales nunca van al repo (`*.pdf` en .gitignore); los tests usan fixtures sintéticas

**Recepción de documentos** (v0.14.0, esquema 1.7.0; emisor en v0.17.0, esquema 1.10.0)
- `POST /wp-json/mdf-ca/v1/facturas` (multipart: `pdf`, `resultado` del parser y `notificar` opcional). Application Passwords sobre el usuario técnico `mdf-robot` (rol `mdf_robot`, una sola capacidad `mdf_ca_subir_facturas`); el `permission_callback` es `Recepcion_Autenticacion::autorizar()`, único punto para cambiar de mecanismo
- El robot solo puede usar el namespace `mdf-ca/v1` (`rest_pre_dispatch`, también `batch`); su login por contraseña está rechazado; las application passwords solo existen para él. Errores uniformes: un filtro sustituye cualquier error del namespace por el mismo cuerpo
- `Factura_Emisores` es la lista de emisores y series en código, único sitio que lo decide; nunca se deduce el emisor de la serie. Serie `^[A-Z]{1,3}$/D`, número `^\d{1,20}$/D`
- Columnas nullable `documentos.hash_sha256` (UNIQUE), `emisor_cif`, `factura_serie` y `factura_numero` (UNIQUE conjunto); los NULL no chocan. Misma factura para la misma farmacia: 200 duplicado idempotente; para otra: 422 `documento_en_otra_farmacia` uniforme. La carrera la pierde el INSERT del segundo, cuyo error de base de datos se silencia para no llevar serie y número al log
- Nombre visible `{Factura|Abono} {sociedad} {serie} {número}`, abono por el signo del importe revalidado
- Interruptor `mdf_ca_recepcion_activa` apagado por defecto; `MDF_CA_RECEPCION_DESACTIVADA` lo anula. Tope diario configurable (1000 por defecto) y registro de auditoría solo con recuentos (`wp_mdf_ca_recepcion_registro`). Pantalla: Documentos MDF › Recepción automática
- La subida de PDF (también la del backoffice) exige el tipo real `application/pdf` con `fileinfo` (confirmada en producción)

**Envío por lotes** (repo `Parser_facturas_SAGE`, paquete `envio_facturas/`; diseño en `docs/robotito.md`)
- Recorre una carpeta de PDF, los parsea y los manda al endpoint. Sin `--enviar` es dry-run; `notificar=0` por defecto. Credenciales solo por variables de entorno (`MDF_ENVIO_*`) o fichero `--config` fuera del repo; https obligatorio y TLS nunca desactivado
- Lo que el parser no resuelve nunca se envía: se copia a `excepciones/<estado>/` con un informe CSV sin CIF, nombres ni importes, y se resuelve subiéndolo a mano desde Documentos MDF (no hay bandeja de excepciones en el servidor). Una factura resuelta a mano no se reenvía (queda sin emisor, serie ni número y el servidor no la detectaría como duplicada)
- 401, 429 y 503 detienen el lote; 400 y 422 se copian a excepciones; 500 o red se reintentan 3 veces. Registro local `registro.jsonl` de solo añadir, con bloqueo contra dos lotes simultáneos
- `cif_sin_farmacia` es permanente (hay facturas de clientes que no son farmacias del portal). Antes del histórico de 2026: dar de alta las farmacias con el importador y subir el tope diario
- Operación recomendada: manual tras cada exportación de SAGE (simulación y luego envío), al menos el primer mes. Lo opera la persona de MDF que exporta de SAGE, en un Mac

**Pendiente de publicar, publicación en bloque y deshacer** (v0.15.0 a v0.19.0, esquemas 1.8.0 y 1.11.0)
- Columna `documentos.publicado` (DEFAULT 1). La recepción crea con publicado = 0 mientras `mdf_ca_recepcion_requiere_aprobacion` esté activa (por defecto activada); la subida de backoffice siempre publica
- `Permissions::puede_ver_documento()` exige misma farmacia y publicado (un único predicado privado, compartido con `filtrar_documentos_visibles()`); un documento no publicado da el mismo 404 uniforme que uno inexistente o ajeno. Excepción deliberada: `find_pendientes_aviso()` filtra publicado = 1 en SQL
- Vista previa de admin por `admin-post.php`, sin URL estable al fichero: nonce por documento, token de un solo uso y marca de agua con el login del admin; solo `Permissions::puede_previsualizar_documento()`
- «Publicar todos los pendientes»: dos pasos; al preparar se guarda en un transient del usuario la huella de los ids publicables; al aplicar se consume, se recalcula y solo se escribe si coincide. Se publica por lista explícita de ids, nunca por filtro, así que lo que llegue después no se publica sin haber sido contado. Tandas de 500 dentro de una transacción
- Lotes: tabla `wp_mdf_ca_publicacion_lotes` y columna `documentos.publicacion_lote_id`, que solo vale L mientras el documento siga publicado desde el lote L; cualquier otro cambio de estado la pone a NULL. Deshacer un lote usa el mismo patrón de dos pasos y solo toca lo que siga con ese lote y publicado. Deshacer no retira lo que la farmacia ya haya visto o descargado

**Trazabilidad y pantallas** (v0.20.0 y v0.21.0, esquema 1.12.0)
- Tabla de solo añadir `wp_mdf_ca_publicacion_eventos` (documento, acción, origen, usuario, fecha UTC, lote). Un único punto cambia `publicado`: `Documento_Repository::cambiar_estado()`, por tandas con `SELECT … FOR UPDATE`, `UPDATE` e `INSERT` multifila de eventos dentro de la transacción de quien llama; un fallo revierte estado y eventos. Sin `UPDATE`, `DELETE` ni `TRUNCATE` sobre la tabla de eventos
- Un documento que nace publicado deja un evento `recepcion` o `subida_backoffice`. «Anterior a la versión» se decide por id de documento (`mdf_ca_eventos_desde_doc_id`). Guarda solo ids de usuario, fecha, acción, origen y lote; conservación propuesta de 6 años, a validar en la revisión RGPD
- Submenú Documentos publicados con buscador paginado en SQL (despublicar cualquier documento) e historial por documento
- Pendientes de publicar paginado por farmacias (20 por página, tope de 50 documentos por grupo); contadores siempre en SQL sobre todos los pendientes, nunca sumando lo pintado. Orden estable por pendiente más antiguo. `Filtro_Listado_Documentos` sanea farmacia, búsqueda y página (`(int)` con mínimos, no `absint()`). Sin índice nuevo (medido)

**Documentos organizados** (v0.16.0, esquema 1.9.0)
- Columna `documentos.fecha_documento DATE NULL` (fecha de la factura, no de subida), rellenada por la recepción con la fecha ya revalidada. Un único validador (`Documento_Service::fecha_documento_valida()`: `Y-m-d` estricto, del 1-1-2000 a hoy+31 días); la subida manual la exige si el tipo es factura
- Shortcode del listado: atributo `vista` (`agrupada` por defecto, `plana` idéntica al HTML anterior). Agrupado por tipo en orden fijo (Facturas, Modelo 347, Contratos, Presupuestos, Entregables, Otros); facturas por año y mes de la fecha de factura, con "Sin fecha" al final; la fecha de subida nunca sustituye a la de factura

**Modelo 347 en Documentación** (#307, parte del plugin; v0.23.0, esquema 1.13.0)
- Tipo `modelo_347` («Modelo 347») en `Documento_Tipos`, justo después de Facturas. En el listado agrupado: `__grupo--modelo_347` con una sección `__anio[data-anio]` por ejercicio (el más reciente primero y abierto, sin meses ni fecha visible, ordenados por nombre) y `__anio--sin-ejercicio` al final, con «Subido el …». La vista plana no cambia. Aviso por email: «Modelo 347» / «Modelos 347»
- El ejercicio va en una columna propia `ejercicio`, no en `fecha_documento = AAAA-12-31`: `fecha_documento_valida()` rechazaría el ejercicio en curso hasta diciembre, y no es la fecha del documento. Un único validador: `Documento_Service::ejercicio_valido()` (de 2000 al año en curso). Los importes del 347 nunca se guardan en la BD
- Unicidad: `UNIQUE modelo_347 (farmacia_id, emisor_cif, ejercicio, vigente)`, con `vigente` = 1 en el vigente y **NULL** (nunca 0) en todo lo demás y en los retirados. Así puede haber varios retirados y un único vigente por farmacia, sociedad y ejercicio (probado en MySQL 8.4; los NULL no chocan tampoco en MariaDB). La misma combinación con otro PDF se rechaza con un mensaje claro y nunca se sustituye; la carrera la pierde el INSERT, con el error de la BD silenciado
- Correcciones: **«Retirar para corrección»** en Documentos MDF (solo el administrador, con nonce por documento y casilla de confirmación). `Documento_Publicacion_Service::retirar_modelo_347()`, en una transacción: `FOR UPDATE`, despublica con `cambiar_estado()` si estaba publicado, `marcar_retirado()` (`vigente = NULL`, `retirado_en`) y evento `retirado`. Retirado es terminal: `cambiar_estado()` no lo publica y `Documento_Repository::PENDIENTE` (`publicado = 0 AND retirado_en IS NULL`) lo saca de todas las consultas de pendientes. Lo que la farmacia ya vio o descargó no se retira
- Subida manual: con el tipo Modelo 347 se piden ejercicio y sociedad emisora (`Factura_Emisores::get_opciones()`), los dos obligatorios. La fecha se ignora y el nombre lo fija el servidor: `Documento_Service::nombre_modelo_347()`, «Modelo 347 {sociedad} {ejercicio}». Sin hash: subir el mismo PDF otra vez se rechaza como combinación repetida
- No hay ruta REST: los 347 se generan en el servidor desde el Excel de SAGE subido en el backoffice (ver «Generador del Modelo 347»). `Permissions`, el endpoint, el visor y la recepción de facturas no cambian

**Generador del Modelo 347** (#307; v0.24.0, esquema 1.14.0). Solo clientes de Mediformplus (B82827635)
- Pantalla «Documentos MDF › Modelo 347» (`Admin_Modelo_347`) con tres pestañas: Generar cartas, Publicar y enviar, y Ajustes. Capacidad propia `mdf_ca_gestionar_347` (`Roles::conceder_gestion_347()`, solo el rol administrator, en `maybe_upgrade()` con la marca `mdf_ca_cap_347_v1`) en toda acción: pantalla, `admin-post` y AJAX, más un nonce propio por acción (por documento o por envío cuando aplica)
- Librerías vendorizadas en `lib/` (no en `vendor/`, que `.gitignore` ignora): dompdf 3.1.6 y OpenSpout 5.12.2, que **exige PHP ~8.4**. `lib/.htaccess` deniega todo y la carga es perezosa (`Modelo_347_Librerias`). La LGPL-3.0 de php-svg-lib obliga a distribuir el conjunto como GPLv3 (el plugin es GPL-2.0-or-later). Cómo se reconstruye, en `lib/LEEME.md`
- Fuentes: Liberation Serif y Sans (OFL, métricamente idénticas a Times New Roman), preinstaladas en `lib/fonts` con `installed-fonts.json` en rutas relativas y las cachés `.ufm.json` generadas
- Dónde escribe dompdf (revisado en su código):
  - `fontDir` y `fontCache` = `lib/fonts`, **solo lectura** en ejecución. `installed-fonts.json` solo se escribe al registrar fuentes, al vendorizar. Cpdf solo escribe una caché `.ufm.json` si falta, y `Modelo_347_Librerias::fuentes_completas()` impide renderizar si falta alguna. Así dompdf nunca escribe dentro del plugin y `deploy.sh --delete` no borra nada generado en el servidor;
  - `tempDir` **sí** se escribe en cada render (subconjuntos de fuente y los PNG del canal alfa del logo). Va a una subcarpeta aleatoria `r-*` (0700) por render, dentro de `Clientes/private-docs/.tmp-generador-347/`: fuera del plugin (`deploy.sh` no la toca), fuera de `wp-content` y con su propio `.htaccess` e `index.php` (`Carpeta_Privada_Proteccion::asegurar()`, sonda HTTP = 403). `Modelo_347_Carta` la borra entera al terminar; es una por render porque Cpdf solo borra sus PNG en el destructor, al final de la petición, y así dos renders simultáneos no se pisan;
  - las subcarpetas de más de una hora (renders interrumpidos) se borran en el siguiente render;
  - nunca `get_temp_dir()`, que puede caer en `wp-content`, que es pública. Si la carpeta privada no existe, no es escribible o su `.htaccess` no deniega, no se genera.
- Lectura del Excel (`Modelo_347_Excel_Lector`):
  - antes de abrir, `Xlsx_Validator` (el mismo que la subida manual, extraído de `Documento_Service::validar_xlsx()`): ZIP OOXML, sin VBA ni objetos incrustados, límites anti zip bomb y rechazo de cualquier XML con DOCTYPE o ENTITY (desde #307, también en la subida manual);
  - **tipo del libro según la regla OPC** (ECMA-376 parte 2): Override de `/xl/workbook.xml` y, si no hay, el Default de la extensión `xml`, sin distinguir mayúsculas. Tiene que ser exactamente `…sheet.main+xml`, así que un xlsm se rechaza venga de donde venga, y un Override `macroEnabled` gana a un Default `main`. Dos Override para la misma parte o dos Default para la misma extensión: rechazo (paquete inválido y ambiguo). Antes solo se miraba el Override y se rechazaba el Excel real de SAGE: su librería .NET (estilo OpenXML SDK) declara el tipo del libro con `<Default Extension="xml" …sheet.main+xml/>`, sin Override;
  - formato SAGE comprobado con un ficticio de estructura idéntica (`tests/generar-347-sage-net.py`): 6 entradas en su orden, `_rels/.rels` sin comprimir, BOM en los `.rels` y en `[Content_Types].xml`, prefijo `x:`, celdas `t="str"` y `t="n"` sin cadenas compartidas, y rutas absolutas en los `.rels`. OpenSpout y SheetJS (visor) lo leen bien y la simulación da lo mismo que con el ficticio de OpenSpout;
  - hoja «347» y cabeceras por nombre, sin mayúsculas, tildes ni espacios sobrantes. Si falta una columna o hay una duplicada, se rechaza el fichero entero;
  - límites: 5 MB y 2000 filas;
  - importes en céntimos. En texto solo valen formatos inequívocos («1.234» es ambiguo y se rechaza). Trimestre vacío = 0 con aviso; DEV vacío o ilegible bloquea la fila;
  - el temporal se borra antes de redirigir (`redirigir()` hace `exit`, que se salta los `finally`).
- Simulación (`Modelo_347_Simulacion_Service`). Categorías, por prioridad:
  - bloquean: `invalido`, `importe_invalido`, `no_cuadra` (±1 céntimo), `repetido` (**todas** las apariciones del identificador), `no_portal`, `ya_vigente` (se omite, nunca se sustituye);
  - se generan: `sin_cuenta` (cuenta no activa o sin email: carta guardada y publicada, sin correo, y también en el ZIP) y `lista`.

  Avisos que no bloquean: trimestre negativo, trimestre vacío y RECC distinto de 0. Totales DEV del fichero, de «lista» y de «sin cuenta». La huella cubre línea, categoría, farmacia, identificador, nombre, domicilio e importes.
- Revisar cartas (pantalla de simulación, bajo «Totales para cuadrar con SAGE»):
  - «Vista previa (hasta 3 cartas, PDF)» y un enlace «Ver carta» en cada fila;
  - sirven para **cualquier fila con identificador válido y suma correcta**, se genere o no: `Modelo_347_Simulacion_Service::PREVISUALIZABLES` = lista, sin cuenta, no está en el portal, ya vigente y repetido, por ese orden de prioridad en la vista previa global. Las inválidas, con importes ilegibles o descuadradas, no (404 si se fuerza la URL). Antes solo había vista previa si existía alguna fila «lista» o «sin cuenta», y con el Excel real (todo «no está en el portal») el botón no aparecía;
  - al lado, «Descargar ZIP de las que no se pueden enviar (N)», con las cartas de «sin cuenta» y «no está en el portal»;
  - todo exige el correo de Administración en Ajustes y se genera al vuelo, sin guardar nada.
- Qué se guarda (RGPD):
  - el Excel, nunca;
  - las filas saneadas, en un transient de simulación de 15 min, consumido al confirmar y borrado al cancelar o al empezar otra simulación;
  - las filas que se generan, en el transient del trabajo, durante 1 h y borrado al terminar;
  - la vista previa, el ZIP y la prueba, al vuelo y sin disco.
- Generación por tandas AJAX de 10 (`Modelo_347_Generacion_Service`) mediante `Documento_Service::guardar_modelo_347_generado()`:
  - es el mismo camino que la subida (extraído a `registrar()`) y nace **pendiente**, con `notificado_en` en NULL y sin hash;
  - unicidad con comprobación previa y UNIQUE. Dos tandas a la vez dejan una sola carta (probado);
  - reprocesar el mismo Excel solo genera lo que falta. Corregir = retirar y reprocesar.
- Carta (`Modelo_347_Carta` y `includes/plantillas-347/{CIF}.php`):
  - una sociedad está habilitada si existe su plantilla, así que Formación será añadir `B88471149.php`;
  - fiel al `.docx`: A4, márgenes de 2,5 y 3 cm, logo local de 4,5 × 3 cm, una página;
  - **dompdf multiplica `line-height` por la altura de la fuente (1,264 en Liberation Serif)**: se usa 0.91 para obtener los 13,8 pt de Word;
  - valores escapados con `esc_html` antes de la plantilla; `isRemoteEnabled`, `isPhpEnabled` e `isJavascriptEnabled` desactivados; `chroot` en fuentes e imagen. Probado: ninguna petición remota (CSS, `@import`, fuente, fondo, imagen) y ningún fichero local fuera del `chroot`;
  - el correo de Administración de la carta es un ajuste obligatorio, sin dirección en el código.
- Publicar y enviar (`Modelo_347_Publicacion_Service`), en dos pasos con huella:
  - en una transacción: lote (`filtros` = `{"tipo":"modelo_347","emisor","ejercicio"}`), `cambiar_estado()` con el origen de evento nuevo `modelo_347` y la cola de envíos de las farmacias con cuenta activa y email;
  - al deshacer el lote se cancelan los envíos aún en cola (`Despublicacion_Lote_Service`). Si después se vuelve a publicar, el cancelado vuelve a la cola y lo ya enviado no se toca.
- Envíos (`wp_mdf_ca_347_envios`, UNIQUE `documento_id`, sin importes), `Modelo_347_Envio_Service`:
  - tandas AJAX al ritmo del ajuste (5 correos cada 30 s por defecto). El servidor también exige el intervalo, pero esa comprobación no es atómica y solo es prudencia;
  - **cada carta se reclama** con un `UPDATE` condicionado `pendiente → enviando`, y eso es lo que impide el doble envío (probado con 4 peticiones simultáneas);
  - antes de enviar se comprueba de nuevo que sigue publicada y vigente y que la cuenta sigue activa con email; el destinatario es el email actual de la cuenta;
  - PDF adjunto desde memoria con `phpmailer_init`. Reply-To del ajuste, obligatorio para enviar; el From es el del SMTP.
- Estados del envío:
  - `wp_mail()` true → `enviado` y se marca `notificado_en`. False → `fallido`, que solo vuelve a la cola con «Reintentar los fallidos»;
  - un `enviando` de más de 10 minutos es «interrumpido» y no se reintenta solo;
  - «Reenviar» (casilla de confirmación) es la única forma de mandar dos veces.
- El aviso genérico excluye `modelo_347` en `find_pendientes_aviso()`; tampoco avisa de los 347 subidos a mano
- «Enviarme una prueba»: a la dirección del ajuste, con «[PRUEBA]», sin publicar ni registrar
- ZIP de «sin cuenta» y «no está en el portal»: `Zip_Stream` (escritor STORE propio, directo a `php://output`, sin ningún temporal)
- **Contraseña en el PDF: descartada** (se implementó y se quitó antes del commit). dompdf solo cifra con **RC4 de 40 bits** (`/V 1 /R 2` en Cpdf), que se rompe en segundos, y la contraseña prevista era el CIF/NIF del destinatario, un dato que conoce cualquiera que tenga una factura suya. Además solo podía aplicarse al ZIP: las cartas del portal se abren en el visor y se adjuntan tal como se guardaron, y no se pueden volver a cifrar al enviar porque los importes no están en la BD. No aportaba protección y podía dar una falsa sensación de seguridad. Si algún día hiciera falta, tendría que ser AES con otra librería y una contraseña que no sea un dato público
- `maybe_upgrade()` con cerrojo: comprobación barata y, si hay trabajo pendiente, `GET_LOCK` sin espera (si otra petición lo tiene, esta no migra). Con el cerrojo se vacía la caché de options y se vuelve a comprobar contra la BD; `RELEASE_LOCK` en un `finally`. Toda migración nueva debe añadir su marca a `Activator::MARCAS_MIGRACION`
- Modo proveedores (sin cruce con el portal, email de la columna «Email», solo correo y sin guardar) y Formación: no implementados; la simulación, la carta y el envío están separados para enchufarlos
- Pruebas: `tests/generar-347-ficticio.php` (OpenSpout, CIF por argumento), `tests/generar-347-sage-net.py` (estructura exacta de SAGE, mismas filas) y `tests/generar-347-maliciosos.py <origen> <carpeta> [<prefijo>]`. Este último genera 8 variantes malas por formato (DOCTYPE, xlsm renombrado, VBA, zip bomb, sin columna, sin hoja, Override `macroEnabled` y PDF renombrado), con sus ficheros en `tests/fixtures/`. `tests/` no se despliega
- Plantilla original: `docs/MODELO CARTA 347.docx` (no se despliega: `deploy.sh` excluye `docs/` y `*.docx`, y el `.htaccess` de la raíz deniega `docx` además de `md`, `lock`, `sh`, `yml`, `yaml` y `log`)

**Protección de la carpeta privada** (v0.22.0)
- `Carpeta_Privada_Proteccion::asegurar()` crea solo lo que falta (carpeta, `.htaccess` de denegación para Apache 2.4 y 2.2, `index.php`, con 0644) abriendo con `fopen('x')`, de modo que nunca sobrescribe. `sondear()` pide por HTTP (`site_url()`, timeout 5 s, sin redirecciones, `sslverify`) una sonda `.txt` temporal que borra siempre, y limpia las huérfanas de más de 10 minutos
- Solo se da por protegida si el `.htaccess` deniega y la sonda da 403 o 404. Alarma roja solo con prueba (200 con el contenido exacto de la sonda); cualquier otro resultado es aviso suave. Un `.htaccess` existente que no deniega no se toca y se avisa. Caché de 12 h si está bien y 1 h si no. `maybe_upgrade` solo crea lo que falta; la sonda corre en la primera pantalla de administración del plugin. Decisión: solo avisar, sin bloquear la subida
- `.htaccess` en la raíz del plugin que deniega `md`, `lock`, `sh`, `yml`, `yaml`, `log` y (desde #307) `docx`, como segunda capa frente a ficheros de trabajo subidos por error

## Estado

- Fases 0 a 3 cerradas con batería de seguridad pasada
- **Fase 4 desplegada en producción el 08/10/2026** (plugin 0.22.0, esquema 1.12.0) y batería de cierre superada: acceso cruzado, sin sesión, enlace directo, cliente fuera del backoffice, API de páginas, ficheros de trabajo no públicos, robot limitado a su ruta y revisión de código sin hallazgos de gravedad alta
- Etiquetas de Git: `fase3-prod` (último estado de la Fase 3) y `fase4-prod` / `fase4-327`
- Copia de archivos (`backup.sh`, repo `deploy-script`): `wp-content` y `Clientes/private-docs`, en `~/Backups-MDF/archivos` con permisos 700. La base de datos la copia IONOS a diario

Pendientes:
- **#307 Generador de Modelo 347**: v0.23.0 (soporte en Documentación) y v0.24.0 (generador desde el Excel de SAGE, solo Mediformplus) hechas y verificadas en local, sin desplegar. Antes de usarlo en producción:
  - configurar en Ajustes el correo de Administración, el Reply-To y la dirección de prueba;
  - confirmar el límite del SMTP de IONOS;
  - probarlo con el Excel real (lo valida la persona de MDF);

  Pendientes: Formación y el modo proveedores. Avisar a MKT de las clases del grupo Modelo 347
- **#329 Endurecimiento menor:** mensaje de login del robot que delata la cuenta, índice REST de `mdf-ca/v1`, página de ejemplo y temas inactivos, actualizar WP Mail SMTP, retoques de `deploy.sh`, y **borrar los documentos de prueba** de las farmacias piloto (incluido `sol.pdf`, de la Fase 1) antes de que MDF use el portal
- Sección de Cuota (tarea #254): a la espera del contenido de MKT
- Avisar a MKT de las clases CSS del listado agrupado

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores. Debe fallar siempre, con respuesta idéntica a la de un id inexistente.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo (carpeta y fichero de `Clientes/private-docs` dan 403 o 404).
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- El usuario robot no puede usar rutas REST fuera de `mdf-ca/v1` (con control positivo: la misma contraseña debe autenticar en su ruta).
- Ningún fichero de trabajo del plugin (`.md`, `docs/`, `.htaccess`) se sirve públicamente.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa (revisión de código de solo lectura).
- Decisiones de arquitectura de la fase documentadas.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico (aplazado, fuera del MVP)
- Pasarela de pago online y herramientas de pago
- Integración con CRM (MDF aún no lo ha seleccionado)
- SSO con "la Academia" (viabilidad pendiente de datos de MDF; alternativa si no es viable: credenciales sincronizadas en el alta, no sesión compartida)
- Ejecución programada o carpeta vigilada del envío de facturas (se decide tras el primer mes de operación manual)

## Fase 5 (siguiente)

Revisión RGPD (tabla de eventos, copias de excepciones y de backup, plazos de conservación, encargado de tratamiento, política de privacidad, licencia AGPL de PyMuPDF), documentación técnica de traspaso, plan de mantenimiento (Luis y Víctor; copia de archivos manual: quién, cuándo y cuánto se conserva; WP-Cron frente a cron de IONOS; SPF y DKIM), formación a MDF y cuentas individuales de backoffice para cada persona de MDF.