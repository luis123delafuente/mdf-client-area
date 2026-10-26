# mdf-client-area — Contexto para Claude Code

Plugin de WordPress que concentra toda la lógica de negocio del Área de Clientes de Mediformplus SL (MDF): permisos, documentos, catálogo y (más adelante) tokens. Elementor solo maqueta; este plugin decide qué se ve y qué se entrega.

- Cliente: Mediformplus SL (MDF), grupo de farmacias (unas 200 farmacias previstas)
- Stack: WordPress + Elementor + este plugin propio
- Entorno local: WordPress con Local (by WP Engine), PHP 8.4.18, Apache, MySQL 8.4.0, site `mdf-client-area.local`
- Producción: `clientes.mediformplus.com`, hosting IONOS (servidor dedicado gestionado), PHP 8.4.26 (el plugin exige **PHP 8.4**: `Requires PHP: 8.4`, por OpenSpout), WordPress 7.1, base de datos **MariaDB 11.8** (distinta del MySQL local: cualquier SQL nuevo debe funcionar en los dos)
- Límites de PHP en producción (Salud del sitio, comprobados el 19/10/2026): `memory_limit` -1 (sin límite), `max_execution_time` 50000, `upload_max_filesize` y `post_max_size` 64M. Extensiones: solo falta imagick (no se usa; mbstring, zlib e iconv presentes). `max_file_uploads` no aparece, pero la #334 sube las facturas de una en una. Con `memory_limit` -1, la parada por memoria de `Pdf_Limites` no actúa: la memoria de una lectura la acotan los demás topes (flujos, objetos, valores, códigos, palabras), y el tiempo, sus 10 s (no el `max_execution_time`)
- Ruta del plugin en ambos entornos: `wp-content/plugins/mdf-client-area/`
- Despliegue: SFTP con `lftp mirror -R --delete` vía `deploy.sh` (repo `deploy-script`; rsync no funciona en este hosting). Siempre ensayar antes con `./deploy.sh --dry-run`. Nunca desplegar el WordPress completo ni `wp-config.php`, solo esta carpeta. El script (`wp-content/plugins/deploy-script/deploy.sh`, carpeta hermana del plugin, **sin control de versiones**) excluye `.git`, `.gitignore`, `.DS_Store`, `node_modules`, `tests`, `*.log`, `CLAUDE.md`, `*.md`, `*.docx` y `docs/`; los ficheros que empiezan por punto (`assets/.htaccess`, `.htaccess` de la raíz) sí se suben. La cuenta SFTP de despliegue tiene contraseña propia, distinta de la de wp-admin
- Fechas: objetivo de entrega el 12 de octubre de 2026; fecha límite el 8 de noviembre de 2026
- Recursos (JS, CSS y módulos): **todos** llevan `?ver=` = `MDF_CA_VERSION` + fecha de modificación del fichero, calculado en un único sitio, `Recursos::version()`, `Recursos::url()` y `Recursos::url_versionada()`. Si un recurso cambia en un despliegue sin subir `MDF_CA_VERSION`, su URL cambia y ningún navegador sigue usando la copia en caché; pasó en las pruebas del 347. Cubre lo encolado con `wp_enqueue_*` (selector de farmacia, Documentos MDF, Modelo 347, zona de arrastrar archivos, Subir facturas, Inicio, menú de perfil y el formulario de herramientas del Herramientas MDF) y lo que el visor enlaza a mano (su CSS y JS, PDF.js y su worker como URL completas `pdfjsUrl`/`pdfjsWorkerUrl`, y SheetJS). Cualquier recurso nuevo debe pasar por `Recursos`; nunca `plugins_url()` con `MDF_CA_VERSION` a mano
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
- Rol **«Gestión MDF» (`mdf_gestor`, #337)** para el personal de MDF: no es administrador de WordPress. Solo `read` y las capacidades propias del plugin (`Roles::CAP_*`); el administrador tiene además todas. Ver «Rol Gestión MDF y backoffice simplificado»
- **Planes** como entidad de datos (`wp_mdf_ca_planes`), no hardcodeados; cada farmacia tiene `plan_id`
- Toda comprobación de permiso pasa por una única función centralizada (`Permissions::puede_ver_documento()`, `puede_descargar_documento()`, `puede_previsualizar_documento()`, `puede_ver_item_catalogo()`, `filtrar_items_catalogo_visibles()`, `puede_visualizar_item_catalogo()`, `puede_descargar_item_catalogo()`, `puede_ver_bloque_por_plan()`), para poder añadir excepciones en el futuro sin refactorizar
- Dos módulos de contenido separados: catálogo de **Herramientas** (por plan y por farmacias concretas; desde la v0.35.0 Formación ya no es parte del catálogo, sino una página de Elementor con un enlace a la Academia) vs documentos privados por farmacia, vinculados por CIF/NIF, visibles solo por su titular (no dependen del plan)
- Documentos: carpeta `Clientes/private-docs/` **en la raíz de WordPress** (`ABSPATH`, no en `wp-content`; ruta relativa en `Documento_Endpoint::CARPETA_RELATIVA`), protegida con `.htaccess` de denegación total e `index.php`, que el propio plugin crea si faltan y vigila (`Carpeta_Privada_Proteccion`). Solo se sirven por el script PHP del plugin, que valida permiso antes de leer el fichero
- Alta de farmacias: un único camino lógico. El importador CSV y el formulario de alta manual comparten el mismo servicio; CIF/NIF es la clave normalizada y validada; importación idempotente (upsert por CIF/NIF, simulación previa antes de escribir)
- Facturas: MDF las sube desde el backoffice («Subir facturas», #334): el plugin las lee en el servidor (`Factura_Lectura_Service`, #333, mismas reglas y mismo resultado que el parser de Python del repo hermano `Parser_facturas_SAGE`), las cruza y las guarda con `Factura_Recepcion_Service`; las que no puede asignar van a la Bandeja de facturas (#335). Desde la #336 no hay otra entrada: el robot del Mac, su usuario y la ruta REST se han retirado, y el plugin no tiene ninguna ruta REST propia
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
- Catálogo: tablas `wp_mdf_ca_catalogo` + `wp_mdf_ca_catalogo_planes` (N:M real). Item sin ningún plan asignado: nadie lo ve. `Catalogo_Repository` es el único acceso a ambas tablas; `set_planes()` sustituye el conjunto completo. **Rehecho en la v0.35.0** (ver «Herramientas del catálogo»): secciones y tipos fijos sustituidos por categorías, etiquetas, archivo o enlace y farmacias concretas
- `enlace_url` del catálogo se valida solo por formato (`filter_var` + esquema http/https). `wp_http_validate_url()` descartado: hace resolución DNS real y rechazaba URLs válidas

**Documentos**
- Nombre en disco generado (`{farmacia_id}-{16 bytes hex}.{ext}`), nunca el original; el nombre visible vive solo en `wp_mdf_ca_documentos.nombre`
- Tipos permitidos: PDF, JPG/JPEG, PNG, WEBP y XLSX, validados con `wp_check_filetype_and_ext()` (contenido real, no extensión). Tamaño máximo 20 MB. Se excluyen SVG y los formatos Office con macros
- Tipo/etiqueta de documento (`Documento_Tipos`: contrato, factura, presupuesto, entregable, otro) es una lista fija en código, no una entidad de datos
- Admin de Documentos y de Planes: menú propio, capacidad + `admin-post.php` con nonce (aquí sí interesa el gate de capacidad de `admin-post.php`). Desde la #337, Documentos usa `Admin_Documentos::CAPACIDAD` (`mdf_ca_gestionar_documentos`) y Planes y Catálogo, desde la v0.32.0, `Roles::CAP_GESTIONAR_CATALOGO` (`mdf_ca_gestionar_catalogo`)
- Caso límite documentado: no existe borrado de farmacias; si se añade, la pantalla de documentos ya muestra "Farmacia ya no existe (ID N)"

**Excel (.xlsx)**
- Se admite `.xlsx` a petición de MDF. Se siguen rechazando `.xls`, `.xlsm` y `.xlsb` (no se puede garantizar la ausencia de VBA)
- Un `.xlsx` se valida por contenido (`Documento_Service::validar_xlsx()`): ZIP con `[Content_Types].xml` y `xl/workbook.xml`, libro principal de tipo xlsx (no xlsm), sin `vbaProject.bin` ni `xl/embeddings/`, con límites anti zip-bomb (2000 entradas, 200 MB descomprimido, sin extraer nada a disco). **No se busca "macroEnabled" en todo el `[Content_Types].xml`**: generadores legítimos (SheetJS) lo declaran en un `Default` genérico y daba falsos positivos. Requiere la extensión `zip` de PHP (confirmada en producción)
- ~~Un Excel no es descargable por defecto~~ (regla de la Fase 3, **sustituida en la v0.36.0**: cualquier documento puede ser descargable y es lo predeterminado; ver «Descarga de documentos y una vía por tipo»). Columna `documentos.descargable`; la decisión vive en `Permissions::puede_descargar_documento()`
- En el visor, el Excel se ve con SheetJS 0.20.3 (`assets/vendor/sheetjs/`, vendorizado), dibujado en canvas con marca de agua, con selector de hojas y paginación por filas (2000 filas x 60 columnas por hoja). Se dibujan fondos, color de texto, negrita, cursiva, alineación y celdas combinadas; **no** bordes, formato condicional, degradados, imágenes ni gráficos (los documentos con gráficos o diseño deben subirse como PDF). SheetJS Pro no se usa

**Visor**
- PDF.js vendorizado (solo `pdf.min.mjs` + `pdf.worker.min.mjs`, sin su viewer), nunca desde CDN. `assets/.htaccess` fuerza `AddType application/javascript .mjs`
- Dos capas: `Documento_Visor` (ruta `/mdf-ca-visor/{id}/`) solo exige sesión; la validación real pasa por `Documento_Endpoint` cuando el JS hace `fetch()`. El listado de documentos enlaza siempre al visor, no al endpoint
- PDF e imágenes se pintan en `<canvas>`; la marca de agua (CIF + nombre de farmacia) va horneada en los mismos píxeles, no como capa CSS. Espaciado calculado con `ctx.measureText()`
- Desde la #321 (v0.34.0) un documento puede ir **sin marca de agua** (`documentos.sin_marca`): el visor la deja vacía solo si el documento lo tiene guardado así; ver «Marca de agua por documento»
- `Ctrl/Cmd+P` y `Ctrl/Cmd+S` bloqueados por `keydown`; `beforeprint` vacía el canvas y `afterprint` lo restaura. PrintScreen del sistema no se puede bloquear
- **Un documento no descargable solo se entrega al visor**: `Documento_Token` emite un token de un solo uso (transient con hash, ligado a usuario + documento, TTL 120 s, consumo atómico) que el `fetch()` manda en `?mdf_t=`. Sin token válido, o con `Sec-Fetch-Dest: document`, el endpoint da el mismo 404 uniforme. Desde la v0.36.0 lo exige a **todos** (antes un descargable no lo necesitaba): la descarga va por su propia ruta, `/mdf-ca-documento/{id}/descargar/`
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
- Se marca como notificado solo si el envío sale bien; si falla, **se reintenta cada día a las 09:00 mientras siga la causa** (sin reintentos inmediatos ni bucle). Riesgo asumido: aviso duplicado antes que perdido. Desde la v0.38.0 el motivo se ve (ver «Motivo de los correos fallidos»)
- Farmacias sin cuenta activa: sus documentos quedan pendientes y entran en el primer aviso tras activar. El email solo lleva recuentos por tipo y un enlace a Documentación; nunca nombres, CIF ni enlaces al fichero
- Desglose por tipo (`Documento_Tipos::texto_recuento()`), en singular y plural: «1 factura» / «2 facturas», igual con contrato, presupuesto y entregable, «Modelo 347» / «Modelos 347» y, desde la v0.38.1, «1 documento de otro tipo» / «2 documentos de otro tipo» (antes «2 otros»; los documentos sin tipo cuentan aquí). Comprobado en Mailpit con 1 y 2 de tipo «Otro» y con varios tipos mezclados
- Interruptor `mdf_ca_avisos_documentos_activos` apagado por defecto (MDF prefiere no avisar de momento); `MDF_CA_AVISOS_DESACTIVADOS` en wp-config.php lo anula
- Pendiente: opción de baja; los documentos silenciados son indistinguibles de los notificados

**Parser de facturas SAGE** (repo `Parser_facturas_SAGE`; cruce en el plugin desde v0.13.0)
- El parser (Python, PyMuPDF, licencia AGPL a revisar antes de la entrega) devuelve datos y un estado; el cruce lo hace `Factura_Cruce_Service::cruzar()` con `find_by_cif()`, que revalida el CIF y exige emisor, serie, número, fecha e importe válidos, sin fiarse del JSON recibido. El parser nunca devuelve "asignada"
- Emisores: Mediformplus (B82827635, serie `A`) y Mediformplus Formación (B88471149, serie `SF`). SAGE exporta por sociedad. La serie identifica al emisor y ninguna se comparte
- Se lee por coordenadas. Copias delimitadas por PÁG. = 1; todas las copias con el mismo número de páginas, la misma cabecera y el mismo total. El total solo en la última página de cada copia; las intermedias llevan "SUMA Y SIGUE". La moneda aparece exactamente una vez ("(EUR)" bajo el total en Mediformplus, "€" en el importe en Formación). Cualquier cosa que no encaje da `formato_inesperado`. Sin OCR
- Estados: sin texto > formato inesperado > número discrepante > sin CIF > CIF inválido > ok. Abonos con importe negativo
- Pendiente de verificar con cientos de facturas reales de 2026 (solo se han probado unas pocas; el rótulo de las páginas intermedias de Formación no está confirmado)
- Los PDF reales nunca van al repo (`*.pdf` en .gitignore); los tests usan fixtures sintéticas

**Recepción de documentos** (v0.14.0, esquema 1.7.0; emisor en v0.17.0, esquema 1.10.0). **La ruta REST y el robot se retiraron en la #336** (ver «Retirada del robot»); sigue vigente lo que comparte «Subir facturas»:
- `Factura_Emisores` es la lista de emisores y series en código, único sitio que lo decide; nunca se deduce el emisor de la serie. Serie `^[A-Z]{1,3}$/D`, número `^\d{1,20}$/D`
- Columnas nullable `documentos.hash_sha256` (UNIQUE), `emisor_cif`, `factura_serie` y `factura_numero` (UNIQUE conjunto); los NULL no chocan. Misma factura para la misma farmacia: 200 duplicado idempotente; para otra: 422 `documento_en_otra_farmacia` uniforme. La carrera la pierde el INSERT del segundo, cuyo error de base de datos se silencia para no llevar serie y número al log
- Nombre visible `{Factura|Abono} {sociedad} {serie} {número}`, abono por el signo del importe revalidado
- Registro de auditoría solo con recuentos (`wp_mdf_ca_recepcion_registro`, `Recepcion_Registro`), con columna `origen` dentro de la clave primaria (esquema 1.15.0): hoy solo se escribe `backoffice`; `rest` queda en las filas antiguas del robot. Se ve en «Subir facturas», en «Actividad de los últimos 14 días»
- La subida de PDF (también la del backoffice) exige el tipo real `application/pdf` con `fileinfo` (confirmada en producción)

**Envío por lotes** (repo `Parser_facturas_SAGE`, paquete `envio_facturas/`; diseño en `docs/robotito.md`). **Obsoleto desde la #336**: la ruta a la que enviaba ya no existe y `docs/robotito.md` está marcado como obsoleto. Se conserva como historia:
- Recorre una carpeta de PDF, los parsea y los manda al endpoint. Sin `--enviar` es dry-run; `notificar=0` por defecto. Credenciales solo por variables de entorno (`MDF_ENVIO_*`) o fichero `--config` fuera del repo; https obligatorio y TLS nunca desactivado
- Lo que el parser no resuelve nunca se envía: se copia a `excepciones/<estado>/` con un informe CSV sin CIF, nombres ni importes, y se resuelve subiéndolo a mano desde Documentos MDF (no hay bandeja de excepciones en el servidor). Una factura resuelta a mano no se reenvía (queda sin emisor, serie ni número y el servidor no la detectaría como duplicada)
- 401, 429 y 503 detienen el lote; 400 y 422 se copian a excepciones; 500 o red se reintentan 3 veces. Registro local `registro.jsonl` de solo añadir, con bloqueo contra dos lotes simultáneos
- `cif_sin_farmacia` es permanente (hay facturas de clientes que no son farmacias del portal). Antes del histórico de 2026: dar de alta las farmacias con el importador y subir el tope diario
- Operación recomendada: manual tras cada exportación de SAGE (simulación y luego envío), al menos el primer mes. Lo opera la persona de MDF que exporta de SAGE, en un Mac

**Pendiente de publicar, publicación en bloque y deshacer** (v0.15.0 a v0.19.0, esquemas 1.8.0 y 1.11.0)
- Columna `documentos.publicado` (DEFAULT 1). Las facturas de «Subir facturas» y de la bandeja nacen siempre con publicado = 0 (la aprobación previa configurable era solo de la ruta REST, retirada en #336); la subida de backoffice siempre publica
- `Permissions::puede_ver_documento()` exige misma farmacia y publicado (un único predicado privado, compartido con `filtrar_documentos_visibles()`); un documento no publicado da el mismo 404 uniforme que uno inexistente o ajeno. Excepción deliberada: `find_pendientes_aviso()` filtra publicado = 1 en SQL
- Vista previa de admin por `admin-post.php`, sin URL estable al fichero: nonce por documento, token de un solo uso y marca de agua con el login del admin; solo `Permissions::puede_previsualizar_documento()`
- «Publicar todos los pendientes»: dos pasos; al preparar se guarda en un transient del usuario la huella de los ids publicables; al aplicar se consume, se recalcula y solo se escribe si coincide. Se publica por lista explícita de ids, nunca por filtro, así que lo que llegue después no se publica sin haber sido contado. Tandas de 500 dentro de una transacción
- Lotes: tabla `wp_mdf_ca_publicacion_lotes` y columna `documentos.publicacion_lote_id`, que solo vale L mientras el documento siga publicado desde el lote L; cualquier otro cambio de estado la pone a NULL. Deshacer un lote usa el mismo patrón de dos pasos y solo toca lo que siga con ese lote y publicado. Deshacer no retira lo que la farmacia ya haya visto o descargado

**Trazabilidad y pantallas** (v0.20.0 y v0.21.0, esquema 1.12.0)
- Tabla de solo añadir `wp_mdf_ca_publicacion_eventos` (documento, acción, origen, usuario, fecha UTC, lote). Un único punto cambia `publicado`: `Documento_Repository::cambiar_estado()`, por tandas con `SELECT … FOR UPDATE`, `UPDATE` e `INSERT` multifila de eventos dentro de la transacción de quien llama; un fallo revierte estado y eventos. Sin `UPDATE`, `DELETE` ni `TRUNCATE` sobre la tabla de eventos
- Un documento que nace publicado deja un evento `subida_backoffice` (o `recepcion`, solo en los antiguos del robot; el historial lo muestra como «Recibido por el robot (retirado), nació publicado» y ya no se puede escribir). Desde la #334, una factura de «Subir facturas» deja siempre, en la misma transacción que el alta, un evento `recibido` (no cambia `publicado`) con origen `subida_facturas` y el usuario. Lo que el robot recibía pendiente no dejaba evento. Una factura asignada a mano desde la Bandeja de facturas (#335) deja `asignado_manual` con origen `bandeja` y el usuario; la que «Volver a comprobar todas» asigna sola deja `recibido` con origen `bandeja` (desde la 1.16.0 `accion` es VARCHAR(20)). Eliminar un documento (v0.28.0) deja un evento `eliminado`; sus eventos siguen después de borrar la fila y el historial los muestra. «Anterior a la versión» se decide por id de documento (`mdf_ca_eventos_desde_doc_id`). Guarda solo ids de usuario, fecha, acción, origen y lote; conservación propuesta de 6 años, a validar en la revisión RGPD
- Submenú Documentos publicados con buscador paginado en SQL (despublicar cualquier documento) e historial por documento
- **Documento despublicado e historial** (#322; v0.34.1, sin cambio de esquema). Comprobado en local con una factura de prueba: al despublicarlo sale de «Documentos publicados», la farmacia deja de verlo en Documentación (el visor da el mismo 404 que un id inexistente) y vuelve a «Pendientes de publicar», porque pendiente es `publicado = 0` sin retirar. El historial («Eventos del documento», antes «Publicaciones y despublicaciones») lista en orden, con fecha y usuario: recibido, publicado, despublicado, publicado y despublicado en bloque (origen «En bloque…» / «Al deshacer un lote» y el número de lote), marca quitada y puesta, y por último «Eliminado», con el aviso de que el documento ya no existe; sin ninguna clave interna a la vista. El origen `modelo_347` se lee «Generador del Modelo 347» (sirve para la publicación desde «Publicar y enviar» y para el alta de una carta sin marca). El historial se abre desde Pendientes, Documentos publicados y, desde la v0.34.1, cada fila de la tabla de Documentos MDF
- **Decisión: el historial de un documento eliminado solo se abre con su número** (`admin.php?page=mdf-ca-documentos-publicados&historial=ID`). No sale en ningún listado y el evento `eliminado` no guarda el nombre a propósito: así no quedan datos de una factura borrada, y lo que se elimina son documentos subidos por error (solo no publicados y nunca un 347)
- Pendientes de publicar paginado por farmacias (20 por página, tope de 50 documentos por grupo); contadores siempre en SQL sobre todos los pendientes, nunca sumando lo pintado. Orden estable por pendiente más antiguo. `Filtro_Listado_Documentos` sanea farmacia, búsqueda y página (`(int)` con mínimos, no `absint()`). Sin índice nuevo (medido)

**Documentos organizados** (v0.16.0, esquema 1.9.0)
- Columna `documentos.fecha_documento DATE NULL` (fecha de la factura, no de subida), rellenada por la recepción con la fecha ya revalidada. Un único validador (`Documento_Service::fecha_documento_valida()`: `Y-m-d` estricto, del 1-1-2000 a hoy+31 días); desde la v0.36.0 las facturas solo entran por «Subir facturas» y la Bandeja, que ponen siempre la fecha leída o la escrita al asignar (en la subida manual la fecha es opcional)
- Shortcode del listado: atributo `vista` (`agrupada` por defecto, `plana` idéntica al HTML anterior). Agrupado por tipo en orden fijo (Facturas, Modelo 347, Contratos, Presupuestos, Entregables, Otros); facturas por año y mes de la fecha de factura, con "Sin fecha" al final; la fecha de subida nunca sustituye a la de factura

**Modelo 347 en Documentación** (#307, parte del plugin; v0.23.0, esquema 1.13.0)
- Tipo `modelo_347` («Modelo 347») en `Documento_Tipos`, justo después de Facturas. En el listado agrupado: `__grupo--modelo_347` con una sección `__anio[data-anio]` por ejercicio (el más reciente primero y abierto, sin meses ni fecha visible, ordenados por nombre) y `__anio--sin-ejercicio` al final, con «Subido el …». La vista plana no cambia. Aviso por email: «Modelo 347» / «Modelos 347»
- El ejercicio va en una columna propia `ejercicio`, no en `fecha_documento = AAAA-12-31`: `fecha_documento_valida()` rechazaría el ejercicio en curso hasta diciembre, y no es la fecha del documento. Un único validador: `Documento_Service::ejercicio_valido()` (de 2000 al año en curso). Los importes del 347 nunca se guardan en la BD
- Unicidad: `UNIQUE modelo_347 (farmacia_id, emisor_cif, ejercicio, vigente)`, con `vigente` = 1 en el vigente y **NULL** (nunca 0) en todo lo demás y en los retirados. Así puede haber varios retirados y un único vigente por farmacia, sociedad y ejercicio (probado en MySQL 8.4; los NULL no chocan tampoco en MariaDB). La misma combinación con otro PDF se rechaza con un mensaje claro y nunca se sustituye; la carrera la pierde el INSERT, con el error de la BD silenciado
- Correcciones: **«Retirar para corrección»** en Documentos MDF (solo el administrador, con nonce por documento y casilla de confirmación). `Documento_Publicacion_Service::retirar_modelo_347()`, en una transacción: `FOR UPDATE`, despublica con `cambiar_estado()` si estaba publicado, `marcar_retirado()` (`vigente = NULL`, `retirado_en`) y evento `retirado`. Retirado es terminal: `cambiar_estado()` no lo publica y `Documento_Repository::PENDIENTE` (`publicado = 0 AND retirado_en IS NULL`) lo saca de todas las consultas de pendientes. Lo que la farmacia ya vio o descargó no se retira
- ~~Subida manual de un 347~~ (retirada en la v0.36.0, decisión de MDF: el 347 solo entra por su generador). **Corregir un 347** = «Retirar para corrección» en Documentos MDF y volver a generarlo desde el Excel en «Modelo 347»: el retirado deja de ser vigente, la simulación lo da como «lista» (o «sin cuenta») y se genera una carta nueva, pendiente de publicar. Probado con un Excel de una sola fila. El nombre visible sigue siendo `Documento_Service::nombre_modelo_347()`
- No hay ruta REST: los 347 se generan en el servidor desde el Excel de SAGE subido en el backoffice (ver «Generador del Modelo 347»). `Permissions`, el endpoint, el visor y la recepción de facturas no cambian

**Generador del Modelo 347** (#307; v0.24.0, esquema 1.14.0). Solo clientes, de Mediformplus (B82827635) y, desde la v0.37.0, de Formación (B88471149; ver «Modelo 347 de Formación»)
- Pantalla «Documentos MDF › Modelo 347» (`Admin_Modelo_347`) con tres pestañas: Generar cartas, Publicar y enviar, y Ajustes. Capacidad propia `mdf_ca_gestionar_347` (desde la #337 la tienen el rol Gestión MDF y el administrador; `Roles::conceder_gestion_347()`, en `maybe_upgrade()` con la marca `mdf_ca_cap_347_v1`) en toda acción: pantalla, `admin-post` y AJAX, más un nonce propio por acción (por documento o por envío cuando aplica)
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
- Buscador de filas en la simulación, encima de las tablas, con el mismo marcado y textos que el de «Documentos publicados»:
  - etiqueta «CIF/NIF o nombre», `input type="search" class="regular-text"`, botón primario «Buscar», «Quitar filtros» y un `h2` con «Filas que coinciden: N de M» o «Ninguna fila con estos filtros»;
  - **filtra en el navegador** (`admin-modelo-347.js`), sin ninguna petición: el término no llega al servidor, a la URL ni al historial. Busca por fragmento en el nombre del Excel y en el de la farmacia del portal, sin mayúsculas ni tildes (NFD), y en el CIF/NIF ignorando además espacios, guiones y puntos;
  - filtra todas las tablas a la vez. Cada una muestra «N de M» y, si se queda en 0, se colapsa con «Ninguna fila con estos filtros.»;
  - los recuentos, los totales, la vista previa global y el ZIP siempre son del fichero entero (lo dice la propia pantalla);
  - nace oculto y lo muestra el JS: sin JS, la pantalla queda como siempre;
  - el `h2` lleva además `display:none`, porque el CSS de wp-admin (`h1…h6 { display: block }`) anula el atributo `hidden`;
  - diferencias con «Documentos publicados»: no recarga la página, filtra mientras se escribe, no hay paginación y hay varias tablas en vez de una.
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
  - una sociedad está habilitada si existe su plantilla (`B82827635.php` y, desde la v0.37.0, `B88471149.php`). La plantilla solo pone maqueta, texto y logo; los datos de la sociedad salen de Ajustes;
  - fiel al `.docx`: A4, márgenes de 2,5 y 3 cm, logo local de 4,5 × 3 cm, una página;
  - **dompdf multiplica `line-height` por la altura de la fuente (1,264 en Liberation Serif)**: se usa 0.91 para obtener los 13,8 pt de Word;
  - valores escapados con `esc_html` antes de la plantilla; `isRemoteEnabled`, `isPhpEnabled` e `isJavascriptEnabled` desactivados; `chroot` en fuentes e imagen. Probado: ninguna petición remota (CSS, `@import`, fuente, fondo, imagen) y ningún fichero local fuera del `chroot`;
  - el correo de Administración de la carta es un ajuste obligatorio, sin dirección en el código. Desde la v0.37.0 también la razón social y la dirección del pie (obligatorias) y una firma opcional (`Modelo_347_Ajustes::faltan_para_carta()`);
- Publicar y enviar (`Modelo_347_Publicacion_Service`), en dos pasos con huella:
  - en una transacción: lote (`filtros` = `{"tipo":"modelo_347","emisor","ejercicio"}`), `cambiar_estado()` con el origen de evento nuevo `modelo_347` y la cola de envíos de las farmacias con cuenta activa y email;
  - al deshacer el lote se cancelan los envíos aún en cola (`Despublicacion_Lote_Service`). Si después se vuelve a publicar, el cancelado vuelve a la cola y lo ya enviado no se toca.
- Envíos (`wp_mdf_ca_347_envios`, UNIQUE `documento_id`, sin importes), `Modelo_347_Envio_Service`:
  - tandas AJAX al ritmo del ajuste (5 correos cada 30 s por defecto). El servidor también exige el intervalo, pero esa comprobación no es atómica y solo es prudencia;
  - **cada carta se reclama** con un `UPDATE` condicionado `pendiente → enviando`, y eso es lo que impide el doble envío (probado con 4 peticiones simultáneas);
  - antes de enviar se comprueba de nuevo que sigue publicada y vigente y que la cuenta sigue activa con email; el destinatario es el email actual de la cuenta;
  - PDF adjunto desde memoria con `phpmailer_init`. Reply-To del ajuste, obligatorio para enviar; el From es el del SMTP.
- Estados del envío:
  - envío correcto → `enviado` y se marca `notificado_en`. Fallo → `fallido` con su motivo (ver «Motivo de los correos fallidos»), que solo vuelve a la cola con «Reintentar los fallidos»;
  - un `enviando` de más de 10 minutos es «interrumpido» y no se reintenta solo;
  - «Reenviar» (casilla de confirmación) es la única forma de mandar dos veces.
- El aviso genérico excluye `modelo_347` en `find_pendientes_aviso()`; tampoco avisa de los 347 subidos a mano
- «Enviarme una prueba»: a la dirección del ajuste, con «[PRUEBA]», sin publicar ni registrar
- ZIP de «sin cuenta» y «no está en el portal»: `Zip_Stream` (escritor STORE propio, directo a `php://output`, sin ningún temporal)
- **Contraseña en el PDF: descartada** (se implementó y se quitó antes del commit). dompdf solo cifra con **RC4 de 40 bits** (`/V 1 /R 2` en Cpdf), que se rompe en segundos, y la contraseña prevista era el CIF/NIF del destinatario, un dato que conoce cualquiera que tenga una factura suya. Además solo podía aplicarse al ZIP: las cartas del portal se abren en el visor y se adjuntan tal como se guardaron, y no se pueden volver a cifrar al enviar porque los importes no están en la BD. No aportaba protección y podía dar una falsa sensación de seguridad. Si algún día hiciera falta, tendría que ser AES con otra librería y una contraseña que no sea un dato público
- `maybe_upgrade()` con cerrojo: comprobación barata y, si hay trabajo pendiente, `GET_LOCK` sin espera (si otra petición lo tiene, esta no migra). Con el cerrojo se vacía la caché de options y se vuelve a comprobar contra la BD; `RELEASE_LOCK` en un `finally`. Toda migración nueva debe añadir su marca a `Activator::MARCAS_MIGRACION`
- **Decisión de MDF: no hay 347 de proveedores en el portal** (el área es solo para clientes). El modo proveedores (sin cruce, email de la columna «Email») no se construye; la columna Email del Excel se sigue admitiendo y se ignora
- Pruebas: `tests/generar-347-ficticio.php` (OpenSpout, CIF por argumento), `tests/generar-347-sage-net.py` (estructura exacta de SAGE, mismas filas; desde la v0.37.0 su escritor es la función `escribir()`), `tests/generar-347-formacion.py` (Formación, 13 columnas sin Email, los cinco casos de la v0.37.0) y `tests/generar-347-maliciosos.py <origen> <carpeta> [<prefijo>]`. Este último genera 8 variantes malas por formato (DOCTYPE, xlsm renombrado, VBA, zip bomb, sin columna, sin hoja, Override `macroEnabled` y PDF renombrado), con sus ficheros en `tests/fixtures/`. `tests/` no se despliega
- Plantilla original: `docs/MODELO CARTA 347.docx` (no se despliega: `deploy.sh` excluye `docs/` y `*.docx`, y el `.htaccess` de la raíz deniega `docx` además de `md`, `lock`, `sh`, `yml`, `yaml` y `log`)

**Estado de los presupuestos: aceptar o rechazar desde el visor** (v0.44.0, **esquema 1.23.0**, petición del cliente). Solo los documentos de tipo presupuesto tienen estado: pendiente, aceptado o rechazado
- Columnas nuevas en `wp_mdf_ca_documentos` (NULL fuera de los presupuestos): `presupuesto_estado`, `presupuesto_cambiado_en` (UTC), `presupuesto_cambiado_por`, `presupuesto_via` (`area` = la farmacia; `mdf` = MDF), `presupuesto_motivo` (rechazo), `presupuesto_hash` (SHA-256 del fichero al responder la farmacia: constancia de qué se aceptó) y `presupuesto_aviso_pendiente`. Migración `mdf_ca_presupuestos_estado_v1`: los presupuestos existentes pasan a pendiente (sin evento, como la de descargables). Además, `accion` de la tabla de eventos pasa a `VARCHAR(30)`: «presupuesto_rechazado» no cabía en 20 y con MySQL estricto el INSERT habría fallado (en local se recortó: quedan dos eventos de prueba recortados en el documento 8695)
- `Presupuesto_Estado` (valor de solo lectura, desde la fila; `Documento::get_presupuesto()`), `Presupuesto_Service` (aceptar/rechazar por la farmacia; `cambiar_por_mdf()` a pendiente o aceptado), `Presupuesto_Aviso` (correos), `Presupuesto_Ajustes` (correo del responsable, solo `Roles::CAP_ADMINISTRAR`, sin dirección en el código) y `Admin_Presupuestos` (Documentos MDF > Presupuestos)
- **Subida** (Documentos MDF): con tipo presupuesto aparece «Estado del presupuesto» (Pendiente/Aceptado), obligatorio; lo valida `Documento_Service::subir()` (`estado_presupuesto()`). Aceptado al subir: vía `mdf`, evento «presupuesto_aceptado» en la transacción del alta y sin correos
- **Visor** (`/mdf-ca-visor/{id}/`): franja bajo la cabecera con el estado y, si está pendiente, «Rechazar» y «Aceptar presupuesto», cada uno con su `<dialog>` de confirmación (`documento-visor-presupuesto.js`); el rechazo exige motivo (hasta 1.000 caracteres). POST al propio visor con nonce por documento; `Presupuesto_Service` decide todo (Permissions, tipo, pendiente) con la fila bloqueada y el UPDATE condicionado a «pendiente»: dos respuestas a la vez, solo cuenta una. Ajeno, inexistente o no presupuesto: el 404 uniforme. Después, PRG con `?respuesta=` (clave fija, nunca texto). La franja solo sale si el usuario puede ver el documento: la página de un ajeno sigue igual a la de uno inexistente
- **Listado** (`[mdf_ca_listado_documentos]`): etiqueta `mdf-ca-listado-documentos__estado` (`--pendiente|--aceptado|--rechazado`) con «Pendiente», «Aceptado el dd/mm/aaaa» o «Aceptado» si lo puso MDF (la fecha sería la de MDF). CSS en `assets/css/listado-documentos.css`, solo si hay presupuestos
- **Correos** tras aceptar o rechazar, después del COMMIT (si fallan, el estado ya está guardado): al responsable (farmacia, CIF, presupuesto, quién, cuándo y motivo; Reply-To del usuario), con reintento cada hora por WP-Cron (`mdf_ca_presupuestos_reintentar_aviso`) mientras quede `presupuesto_aviso_pendiente` y botón «Reintentar ahora»; y una confirmación al usuario de la farmacia, sin nombre del documento ni motivo (mismo criterio que el aviso de documentos nuevos), una sola vez. El envío es síncrono: el visor tarda lo que tarde el SMTP
- **MDF** (gestor y administrador): pantalla Presupuestos con filtro por estado, quién y cuándo, motivo, aviso sin enviar y «Cambiar estado» a Pendiente o Aceptado (evento con origen individual). Devolver a pendiente borra motivo y huella de la fila (quedan en el historial) y cancela el aviso. El rechazo solo lo hace la farmacia. Historial: «Presupuesto aceptado/rechazado/devuelto a pendiente», origen «La farmacia, desde el área de clientes»
- Comprobado en local: casos límite del servicio (motivo vacío y largo, factura, admin sin farmacia, inexistente, ya respondido, MDF repetido y estado no permitido), eventos, correos en Mailpit (texto y Reply-To), reintento sin y con correo del responsable, subida aceptada y sin estado, contrato sin estado; en Chrome con la sesión de Patricia, aceptar y rechazar desde el visor (motivo obligatorio), listado y móvil a 390 px. **Falta**: ver las pantallas del admin en el navegador (se comprobó su HTML con WP-CLI), Safari y un SMTP real

**Ventana de cada herramienta** (v0.43.0, **esquema 1.22.0**, feedback del cliente). Al hacer clic en una tarjeta de Herramientas se abre, en el centro, una ventana con el tipo, el nombre, un párrafo explicativo, las etiquetas y los mismos botones. Se cierra con la X, con Esc o con un clic fuera
- Columna nueva `descripcion_detallada TEXT NULL` en `wp_mdf_ca_catalogo` (la añade `dbDelta` al subir `MDF_CA_DB_VERSION`; no toca nada más). `Catalogo_Item::get_descripcion_detallada()`. `Catalogo_Service::MAX_DESCRIPCION_DETALLADA` = 600 (error si se pasa; vacía se guarda `NULL`). La clave `descripcion_detallada` de `guardar()` es opcional
- Ficha de la herramienta en el admin: «Descripción detallada (opcional)», un `textarea` debajo de la breve, en el alta y en la edición. Un solo párrafo: `sanitize_text_field()` convierte los saltos de línea en espacios
- `Shortcode_Catalogo_Herramientas`: cada `<li>` lleva `data-ficha` y su `<dialog>` ya pintado (HTML abajo). El nombre es un `<button>` para el teclado. Sin descripción detallada, la ventana muestra la breve. Encola `assets/css/catalogo-herramientas.css` y `assets/js/catalogo-herramientas.js` solo al pintarse (van al pie). El JS solo abre y cierra (`showModal()`; foco en la ventana y, al cerrar, de vuelta al nombre); los enlaces de la tarjeta siguen yendo directos. Sin JS, la tarjeta funciona como antes
- Comprobado en local (Chrome, sesión de cliente): alta, edición, vaciado y límite por `Catalogo_Service::guardar()`; el formulario del admin con y sin valor; abrir con clic en la tarjeta y en el nombre, cerrar por las tres vías, «Descargar» de la tarjeta sin abrir la ventana, foco, sin desbordes a 390 px. Textos de prueba en `tests/datos-sinteticos/detallar-herramientas-patricia.php`. **Falta** Safari y verlo en el admin con el navegador

**«Volver» del visor a la página de su tipo** (v0.42.0, sin cambio de esquema, feedback del cliente). `Documento_Visor::url_volver_documento()`: una factura vuelve a Facturas, un 347 a Modelo 347, etc. Sin tipo o con un tipo sin página propia, a «Otros documentos» (como el listado); si esa página no está publicada, a Documentación. Las herramientas siguen volviendo a Herramientas. Comprobado con los seis tipos de los datos sintéticos.

**Visor de documentos con la imagen del área** (v0.41.0, sin cambio de esquema). Solo presentación: token de un solo uso, marca de agua horneada, `fetch()` y rutas no cambian, y el JS conserva todos sus `id`
- `Documento_Visor::pintar()` admite `$url_volver` (opcional, al final). Cabecera blanca fija con el logo verde, «Volver», el título del documento y «Descargar» (verde, solo si `Permissions` lo permite, como antes). Debajo, la barra de paginación (se oculta con `:has()` si no hay nada que paginar). Documento como una hoja sobre fondo gris; Montserrat propia del plugin. En móvil, «Volver» y «Descargar» quedan como iconos
- Visor de documentos: título = nombre del documento y «Volver» a Documentación **solo si `puede_ver_documento()`**; con uno ajeno o inexistente, título «Documento» y sin «Volver» (comprobado: las dos páginas solo difieren en el id pedido de `endpointUrl`, como antes). Herramientas: «Volver» a Herramientas (`url_pagina_area()`). Vista previa del administrador: sin «Volver». La pestaña lleva «| Área de clientes MDF»; «Página N de M» con tilde
- Comprobado en local (Chrome, sesión de cliente): factura PDF de una página, herramienta Excel, móvil a 390 px y la igualdad ajeno/inexistente. **Falta** un PDF de varias páginas, un Excel con varias hojas, la vista previa del administrador y Safari

**Pantalla de acceso y menú de perfil con la imagen del área** (v0.40.0, sin cambio de esquema). Solo presentación: el flujo de `wp-login.php`, los errores uniformes de `Endurecimiento` y la redirección no cambian
- `Login_Personalizado`: encola `assets/css/login.css` (vía `Recursos`), pinta en `login_header` un panel de marca (logo blanco, «Área de clientes», qué hay dentro, teléfono de ayuda y la marca de agua del logo) y en `login_footer` un pie con los avisos legales de mediformplus.com; añade en `login_message` (prioridad 20, delante del aviso de invitación) un título y una pista por pantalla (`login`, `lostpassword`, `rp`/`resetpass`, `checkemail`; las demás, como estaban); quita el selector de idioma; el logo nativo (oculto) apunta a mediformplus.com y la pestaña dice «Acceder | Área de clientes MDF». En «Recuperar contraseña» se oculta el aviso informativo nativo, que repetía la pista (los errores van en `#login_error`). El mensaje de bienvenida de la invitación, con tildes
- Recursos propios, sin depender de Elementor, de la biblioteca de medios ni de Google: `assets/img/mdf-logo-blanco.png` y `mdf-logo-verde.png` (de mediformplus.com) y Montserrat variable 400–700 en `assets/fonts/` (latin y latin-ext, con su `OFL.txt`)
- Pantalla partida en escritorio (panel verde a la izquierda, formulario a la derecha); por debajo de 900 px el panel es una banda arriba. Colores, radio de 12 px y foco como en `DESIGN.md`
- `Menu_Perfil`: botón «Mi cuenta» (avatar, texto y flecha; iconos de Tabler en línea) y panel con «Has accedido como», el nombre de la farmacia del usuario en sesión (`Farmacia_Repository::find_by_wp_user_id()`), su usuario si es distinto del nombre y «Cerrar sesión». CSS con especificidad alta a propósito: el reset de Hello Elementor pinta los botones de rosa en `:hover`/`:focus`. La cabecera de Elementor reserva 184 px a la derecha para el botón (clase global `mdf-cabecera`)
- Comprobado en local: login, «Recuperar contraseña» y móvil (390 px) en Chrome, sin desbordes; el menú con una sesión de cliente real en escritorio y móvil (abre, cierra y no se solapa con la cabecera ni con el menú). **Falta** ver un error de login y la pantalla de elegir contraseña de una invitación, y Safari

**Portada del área y una página por tipo de documento** (v0.39.0, sin cambio de esquema). El front maquetado en Elementor (ver `DESIGN.md`) añade una portada y divide Documentación en una página por tipo:
- `Area_Privada_Pages::get_paginas_adicionales()`: la portada `area-de-clientes` y seis páginas hijas de Documentación (`facturas`, `modelo-347`, `contratos`, `presupuestos`, `entregables`, `otros-documentos`; `slug_de_tipo()`), con los IDs en su propia option `mdf_ca_area_privada_paginas_adicionales_ids`. Las cuatro secciones y su option no cambian: la navegación del shortcode y el enlace del aviso por email siguen igual
- `crear_paginas_adicionales()`: mismo criterio que `crear_paginas()` (idempotente, no pisa nada): adopta por slug (la de tipo, como hija de Documentación) cualquier página que ya exista, en cualquier estado, y crea publicadas las que falten con el shortcode del tipo. Se ejecuta en `activate()` y en `maybe_upgrade()` con la marca `mdf_ca_area_privada_paginas_adicionales_v1`, que solo se pone si están todas (sin Documentación, se reintenta)
- `get_ids_protegidos()` (las cuatro y las siete) sustituye a `get_page_ids()` en `proteger_area_privada()`, en la ocultación REST (listado e individual) y en `Menu_Perfil`
- Destino tras el login (`get_area_privada_url()`): la portada si existe y está publicada; si no, Documentación como antes
- `[mdf_ca_listado_documentos tipo="…"]`: claves de `Documento_Tipos::get_grupos_listado()`. Se filtra **después** de `Permissions::filtrar_documentos_visibles()` (solo elige entre lo visible); `otro` incluye los documentos sin tipo; en la vista agrupada se omite el `<details>` del tipo y el contenedor lleva `mdf-ca-listado-documentos--tipo-{clave}`; vacío por tipo («No hay facturas disponibles.»); un tipo desconocido no lista nada. Sin `tipo`, el listado es el de siempre
- Comprobado en local: con la farmacia 4, los seis tipos suman los 8 documentos visibles del listado completo (solo publicados; `otro` con el que no tiene tipo); tipo vacío, desconocido y sin sesión; listado sin `tipo` igual (3 grupos) y sin documentos de otra farmacia (4 y 8). Sin sesión, las siete páginas nuevas dan 302 al login y 404 en `/wp/v2/pages/{id}`, y no salen en el listado REST. **Falta** ver el menú de perfil y el destino tras el login con una sesión de farmacia real (Chrome y Safari)
- En local las siete páginas son las maquetas de Elementor ya hechas (27 y 91–96), adoptadas por slug, y los diseños de las cuatro secciones se pasaron a 13–16. **En producción** la migración creará las siete páginas solo con el shortcode: hay que llevar allí los diseños de Elementor (exportar e importar) y los medios (iconos y logos)

**Motivo de los correos fallidos** (v0.38.0, sin cambio de esquema ni evento nuevo). En producción un «Enviar ahora» de Avisos por email dio «1 fallido» sin ningún rastro: el aviso genérico solo miraba el `false` de `wp_mail()` y no guardaba nada
- `Correo::enviar()` es el único envío del aviso genérico y del 347 (las invitaciones siguen con `wp_mail()` directo):
  - comprueba el destinatario con `is_email()` y, si no vale, no llama a `wp_mail()`;
  - engancha `wp_mail_failed` y devuelve `null` si sale, o `{categoria, detalle}`. Categorías (`Correo::texto_categoria()`): «Email no válido», «El servidor de correo rechazó el envío» (autenticación, remitente, destinatario o datos rechazados), «No se pudo conectar con el servidor de correo» (conexión, tiempo de espera, `mail()`/sendmail), «No se pudo generar el adjunto» y «Error desconocido» (también `wp_mail()` false sin `wp_mail_failed`, p. ej. un `pre_wp_mail`). Se deducen de los textos en inglés de PHPMailer, que WordPress no traduce;
  - el detalle es el mensaje de PHPMailer, saneado, cortado a 255 caracteres y con el usuario y la contraseña del SMTP tachados si aparecieran (PHPMailer no los pone). Puede llevar el email del destinatario y un trozo de la respuesta del servidor: por eso solo lo ve el administrador.
- **Aviso genérico:** tras «Enviar ahora», la lista de farmacias que fallaron con categoría y detalle. El último fallo de cada farmacia (también del cron) va a la usermeta `mdf_ca_ultimo_fallo_aviso` (`fecha`, `categoria`, `detalle`) y se borra con el siguiente aviso que salga bien; «Pendientes de aviso» lista las farmacias pendientes cuyo último aviso falló. La pantalla es solo del administrador. `error_log` sigue solo con recuentos
- **347:** la columna `error` guarda `clave|detalle` (`Correo::serializar()` / `leer()`). La tabla de envíos muestra la categoría a todos y el detalle solo con `Roles::CAP_ADMINISTRAR`; los motivos de cancelación (textos fijos) y los errores anteriores se muestran tal cual. «Enviarme una prueba» da la categoría y, al administrador, el detalle. Un PDF ilegible es «No se pudo generar el adjunto». Al terminar la tanda: «N fallidos: ver el motivo en la tabla»
- Sin evento en el historial: el aviso genérico es por farmacia y se reintentaría a diario; el 347 ya tiene su fila de envío
- **WordPress traduce los mensajes de PHPMailer** al idioma del sitio (`WP_PHPMailer`): con el sitio en español, «SMTP Error: Could not connect…» llega como «Error SMTP: no se pudo conectar…». Por eso `Correo` compara con cada `msgid` en inglés y con su `__()` del núcleo
- Comprobado en local con datos ficticios («ZZMAIL», borrados al terminar; un mu-plugin temporal fuera del repo para el SMTP caído y otro para entrar como gestor, también borrados):
  - email inválido puesto por SQL: no llega a PHPMailer y queda «Email no válido»;
  - SMTP a un puerto cerrado, con usuario y contraseña ficticios: «No se pudo conectar con el servidor de correo» en Avisos por email (tras «Enviar ahora» y en pendientes) y en el 347, sin las credenciales;
  - el siguiente aviso correcto borra la usermeta y la farmacia sale de la lista;
  - 347: envío correcto (Mailpit, con adjunto), cancelado por cuenta no activa, PDF ilegible («No se pudo generar el adjunto»), «Reintentar los fallidos» y «Reenviar»; resumen de la tanda «1 fallidos: ver el motivo en la tabla»;
  - en Chrome, el gestor ve la categoría sin el detalle y el administrador, las dos. Ningún error de PHP en `debug.log`.

  **Falta:** Safari, y «Enviarme una prueba» con un fallo (no se probó: los Ajustes locales no tienen dirección de prueba)

**Modelo 347 de Formación** (v0.37.0, sin cambio de esquema). Segunda sociedad del generador, con la misma lógica: lectura, simulación, huella, confirmación, generación, publicación, envío, retirada y regeneración son las de Mediformplus
- **El Excel de Formación es igual que el de Mediformplus:** las mismas 13 columnas obligatorias con los mismos nombres (Código, Nombre, NIF, Dirección, Población, Código postal, Trimestre 1 a 4, Importe DEV, Importe RECC, RECC), sin la columna Email (ya era opcional). **El fichero no dice de qué sociedad es**: no lleva el CIF del emisor en ningún sitio, solo el de cada cliente. Así que no se puede rechazar por formato ni por CIF; en su lugar, decisión de Luis:
  - **cruce con las facturas del portal** (`Documento_Repository::emisores_de_facturas()`): una fila es dudosa si su farmacia tiene facturas de otra sociedad y ninguna de la elegida (aviso «Solo tiene facturas de X en el portal»). Si las dudosas son más de la mitad de las filas cuya farmacia tiene alguna factura con emisor, la simulación se bloquea con un error claro y no se puede generar (ni en la pantalla ni forzando la petición). Las filas sin facturas no cuentan. El resultado del cruce entra en la huella;
  - **confirmación explícita**: generar exige marcar «Confirmo que este Excel es el del Modelo 347 de {razón social} ({CIF}) exportado de SAGE» (`required` en el navegador y comprobado en `Modelo_347_Generacion_Service::confirmar()` antes de consumir la simulación).

  Probado con los dos Excel ficticios: cada uno con su sociedad, sin bloqueo; cruzados, bloqueados. **Límite asumido:** si las farmacias del fichero no tienen facturas en el portal (p. ej., antes de subir las de Formación), el cruce no dice nada y solo queda la casilla
- **DEV y RECC** (sin cambios, igual que Mediformplus): Importe DEV es el total anual de la carta y tiene que cuadrar con T1+T2+T3+T4 (±1 céntimo) o la fila se bloquea; trimestre vacío = 0 con aviso; negativos admitidos con aviso y con signo en la carta. Importe RECC y RECC se leen pero no salen en la carta ni en la huella: solo dan el aviso «RECC distinto de 0». **Duda abierta para Administración:** el código no dice qué significan (parecen devengo y régimen especial del criterio de caja); hasta que lo confirmen se tratan así. Una fila con todo a 0 se genera (carta con total 0,00 €), como en Mediformplus
- **Datos de la sociedad en Ajustes** (por sociedad, editables por quien gestiona el 347): razón social y dirección del pie (obligatorias para generar, previsualizar y el ZIP) y firma opcional (líneas al final de la carta, sin margen inferior: caben dos líneas en la misma página, porque la carta la ocupa casi entera). El CIF no se edita. Ya no hay datos de la sociedad en las plantillas: la de Mediformplus pinta lo mismo que antes con «MEDIFORMPLUS, S.L.» y «Calle Mahón, 6. - 28290 Las Rozas. - MADRID» en Ajustes (comprobado). Sin migración: el 347 no está desplegado; en producción hay que rellenar los Ajustes de cada sociedad antes de usarlo. `{SOCIEDAD}` del correo usa la razón social de Ajustes (el nombre corto si falta)
- **Carta de Formación PROVISIONAL** (`includes/plantillas-347/B88471149.php`): copia de la de Mediformplus, con un recuadro «PROVISIONAL… No enviar» fijo en el hueco del logo (fuera del flujo, para no mover el texto) y «[PROVISIONAL]» delante del primer párrafo. Sin logo (`logo` null: la plantilla puede no tener cabecera). Al recibir el texto definitivo de MDF se sustituye el cuerpo, se quitan las dos marcas y se añade el logo en `img/`
- Identificación: título «Modelo 347 Formación {año}» (`Documento_Service::nombre_modelo_347()`, ya existía), columna «Modelo 347» de Documentos MDF («Formación · Publicado»), «Publicar y enviar» por sociedad y adjunto `Modelo-347-Formacion-{año}.pdf`. Mismo tipo `modelo_347` y misma unicidad por farmacia, sociedad y ejercicio: una farmacia puede tener a la vez el de Mediformplus y el de Formación del mismo año
- Sin columna Email: el aviso va, como siempre, al email de la cuenta del portal
- **Hallazgo corregido de paso (visor, #321):** con un documento ajeno sin marca de agua, `Documento_Visor` dejaba `marcaAgua` vacío y con un id inexistente ponía la de la farmacia: la página delataba que el id existía. Ahora solo mira la configuración del documento si `Permissions::puede_ver_documento()` lo permite; ajeno e inexistente dan el mismo HTML (comprobado con diff)
- **Imagick en local:** el PHP de línea de comandos de Local tiene Imagick y dompdf lo usa para el canal alfa del logo PNG, con `ImagickException: Unable to set format` (la carta de Mediformplus no se genera por wp-cli; la de Formación, sin logo, sí). Por la web funciona. Producción no tiene Imagick. No es de la v0.37.0: pasa igual con el código anterior
- Comprobado en local con datos ficticios (farmacias, cuentas y facturas «ZZ347», borradas al terminar; los Ajustes de Formación se quedan con datos ficticios para probar):
  - Excel de Formación de cinco filas (normal, NIF fuera del portal, importe cero, trimestres vacíos, DEV y RECC): simulación, huella (una huella distinta se rechaza), casilla obligatoria, generación de 4 cartas, publicación, envío (Mailpit: razón social, Reply-To y adjunto de Formación), retirada para corrección y regeneración de una sola;
  - un Excel de Mediformplus no se genera como Formación y al revés (bloqueo en la simulación y al confirmar);
  - visibilidad: la farmacia ve y descarga su carta (nombre con tilde en `filename*`); token de un solo uso; la de otra farmacia, un id inexistente y sin sesión dan el mismo 404; visor ajeno igual que inexistente; carpeta y fichero directos, 403;
  - regresión del 347 de Mediformplus en Chrome: simulación con los mismos recuentos, vista previa con logo y pie idénticos a antes y generación.

**Eliminar documentos subidos por error** (v0.28.0, sin cambio de esquema)
- Acción «Eliminar» en Pendientes de publicar (junto a «Publicar») y en una columna nueva «Acciones» de la tabla de Documentos MDF
- Solo documentos **no publicados** (pendientes o despublicados) y que **no son Modelo 347**. Un publicado hay que despublicarlo antes, para que nunca desaparezca de golpe algo que una farmacia puede estar viendo. Los 347 siguen con «Retirar para corrección». La regla vive en un solo sitio, `Documento_Eliminacion_Service::motivo_no_eliminable()`, y el `DELETE` la repite (`publicado = 0 AND tipo_documento <> 'modelo_347'`)
- Confirmación en una pantalla propia (`admin.php?page=mdf-ca-documentos&eliminar=ID&volver=pendientes|documentos`): «Se eliminará la factura X de la farmacia Y. No se puede deshacer.» y «Eliminar definitivamente». Funciona sin JS. El borrado es un POST a `admin-post` (`mdf_ca_eliminar_documento`) con nonce por documento y `Admin_Documentos::CAPACIDAD`; vuelve a la pantalla de origen con su aviso
- `Documento_Eliminacion_Service::eliminar()`, todo o nada:
  - en una transacción con la fila bloqueada (`FOR UPDATE`);
  - el fichero se aparta dentro de la carpeta privada con un `rename` a `.eliminando-<aleatorio>` (atómico y reversible);
  - `DELETE` de la fila e `INSERT` del evento `eliminado` (origen `individual`, usuario y fecha, sin datos del documento);
  - `COMMIT` y después se borra el fichero apartado. Si algo falla antes, `ROLLBACK` y el fichero vuelve a su nombre.

  Si el fichero ya no estaba en disco, se borra igual la fila y se avisa
- **Documentos antiguos (v0.32.1, sin cambio de esquema).** En producción falló al eliminar el «Documento Prueba Fase 1»: los documentos de la Fase 1 solo guardaban `farmacia_id`, `nombre` y `ruta_fichero`, y las migraciones dejaron en NULL tipo, MIME, tamaño, hash y fecha. El `DELETE` exigía `tipo_documento <> 'modelo_347'`, que con NULL no es verdadero en SQL: no borraba nada y la transacción se revertía. Ahora es `( tipo_documento IS NULL OR tipo_documento <> 'modelo_347' )`, igual que ya hacía `find_pendientes_aviso()`. Las reglas no cambian: ni publicados ni 347 (comprobado también por SQL directo), en transacción y con el evento `eliminado`
- Cuando falla, el mensaje dice en qué paso (el documento cambió a la vez, la BD rechazó el borrado, no se pudo apuntar el evento, la BD no confirmó o no se pudo apartar el fichero) y `error_log` guarda el motivo técnico: id, paso, publicado, tipo y error de la BD, nunca el nombre ni datos de la factura (`Documento_Eliminacion_Service::registrar_fallo()`)
- Revisado con documentos con la forma de la Fase 1 (en local): despublicar, publicar e historial funcionan. **El visor no los pinta**: sin MIME, el endpoint sirve `application/octet-stream` y el visor (de la farmacia y la vista previa del administrador) dice «Este tipo de documento no se puede visualizar aquí»; en Documentos MDF salen sin tamaño. **Decisión: borrarlo, sin migración.** En producción solo existe ese documento antiguo y es de prueba: tras desplegar la 0.32.1 se elimina desde Documentos MDF (despublicándolo antes si está publicado). No se completan MIME ni tamaño de documentos antiguos; si apareciera otro, se decide entonces
- Revisión del resto del plugin (v0.32.1) en busca de negaciones SQL (`<>`, `!=`, `NOT IN`, `NOT LIKE`) y comparaciones de orden sobre columnas que admiten NULL: **solo fallaba este `DELETE`**. Las demás:
  - `find_pendientes_aviso()` ya llevaba `tipo_documento IS NULL OR …`;
  - la migración de planes de la Fase 3 (`plan IS NOT NULL AND plan != ''`) excluye los NULL a propósito;
  - los envíos del 347 (`estado = 'enviando' AND ultimo_intento_en < …`, en «Reenviar» y en el recuento de «interrumpido») no pueden encontrarse un NULL: la única forma de pasar a «enviando» es la reclamación, que escribe `ultimo_intento_en` en la misma orden. Y si lo hubiera, excluirlo es lo prudente: nunca se reenvía algo que podría estar en curso.

  **Regla para SQL nuevo:** una negación sobre una columna nullable lleva `( columna IS NULL OR columna <> … )` si el NULL debe entrar, o un comentario que diga por qué se excluye
- Al borrar la fila, su hash y su emisor + serie + número dejan de bloquear: la misma factura se puede volver a subir (comprobado con «Subir facturas»)
- El historial de un documento eliminado muestra sus eventos, el último «Eliminado», con la nota de que ya no existen ni el documento ni su fichero
- Comprobado en local con datos de prueba:
  - eliminar desde las dos pantallas, también en Chrome;
  - negativas: un publicado y un 347 despublicado (ni se ofrece el formulario y el servicio se niega), sin nonce (403), un cliente (fuera del backoffice) y sin sesión (400);
  - fichero ausente: aviso y fila borrada;
  - la carpeta privada queda igual que la BD y no quedan apartados;
  - eventos y volver a subir;
  - regresión de Pendientes, publicación en bloque, deshacer lote, Subir facturas y el resto de pantallas.

**Subir facturas** (#334; v0.27.0, esquema 1.15.0). Sustituye al programa de envío del Mac
- Documentos MDF › Subir facturas (`Admin_Subir_Facturas`):
  - capacidad: la de Documentos MDF, `Admin_Documentos::CAPACIDAD` (desde la v0.28.0 único sitio para Documentos MDF, Pendientes, Documentos publicados, Subir facturas y Eliminar; desde la #337, `mdf_ca_gestionar_documentos`);
  - un nonce propio para todas las peticiones de la pantalla;
  - solo `wp_ajax_` (sin `nopriv`). Comprobado: sin sesión, 400; un cliente, sin nonce o con el nonce de otra acción, 403.
- Selección con `Admin_Zona_Archivos` en modo lote:
  - varios PDF, carpetas soltadas con sus subcarpetas y botones «Elegir archivos» y «Elegir carpeta»;
  - ignora sin error los ocultos (`.DS_Store`, carpetas que empiezan por punto) y lo que no es PDF, y dice cuántos;
  - antes de empezar: «N facturas encontradas (X MB)» y «Subir»;
  - topes: 500 facturas por subida (`MAX_FACTURAS`) y 10 MB por factura (`Pdf_Limites::MAX_BYTES_FICHERO`); si se superan, no se añade nada y lo dice.
- Subida de una en una (`admin-subir-facturas.js`, una petición `admin-ajax` `mdf_ca_subir_factura` por factura):
  - barra de progreso y recuento en vivo;
  - «Parar», que termina la factura en curso, y «Continuar»;
  - un fallo de red o del servidor no corta la subida: la factura queda «con error» y se puede reintentar;
  - sin permiso, con la sesión caducada o con el servidor sin extensiones, se para con el motivo;
  - aviso al cerrar la página a medias. Volver a subir lo mismo no duplica nada.
- Cada factura en el servidor:
  - una sola factura por petición, subida HTTP real, 10 MB como mucho y tipo real `application/pdf` (fileinfo; `fileinfo` pasa a los requisitos de la lectura);
  - el nombre se sanea (`sanitize_file_name`, sin ruta) y solo sirve para contrastar serie y número;
  - `Factura_Lectura_Service` → `Factura_Recepcion_Service` (cruce, duplicados por hash y por emisor + serie + número, nombre visible y fecha de factura), siempre pendiente, sin aviso por email, con evento `recibido` y registro con origen `backoffice`.

- Respuesta y resumen en lenguaje claro, por grupos:
  - subidas, con la farmacia;
  - ya estaban;
  - no reconocidas, con el motivo: «no se encuentra el CIF del cliente», «el CIF no corresponde a ninguna farmacia del portal», «el número de factura no coincide con el nombre del fichero», «no parece una factura de SAGE de Mediformplus o de Formación», «el PDF está protegido con contraseña», «el PDF no se puede abrir», «no es un PDF», «pesa más de 10 MB»…;
  - con error.

  Desde la #335, las que se pudieron leer pero no asignar van a la Bandeja de facturas (grupo «Enviadas a la Bandeja de facturas», con enlace; ver su sección). Las que ni se leen (cifradas, que no se abren, no PDF, más de 10 MB o por encima de los topes del lector) no se guardan; su lista (nombre de fichero y motivo, `.txt`) se genera en el navegador. Al final, enlace a «Pendientes de publicar»
- Comprobado con sintéticas (`tests/lectura-facturas/generar_lote_subida.py`):
  - una carpeta con subcarpetas, `.DS_Store`, un Excel, una carpeta oculta y un PDF que no es factura;
  - las dos sociedades, un abono y una de 2 × 3 páginas;
  - un CIF sin farmacia y un número distinto del nombre;
  - maliciosos de la #333 y repetir la carpeta (todo «ya estaban»);
  - cada fichero da lo mismo que la ruta REST con el resultado de Python (comprobado antes de retirarla en #336);
  - 200 facturas en 34 s (unos 170 ms por petición con el arranque de WordPress; 51 ms de media dentro de PHP), con un pico de 48 MB por petición, casi todo WordPress;
  - en Chrome: elegir archivos, topes, Parar y Continuar, reintento tras un corte de red y descarga de la lista.

  Las carpetas soltadas se probaron con entradas simuladas (la API no deja crear una carpeta real desde una prueba); **falta arrastrar una carpeta real desde el Finder y «Elegir carpeta» en Safari y Chrome**

**Bandeja de facturas** (#335; v0.29.0, esquema 1.16.0)
- Documentos MDF › Bandeja de facturas (`Admin_Bandeja_Facturas`), con el número de pendientes en el menú. Recoge lo que «Subir facturas» ha **leído** (`Factura_Lectura_Service::se_pudo_leer()`) pero no ha podido asignar: `Bandeja_Facturas_Service::MOTIVOS_QUE_ENTRAN` = sin CIF, CIF inválido, CIF sin farmacia, número distinto del nombre del fichero, sin texto y formato inesperado. No entran: repetidas, cifradas, que no se abren, no PDF, más de 10 MB ni por encima de los topes del lector. La recepción REST no usa la bandeja (sigue respondiendo 422)
- Tabla `wp_mdf_ca_bandeja_facturas`: ruta, nombre original, tamaño, hash (UNIQUE), motivo, lo leído (emisor, serie, número, fecha, CIF leído, abono; todo nullable), usuario y fecha de subida. UNIQUE (emisor, serie, número): los NULL no chocan. El fichero va a la carpeta privada como `bandeja-<hex>.pdf`, sin enlace directo
- `entrar()` devuelve `bandeja`, `ya_en_bandeja` (mismo hash, o mismo emisor + serie + número con otros bytes), `ya_documento` o `error`; en «Subir facturas» los dos del medio salen como «repetida» («ya estaba en la Bandeja de facturas» / «ya estaba subida (con otro nombre de fichero)»)
- Por factura: motivo en lenguaje claro, datos leídos, antigüedad, quién la subió y cuándo se borra. Acciones:
  - **Ver**: `admin-post` GET con nonce por factura (`ver_{id}`; sin él, 404) → `Documento_Visor::renderizar_vista_previa()` con marca de agua «BANDEJA - {login}». El fichero lo sirve `admin-ajax` (`mdf_ca_bandeja_fichero`) con un token de un solo uso de `Documento_Token` con **ámbito** `bandeja` (desde la 0.29.0 `emitir()`/`consumir()` llevan ámbito; un token de documento no abre un fichero de la bandeja ni al revés). Va por `admin-ajax` y no por `admin-post` porque `Role_Restrictions` redirige a los clientes en `admin-post`: así el fichero da el **mismo 404** sin token, reusado, con `Sec-Fetch-Dest: document`, sin sesión (handler `nopriv`) y como cliente. Las pantallas y acciones se comportan como las de Documentos MDF (un cliente es redirigido fuera del backoffice; sin sesión, 400 o login)
  - **Asignar a farmacia**: pantalla propia con el selector de farmacia (obligatorio, `$selector_obligatorio` en el parcial). Lo leído manda y no se edita; solo se piden los datos que faltan (sociedad, serie y número, fecha, abono). Si el CIF leído no es el de la farmacia elegida, vuelve con un aviso y una casilla de confirmación obligatoria. En cualquier vuelta con error se conserva lo rellenado: la farmacia por la URL (`&farmacia=ID`) y la sociedad, serie, número, fecha y abono en un transient del usuario y de la factura (`mdf_ca_bandeja_form_{usuario}_{id}`, 5 min, se consume al pintar; nunca en la URL, para no dejar datos de la factura en los registros del servidor). Con el parser actual la casilla nunca coincide con campos por rellenar: sin texto no hay CIF leído y el CIF solo se guarda cuando el resto de campos se ha leído. Pasa por `Factura_Recepcion_Service::asignar_manual()` (mismas validaciones `datos_validos()`, misma unicidad y carrera, nombre visible y registro), queda pendiente de publicar, sin aviso, con evento `asignado_manual`. El fichero se copia (`desde_disco`) y la fila de la bandeja se borra después
  - **Descartar**: pantalla de confirmación y POST con nonce por factura; borra fila y fichero
- **Volver a comprobar todas**: vuelve a leer cada factura con el lector y, solo si el cruce da asignada, la manda por `Factura_Recepcion_Service::recibir()` (origen `bandeja`, pendiente), para no inflar el registro con las que siguen sin farmacia. Presupuesto de 20 s por pulsación; lo que no dé tiempo queda para la siguiente
- Tabla de solo añadir `wp_mdf_ca_bandeja_eventos` (bandeja_id, acción, usuario, documento_id, fecha), sin datos fiscales: `entrada`, `asignada`, `asignada_auto`, `ya_existia`, `descartada`, `caducada` (usuario NULL)
- Borrado automático: WP-Cron diario (`mdf_ca_bandeja_caducar`, se programa en `init`, se quita al desactivar), por tandas de 200, de lo que lleve más de `mdf_ca_bandeja_dias` días (90 por defecto, de 7 a 3650, editable en la pantalla); borra fichero y fila. La pantalla avisa de las que se borran en los próximos 14 días. **Plazo a validar en la revisión RGPD de la Fase 5** (la bandeja guarda facturas de clientes que quizá no son farmacias del portal)
- Seguridad: `Permissions::puede_gestionar_bandeja()` sobre `Admin_Documentos::CAPACIDAD` y un nonce por acción (por factura en ver, asignar y descartar). Sin valores fiscales en los registros: el INSERT duplicado silencia el error de la BD, como en la recepción
- Comprobado en local con sintéticas (`tests/lectura-facturas/generar_bandeja.py`): cada motivo entra y los maliciosos no; repetir y la misma factura con otros bytes no entran dos veces; ver; asignar con CIF coincidente, con CIF distinto (confirmación) y sin texto (pidiendo los datos); descartar; volver a comprobar tras dar de alta la farmacia; caducidad con el fichero borrado; disco igual que la BD; acceso como cliente, sin sesión, sin nonce y con token de otro ámbito. En Chrome: resumen de Subir facturas, pantalla con el contador, visor, selector y asignar. Regresión: Subir facturas, Pendientes, publicación en bloque y deshacer, eliminar, recepción REST, 347 y todas las pantallas sin errores
- **Falta probar en Safari** (ver el informe de la #335)

**Retirada del robot y de la ruta REST** (#336; v0.30.0, esquema 1.17.0). Desde «Subir facturas» (#334) y la bandeja (#335) el robot ya no tenía uso y solo dejaba una puerta abierta
- Quitado del código: la ruta `POST /wp-json/mdf-ca/v1/facturas` (`Recepcion_Documentos_Endpoint`), su autenticación y aislamiento (`Recepcion_Autenticacion`), el rol `mdf_robot` y la capacidad `mdf_ca_subir_facturas` (`Roles`), la redirección del robot en `Role_Restrictions` y la pantalla «Recepción automática» (`Admin_Recepcion_Documentos` y su vista) con el interruptor, el tope diario, la aprobación previa y el alta del usuario robot. Sin rutas, el namespace `mdf-ca/v1` deja de existir: `/wp-json/mdf-ca/v1/` da 404 y el índice `/wp-json/` no lo lista (cierra el hallazgo del índice REST de la #298/#329)
- **Contraseñas de aplicación desactivadas en todo el sitio** (`Contrasenas_Aplicacion`, filtro `wp_is_application_passwords_available` a false): desaparece la sección del perfil, crearlas por REST da 501 `application_passwords_disabled` y una petición con Basic Auth se trata como anónima aunque la contraseña sea válida (401 `rest_not_logged_in`; por XML-RPC, 403)
- Migración (`Robot_Retirado::ejecutar()`, en `maybe_upgrade()` con la marca `mdf_ca_robot_retirado_v1`):
  - busca los usuarios con el rol en la usermeta de capacidades (la búsqueda por rol de WordPress es un LIKE y no exige que el rol siga registrado) y el usuario `mdf-robot`;
  - a todos les borra las contraseñas de aplicación;
  - borra el usuario solo si su usermeta de capacidades es exactamente `{mdf_robot: true}` (se mira `$usuario->caps`, porque `$usuario->roles` omite los roles no registrados). Con otro rol o alguna capacidad suelta no se toca: queda en la option `mdf_ca_robot_conservados` y un aviso en todo el backoffice (solo `manage_options` + `delete_users`) lo lista con enlace a su perfil y un botón «Ya lo he revisado» (admin-post con nonce). El aviso se va solo si el usuario se borra o pierde el rol;
  - `remove_role( 'mdf_robot' )` y borra las options `mdf_ca_recepcion_activa`, `mdf_ca_recepcion_tope_diario`, `mdf_ca_recepcion_requiere_aprobacion` y la marca antigua `mdf_ca_roles_v2`;
  - idempotente (una segunda ejecución no hace nada) y solo con la API de usuarios y options, igual en MySQL y MariaDB. La constante `MDF_CA_RECEPCION_DESACTIVADA` ya no se lee: si está en el `wp-config.php` de producción se puede quitar.
- Lo histórico se conserva: la tabla de registro (que usa «Subir facturas») y los eventos. El registro muestra «N por el robot (retirado)» en las filas `rest` y el historial, «Recibido por el robot (retirado), nació publicado» en los eventos `recepcion` (con «usuario borrado (ID N)»). `Publicacion_Evento_Repository` ya no acepta escribir el origen `recepcion` ni `Recepcion_Registro` el origen `rest`
- «Recepción automática» se funde en «Subir facturas»: al pie, «Actividad de los últimos 14 días», de solo lectura (Subidas, Ya estaban, Sin asignar, No válidas, Publicadas en bloque y Despublicadas al deshacer un lote). Un enlace guardado a `page=mdf-ca-recepcion-documentos` redirige ahí (`admin_page_access_denied`) a quien puede usar «Subir facturas»
- Simplificado lo que solo existía por la ruta: `Factura_Recepcion_Service::recibir( $archivo, $resultado, $origen_alta, $accion_alta )` y `asignar_manual( $archivo, $farmacia_id, $datos )` sin notificar, publicar ni origen (siempre pendiente, sin aviso y registro `backoffice`); `Documento_Service::subir_factura_recibida()` igual, con fecha y origen del evento obligatorios. El nonce de «Subir facturas» pasa a `mdf_ca_pantalla_subir_facturas` (antes coincidía con el nombre de la capacidad retirada)
- Producción: el `.htaccess` de la raíz de WordPress pudo recibir a mano la línea que pasa `Authorization` a PHP (para el robot): ya no hace falta y se puede quitar
- Comprobado en local con usuarios de prueba:
  - antes de migrar, las contraseñas del robot y de un usuario editor + `mdf_robot` autenticaban (400 frente a 401 sin credenciales) y el namespace respondía 200;
  - la migración borra el robot y sus contraseñas, conserva y avisa del mixto, quita rol y options, y repetida no hace nada. También desde cero, con un robot recreado a mano;
  - después: ruta y namespace 404, índice sin `mdf-ca`, Basic Auth con una contraseña válida rechazada, sección del perfil ausente (Chrome);
  - regresión: Subir facturas con bandeja (recomprobar, asignar, descartar), Pendientes, publicación en bloque y deshacer, eliminar, 347, importador (simulación), visor (token, reutilizado, sin sesión), historial con un evento antiguo de origen `recepcion`, accesos de cliente y sin sesión, todas las pantallas sin errores de PHP.

**Rol Gestión MDF y backoffice simplificado** (#337; v0.31.0, esquema 1.18.0). Prepara las cuentas individuales de la Fase 5
- Capacidades, todas en `Roles` (único sitio; las pantallas usan las constantes, nunca un literal):
  - `CAP_BACKOFFICE` (`mdf_ca_backoffice`): portada «Inicio» y vista simplificada;
  - `CAP_GESTIONAR_DOCUMENTOS` (`mdf_ca_gestionar_documentos`, = `Admin_Documentos::CAPACIDAD`): Documentos MDF y subida manual, Subir facturas, Bandeja, Pendientes (publicar, despublicar, publicar todos, deshacer lote), Eliminar, Documentos publicados e historial, y la vista previa (`Permissions::puede_previsualizar_documento()`);
  - `CAP_GESTIONAR_347` (`mdf_ca_gestionar_347`): el Modelo 347 completo, ajustes incluidos. «Retirar para corrección» exige esta y la de documentos;
  - `CAP_GESTIONAR_FARMACIAS` (`mdf_ca_gestionar_farmacias`, = `Admin_Farmacias::CAPACIDAD`): Farmacias MDF (listado y alta), importador CSV e invitaciones;
  - `CAP_GESTIONAR_CATALOGO` (`mdf_ca_gestionar_catalogo`, «Catálogo y planes», desde la v0.32.0): Planes MDF y Herramientas MDF (crear, editar y borrar planes y herramientas, y desde la v0.35.0 sus categorías y etiquetas). Lo que ve cada farmacia lo sigue decidiendo `Permissions::puede_ver_item_catalogo()` / `puede_ver_bloque_por_plan()` con el plan de la farmacia;
  - `CAP_ADMINISTRAR` (= `manage_options`): lo que sigue siendo solo del administrador: Avisos por email (interruptor de todo el sitio), Herramientas MDF › Ajustes («Permitir herramientas Excel con macros», v0.35.0), avisos de protección de la carpeta y de requisitos del servidor, y el aviso del robot retirado.
- Rol `mdf_gestor`: `read` + las cinco primeras (`Roles::CAPACIDADES_PLUGIN`), nada más (ni `upload_files`, `edit_posts`, `list_users`, `create_users` ni `promote_users`). El administrador recibe las cinco y no pierde nada. Migración `mdf_ca_rol_gestor_v1` (`Roles::registrar_gestor()`, idempotente con `remove_role` + `add_role`, y `conceder_capacidades_administrador()`); solo la API de roles, igual en MySQL y MariaDB. `uninstall.php` quita el rol y las capacidades `mdf_ca_*` del administrador
- Las invitaciones siguen creando siempre `mdf_cliente` (fijo en `Invitacion_Service`; ningún campo de la petición llega a `wp_insert_user()`). Nuevo: reinvitar una farmacia cuyo usuario vinculado no es `mdf_cliente` se rechaza sin generar clave (`mdf_ca_invitacion_usuario_no_cliente`), para que el gestor nunca dispare el restablecimiento de contraseña de una cuenta de personal
- `Admin_Inicio`:
  - «Inicio» (`admin.php?page=mdf-ca-inicio`): tarjetas de Subir facturas, Bandeja (N), Pendientes de publicar (N), Modelo 347, Alta de farmacia e Invitaciones, solo las que el usuario puede usar. El administrador también la tiene, junto a los menús del plugin;
  - vista simplificada (`Admin_Inicio::vista_simplificada()`: `CAP_BACKOFFICE` sin `CAP_ADMINISTRAR`): tras el login va a Inicio si no pidió una página concreta (`login_redirect`) y el Escritorio redirige a Inicio (`load-index.php`); el menú lateral es una lista blanca (Inicio, Documentos MDF, Farmacias MDF, Invitaciones MDF, Planes MDF, Herramientas MDF y Perfil), también frente a menús de otros plugins; la barra superior pierde logo, «Añadir», comentarios, edición, actualizaciones, buscador y paleta de comandos;
  - todo esto es presentación: lo que deniega es la capacidad. WordPress da 403 «no tienes permisos» en cualquier otra pantalla (500 en `user-edit.php` y las acciones de administrador, que es el `wp_die()` por defecto; «No se ha podido cargar» en una pantalla del plugin cuyo menú no se registra sin la capacidad).
- `Role_Restrictions` no cambia: solo saca de wp-admin a `mdf_cliente`
- Comprobado en local con un gestor y un cliente de prueba (borrados al terminar):
  - el gestor sube facturas, asigna desde la bandeja, publica, despublica, publica todos y deshace el lote, elimina, sube a mano, ve la vista previa y el historial, simula y genera un 347 y guarda sus ajustes, da de alta una farmacia, simula el importador e invita (correo en Mailpit), con el mismo resultado que el administrador y los eventos a su nombre;
  - el gestor no puede: plugins, temas, actualizaciones, usuarios (también `context=edit`, crear y editar por REST), ajustes (pantalla, `options.php` y `/wp/v2/settings`), entradas, páginas y medios (pantalla y REST), Planes, Catálogo, Avisos y sus `admin-post`, `install-plugin` por AJAX, darse `administrator` desde `profile.php` o `/wp/v2/users/me` (403 `rest_cannot_edit_roles`), ni invitar con otro rol (`role=administrator` en el POST crea `mdf_cliente`);
  - el cliente sigue fuera de wp-admin, su Documentación y su visor funcionan y el acceso cruzado da el mismo 404 que un id inexistente;
  - el administrador ve lo mismo que antes más «Inicio» (Chrome) y la regresión de todas las pantallas no da errores;
  - la migración repetida y la desinstalación (seguida de volver a migrar) dejan los roles como deben.

  **Catálogo y planes para el gestor** (v0.32.0, esquema 1.19.0, marca `mdf_ca_cap_catalogo_v1`: vuelve a registrar el rol con su lista completa y añade la capacidad al administrador; repetida deja los roles idénticos). Antes de dárselo se revisó que el gestor, sin `unfiltered_html`, no puede meter HTML en el área del cliente: el nombre se sanea con `sanitize_text_field` y se escapa al pintar, y el enlace solo admite http/https (un `javascript:` queda vacío). Comprobado en local con un gestor y dos farmacias de prueba (borrados al terminar):
  - el gestor crea, renombra (el slug no cambia) y borra planes, y no puede borrar uno en uso; crea, edita, reasigna y borra elementos del catálogo; y asigna el plan de cada farmacia con el importador;
  - con A en un plan y B en otro, cada cliente ve en Herramientas solo los elementos de su plan y los compartidos, y el elemento sin plan no lo ve nadie (el shortcode sigue pasando por `Permissions`);
  - el cliente sigue fuera (302 a Documentación en las pantallas y en las acciones de guardar y borrar, sin cambios en la BD); sin sesión, 400;
  - el gestor sigue sin acceso a Avisos por email, la protección de la carpeta y el aviso del robot;
  - regresión de todas las pantallas del plugin (gestor y administrador) y de las cuatro páginas del área del cliente, sin errores de PHP ni líneas nuevas en `debug.log`.

  Elementor no está instalado en local: queda cubierto por la capacidad (su editor exige `edit_posts`) y por la lista blanca del menú. **Falta mirar la vista del gestor en un navegador** (Chrome y Safari): en local solo se comprobó su HTML con curl, porque iniciar su sesión en el navegador exige escribir credenciales.

**Arrastrar y soltar archivos en las subidas** (#339; v0.26.0, sin cambio de esquema)
- Componente reutilizable `Admin_Zona_Archivos`:
  - `encolar()` encola `assets/js/admin-zona-archivos.js` y `assets/css/admin-zona-archivos.css` por `Recursos`;
  - `atributos( $tipos_en_texto, $max_bytes )` marca un `<input type="file">` para que el JS lo mejore.
- Uno o varios archivos según el atributo `multiple` del input. Con varios, lo nuevo se suma a lo que ya había, sin repetir. Está pensado para «Subir facturas» (#334)
- Modo lote (#334, `atributos( ..., $carpetas = true, $max_archivos )`, solo con `multiple`): carpetas soltadas, recorridas con `webkitGetAsEntry()` y `readEntries()` (por tandas de 100, como mucho 10.000 entradas), botón «Elegir carpeta» (input `webkitdirectory` sin `name`), ocultos y otros tipos ignorados y contados, lista resumida a partir de 10 con «Quitar todos», y la zona se bloquea mientras el input esté deshabilitado. El evento `mdf-ca-zona-vaciar` en el input la vacía. El CSS de wp-admin da `display` a `.button` y anula `hidden`: los botones se ocultan también con `style.display`
- Hoy en tres pantallas, de un solo archivo:
  - subida manual de Documentos MDF (PDF, imágenes y `.xlsx`, 20 MB: `Documento_Service::TAMANO_MAXIMO_BYTES`, que pasa a ser pública);
  - Excel del Modelo 347 (`.xlsx`, `Modelo_347_Excel_Lector::MAX_BYTES`);
  - importador CSV de farmacias (`.csv`, `Farmacia_Csv_Parser::TAMANO_MAXIMO_BYTES`).
- El formulario y el servidor no cambian. Lo soltado pasa al mismo input con `DataTransfer` (oculto a la vista, fuera del orden de tabulación y con `aria-hidden`), y el envío es el de siempre: mismo nonce, misma capacidad, misma validación. No hay ruta ni acción AJAX nueva
- Rechazo inmediato en el navegador, solo por comodidad (el servidor valida igual):
  - tipo que no está en el `accept` del input (por extensión o MIME);
  - más del tamaño máximo;
  - varios archivos en un input de uno;
  - carpetas (con `webkitGetAsEntry()`; sin esa API, un objeto de 0 bytes se rechaza como «vacío o carpeta»).

  Lo rechazado nunca se queda en el input, y lo elegido con el selector pasa por las mismas comprobaciones
- Soltar un archivo fuera de la zona, en cualquier parte de esas pantallas, se bloquea (`dragover` y `drop` en `document`) y se avisa en la zona
- Accesibilidad:
  - la zona es un `role="button"` con `tabindex=0`; Enter y espacio abren el selector;
  - su nombre accesible es la etiqueta del campo más la instrucción, y la descripción, los tipos, el tamaño y el mensaje;
  - el resultado y los errores se anuncian en un `role="status"`;
  - enviar sin archivo lo dice la zona y le da el foco: el input oculto no puede mostrar el aviso del navegador.
- Sin JS, o sin `DataTransfer` construible (Safari anterior a 14.1), el input se queda como siempre
- Probado en Chrome, con datos de prueba, en las tres pantallas:
  - envío arrastrando y con el botón, con el mismo resultado en el servidor: factura con fecha, 347 con ejercicio, Excel, simulación del 347 e importador en simulación;
  - rechazos de tipo, tamaño, dos archivos, objeto vacío y soltar fuera;
  - quitar y volver a elegir, orden de tabulación y HTML sin JS.

  El arrastre se simuló con eventos `DragEvent` reales y objetos `File`: falta arrastrar desde el Finder de verdad (sobre todo una carpeta, el cursor y que el navegador no abra el archivo soltado fuera) y probarlo en Safari

**Lectura de facturas en PHP** (#333; v0.25.0, sin cambio de esquema). Sale de la prueba de concepto #332
- `Factura_Lectura_Service::analizar( $ruta, $nombre_original )` devuelve el mismo array que `ResultadoFactura.a_dict()` de Python, más `paginas`, y nunca lanza. El resultado entra tal cual en `Factura_Cruce_Service::cruzar()`, igual que el del robot (probado: mismo cruce que con Python en las 90 sintéticas, también `asignada`), y también en el endpoint REST
- Por partes:
  - `Pdf_Parser_Acotado` (smalot/pdfparser 2.13.1, vendorizado en `lib/`) abre el PDF y resuelve objetos y fuentes;
  - `Pdf_Palabras` interpreta el contenido (con el lector propio `Pdf_Contenido`, CTM, Form XObjects y CropBox) y saca las palabras con caja como `page.get_text("words")`;
  - `Pdf_Fuente` y `Pdf_Metricas_Base14` dan anchos, métricas y Unicode;
  - `Factura_Sage_Parser` es el port línea a línea de `parser.py`.
- Reglas reutilizadas: sociedades y series de `Factura_Emisores`, CIF/NIF de `Identificador_Fiscal_Validator`. No contradicen las de Python, que son una copia; el plugin es más estricto en serie (≤ 3 letras), número (≤ 20 cifras) y fecha (rango), pero eso lo sigue aplicando el cruce, igual que con el robot
- Diferencias deliberadas con Python:
  - los motivos nunca llevan valores (Python cita serie y número en dos);
  - cifras solo ASCII;
  - un importe que no cabe en un entero de PHP no se extrae;
  - más de 50 páginas, más de 10 MB, un fichero sin `%PDF-` en el primer KB (p. ej. una imagen renombrada, que MuPDF abriría como `sin_texto`) y un PDF cifrado (aunque se abra sin contraseña, que Python sí lee) dan `formato_inesperado`.
- Topes (`Pdf_Limites`, una instancia por factura), revisados en cada camino de smalot:
  - 10 MB de fichero; 50 páginas (contadas antes de pedirlas, con tope de nodos del árbol); 20.000 objetos, incluidos los de un object stream, contados antes de que smalot los cree; 100.000 valores en diccionarios y arrays;
  - 16 MB descomprimidos por flujo y 48 MB en total. FlateDecode va por trozos de 4 KB con `inflate_add()` (`Pdf_Filtros_Acotados`), sin el `tmpfile()` de smalot;
  - xref streams: hasta 256 KB, `/W` en línea con anchos ≤ 8 y `/Columns` ≤ 64;
  - ToUnicode: 8 planos de 65.536 códigos (dompdf declara un plano entero por fuente);
  - contenido: 1.000.000 de operaciones, 64 operandos seguidos, arrays de 65.536 elementos y 32 niveles; 20.000 palabras por página; Form XObjects hasta 8 niveles, sin reentrar en uno que ya se está ejecutando, como MuPDF;
  - 10 s por factura y parada si la memoria se acerca a `memory_limit` a menos de 24 MB.
- Fallos de smalot encontrados y acotados (todos daban error fatal o bucle sin fin):
  - un ToUnicode `<00000000> <FFFFFFFF>` recorre 4.000 millones de códigos;
  - `unpack`/`array_fill` y los bucles de `/W` de un xref stream;
  - `Document::init()` cuenta las páginas sin tope (un árbol con nodos compartidos explota). Por eso `Pdf_Parser_Acotado::parseContent()` es una copia que usa `Pdf_Documento_Acotado`;
  - un `/Encrypt` en línea no se detecta y el PDF cifrado se lee como basura; ahora se detecta y se rechaza (`Pdf_Cifrado`);
  - además, ya en la #332: vacía un `TJ` que empieza por número.
- Causa del desfase de la primera prueba con reales (#332, x desviada casi un ancho de página con la misma vertical): un fallo real de posición del extractor antiguo con la matriz volteada. Todo el texto de SAGE va con CTM [1, 0, 0, -1, …] y el extractor antiguo lo colocaba mal. **No era un artefacto del emparejamiento**, como se supuso. El diagnóstico con las facturas reales dio «robusto: desviadas 100 %» con el extractor antiguo y 0 con el nuevo (`Pdf_Palabras`)

  Si se actualiza smalot, hay que revisar las subclases (ver `lib/LEEME.md`) y repetir la regresión.
- También corregido respecto a la prueba de concepto: MacRoman con `iconv` (`mb_convert_encoding` lanza `ValueError`), rangos de `/W` acotados a 16 bits y caché de fuentes por documento (la estática se podía equivocar entre dos PDF de la misma petición)
- Nada se ejecuta ni se incluye a partir del PDF ni se escribe en disco (probado con un `TMPDIR` propio). No registra nada: los avisos de PHP se silencian mientras dura la lectura y los motivos son textos fijos. Al terminar, `gc_collect_cycles()`: tras 340 análisis seguidos la memoria en uso sigue en 2,2 MB
- Rendimiento con sintéticas: de 7 a 30 ms y de 2 a 4 MB por factura; la carta de dompdf, 63 ms y 11 MB
- **Con las 6 facturas reales de SAGE, sobre el servicio del plugin:** `comparar.py` da 6 de 6 coincidencias campo a campo con Python, de 11 a 27 ms y 2,5 MB como máximo por factura. El diagnóstico de palabras da 0 desviadas
- Extensiones (`Requisitos_Servidor`, único sitio): lectura de facturas, zlib, mbstring, iconv y ctype; generador del 347, dom, mbstring, iconv, gd, zip, xmlreader, libxml, filter, fileinfo y ctype. Si falta alguna:
  - `Admin_Requisitos_Servidor` lo avisa en las pantallas del plugin y en Plugins (adonde vuelve WordPress tras activar), solo al administrador (`Roles::CAP_ADMINISTRAR`);
  - el 347 (`Modelo_347_Carta::pdf()` y `Modelo_347_Excel_Lector::leer()`) devuelve un `WP_Error` con el mensaje;
  - la lectura devuelve `formato_inesperado` con el mensaje.

  Para probarlo en local: filtro `mdf_ca_requisitos_faltan`
- `Librerias::cargar()`: un único autoload de `lib/` para dompdf, OpenSpout y smalot (`Modelo_347_Librerias::cargar()` delega en él)
- Regresión en `tests/lectura-facturas/` (no se despliega; sustituye a `tests/poc-facturas`, borrada). Todo sin WordPress salvo el cruce, y nunca imprimen valores salvo con los PDF maliciosos, que son sintéticos:
  - `comparar.py` (campos contra Python);
  - `comparar_palabras.py` (palabras contra PyMuPDF; empareja por fila y por la x más cercana, para que dos palabras repetidas en una fila no se crucen; con `--diagnostico`, informe por página);
  - `comparar_cruce.py` (cruce contra Python con wp-cli; `--crear-farmacias` solo en local);
  - `generar_maliciosos.py` y `probar_maliciosos.py` (30 casos, `memory_limit` 128M);
  - `fuzz.py` (1.500 variantes, en lotes de 25 por proceso).

  Resultado: 91 de 91 PDF campo a campo (sintéticas del parser, Chrome, dompdf, hipótesis y `~/Pruebas-robotito`), 90 de 90 palabra a palabra (el que falta no es un PDF) a 0,00 pt y en el mismo orden; maliciosos y fuzz sin ningún fallo (peor caso: 1,1 s y 55 MB)

**Marca de agua por documento** (#321; v0.34.0, esquema 1.20.0)
- Columna `documentos.sin_marca TINYINT(1) NOT NULL DEFAULT 0`: todo documento lleva marca salvo que se quite expresamente. La añade dbDelta con el valor por defecto, así que lo existente queda con marca (sin marca de migración propia)
- **El visor decide con el valor guardado**, nunca con un parámetro de la petición: `Documento_Visor::renderizar()` deja `marcaAgua` vacío si `Documento::is_sin_marca_de_agua()`, y el JS no pinta nada con el texto vacío. Lo mismo en la vista previa del administrador (`Admin_Publicacion_Documentos::gestionar_vista_previa()`): lo que ve MDF es lo que verá la farmacia. El resto del visor no cambia (canvas, sin descarga ni impresión, token de un solo uso, permiso en el servidor). La vista previa de la Bandeja de facturas sigue con su marca «BANDEJA» (todavía no es un documento)
- Dónde se elige, al dar de alta (desde la v0.36.0, casillas en positivo «Descargable» y «Marca de agua», que salen como **descargable sí y marca no**; ver «Descarga de documentos y una vía por tipo»):
  - subida manual: las dos casillas;
  - «Subir facturas»: las dos para toda la tanda, en el bloque de «Subir»; el JS las fija al pulsar «Subir» (también al continuar tras «Parar») y manda `marca_agua` y `descargable` (`1`/`0`) en cada factura; si no llegan, los valores por defecto. Las que van a la bandeja se deciden al asignarlas;
  - bandeja, al asignar: las dos (se conservan en una vuelta con error, como el resto del formulario). «Volver a comprobar todas» usa los valores por defecto (antes, siempre con marca);
  - Modelo 347, al confirmar la generación: las dos para todas las cartas; se guardan en el trabajo (transient) y no se vuelven a leer de las tandas AJAX.

  Todas pasan por `Documento_Service::sin_marca_de_agua( bool )` (opción de la instancia; `Factura_Recepcion_Service` la fija en cada llamada porque reutiliza su instancia) y `registrar()`: un alta sin marca abre transacción y deja, además del evento de alta, el evento `marca_quitada` con el mismo origen (`subida_backoffice`, `subida_facturas`, `bandeja` o `modelo_347`) y el usuario
- Después, en cualquier momento: columna «Marca de agua» en Documentos MDF (todos los documentos, pendientes o publicados) y en Pendientes de publicar, con «Quitar marca» / «Poner marca» (`parcial-marca-agua.php`). POST a `admin-post` `mdf_ca_cambiar_marca` con `Admin_Documentos::CAPACIDAD` y nonce por documento; vuelve a la misma pantalla con sus filtros. `Documento_Marca_Service::cambiar()`: transacción, `FOR UPDATE`, `UPDATE … WHERE sin_marca <> X` y evento `marca_quitada` o `marca_puesta` (origen `individual`); si ya estaba así, no cambia nada ni deja evento. El historial los muestra como «Marca de agua quitada» / «Marca de agua puesta»
- Publicar, despublicar, publicar todos, deshacer lote y eliminar no tocan el ajuste. El PDF adjunto del envío del 347 no cambia (la marca es del visor, no del fichero)
- Comprobado en local, como gestor y con datos de prueba:
  - un documento de cada vía con y sin marca (subida manual PDF y Excel, Subir facturas, bandeja y 347), con sus eventos;
  - visor del cliente con y sin marca en PDF y Excel; vista previa igual que el cliente; quitar y poner la marca de un documento publicado cambia el visor del cliente al momento;
  - el cliente no puede cambiarlo: el visor ignora `?sin_marca=1`, `?marcaAgua=` y similares; la acción le saca a Documentación (también con el nonce del gestor); sin sesión, 400; el gestor sin nonce, 403;
  - regresión: publicar todos y deshacer lote conservan el ajuste, eliminar (también uno sin marca), bandeja, 347 y las pantallas del plugin y del área del cliente sin errores
  - en Chrome (administrador): la vista previa pinta la marca en la carta del 347 y en el Excel con marca y no pinta nada en los sin marca; «Poner marca» con un clic en Pendientes; las casillas de la subida manual y de «Subir facturas» (que sale al elegir ficheros), desmarcadas por defecto. La del 347 y la de la bandeja se comprobaron por curl.

**Herramientas del catálogo** (v0.35.0, esquema 1.21.0). El catálogo se rehace y pasa a ser **solo Herramientas**
- **Formación** deja el catálogo: es una página de Elementor con un enlace a la Academia. En local la página no usaba el catálogo y el catálogo estaba vacío; producción no se pudo mirar desde aquí. `[mdf_ca_catalogo_herramientas seccion="formacion"]` no pinta nada; sin atributo, o con `herramientas`, pinta las herramientas. `Catalogo_Tipos` desaparece; `Catalogo_Secciones` queda solo para la migración y para rellenar `seccion` (NOT NULL)
- **Modelo** (sin FOREIGN KEY):
  - `wp_mdf_ca_catalogo` gana `descripcion`, `categoria_id`, `recurso` (`enlace`/`archivo`), `formato`, `ruta_fichero`, `tipo_mime`, `tamano`, `descargable`, `marca_agua` (DEFAULT 1), `visible` (DEFAULT 1) y `fecha_modificacion`. `seccion` y `tipo` ya no se leen;
  - tablas nuevas: `catalogo_categorias` y `catalogo_etiquetas` (nombre editable y **slug inmutable**, los dos UNIQUE; la collation no distingue mayúsculas ni tildes), `catalogo_item_etiquetas` y `catalogo_farmacias` (PK compuesta);
  - «Solo para farmacias concretas» no tiene columna: está marcada si la herramienta tiene farmacias, y desmarcarla vacía la lista;
  - `Catalogo_Repository` (herramientas y tablas puente; relaciones cargadas de todas a la vez), `Catalogo_Termino_Repository` (categorías y etiquetas, construido solo con `categorias()` o `etiquetas()`), `Catalogo_Service` y `Catalogo_Termino_Service`.
- **Quién la ve** (solo `Permissions`):
  - `puede_ver_item_catalogo()` / `filtrar_items_catalogo_visibles()`: visible, recurso completo, no es un `.xlsm` con el ajuste apagado, y (plan de la farmacia entre los suyos **o** farmacia en su lista). Las dos vías se suman; sin planes ni farmacias, nadie. Comparten el predicado `item_visible_para_farmacia()`; el plan se resuelve en `slug_plan_de_farmacia()`, también para `puede_ver_bloque_por_plan()`;
  - `puede_visualizar_item_catalogo()`: archivo PDF, `.xlsx`, JPG o PNG;
  - `puede_descargar_item_catalogo()`: archivo marcado como descargable, o Word, PowerPoint o `.xlsm` (siempre).
- **Formatos** (`Catalogo_Formatos`, lista fija): PDF, `.xlsx`, JPG y PNG van al visor; `.docx`, `.pptx` y `.xlsm` se descargan siempre, sin marca. 20 MB (`Catalogo_Archivo_Validator`, el mismo tope que los documentos). La extensión solo elige la comprobación; el contenido decide:
  - PDF: fileinfo `application/pdf`. JPG y PNG: fileinfo y `getimagesize()`. `.xlsx`: `Xlsx_Validator`;
  - `.docx`, `.pptx` y `.xlsm`: `Ooxml_Validator` (nuevo). Mismos topes anti zip bomb, sin DOCTYPE ni ENTITY y tipo de la parte principal por la regla OPC (`Xlsx_Validator::tipo_de_parte()` y `declara_dtd()` pasan a públicas). En docx y pptx rechaza `vbaProject.bin`, `vbaData.xml`, `activeX/` y cualquier incrustado que no sea `.xlsx`, `.docx` o `.pptx` (los gráficos de Office guardan así sus datos; un OLE `.bin` se rechaza). En los tres rechaza **relaciones externas** que no sean hipervínculos: una plantilla adjunta remota (`attachedTemplate`) es la vía habitual para colar macros en un docx sin macros. Límite asumido: no se abre un `.xlsx` incrustado para validarlo;
  - `.docm`, `.dotm`, `.pptm`, `.potm`, `.ppsm`, `.xlsb`, `.xltm` y `.xlam`, siempre rechazados con su mensaje; un `.docm` renombrado a `.docx` da «contiene macros»;
  - `.xlsm`: solo con el ajuste «Permitir herramientas Excel con macros» (`Catalogo_Ajustes`, option `mdf_ca_herramientas_permitir_xlsm`, **apagado por defecto**, solo el administrador). Al subirlo sale un aviso. Con el ajuste apagado, los ya subidos no los ve nadie (404) y el listado los marca.
- **Almacenamiento:** `Clientes/private-docs/herramientas/` (`Catalogo_Service::CARPETA_RELATIVA`), creada y protegida con `Carpeta_Privada_Proteccion::asegurar()`; si no existe, no se puede escribir o su `.htaccess` no deniega, no se guarda nada. Nombre generado `h-{32 hex}.{formato}`; `Catalogo_Service::ruta_en_carpeta()` solo acepta ese patrón. Al guardar con archivo nuevo: fichero nuevo, BD en transacción y, solo si confirma, se borra el antiguo (si falla, se borra el nuevo). Pasar de archivo a enlace también borra el antiguo. Eliminar: `rename` a `.eliminando-*`, `DELETE` de la fila y de las tres puentes, `COMMIT` y borrado (el patrón de `Documento_Eliminacion_Service`)
- **Entrega** (`Herramienta_Endpoint`, rewrite `^mdf-ca-herramienta/([0-9]+)/(ver|fichero|descargar)/?$`, flush con la marca `mdf_ca_herramientas_rewrite_v1`):
  - `ver/`: sin sesión, al login (como el visor de documentos); sin permiso, el 404 uniforme. Pinta `Documento_Visor::pintar()` (ahora público, con URL de descarga aparte y título). La marca (CIF y nombre de la farmacia que la abre) sale de lo guardado; ningún parámetro la quita ni fuerza la descarga;
  - `fichero/`: solo para el `fetch()` del visor, con un token de `Documento_Token` de **ámbito `herramienta`** (uno de documento o de la bandeja no vale) y nunca con `Sec-Fetch-Dest: document`. Siempre exige token, aunque la herramienta sea descargable;
  - `descargar/`: adjunto con el nombre de la herramienta y la extensión del formato (`filename*` UTF-8 y `filename` ASCII de respaldo, sin caracteres no válidos; desde la v0.36.0 lo escribe `Descarga_Fichero`, compartido con los documentos). La marca es del visor: «descargable + marca» se ve con marca y se descarga sin ella, a propósito;
  - el visor enseña ahora «Descargar» también con PDF e imágenes (antes solo con Excel). En documentos no cambia nada: solo el Excel puede ser descargable.
- **Backoffice** (Herramientas MDF, `CAP_GESTIONAR_CATALOGO`, nonce por acción y por herramienta o término; vistas `admin-catalogo*.php` y `parcial-aviso-catalogo.php`):
  - **Herramientas**: listado (categoría y etiquetas, recurso y configuración, «Visible para» con planes y «N farmacias concretas», estado y avisos: falta el enlace, `.xlsm` con el ajuste apagado, sin planes ni farmacias) y formulario aparte (`&accion=nueva`, `&editar=ID`). Orden: nombre, descripción, categoría, etiquetas, recurso, configuración, visualización y estado; el recurso va antes que la configuración porque la decide. Si falla, se conserva lo rellenado (transient del usuario, 5 min; el archivo hay que volver a elegirlo). Eliminar: POST con confirmación;
  - `admin-catalogo.js`: oculta el bloque que no aplica y la configuración con un enlace; con `.docx`, `.pptx` o `.xlsm` (el elegido o el actual) fija «Descargable» y quita «Marca de agua», y lo explica; muestra el aviso del `.xlsm`; avisa si no hay planes ni farmacias. Etiquetas con autocompletado y fichas; Enter nunca crea una ni envía el formulario. Sin JS todo se ve, las etiquetas son casillas y las farmacias elegidas se quitan desmarcándolas;
  - selector de farmacia en **modo múltiple** (`$selector_multiple` en el parcial, `data-mdf-ca-multiple`): cada elegida pasa a una lista de casillas `farmacia_ids[]` con «Quitar»;
  - **Categorías** y **Etiquetas**: crear, renombrar (el slug no cambia y se muestra «para MKT») y borrar. Una categoría en uso no se puede borrar (ni botón ni servicio; el `DELETE` lo repite en SQL con `NOT EXISTS`); una etiqueta en uso, sí, tras una pantalla «Se quitará de N herramientas», en una transacción;
  - **Ajustes**: solo el administrador.
- **Área de cliente** (`[mdf_ca_catalogo_herramientas]`), HTML para MKT. Cambian las clases: las antiguas `mdf-ca-catalogo-herramientas*` desaparecen.

  ```html
  <div class="mdf-ca-herramientas">
    <section class="mdf-ca-herramientas__categoria" data-categoria="{slug}">
      <h2 class="mdf-ca-herramientas__categoria-titulo">{Categoría}</h2>
      <ul class="mdf-ca-herramientas__lista">
        <li class="mdf-ca-herramienta" data-categoria="{slug}" data-etiquetas="{slug1 slug2}" data-tipo="enlace|pdf|xlsx|xlsm|docx|pptx|jpg|png" data-ficha="mdf-ca-ficha-{id}">
          <h3 class="mdf-ca-herramienta__nombre"><button type="button" class="mdf-ca-herramienta__abrir-ficha" aria-haspopup="dialog" aria-controls="mdf-ca-ficha-{id}">{Nombre}<span class="mdf-ca-herramienta__oculto">, más información</span></button></h3>
          <p class="mdf-ca-herramienta__descripcion">{Descripción}</p>        <!-- solo si la tiene -->
          <ul class="mdf-ca-herramienta__etiquetas">                          <!-- solo si tiene -->
            <li class="mdf-ca-herramienta__etiqueta" data-etiqueta="{slug}">{Etiqueta}</li>
          </ul>
          <div class="mdf-ca-herramienta__acciones">
            <a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--abrir" target="_blank" rel="noopener noreferrer">Abrir</a>
            <a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--ver">Ver</a>
            <a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--descargar">Descargar</a>
          </div>
          <dialog class="mdf-ca-ficha" id="mdf-ca-ficha-{id}" tabindex="-1" aria-labelledby="mdf-ca-ficha-{id}-titulo">  <!-- v0.43.0 -->
            <div class="mdf-ca-ficha__caja">
              <div class="mdf-ca-ficha__cabecera"><span class="mdf-ca-ficha__tipo">{PDF|Excel|Enlace web…}</span><button type="button" class="mdf-ca-ficha__cerrar" aria-label="Cerrar">{svg}</button></div>
              <h2 class="mdf-ca-ficha__titulo" id="mdf-ca-ficha-{id}-titulo">{Nombre}</h2>
              <p class="mdf-ca-ficha__texto">{Descripción detallada, o la breve si no hay}</p>  <!-- si hay alguna -->
              <ul class="mdf-ca-herramienta__etiquetas">…</ul>                                     <!-- solo si tiene -->
              <div class="mdf-ca-herramienta__acciones mdf-ca-ficha__acciones">{los mismos botones}</div>
            </div>
          </dialog>
        </li>
      </ul>
    </section>
  </div>
  <p class="mdf-ca-herramientas mdf-ca-herramientas--vacio">No hay herramientas disponibles.</p>  <!-- si no ve ninguna -->
  ```

  Categorías y herramientas por nombre. «Abrir» solo en enlaces; «Ver» y «Descargar», según `Permissions`. Los slugs no cambian al renombrar: el buscador y los filtros de categoría y etiqueta (no hechos todavía) pueden apoyarse en `data-categoria`, `data-etiquetas` (separadas por espacios: `[data-etiquetas~="slug"]`) y `data-tipo` sin tocar el modelo. Nunca se pintan planes ni farmacias
- **Migración** (`Activator::migrar_catalogo_a_herramientas()`, marca `mdf_ca_herramientas_v1`, también en `activate()`): solo filas con `categoria_id IS NULL`, todas como enlace y con sus planes (la puente no se toca): las de Formación, **ocultas** en «Formación (antigua)» (`formacion-antigua`); las que no tienen enlace, **ocultas** en «General» (`general`), y el listado las marca para completarlas; el resto, visibles en «General». Las categorías se crean por slug solo si hacen falta. Solo `INSERT` y `UPDATE` simples; repetida no cambia nada
- **Comprobado en local** con datos ficticios (borrados al terminar; el catálogo local queda vacío, como estaba):
  - migración con cuatro elementos antiguos (Formación, enlace con dos planes, sin enlace, enlace de un plan), repetida sin cambios;
  - una herramienta de cada formato y un enlace, con las combinaciones de configuración; el Word mandado como «no descargable y con marca» queda descargable y sin marca;
  - rechazos: `.xlsm` con el ajuste apagado, `.docm`, `.pptm`, docm y xlsm renombrados, PNG renombrado a PDF, docx con plantilla externa, docx con DOCTYPE, pptx con OLE, `.txt`, enlace `javascript:` y `ftp:`, sin archivo, sin categoría, etiqueta, categoría o farmacia inexistentes y sin nombre. Admitidos: pptx con un `.xlsx` incrustado y docx con un hipervínculo;
  - `.xlsm` con el ajuste encendido: siempre descargable y sin marca, con aviso; al apagarlo, 404 y marcado en el listado; el ajuste, 403 para el gestor y sin nonce;
  - farmacia A (plan básico), B (premium) y C (sin plan, elegida a mano): cada una ve solo lo suyo y en las 17 herramientas (visibles, ocultas, sin planes, Formación) ver, fichero y descargar dan lo esperado y 404 en lo demás (68 comprobaciones, también sin sesión);
  - visor: marca con el CIF y el nombre de quien la abre, vacía si la herramienta no la lleva, ignorando `?sin_marca=1` y similares; token de un uso, que no vale para otra herramienta, para otro usuario ni con `Sec-Fetch-Dest: document`, y un token de documento con el mismo id tampoco;
  - sustituir el archivo (el antiguo desaparece del disco), editar sin archivo (se conserva), pasar a enlace (se borra) y eliminar (fichero y filas, sin apartados); carpeta y fichero directos, 403;
  - categorías y etiquetas: repetidas sin distinguir mayúsculas ni tildes, renombrar sin cambiar el slug, categoría en uso no borrable, etiqueta en uso con confirmación;
  - acciones sin nonce (403), con el nonce de otra herramienta (403), como cliente (302 a Documentación), sin sesión (400) y con una clase de término inventada (400); HTML escapado (nombre, descripción y enlace hostiles) y ninguna mención a farmacias ni planes en el HTML del cliente;
  - regresión: visor y endpoint de documentos (PDF y Excel, token, acceso ajeno), Excel descargable de documentos, vista previa del administrador, plan en uso no borrable, todas las pantallas del plugin como administrador y gestor y las cuatro páginas del área de cliente, sin errores ni líneas nuevas en `debug.log`;
  - en Chrome (administrador): listado, formulario (configuración forzada con `.docx` y `.xlsm`, `.docm` rechazado en la zona, etiquetas con teclado, selector múltiple por CIF y por nombre, aviso de «no la verá nadie», ocultar con enlace). Como farmacia, con un enlace de un solo uso de un mu-plugin temporal fuera del repo (borrado): Herramientas por categoría, imagen y PDF con marca, Excel y PNG descargables con «Descargar», 404 en una ajena y el visor de documentos sin cambios.

  **Falta:** Safari; arrastrar un archivo real desde el Finder al formulario; comprobar en producción si hay elementos de Formación o sin enlace antes de desplegar (quedarán ocultos); y que MKT maquete Formación en Elementor y dé estilo a las clases nuevas

**Descarga de documentos y una vía por tipo** (v0.36.0, sin cambio de esquema). Dos decisiones de MDF
- **Decisión 1: los documentos son descargables por defecto.** Cualquier formato (PDF, Excel o imagen) puede ser descargable, por documento, y es lo predeterminado. Sustituye la regla de la Fase 3 («solo un Excel, y no por defecto»)
  - **Regla** (`Permissions`): `puede_descargar_documento()` = puede verlo y `is_descargable()`. `filtrar_documentos_descargables()` hace lo mismo para el listado, resolviendo la farmacia una sola vez;
  - **entrega** (`Documento_Endpoint`):
    - `/mdf-ca-documento/{id}/` es solo para el `fetch()` del visor: token de un solo uso **siempre** y nunca `Sec-Fetch-Dest: document`;
    - `/mdf-ca-documento/{id}/descargar/` (rewrite nuevo, flush con la marca `mdf_ca_documento_descarga_rewrite_v1`) sirve el adjunto solo si `Permissions` lo permite; si no, el 404 uniforme;
    - ningún parámetro lo cambia: `?mdf_ca_documento_descargar=1` en un no descargable da 404, y por `index.php?…` hay una redirección canónica y luego 404;
  - **nombre del fichero:** `Descarga_Fichero` (nuevo, compartido con `Herramienta_Endpoint`) escribe el nombre visible del documento más la extensión de su MIME (pdf, xlsx, jpg, png, webp), con `filename*` UTF-8 (tildes) y `filename` ASCII de respaldo, sin repetir la extensión. También hace nosniff, nocache y realpath + prefijo;
  - **visor:** «Descargar» solo si `Permissions` lo permite, apuntando a `/descargar/`. La vista previa del administrador no ofrece descarga;
  - **listado de Documentación**, para MKT: junto al nombre de cada documento descargable, en las dos vistas (plana y agrupada), `<a class="mdf-ca-listado-documentos__descargar" href="…/mdf-ca-documento/{id}/descargar/" aria-label="Descargar {nombre}">Descargar</a>`. Si no es descargable, no aparece.
- **Configuración por defecto: descargable sí, marca no.** Un único sitio, `Documento_Service::POR_DEFECTO_DESCARGABLE` y `POR_DEFECTO_SIN_MARCA`:
  - en las cuatro vías las casillas salen así (ver «Marca de agua por documento»);
  - las vías sin formulario usan esos valores: «Volver a comprobar todas» de la Bandeja, y `recibir()` y `asignar_manual()` cuando no se les pasa nada;
  - `Documento_Service::descargable( bool )`, como `sin_marca_de_agua()`.
- **Eventos al dar de alta:** un alta **no descargable** deja `descarga_desactivada` en su transacción, igual que un alta sin marca deja `marca_quitada`.
- **Cambiar después:** desde Documentos MDF (columna «Descarga») y Pendientes, con `parcial-descarga.php` junto a `parcial-marca-agua.php`:
  - acción `mdf_ca_cambiar_descarga`, con `Admin_Documentos::CAPACIDAD` y nonce por documento;
  - `Documento_Configuracion_Service` (antes `Documento_Marca_Service`) con `cambiar_marca()` y `cambiar_descarga()`: transacción, `FOR UPDATE`, UPDATE condicionado y evento `descarga_activada` / `descarga_desactivada` (origen `individual`); si ya estaba así, ni cambio ni evento;
  - el historial los muestra como «Descarga activada» / «Descarga desactivada»;
  - publicar, despublicar, deshacer lote y eliminar no tocan el ajuste.
- **Migración** (`mdf_ca_documentos_descargables_v1`, también en `activate()`): `UPDATE … SET descargable = 1 WHERE descargable = 0` (`Documento_Repository::marcar_todos_descargables()`):
  - la marca de cada documento no se toca;
  - sin evento por documento, porque es un cambio masivo de configuración pedido por MDF; el historial no lo refleja;
  - repetida no cambia nada;
  - el DEFAULT 0 de la columna se deja: toda alta fija el valor expresamente.
- **Decisión 2: cada tipo tiene una sola vía de entrada.**
  - **vías:** facturas por «Subir facturas» (también una sola; lo que no se puede asignar va a la Bandeja); Modelo 347 por su generador; contratos, presupuestos, entregables y otros por la subida manual;
  - **formulario manual:** el selector solo ofrece esos cuatro tipos (`Documento_Tipos::get_opciones_subida_manual()`), con el texto «Para subir facturas usa Subir facturas (también sirve para una sola); para el Modelo 347, usa Modelo 347» y sus enlaces. Fuera ejercicio, sociedad, la fecha obligatoria de factura y `assets/js/admin-documentos.js`;
  - **servidor:** `Documento_Service::subir()` rechaza `factura` y `modelo_347` (`TIPOS_CON_VIA_PROPIA`) con un mensaje que remite a su vía, aunque se fuerce el tipo. Pierde `$ejercicio` y `$emisor_cif`, y `guardar()` su `$modelo_347`;
  - **se conservan**, porque los usan el generador y la recepción: `validar_modelo_347()`, `nombre_modelo_347()`, `guardar_modelo_347_generado()`, `subir_factura_recibida()`, la unicidad del 347 en `registrar()` y «Retirar para corrección»;
  - los documentos de esos tipos que ya existían no cambian.
- **Comprobado en local** con datos ficticios (borrados al terminar):
  - **migración:** con 36 documentos, 2 de ellos descargables: los 36 descargables, la marca idéntica (huella de `id:sin_marca`) y ningún evento; repetida, igual;
  - **descarga:** como farmacia, PDF, Excel e imagen, descargables y no:
    - en el listado y el visor, «Descargar» solo en los descargables;
    - descarga con el nombre con tildes en `filename*` y la extensión correcta;
    - 404 al forzar: un no descargable por `/descargar/`, con parámetros o por `index.php`; el de otra farmacia; sin sesión; un id inexistente; el endpoint sin token o con `Sec-Fetch-Dest: document`;
  - **cambio de descarga:** en Documentos MDF y en Pendientes, con su evento en el historial; repetirlo avisa «ya era…»; sin nonce, 403; como cliente, fuera; sin sesión, 400;
  - **valores por defecto en las cuatro vías:**
    - subida manual: presupuesto, contrato y entregable, con su evento `marca_quitada`, y un «otro» sin descarga y con marca, con `descarga_desactivada`;
    - Subir facturas: con las casillas, y sin ellas como un JS antiguo;
    - Bandeja: al asignar, conservándolas tras un error; con valores cambiados; y en «Volver a comprobar todas»;
    - 347: generar con un Excel de una fila, retirar y volver a generar con otros valores;
  - **una vía por tipo:** factura y 347 forzados en la petición, rechazados; Subir facturas con una sola factura (entra) y una sin texto (Bandeja);
  - **regresión:** publicar los de una farmacia (conserva la configuración), deshacer un lote, eliminar, la descarga de herramientas por `Descarga_Fichero`, todas las pantallas como administrador y gestor, y las cuatro páginas del cliente, sin errores;
  - **en Chrome:** el formulario manual, «Quitar descarga» / «Permitir descarga» con clic real, y como farmacia (enlace de un solo uso, mu-plugin temporal fuera del repositorio, borrado) el listado con «Descargar» y el visor con y sin descarga.

  **Falta:** Safari (descargar con tildes y desde el visor) y avisar a MKT de la clase `mdf-ca-listado-documentos__descargar`

**Endurecimiento: descubrir usuarios y versión** (#329, parte de código; v0.33.0, sin cambio de esquema). Los nombres de usuario de las farmacias son su CIF/NIF: descubrir usuarios desde fuera revela la cartera de clientes. Todo en `Endurecimiento`, salvo el `.htaccess` del plugin
- Antes (sin sesión, en local): `/wp/v2/users` listaba al administrador y `/users/<id>` daba 401 si el usuario existía y 404 si no; `?author=1` redirigía a `/author/<login>/`, y `?author=N` y `/author/<slug>/` daban 200 si existía y 404 si no (con farmacias, el slug es su CIF); feed y oEmbed con el autor; XML-RPC vivo (79 métodos); versión de WordPress en `generator` y en los `ver=` del front y del login; `X-Powered-By` con la de PHP; login y «He olvidado mi contraseña» con mensajes distintos si existía el usuario o el correo; las carpetas del plugin listaban sus ficheros
- Ahora:
  - **REST:** sin `list_users`, las rutas `/wp/v2/users*` no existen (filtro `rest_endpoints`): mismo 404 `rest_no_route` para cualquier id, también `/users/me` y por `?rest_route=`, y fuera del índice. Con sesión, igual para el cliente y el gestor; el administrador las sigue teniendo (y gestiona usuarios en wp-admin, que no las usa);
  - **autores:** `?author=`, `/author/<slug>/` y sus feeds dan el mismo 404 exista o no el usuario, sin la redirección canónica (`template_redirect` con prioridad 0); `author_link` apunta a la portada;
  - **mapas del sitio:** sin el proveedor `users` (en local están apagados porque el sitio desaconseja la indexación; en producción podrían estar activos). **Feeds:** el autor es el nombre del sitio. **oEmbed:** sin `author_name` ni `author_url`;
  - **login:** usuario inexistente, correo inexistente y contraseña incorrecta dan el mismo error (`mdf_ca_credenciales_incorrectas`, filtro `authenticate` con prioridad 99); los demás (campo vacío) no revelan nada y no cambian. El área de cliente usa el login nativo: no hay otro formulario;
  - **recuperar contraseña:** si el usuario o el correo no existen, la misma redirección a «revisa tu correo» que si existen, sin enviar nada (`lostpassword_post`, solo en `wp-login.php`). El enlace con clave inválida ya daba lo mismo;
  - **XML-RPC:** `xmlrpc.php` responde 403 antes de cargar el servidor XML-RPC; además `xmlrpc_enabled` a false, sin métodos, sin cabecera `X-Pingback` ni enlace RSD;
  - **versión:** fuera el `generator` (HTML y feeds). El `?ver=<versión de WordPress>` de los recursos del front y del login se sustituye por una huella (`wp_hash`) que cambia al actualizar, para no romper la caché. En el login se desactiva la concatenación de `load-styles.php`/`load-scripts.php`, que llevaba la versión sin pasar por los filtros. Fuera `X-Powered-By`. En wp-admin no se toca (la versión solo la ve quien tiene sesión);
  - **carpetas del plugin:** el `.htaccess` del plugin devuelve 403 a cualquier carpeta (`RewriteCond %{REQUEST_FILENAME} -d`), con `mod_rewrite` y no con `Options -Indexes`, que exige `AllowOverride Options` y, si el hosting no lo permite, daría 500 en todos los recursos. Los ficheros (JS, `.mjs` de PDF.js, CSS) se sirven igual.
- Revisado además sin sesión, sin cambios: comentarios por REST (solo el de ejemplo de WordPress, que la #329 ya prevé borrar), páginas y entradas por REST (llevan el id numérico del autor, que ya no se puede resolver), registro cerrado, carpeta privada 403, el AJAX `nopriv` del plugin 404, `readme.html` sin número de versión
- **Fuera del plugin (pasos a mano en producción):** `/wp-content/uploads/` y sus subcarpetas listan su contenido en local (solo medios públicos, ningún documento de farmacia): comprobar en IONOS y, si pasa, `Options -Indexes` en el `.htaccess` de la raíz (fuera de lo que despliega `deploy.sh`), si el hosting lo permite. En ese mismo `.htaccess`, la línea `RewriteRule .* - [E=HTTP_AUTHORIZATION:…]` era para el robot (#336) y ya se puede quitar. `readme.html` y `license.txt` de la raíz se pueden borrar (WordPress los vuelve a poner al actualizar)
- Comprobado en local antes y después, sin sesión y con sesión de cliente, gestor y administrador: cada punto de arriba; el administrador gestiona usuarios (`users.php`, `user-edit.php`, `user-new.php`); el gestor invita (el usuario creado es `mdf_cliente`, con su correo en Mailpit); recuperar contraseña envía correo solo a cuentas que existen; el filtro de login deja pasar al usuario autenticado de los tres roles con su destino de siempre (sin escribir contraseñas: **falta iniciar sesión de verdad con cada rol en el navegador**); y la regresión de todas las pantallas del plugin, del área del cliente y del visor, sin errores ni líneas nuevas en `debug.log`

**Protección de la carpeta privada** (v0.22.0)
- `Carpeta_Privada_Proteccion::asegurar()` crea solo lo que falta (carpeta, `.htaccess` de denegación para Apache 2.4 y 2.2, `index.php`, con 0644) abriendo con `fopen('x')`, de modo que nunca sobrescribe. `sondear()` pide por HTTP (`site_url()`, timeout 5 s, sin redirecciones, `sslverify`) una sonda `.txt` temporal que borra siempre, y limpia las huérfanas de más de 10 minutos
- Solo se da por protegida si el `.htaccess` deniega y la sonda da 403 o 404. Alarma roja solo con prueba (200 con el contenido exacto de la sonda); cualquier otro resultado es aviso suave. Un `.htaccess` existente que no deniega no se toca y se avisa. Caché de 12 h si está bien y 1 h si no. `maybe_upgrade` solo crea lo que falta; la sonda corre en la primera pantalla de administración del plugin. Decisión: solo avisar, sin bloquear la subida
- `.htaccess` en la raíz del plugin que deniega `md`, `lock`, `sh`, `yml`, `yaml`, `log` y (desde #307) `docx`, como segunda capa frente a ficheros de trabajo subidos por error

## Estado

- Fases 0 a 3 cerradas con batería de seguridad pasada
- **Fase 4 desplegada en producción el 08/10/2026** (plugin 0.22.0, esquema 1.12.0) y batería de cierre superada: acceso cruzado, sin sesión, enlace directo, cliente fuera del backoffice, API de páginas, ficheros de trabajo no públicos, robot limitado a su ruta (el robot se retiró después, en la #336) y revisión de código sin hallazgos de gravedad alta
- Etiquetas de Git: `fase3-prod` (último estado de la Fase 3) y `fase4-prod` / `fase4-327`
- Copia de archivos (`backup.sh`, repo `deploy-script`): `wp-content` y `Clientes/private-docs`, en `~/Backups-MDF/archivos` con permisos 700. La base de datos la copia IONOS a diario

Pendientes:
- **#307 Generador de Modelo 347**: v0.23.0 (soporte en Documentación) y v0.24.0 (generador desde el Excel de SAGE, solo Mediformplus) hechas y verificadas en local, sin desplegar. Antes de usarlo en producción:
  - configurar en Ajustes, por sociedad, la razón social, la dirección, la firma (opcional), el correo de Administración, el Reply-To y la dirección de prueba;
  - confirmar el límite del SMTP de IONOS;
  - probarlo con el Excel real (lo valida la persona de MDF);

  Formación (v0.37.0): hecha y verificada en local, sin desplegar; falta el texto definitivo de su carta y su logo, rellenar sus Ajustes y Safari. El modo proveedores queda descartado (decisión de MDF). Avisar a MKT de las clases del grupo Modelo 347
- **#329 Endurecimiento menor:** página de ejemplo y temas inactivos, actualizar WP Mail SMTP, retoques de `deploy.sh`, y **borrar los documentos de prueba** de las farmacias piloto (incluido `sol.pdf`, de la Fase 1) antes de que MDF use el portal. El mensaje de login del robot y el índice REST de `mdf-ca/v1` quedaron resueltos con la #336. La parte de código (descubrir usuarios, XML-RPC, versión y listados del plugin) está hecha en la v0.33.0, sin desplegar; quedan los pasos a mano de producción de su sección (listado de `uploads`, línea del robot en el `.htaccess` de la raíz, `readme.html` y `license.txt`) y el documento antiguo de la Fase 1, que se borra tras desplegar la 0.32.1
- **#332/#333 Lectura de facturas en PHP:** hecha y probada con sintéticas y con 6 facturas reales: 6 de 6 campo a campo, 0 palabras desviadas (ver «Lectura de facturas en PHP»). Falta:
  - revisar la licencia LGPL-3.0 de smalot con la de PyMuPDF en la Fase 5.

  «Subir facturas» (#334) y la Bandeja de facturas (#335, v0.29.0) , la retirada del robot y de la ruta REST (#336, v0.30.0) y el rol Gestión MDF (#337, v0.31.0, con Catálogo y planes en la v0.32.0) hechos y verificados en local, sin desplegar
- **Estado de los presupuestos** (v0.44.0, esquema 1.23.0): hecho y verificado en local, sin desplegar. Al desplegar: poner el correo del responsable en Documentos MDF > Presupuestos (el de la persona que lleva los presupuestos; MDF ya lo tiene) y revisar con MDF los textos de la confirmación al cliente («Nuestro equipo se pondrá en contacto contigo…»). Los presupuestos ya subidos quedan pendientes: marcar como aceptados los que ya lo estén
- **Ventana de cada herramienta** (v0.43.0, esquema 1.22.0): hecho y verificado en local, sin desplegar. Al desplegar, la migración solo añade la columna. Falta Safari y avisar a MKT del campo nuevo y del HTML de la ventana
- **Visor de documentos con la imagen del área** (v0.41.0, sin cambio de esquema): hecho y verificado en local, sin desplegar. Falta PDF de varias páginas, Excel de varias hojas, vista previa del administrador y Safari. Datos sintéticos de prueba de la farmacia 14 en `tests/datos-sinteticos/` (con su script de limpieza)
- **Pantalla de acceso y menú de perfil** (v0.40.0, sin cambio de esquema): hecho y verificado en local, sin desplegar (ver su sección). Falta ver un error de login, la pantalla de elegir contraseña de una invitación y Safari
- **Portada del área y una página por tipo de documento** (v0.39.0, sin cambio de esquema): hecho y verificado en local, sin desplegar (ver su sección). Falta comprobar con sesión de farmacia el menú de perfil y el destino tras el login, y llevar a producción los diseños de Elementor y los medios
- **Motivo de los correos fallidos** (v0.38.0): hecho, sin desplegar (ver su sección). Tras desplegar, «Enviar ahora» en Avisos por email dirá el motivo del fallo que quedó pendiente
- **Modelo 347 de Formación** (v0.37.0, sin cambio de esquema): hecho y verificado en local, sin desplegar. Falta el texto definitivo y el logo de la carta (Luis), rellenar los Ajustes de las dos sociedades en producción, la duda de RECC con Administración y Safari
- **Descarga de documentos y una vía por tipo** (v0.36.0, sin cambio de esquema): hecho y verificado en local, sin desplegar (va junto con la 0.35.0, tampoco desplegada). Falta Safari y avisar a MKT del enlace «Descargar» del listado
- **Herramientas del catálogo** (v0.35.0, esquema 1.21.0): hecho y verificado en local, sin desplegar. Antes de desplegar, mirar en producción el catálogo actual (elementos de Formación y sin enlace quedarán ocultos). Falta Safari y avisar a MKT del HTML nuevo y de la página de Formación en Elementor
- Sección de Cuota (tarea #254): a la espera del contenido de MKT
- Avisar a MKT de las clases CSS del listado agrupado

## Batería de cierre de fase

Se ejecuta al final de cada fase. Si algo falla, la fase no está cerrada:

- Acceso cruzado: con sesión de la farmacia A, intentar alcanzar recursos de la B manipulando identificadores (documentos y herramientas del catálogo: `ver`, `fichero` y `descargar`). Debe fallar siempre, con respuesta idéntica a la de un id inexistente.
- Sin sesión: ningún recurso privado accesible. Ni documentos, ni endpoints, ni datos vía API de WordPress.
- Ningún documento privado alcanzable por enlace directo (carpeta y fichero de `Clientes/private-docs` dan 403 o 404).
- Los usuarios cliente no pueden entrar a `wp-admin` ni acceder a datos de otros por ninguna vía.
- El gestor (`mdf_gestor`) no puede escalar privilegios: ni por URL directa, ni por POST manipulado, ni por REST puede gestionar usuarios o roles (tampoco desde su perfil), plugins, temas, ajustes, entradas o páginas, ni abrir las pantallas técnicas (Avisos por email, protección de la carpeta, requisitos del servidor); y las invitaciones solo crean `mdf_cliente`.
- Sin sesión no se pueden descubrir usuarios: `/wp-json/wp/v2/users` y `/users/<id>` dan el mismo 404 (y con sesión, solo el administrador las tiene); `?author=N` y `/author/<slug>/` dan 404 exista o no el usuario, sin redirigir; los mapas del sitio no tienen usuarios; feeds y oEmbed no llevan autor; el login y «He olvidado mi contraseña» responden igual exista o no el usuario o el correo; `xmlrpc.php` da 403; la versión de WordPress no sale en el HTML, los feeds, el login ni las cabeceras; y las carpetas del plugin no listan su contenido.
- No hay rutas REST propias (`/wp-json/mdf-ca/v1/` da 404 y el índice `/wp-json/` no menciona `mdf-ca`) y las contraseñas de aplicación están desactivadas (una petición con Basic Auth y una contraseña válida se rechaza).
- Ningún fichero de trabajo del plugin (`.md`, `docs/`, `.htaccess`) se sirve públicamente.
- Las comprobaciones de permiso pasan por la función centralizada, no por lógica dispersa (revisión de código de solo lectura).
- Decisiones de arquitectura de la fase documentadas.

## Fuera de alcance ahora (no construir todavía)

- Tokens y su ecommerce específico (aplazado, fuera del MVP)
- Pasarela de pago online y herramientas de pago
- Integración con CRM (MDF aún no lo ha seleccionado)
- SSO con "la Academia" (viabilidad pendiente de datos de MDF; alternativa si no es viable: credenciales sincronizadas en el alta, no sesión compartida)

## Fase 5 (siguiente)

Revisión RGPD (tabla de eventos, plazo de borrado de la Bandeja de facturas, copias de excepciones y de backup, plazos de conservación, encargado de tratamiento, política de privacidad, licencia AGPL de PyMuPDF si el parser de Python se sigue usando para pruebas), documentación técnica de traspaso, plan de mantenimiento (Luis y Víctor; copia de archivos manual: quién, cuándo y cuánto se conserva; WP-Cron frente a cron de IONOS; SPF y DKIM), formación a MDF y cuentas individuales de backoffice para cada persona de MDF (con el rol Gestión MDF de la #337).