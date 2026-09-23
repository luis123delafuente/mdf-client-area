# PDF.js vendorizado

- Origen: paquete npm `pdfjs-dist`, version `6.3.289`.
- Ficheros: solo el nucleo de render (`pdf.min.mjs` + `pdf.worker.min.mjs`),
  sin la UI de visor propia de PDF.js (`web/viewer.html`, `viewer.js`,
  `viewer.css`) -- esta ultima trae su propia barra de herramientas con
  boton de descarga e impresion, justo lo contrario de lo que pide esta
  tarea. `Documento_Visor` usa unicamente la API de `pdfjsLib.getDocument()`
  para parsear el PDF y `page.render()` para pintar cada pagina en el
  `<canvas>` propio del plugin (ver `assets/js/documento-visor.js`).
- Licencia: Apache License 2.0 (fichero `LICENSE` en esta misma carpeta),
  compatible con la licencia GPL-2.0-or-later del plugin.
- Por que vendorizado y no cargado desde un CDN externo: el visor sirve
  documentos privados de farmacias reales; depender en tiempo de ejecucion
  de un CDN de terceros (jsdelivr, unpkg, cdnjs...) anade un tercero con
  capacidad de servir JS distinto al que se audito, y un punto de fallo
  fuera del control de MDF. Los ficheros se descargaron una vez durante el
  desarrollo y quedan versionados en el repo del plugin, como cualquier
  otro fichero del proyecto.
- Actualizar version: sustituir `pdf.min.mjs` y `pdf.worker.min.mjs` por los
  del mismo `build/` de la version nueva de `pdfjs-dist`, y actualizar el
  numero de version en este fichero.
