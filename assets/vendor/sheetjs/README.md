# SheetJS vendorizado

- Origen: SheetJS Community Edition `0.20.3`, build `xlsx.mini.min.js`
  descargado de `https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/`. Es el
  origen oficial: la version de npm (`xlsx@0.18.5`) esta obsoleta y tiene
  vulnerabilidades conocidas corregidas en 0.19.3 / 0.20.2.
- Uso: solo LEE el `.xlsx` en el visor (`assets/js/documento-visor.js`) para
  dibujarlo en canvas. No se usa para escribir ni generar ficheros.
- Licencia: Apache License 2.0 (`LICENSE` en esta carpeta).
- Vendorizado, no cargado desde CDN, por el mismo motivo que PDF.js (ver
  `../pdfjs/README.md`).
- Actualizar: sustituir `xlsx.mini.min.js` por el de la version nueva y
  cambiar el numero aqui.
