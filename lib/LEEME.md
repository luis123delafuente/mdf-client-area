# lib/ — librerías PHP vendorizadas (generador del Modelo 347, #307)

En el servidor no hay Composer. Las librerías se resuelven en un Mac y se copian aquí tal cual, como PDF.js y SheetJS en `assets/vendor/`. Solo las carga `Modelo_347_Librerias::cargar()`, nunca en cada petición.

`lib/.htaccess` deniega todo: ningún PHP de librería se puede ejecutar por HTTP. Va en el repo; no se usa `vendor/` porque `.gitignore` lo ignora.

| Paquete | Versión | Licencia |
|---|---|---|
| dompdf/dompdf | 3.1.6 | LGPL-2.1 |
| dompdf/php-font-lib | 1.0.2 | LGPL-2.1-or-later |
| dompdf/php-svg-lib | 1.0.2 | LGPL-3.0-or-later |
| masterminds/html5 | 2.11.0 | MIT |
| sabberworm/php-css-parser | 9.5.0 | MIT |
| openspout/openspout | 5.12.2 | MIT (**exige PHP ~8.4**) |
| Liberation Serif/Sans (fuentes) | 2.1.5 | SIL OFL 1.1 |

**Licencias.** El plugin es GPL-2.0-or-later. La LGPL-3.0 de php-svg-lib es compatible con la GPLv3, así que el conjunto se puede distribuir como GPLv3.

**Fuentes.** Liberation, con métricas idénticas a Times New Roman (la fuente de la carta), en `liberation-fonts-ttf-2.1.5.tar.gz` (SHA-256 `7191c669bf38899f73a2094ed00f7b800553364f90e2637010a69c0e268f25d0`).

## Reconstruir

1. Instala Composer **solo en el scratchpad**, verificando la suma SHA-256 oficial (`composer.phar.sha256sum`).
2. Con este `composer.json` (plataforma fijada en PHP 8.4.0) ejecuta:

   ```
   php composer.phar update --no-dev --no-scripts --no-plugins
   ```

3. Copia `vendor/` a `lib/vendor/`, junto con `composer.json` y `composer.lock`.
4. Borra `lib/vendor/dompdf/dompdf/lib/fonts/DejaVu*` y `mustRead.html` (no se usan; unos 7 MB).
5. Copia en `lib/fonts/` los TTF `LiberationSerif-{Regular,Bold,Italic,BoldItalic}` y `LiberationSans-{Regular,Bold}`, además de su `LICENSE`.
6. Regístralos con dompdf (`FontMetrics::registerFont()`). Esto genera `.ufm` e `installed-fonts.json`.
7. **Reescribe `installed-fonts.json` con nombres relativos.** dompdf guarda rutas absolutas del Mac y en producción no funcionarían.
8. Borra los TTF originales duplicados.
9. Haz un render con las seis variantes para generar las cachés `.ufm.json`.

## En ejecución

dompdf solo **lee** `lib/fonts`. Si faltara alguna caché `.ufm.json`, el generador no renderiza: así nunca escribe dentro del plugin.

Sus temporales van a una subcarpeta `r-*` por render, dentro de `Clientes/private-docs/.tmp-generador-347/`, que está fuera del plugin y protegida. Ver `Modelo_347_Librerias`.
