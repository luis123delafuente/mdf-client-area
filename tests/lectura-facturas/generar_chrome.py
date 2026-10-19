"""Facturas SINTETICAS de otro productor de PDF (Chrome sin interfaz, motor Skia),
con la geometria de las plantillas SAGE de Parser_facturas_SAGE/tests/sintetica.py.

Sirven para probar la lectura en PHP (#333) con un PDF que no ha salido de PyMuPDF: fuentes
TrueType incrustadas en subconjunto (Type0, Identity-H, con /W), texto con TJ e
interletrado y varios glifos por operador, como un programa de gestion real.
Nunca datos reales.

Uso:
  python3 -I generar_chrome.py <carpeta destino> [<binario de Chrome>]
Arranca Chrome con un perfil temporal propio (no toca el del usuario) y lo borra.
"""

import shutil
import subprocess
import sys
import tempfile
from html import escape
from pathlib import Path

destino = Path(sys.argv[1])
chrome = sys.argv[2] if len(sys.argv) > 2 else "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

EMISOR, EMISOR_F = "B82827635", "B88471149"
PLANTILLAS = {
    "mdf": {"nombre": "Mediformplus, S.L.", "y_cabecera": 141, "y_rotulos": 214, "y_total": 554, "y_total_fra": 548, "eur": True},
    "formacion": {"nombre": "MEDIFORMPLUS FORMACION", "y_cabecera": 158, "y_rotulos": 240, "y_total": 564, "y_total_fra": 564, "eur": False},
}
X = {"FECHA": 53.9, "FACTURA": 129.4, "CLIENTE": 238.6, "N.I.F": 351.5, "TELÉFONO": 443.0, "PÁG.": 530.6}


def texto(x, base, t, size=8, bold=False):
    # top = linea base - ascendente aproximado de Arial (0,905 em).
    peso = "bold" if bold else "normal"
    return f'<span style="left:{x}pt;top:{base - size * 0.905:.2f}pt;font-size:{size}pt;font-weight:{peso}">{escape(t)}</span>'


def pagina(p, *, emisor, cif, serie, numero, fecha, total, pag, marcador, suma_y_sigue, con_total):
    t = []
    t.append(texto(57.8, 75, p["nombre"], 10, True))
    if marcador is not None:
        t.append(texto(300, 75, f"Es copia {marcador}"))
    t.append(texto(57.8, p["y_cabecera"], f"CIF: {emisor}"))
    t.append(texto(300, 90, "FARMACIA FICTICIA DE PRUEBA"))
    yr, yv = p["y_rotulos"], p["y_rotulos"] + 24
    for rot, x in X.items():
        t.append(texto(x, yr, rot, 8, True))
    t.append(texto(48.1, yv, fecha))
    t.append(texto(139.3, yv, f"{serie} {numero}"))
    t.append(texto(235.5, yv, "43000001"))
    if cif:
        t.append(texto(337.8, yv, cif))
    t.append(texto(443.0, yv, "910000000"))
    t.append(texto(538.0, yv, str(pag)))
    if pag > 1:
        t.append(texto(453.0, 300, "SUMA ANTERIOR 999,99"))
    t.append(texto(60, 320, "Línea de producto ficticia con acentos: Ñandú, cigüeña"))
    if suma_y_sigue:
        t.append(texto(462.0, 490, "SUMA Y SIGUE 999,99"))
    yt = p["y_total"]
    t.append(texto(42.5, yt, "TOTAL IMP.", 8, True))
    t.append(texto(267.0, yt, "BASE", 8, True))
    t.append(texto(368.4, yt, "IVA", 8, True))
    t.append(texto(499.9, p["y_total_fra"], "TOTAL FRA", 8, True))
    if p["eur"]:
        t.append(texto(511.3, yt + 3, "(EUR)"))
    if con_total:
        t.append(texto(65, yt + 21, total))
        t.append(texto(274, yt + 21, total))
        importe = total if p["eur"] else total + "€"
        t.append(texto(500 if total.startswith("-") else 513.5, yt + 44, importe, 10, True))
    return '<div class="pag">' + "".join(t) + "</div>"


def png_ruido(ancho, alto):
    """PNG de ruido (incomprimible): simula un logotipo pesado para medir memoria y tiempo."""
    import os, struct, zlib, base64
    filas = b"".join(b"\x00" + os.urandom(ancho * 3) for _ in range(alto))
    def trozo(tipo, datos):
        return struct.pack(">I", len(datos)) + tipo + datos + struct.pack(">I", zlib.crc32(tipo + datos) & 0xFFFFFFFF)
    png = b"\x89PNG\r\n\x1a\n" + trozo(b"IHDR", struct.pack(">IIBBBBB", ancho, alto, 8, 2, 0, 0, 0)) + trozo(b"IDAT", zlib.compress(filas, 1)) + trozo(b"IEND", b"")
    return "data:image/png;base64," + base64.b64encode(png).decode()


def factura(nombre, plantilla="mdf", emisor=EMISOR, cif="B61000014", serie="A", numero="123", fecha="15/09/26",
            total="1.234,56", copias=1, paginas_por_copia=1, logo=False):
    p = PLANTILLAS[plantilla]
    paginas = []
    for c in range(copias):
        for j in range(paginas_por_copia):
            ultima = j == paginas_por_copia - 1
            paginas.append(pagina(p, emisor=emisor, cif=cif, serie=serie, numero=numero, fecha=fecha, total=total,
                                  pag=j + 1, marcador=1 if c > 0 else None, suma_y_sigue=not ultima, con_total=ultima))
    if logo:
        img = f'<img src="{png_ruido(700, 700)}" style="position:absolute;left:400pt;top:20pt;width:150pt;height:150pt">'
        paginas = [pg.replace('<div class="pag">', '<div class="pag">' + img, 1) for pg in paginas]
    return nombre, (
        "<!doctype html><html><head><meta charset='utf-8'><style>"
        "@page{size:595pt 842pt;margin:0} html,body{margin:0;padding:0}"
        "body{font-family:Arial,Helvetica,sans-serif;color:#000}"
        ".pag{position:relative;width:595pt;height:842pt;overflow:hidden;page-break-after:always}"
        ".pag:last-child{page-break-after:auto} span{position:absolute;white-space:pre;line-height:1}"
        "</style></head><body>" + "".join(paginas) + "</body></html>"
    )


CASOS = {
    "chrome_mdf_ok": factura("FAA123.pdf"),
    "chrome_mdf_original_y_copia": factura("FAA124.pdf", numero="124", copias=2),
    "chrome_mdf_abono": factura("FAA125.pdf", numero="125", total="-1.234,56"),
    "chrome_mdf_3_paginas_2_copias": factura("FAA9003.pdf", numero="9003", copias=2, paginas_por_copia=3),
    "chrome_mdf_nif": factura("FAA126.pdf", numero="126", cif="71234567W"),
    "chrome_mdf_cif_mal_formado": factura("FAA127.pdf", numero="127", cif="XX-12"),
    "chrome_mdf_cif_control_erroneo": factura("FAA128.pdf", numero="128", cif="B61000019"),
    "chrome_mdf_sin_cif": factura("FAA129.pdf", numero="129", cif=""),
    "chrome_mdf_sin_farmacia": factura("FAA9004.pdf", numero="9004", cif="B00000000", total="60,50"),
    "chrome_mdf_numero_discrepante": factura("FAA999.pdf", numero="130"),
    "chrome_formacion_ok": factura("FASF186.pdf", plantilla="formacion", emisor=EMISOR_F, serie="SF", numero="186"),
    "chrome_formacion_abono": factura("FASF9002.pdf", plantilla="formacion", emisor=EMISOR_F, serie="SF", numero="9002", total="-242,00"),
    "chrome_formacion_2_paginas": factura("FASF187.pdf", plantilla="formacion", emisor=EMISOR_F, serie="SF", numero="187", paginas_por_copia=2),
    "chrome_importe_grande": factura("FAA131.pdf", numero="131", total="123.456.789,01"),
    # Caso de esfuerzo: imagen de ~1,5 MB en cada pagina (2 copias x 3 paginas).
    "chrome_esfuerzo_logo_grande_6_paginas": factura("FAA9005.pdf", numero="9005", copias=2, paginas_por_copia=3, logo=True),
}

perfil = Path(tempfile.mkdtemp(prefix="chrome-poc-"))
html_dir = Path(tempfile.mkdtemp(prefix="html-poc-"))
try:
    destino.mkdir(parents=True, exist_ok=True)
    for caso, (fichero, html) in CASOS.items():
        (destino / caso).mkdir(exist_ok=True)
        entrada = html_dir / f"{caso}.html"
        entrada.write_text(html, encoding="utf-8")
        subprocess.run(
            [chrome, "--headless=new", "--disable-gpu", f"--user-data-dir={perfil}", "--no-first-run",
             "--no-pdf-header-footer", "--disable-extensions", f"--print-to-pdf={destino / caso / fichero}", entrada.as_uri()],
            check=True, capture_output=True, timeout=60,
        )
    print(f"{len(CASOS)} facturas de Chrome en {destino}")
finally:
    shutil.rmtree(perfil, ignore_errors=True)
    shutil.rmtree(html_dir, ignore_errors=True)
