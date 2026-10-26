# DESIGN.md — Área de Clientes MDF

Contexto de diseño para maquetar en Elementor el Área de Clientes de Mediformplus SL (MDF). Lo usan MKT y cualquier agente que construya páginas con el MCP de Elementor.

El área va a vivir dentro de la web de Mediform, <https://www.mediformplus.com/>, que es la referencia visual. La lógica (permisos, documentos, catálogo, planes) está en este plugin; ver `CLAUDE.md`.

> **Estado: PROVISIONAL.** La identidad de MDF (logo, colores y tipografías) la define MKT y todavía no la tenemos. Hay que pedírsela a Víctor o a MKT antes de dar nada por cerrado. Todo valor marcado como `PROVISIONAL` se cambiará cuando llegue.

---

## 1. Identidad

### Logo

- **Sin logo entregado.** Usar un hueco reservado con el texto «MDF» en la tipografía provisional.
- La web usa varias versiones del logo (para fondo claro y oscuro, y de 2025 y 2026, a juzgar por los nombres de archivo). Hay que pedir a MKT cuál corresponde al área de clientes y en qué formato (preferible SVG).

### Colores — `PROVISIONAL`

Valores observados en el HTML público de mediformplus.com el 24/10/2026. No son una paleta entregada por MKT.

Los colores globales del kit de Elementor de esa web (`#6EC1E4`, `#54595F`, `#7A7A7A`, `#61CE70`) son los que trae Elementor de serie. **No son de marca: no usarlos.**

**El color principal es el verde** (decisión del 24/10/2026). El morado no se usa en el área: un solo color de marca y neutros.

| Token | Valor | Origen | Uso |
|---|---|---|---|
| `mdf-color-primario` | `#70BA65` (verde) | web, el color más usado | Banda de cabecera, botones, filetes, subrayado de enlaces y borde al pasar el ratón |
| `mdf-color-primario-oscuro` | `#5FA854` | derivado del verde | Final del degradado de la banda |
| `mdf-color-primario-suave` | `#EAF5E7` | derivado del verde | Fondos teñidos: tarjeta de Formación, etiquetas |
| `mdf-color-acento` | `#70BA65` | web | Hoy igual que el primario. Detalles decorativos |
| `mdf-color-acento-suave` | `#EAF5E7` | derivado | Hoy igual que `primario-suave` |
| `mdf-color-texto` | `#222222` | neutro | Texto principal, **texto sobre verde** y enlaces |
| `mdf-color-texto-secundario` | `#555555` | neutro | Fechas, contadores, descripciones (≈ 7,5:1 sobre blanco). **No sobre verde** (≈ 3,2:1) |
| `mdf-color-borde` | `#D9D9D9` | neutro | Separadores y bordes de tarjetas |
| `mdf-color-superficie` | `#F5F5F5` | neutro | Fondo de página del área |
| `mdf-color-fondo` | `#FCFBFE` | neutro (blanco roto) | Fondo de tarjetas |
| `mdf-color-morado` / `mdf-color-morado-suave` | `#594394` / `#EEEAF6` | web | **Sin uso en el área.** Se conservan en el kit por si MKT los quiere |

**Reglas de contraste del verde** (`#70BA65`):
- **Texto sobre verde: siempre oscuro** (`mdf-color-texto`, ≈ 6,7:1). Nunca blanco (≈ 2,4:1).
- **Nunca texto verde sobre fondo claro** (≈ 2,4:1). Los enlaces van en `mdf-color-texto` con subrayado verde (clase `mdf-enlace-flecha`).
- **El foco del teclado se marca en `mdf-color-texto`**, no en verde, porque necesita 3:1.

En Elementor son variables globales con estos nombres, para cambiarlas en un solo sitio. No se ponen colores sueltos en los widgets. Clases globales: `mdf-tarjeta`, `mdf-enlace-flecha` y `mdf-etiqueta`.

### Tipografía — `PROVISIONAL`

- **Familia:** Montserrat. Es la que usa mediformplus.com en todo el kit, cargada desde Google Fonts. Alternativa del sistema: `system-ui, sans-serif`.
- **Pesos:** 400 para el texto, 600 para los títulos y 500 para botones y etiquetas. No usar más pesos.
- **Tamaños (móvil → escritorio):** texto 16 px (nunca menos en móvil), texto secundario 14 px, H2 22 → 28 px, H3 18 → 20 px.
- **Interlineado:** 1,5 en el texto.
- **Mayúsculas:** sin titulares ni botones en mayúsculas completas. La web los usa («DESCÚBRELO AQUÍ»), pero en el área se escribe solo con mayúscula inicial.

---

## 2. Tono y público

- **Público:** farmacéuticos y titulares de farmacia. Entran a trabajar: consultar una factura, abrir una herramienta, ver la cuota.
- **Tono:** profesional y sobrio, sin tono de marketing. La web comercial usa exclamaciones y llamadas a la acción («¡Me interesa!», «¡REGÍSTRATE GRATIS!»). El área de clientes **no** copia ese tono.
- **Microcopy:**
  - Verbos cortos y concretos, los mismos que ya pinta el plugin: «Ver», «Abrir», «Descargar».
  - Sin exclamaciones, sin superlativos y sin banners promocionales.
  - Los mensajes vacíos los da el plugin («No hay herramientas disponibles.»). No se añaden textos que expliquen por qué alguien no ve algo: el plugin no lo revela a propósito.
- **Imágenes:** pocas o ninguna. Nada de fotos de stock ni capturas promocionales. Iconos simples y monocromos si ayudan a reconocer una sección.

---

## 3. Estructura del área

Son cuatro páginas, creadas por el plugin en la activación e identificadas por ID, no por slug. MKT puede cambiar sus URLs sin romper nada. Cada página empieza por `[mdf_ca_nav_area_privada]`.

| Sección | Función | Contenido dinámico | Estado |
|---|---|---|---|
| **Documentación** | Documentos privados de la farmacia, vinculados por CIF/NIF y visibles solo para su titular. No dependen del plan. Facturas agrupadas por año y mes de la fecha de factura, Modelo 347 y el resto de tipos. Cada documento se abre en el visor y, si es descargable, tiene «Descargar». | `[mdf_ca_listado_documentos]` (atributo `vista`, por defecto `agrupada`) | Lógica hecha. Falta maquetar |
| **Herramientas** | Catálogo de herramientas que ve la farmacia según su plan o porque se le han asignado a ella. Agrupado por categoría, con acciones «Abrir», «Ver» y «Descargar» según el tipo. | `[mdf_ca_catalogo_herramientas]` | Lógica hecha. Falta maquetar y dar estilo a las clases nuevas |
| **Formación** | Página de Elementor con un enlace a la Academia MDF. No usa el catálogo. | Ninguno (el SSO con la Academia está pendiente) | Falta maquetar. Hoy la página no está hecha con Elementor |
| **Cuota** | Información de la cuota de la farmacia. | Pendiente | A la espera del contenido de MKT (tarea #254) |

**Bloques condicionados por plan:** cualquier bloque que solo deba ver un plan va dentro de `[mdf_ca_si_plan planes="slug1,slug2"]…[/mdf_ca_si_plan]`. Los slugs de plan no cambian aunque se renombre el plan.

**Acceso:** la web de Mediform tiene el enlace «Área clientes» en la cabecera y en el pie, que hoy apunta a `area-privada/index.php`.

---

## 4. Reglas de maquetación

1. **Elementor solo maqueta.** En las páginas solo hay contenido de Elementor y shortcodes del plugin.
2. **Nada de lógica de permisos ni de datos en Elementor.** Sin condiciones de visibilidad por usuario o rol, sin consultas, sin etiquetas dinámicas que lean datos de la farmacia. Si algo depende del plan, se usa `[mdf_ca_si_plan]`. Si depende de la farmacia, lo resuelve un shortcode.
3. **No reimplementar los shortcodes.** Se colocan con el widget «Shortcode» de Elementor y se les da estilo por sus clases (sección 5).
4. **Sin widgets de terceros** salvo que se acuerden antes.
5. **Todo queda en borrador** hasta revisión. Nada se publica desde el MCP.
6. **Estilos en variables y clases globales**, no en cada widget, para que la identidad definitiva se cambie en un solo sitio.

---

## 5. HTML que pinta el plugin (para dar estilo)

Son clases BEM con el prefijo `mdf-ca-`. Su contrato es el HTML, no el estilo: se pueden estilizar, pero no cambiar.

**Navegación** — `[mdf_ca_nav_area_privada]`

```html
<nav class="mdf-ca-nav-area-privada">
  <ul>
    <li class="mdf-ca-nav-area-privada__item--actual"><a href="…">Documentacion</a></li>
    <li><a href="…">Herramientas</a></li>
    …
  </ul>
</nav>
```

**Documentación** — `[mdf_ca_listado_documentos]`

- Contenedor: `mdf-ca-listado-documentos`, `--agrupado` y `--vacio`.
- Grupos: `__grupo`, `__titulo`, `__contador`, `__anio`, `__anio--sin-ejercicio`, `__mes`, `__mes--sin-fecha`.
- Lista: `__lista`, `__item`, `__fecha`, `__fecha--subida`, `__descargar`.

**Herramientas** — `[mdf_ca_catalogo_herramientas]`

- Contenedor: `mdf-ca-herramientas`; si no hay herramientas, `mdf-ca-herramientas--vacio`.
- Categoría: `<section class="mdf-ca-herramientas__categoria" data-categoria>` con `__categoria-titulo` y `__lista`.
- Herramienta: `<li class="mdf-ca-herramienta" data-categoria data-etiquetas data-tipo>` con `__nombre`, `__descripcion`, `__etiquetas`, `__etiqueta`, `__acciones` y `__boton` (`--abrir`, `--ver`, `--descargar`).
- Estado del presupuesto en el listado (v0.44.0): `<span class="mdf-ca-listado-documentos__estado mdf-ca-listado-documentos__estado--pendiente|aceptado|rechazado">` dentro del `__item`. Lo estila el plugin (`listado-documentos.css`): amarillo, verde y rojo suaves, texto #222. La franja de aceptar y rechazar del visor también es del plugin (`documento-visor.css`).
- Ventana de la herramienta (v0.43.0): `<dialog class="mdf-ca-ficha">` dentro de cada tarjeta, con `__tipo`, `__cerrar`, `__titulo`, `__texto` y los mismos botones. La estila el plugin (`catalogo-herramientas.css`), no Elementor; sus botones y etiquetas heredan el estilo de `mdf-catalogo` porque usan las mismas clases.

El detalle completo está en `CLAUDE.md` («Herramientas del catálogo»).

---

## 6. Responsive: móvil primero

Las farmacias usan el área en el móvil, en el mostrador, a menudo con prisa y de pie.

- **Se diseña primero el móvil** y se amplía después a tableta y escritorio, con los puntos de corte por defecto de Elementor.
- **Una columna en móvil.** En escritorio, como mucho dos o tres columnas para las tarjetas de herramientas.
- **Objetivos táctiles de al menos 44 × 44 px** en botones, enlaces de la navegación y «Descargar». Separación suficiente para no pulsar el de al lado.
- **Navegación accesible con el pulgar:** en móvil, la navegación de las cuatro secciones cabe en una fila desplazable o en dos filas, sin menús ocultos tras un icono.
- **Texto de 16 px como mínimo**, sin zoom ni desplazamiento horizontal.
- **Nada de tablas anchas:** los listados se muestran como lista o tarjetas.
- **Peso ligero:** sin vídeos, sliders ni imágenes grandes. Puede haber mala cobertura en la farmacia.
- **Foco visible y contraste AA** en todo, incluidos los estados `:hover`, `:focus` y el elemento activo de la navegación.

---

## 7. Entorno técnico (local, 24/10/2026)

- WordPress 7.1.3, tema activo **Hello Elementor 3.5.1**, Elementor **4.3.5 gratuito** (sin Pro).
- **Sin Theme Builder:** la cabecera y el pie no se maquetan en Elementor. Salen del tema.
- mediformplus.com usa el tema **Unicon** (con tema hijo), Elementor 4.3.5 y muchos plugins más (WooCommerce, Customer Area, Download Manager…).

---

## 8. Front construido (24/10/2026)

**Estado en local (plugin v0.39.0):** las páginas del área están **publicadas** con la plantilla *Elementor Canvas* (sin la cabecera ni el pie de Hello) y **protegidas por el plugin** (sin sesión, al login; con sesión de farmacia, menú de perfil). Tras el login, la farmacia llega a la portada.

| Página | URL | ID | Contenido |
|---|---|---|---|
| Área de clientes (portada) | `/area-de-clientes/` | 27 | Banda con el logo blanco grande (como la portada de la web), accesos directos y bento de 4 tarjetas hacia las secciones |
| Documentación | `/documentacion/` | 13 | **Portada con cajas por tipo** (bento): Facturas destacada; Modelo 347, Contratos, Presupuestos y Entregables en 2×2; Otros documentos en franja ancha; ayuda |
| Facturas | `/documentacion/facturas/` | 91 | `[mdf_ca_listado_documentos tipo="factura"]` |
| Modelo 347 | `/documentacion/modelo-347/` | 92 | `[mdf_ca_listado_documentos tipo="modelo_347"]` |
| Contratos | `/documentacion/contratos/` | 93 | `[mdf_ca_listado_documentos tipo="contrato"]` |
| Presupuestos | `/documentacion/presupuestos/` | 94 | `[mdf_ca_listado_documentos tipo="presupuesto"]` |
| Entregables | `/documentacion/entregables/` | 95 | `[mdf_ca_listado_documentos tipo="entregable"]` |
| Otros documentos | `/documentacion/otros-documentos/` | 96 | `[mdf_ca_listado_documentos tipo="otro"]` |
| Herramientas | `/herramientas/` | 14 | Leyenda de acciones y catálogo (`[mdf_ca_catalogo_herramientas]`) en una tarjeta, y franja de ayuda |
| Formación | `/formacion/` | 15 | Tarjeta partida con la foto de la Academia y el botón «Ir a la Academia», que abre https://academiamdf.com/ en una pestaña nueva |
| Cuota | `/cuota/` | 16 | Estructura propuesta `PROVISIONAL` (tarea #254), facturas y dudas |

Los diseños de Documentación, Herramientas, Formación y Cuota se maquetaron en los borradores 24, 68, 69 y 70 y se copiaron a 13–16 con la API de documentos de Elementor. **Esos cuatro borradores siguen existiendo como copia** y se pueden borrar cuando se dé por bueno el resultado.

**Pantalla de acceso y menú de perfil** (plugin v0.40.0, no son de Elementor; detalle en el `CLAUDE.md` del plugin):
- **Login (`wp-login.php`):** pantalla partida, con el panel de marca verde a la izquierda (logo blanco, «Área de clientes», qué encontrará el cliente, teléfono de ayuda y marca de agua) y el formulario a la derecha, con un título y una pista por pantalla (acceder, recuperar contraseña, elegir contraseña). Pie con los avisos legales. En móvil, el panel pasa a ser una banda arriba. El plugin lleva su propia Montserrat y sus logos en `assets/`.
- **Menú de perfil:** botón «Mi cuenta» en la esquina de la cabecera blanca (la clase `mdf-cabecera` le reserva 184 px a la derecha) y panel con el nombre de la farmacia y «Cerrar sesión».

Cada página de tipo lleva una miga de pan «Documentación › Tipo» (clases `mdf-miga`, `mdf-miga-enlace` y `mdf-miga-texto`), píldoras para cambiar de tipo, el listado en una tarjeta con `mdf-listado` y una columna con «Cómo están ordenados» y ayuda. En el menú sigue marcada «Documentación».

**Atributo `tipo` del listado** (plugin v0.39.0; detalle en su `CLAUDE.md`): se filtra **después** de `Permissions`, `otro` incluye los documentos sin tipo, con `tipo` se omite el `<details>` exterior y el contenedor lleva `mdf-ca-listado-documentos--tipo-{clave}`; cada tipo vacío tiene su mensaje («No hay facturas disponibles.») y un tipo desconocido no lista nada.

**Estructura común** (sin Elementor Pro no hay componentes, así que se repite en cada página y la unifican las clases globales):
1. **Cabecera blanca**, el reflejo de la de mediformplus.com: logo verde (enlace a Inicio), menú en mayúsculas pequeñas y la pestaña oscura «mediformplus.com», en el sitio donde la web tiene «ÁREA CLIENTES». A la derecha quedan 184 px libres para el botón «Mi cuenta» del plugin (fijo). **Se queda fija al hacer scroll en escritorio**; en tableta y móvil no es fija, el menú pasa a dos filas y la pestaña oscura se oculta (el pie ya enlaza a la web).
2. **Banda verde** con una insignia blanca con el icono de la sección junto al título, una entradilla y, de fondo, el **logo blanco grande y translúcido** sangrando por la derecha (el recurso del hero de la web; se oculta en móvil). Las subpáginas de Documentación añaden la miga de pan y **píldoras para cambiar de tipo** (la activa, en oscuro).
3. **Contenido**, que se monta 80 px sobre la banda. Las tarjetas llevan su icono en una insignia verde suave; las grandes, además, el icono gigante al 7 % como marca.
4. **Pie** como el de la web: franja gris `#F1F1F1` con columna de marca (logo, lema «25 años escribiendo el futuro de la Farmacia.», dirección y teléfono), columnas «Área de clientes» y «Mediformplus» (web, Academia y avisos legales) y línea «© 2026 MEDIFORMPLUS».

Inicio, además, lleva en el hero un panel de **accesos directos** (Mis facturas, Presupuestos, Academia MDF), oculto en móvil. Desde el 26/10/2026 (feedback del cliente), la tarjeta grande del bento es **Herramientas** y Documentación pasa a la columna de la derecha, encima de Formación; Presupuestos sustituye al Modelo 347 en los accesos directos (el 347 llega una vez al año). Se cambió el contenido de las tarjetas, no su estructura: en el editor, los nombres de las capas siguen siendo los antiguos («Tarjeta · Documentación (destacada)» es ahora la de Herramientas, «Acceso · Modelo 347» es Presupuestos).

**Iconos:** Tabler Icons 3.19.0 (licencia MIT), en trazo de 1,75, color `#222222` y PNG de 144 px (WordPress no admite SVG sin cambiar un ajuste). Están en la biblioteca de medios:

| ID | Icono | Uso |
|---|---|---|
| 117 | folder | Documentación |
| 122 | tool | Herramientas |
| 121 | school | Formación y Academia |
| 123 | wallet | Cuota |
| 115 | file-invoice | Facturas |
| 120 | report-money | Modelo 347 |
| 114 | file-certificate | Contratos |
| 113 | calculator | Presupuestos |
| 119 | presentation-analytics | Entregables |
| 116 | files | Otros documentos |
| 118 | phone | Ayuda |
| 229 | list-check | Cuota: qué incluye |
| 230 | credit-card | Cuota: importe y forma de pago |
| 231 | calendar-event | Cuota: calendario de cobro |

**Herramientas (68):** encima del catálogo hay una leyenda que explica «Abrir» (pestaña nueva), «Ver» (en el visor, sin descargar) y «Descargar» (se guarda en el equipo), con clases `mdf-leyenda*`. La clase `mdf-catalogo` muestra además en cada ficha el tipo de archivo, a partir del `data-tipo` que pinta el plugin: Enlace web, PDF, Excel, Excel con macros, Word, PowerPoint o Imagen. Si el plugin añade un formato nuevo, sale «Archivo» hasta que se añada su texto a la clase. Las categorías llevan un filete y las fichas se realzan al pasar el ratón.

**Cuota (70):** estructura **propuesta** a falta del contenido de MKT (tarea #254). Lleva un aviso PROVISIONAL y tres apartados con icono (Qué incluye, Importe y forma de pago, Calendario de cobro; clases `mdf-apartado*`) cuyo texto es «Pendiente de MKT». En la columna lateral, «Tus facturas» (enlace a la página 91) y «¿Dudas sobre tu cuota?». MKT puede cambiar, quitar o añadir apartados.

**Imágenes en la biblioteca:** logo verde (124), logo blanco (125) y foto de la Academia (126). Son copias de las de mediformplus.com y ya no se enlazan desde fuera.

**Clases globales** (el orden importa: las primeras ganan):
- `mdf-nav-actual`, `mdf-nav-web`, `mdf-cabecera`, `mdf-logo-cabecera`, `mdf-nav` y `mdf-nav-enlace`.
- `mdf-banda`, `mdf-ancho`, `mdf-titulo-pagina`, `mdf-entradilla`, `mdf-contenedor`, `mdf-tarjeta` y `mdf-titulo-tarjeta`.
- `mdf-texto`, `mdf-boton`, `mdf-enlace-flecha`, `mdf-franja-ayuda` y `mdf-pendiente`.
- **Iconos:** `mdf-icono-caja-banda`, `mdf-icono-caja-clara`, `mdf-icono-caja`, `mdf-icono`, `mdf-titulo-fila`, `mdf-marca-agua` y `mdf-marca-tarjeta`.
- **Navegación de tipos:** `mdf-pildora-actual`, `mdf-pildoras`, `mdf-pildora`, `mdf-miga`, `mdf-miga-enlace` y `mdf-miga-texto`.
- **Accesos directos:** `mdf-acceso`, `mdf-acceso-texto` y `mdf-acceso-flecha`.
- **Pie:** `mdf-pie-banda`, `mdf-pie-ancho`, `mdf-pie-marca`, `mdf-pie-col`, `mdf-pie-titulo`, `mdf-pie-link`, `mdf-pie-logo`, `mdf-pie-legal` y `mdf-pie-texto`. `mdf-pie` y `mdf-pie-enlace` son del pie anterior y ya no se usan.
- `mdf-listado` y `mdf-catalogo`, que dan estilo al HTML de los shortcodes (ver más abajo). `mdf-etiqueta` existe pero no se usa.

**Cómo se da estilo a los shortcodes sin tocar el plugin:** las clases `mdf-listado` y `mdf-catalogo` llevan **selectores descendientes** (`& .mdf-ca-listado-documentos__item {…}`), que Elementor publica como CSS anidado nativo. Se ponen en la **tarjeta que contiene el shortcode**, no en el shortcode. Si cambia el HTML de un shortcode (sección 5), hay que revisar estas dos clases. Los navegadores sin CSS anidado (anteriores a 2023) ven el HTML sin estilo, pero funcional.

**Cómo están colocados los shortcodes:** el MCP no puede insertar el widget «Shortcode». Por eso el shortcode va **escrito como texto en un párrafo** (`span`), y WordPress lo expande al pintar la página. Funciona y está comprobado, pero tiene dos pegas:
- en el editor de Elementor se ve el texto `[mdf_ca_…]` en vez del listado;
- el HTML queda anidado dentro de un `<span>`, lo que no es del todo correcto, aunque los navegadores lo pintan bien.

**Antes de publicar, recomendado:** sustituir ese párrafo por el widget «Shortcode» dentro de la misma tarjeta. Los estilos se mantienen, porque cuelgan de la tarjeta.

**Navegación:** el menú se hace en Elementor (enlaces por ID a las páginas 27 y 13–16), no con `[mdf_ca_nav_area_privada]`. Si el plugin cambia las páginas del área, hay que actualizar el menú a mano en las cinco páginas.

**Visor de documentos (plugin, v0.41.0):** no es una página de Elementor; lo pinta el plugin (`Documento_Visor::pintar()` y `assets/css/documento-visor.css`) con los mismos valores de la sección 1, escritos a mano en su CSS. Cabecera blanca fija con el logo verde, «Volver» (a Documentación o a Herramientas), el nombre del documento y «Descargar» en verde con texto #222 (solo si se permite). Debajo, la barra de páginas, que desaparece si no hay nada que paginar, y el documento como una hoja con sombra sobre gris. En móvil, «Volver» y «Descargar» quedan solo como icono. Si cambia la identidad de la sección 1, hay que cambiar también ese CSS (y `login.css` y `menu-perfil.css`).


---

## 9. Pendiente / decisiones abiertas

- [ ] **Identidad de MDF** (logo, paleta y tipografía definitivas): pedirla a Víctor o a MKT. Hasta entonces, valen los valores `PROVISIONAL` de la sección 1.
- [ ] **Llevar el área a producción:** al desplegar la v0.39.0, el plugin creará allí la portada y las seis páginas de tipo **solo con su shortcode**. Hay que llevar los diseños de Elementor (exportar e importar las once páginas), las clases y variables globales del kit y los medios (iconos y logos; en local, IDs 113–126 y 229–231, que allí cambiarán).
- [ ] **Comprobar con una sesión de farmacia real** (Chrome y Safari) el menú de perfil en las páginas nuevas y que tras el login se llega a la portada.
- [ ] **Visor de documentos:** probar un PDF de varias páginas, un Excel con varias hojas, la vista previa del administrador y Safari.
- [ ] **Borrar los borradores de diseño 24, 68, 69 y 70** cuando se dé por bueno el resultado en 13–16.
- [ ] **Sustituir los shortcodes escritos como texto** por el widget «Shortcode» (ver sección 8).
- [ ] **Modo oscuro:** no lo hay. Las variables de Elementor no tienen versión oscura.
- [ ] **Tema local y tema de la web:** en local está Hello Elementor; mediformplus.com usa Unicon. Si el área va a vivir en esa web, hay que confirmar con quién corresponda en qué instalación y con qué tema se integra, porque cambia la cabecera, el pie y los estilos base.
- [ ] **Enlace «Área clientes»** de la web: hoy apunta a `area-privada/index.php`. Confirmar si se sustituye por estas páginas y cuándo.
- [ ] **Títulos sin tilde en el plugin:** en local, las páginas ya se llaman «Documentación» y «Formación». Pero `Area_Privada_Pages::get_paginas()` sigue con «Documentacion» y «Formacion», que es lo que pinta `[mdf_ca_nav_area_privada]`; el diseño no usa ese shortcode, así que solo importa si vuelve a usarse.
- [ ] **Contenido de Cuota** (tarea #254) y **texto y enlace de Formación** hacia la Academia.
- [ ] **Widgets de terceros:** ninguno acordado por ahora.
