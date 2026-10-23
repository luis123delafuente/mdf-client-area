"""Genera un Excel FICTICIO del Modelo 347 con la estructura EXACTA que exporta
SAGE (libreria .NET estilo OpenXML SDK), para probar el generador (#307).
Nunca datos reales. No se despliega (tests/).

Uso (desde la raiz del plugin):
  python3 -I tests/generar-347-sage-net.py <salida.xlsx> <CIF con cuenta> <CIF sin cuenta> <libre 1> <libre 2> <libre 3> [<CIF con cuenta 2>]

Mismas filas y casos que tests/generar-347-ficticio.php (mismos totales).

Estructura, tal como la describe MDF:
- ZIP con exactamente 6 entradas y en este orden: xl/workbook.xml,
  _rels/.rels (STORED, sin comprimir), xl/styles.xml,
  xl/_rels/workbook.xml.rels, xl/worksheets/sheet1.xml, [Content_Types].xml.
- Sin docProps/, sharedStrings.xml ni theme.
- SpreadsheetML con prefijo x: (x:workbook, x:sheet, x:worksheet, x:cols,
  x:col, x:sheetData, x:row, x:c t="str" / t="n" con x:v). Textos en t="str".
- .rels con rutas absolutas (/xl/...) e IDs tipo R39af0c5950b6475d.
- BOM UTF-8 en _rels/.rels, xl/_rels/workbook.xml.rels y [Content_Types].xml.
- [Content_Types].xml: Default xml = ...sheet.main+xml, Default rels y dos
  Override (styles y sheet1). SIN Override para /xl/workbook.xml.
- Ningun DOCTYPE ni ENTITY.
"""
import secrets
import sys
import zipfile
from xml.sax.saxutils import escape

BOM = "﻿"
NS_X = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_PR = "http://schemas.openxmlformats.org/package/2006/relationships"


def rid():
    return "R" + secrets.token_hex(8)


def p(t):
    return "  " + t + "   "  # relleno de espacios, como SAGE


def filas_ficticias(con_cuenta, sin_cuenta, libre1, libre2, libre3, con_cuenta2=None):
    """Mismas filas que generar-347-ficticio.php. None = celda ausente."""
    filas = [
        ["Código", "Nombre", "NIF", "Dirección", "Población", "Código postal", "Trimestre 1", "Trimestre 2", "Trimestre 3", "Trimestre 4", "Importe DEV", "Importe RECC", "RECC", "Email"],
        [p("430001"), p("Farmacia Peña & Muñoz Ibáñez, C.B."), p(con_cuenta), p("Calle Ficticia Ñandú, 12"), p("Alcalá de Prueba"), p("28001"), 1500.25, -200.10, 0, 300.00, 1600.15, 0, "False", "prueba1@example.com"],
        [p("430002"), p("Farmacia Prueba Sin Cuenta"), p(sin_cuenta), p("Avenida Inventada, 3"), p("Villaprueba"), p("28002"), 100, 200, 300, 400, 1000, 0, "False", ""],
        [p("430003"), p("Farmacia Fuera Del Portal"), p(libre1), p("Plaza Mayor, 1"), p("Pueblo Ficticio"), p("28003"), 50, 50, 50, 50, 200, 0, "False", ""],
        [p("430004"), p("Identificador Mal Formado"), p("XX-12"), "", "", "", 10, 10, 10, 10, 40, 0, "False", ""],
        [p("430005"), p("Control Erróneo"), p("B12345675"), "", "", "", 10, 10, 10, 10, 40, 0, "False", ""],
        [p("430006"), p("Suma Que No Cuadra"), p(libre2), "", "", "", 25, 25, 25, 25, 150, 0, "False", ""],
        [p("430007"), p("Repetida Uno"), p(libre3), "", "", "", 1, 1, 1, 1, 4, 0, "False", ""],
        [p("430008"), p("Repetida Dos"), p(libre3), "", "", "", 2, 2, 2, 2, 8, 0, "False", ""],
    ]
    if con_cuenta2:
        filas.append([p("430009"), p("Farmacia Con RECC"), p(con_cuenta2), p("Calle Segunda, 2"), p("Otro Pueblo"), p("08001"), 1000, 234.56, 0, None, "1.234,56", 12.5, "True", ""])
    return filas


def col(i):
    letras = ""
    i += 1
    while i:
        i, r = divmod(i - 1, 26)
        letras = chr(65 + r) + letras
    return letras


def celda(ref, v):
    if v is None:
        return ""
    if isinstance(v, (int, float)):
        return f'<x:c r="{ref}" t="n"><x:v>{v!r}</x:v></x:c>'.replace("<x:v>-0.0</x:v>", "<x:v>0</x:v>")
    return f'<x:c r="{ref}" t="str"><x:v>{escape(v)}</x:v></x:c>'


def escribir(salida, filas):
    """Escribe las filas (la primera, la cabecera) con la estructura de SAGE."""
    rows = []
    for n, fila in enumerate(filas, start=1):
        celdas = "".join(celda(f"{col(i)}{n}", v) for i, v in enumerate(fila))
        rows.append(f'<x:row r="{n}">{celdas}</x:row>')

    r_libro, r_hoja, r_estilos = rid(), rid(), rid()

    workbook = (
        f'<?xml version="1.0" encoding="utf-8"?>'
        f'<x:workbook xmlns:r="{NS_R}" xmlns:x="{NS_X}"><x:sheets>'
        f'<x:sheet name="347" sheetId="1" r:id="{r_hoja}" /></x:sheets></x:workbook>'
    )
    rels_raiz = (
        BOM + f'<?xml version="1.0" encoding="utf-8"?>'
        f'<Relationships xmlns="{NS_PR}">'
        f'<Relationship Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="/xl/workbook.xml" Id="{r_libro}" />'
        f"</Relationships>"
    )
    estilos = (
        f'<?xml version="1.0" encoding="utf-8"?>'
        f'<x:styleSheet xmlns:x="{NS_X}"><x:fonts count="1"><x:font /></x:fonts>'
        f'<x:fills count="1"><x:fill /></x:fills><x:borders count="1"><x:border /></x:borders>'
        f'<x:cellXfs count="1"><x:xf /></x:cellXfs></x:styleSheet>'
    )
    rels_libro = (
        BOM + f'<?xml version="1.0" encoding="utf-8"?>'
        f'<Relationships xmlns="{NS_PR}">'
        f'<Relationship Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet1.xml" Id="{r_hoja}" />'
        f'<Relationship Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="/xl/styles.xml" Id="{r_estilos}" />'
        f"</Relationships>"
    )
    hoja = (
        f'<?xml version="1.0" encoding="utf-8"?>'
        f'<x:worksheet xmlns:x="{NS_X}"><x:cols><x:col min="1" max="{len(filas[0])}" width="18" customWidth="1" /></x:cols>'
        f'<x:sheetData>{"".join(rows)}</x:sheetData></x:worksheet>'
    )
    content_types = (
        BOM + f'<?xml version="1.0" encoding="utf-8"?>'
        f'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        f'<Default Extension="xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" />'
        f'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />'
        f'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml" />'
        f'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" />'
        f"</Types>"
    )

    entradas = [
        ("xl/workbook.xml", workbook, zipfile.ZIP_DEFLATED),
        ("_rels/.rels", rels_raiz, zipfile.ZIP_STORED),
        ("xl/styles.xml", estilos, zipfile.ZIP_DEFLATED),
        ("xl/_rels/workbook.xml.rels", rels_libro, zipfile.ZIP_DEFLATED),
        ("xl/worksheets/sheet1.xml", hoja, zipfile.ZIP_DEFLATED),
        ("[Content_Types].xml", content_types, zipfile.ZIP_DEFLATED),
    ]

    with zipfile.ZipFile(salida, "w") as z:
        for nombre, contenido, metodo in entradas:
            z.writestr(zipfile.ZipInfo(nombre, (2026, 1, 15, 9, 30, 0)), contenido.encode("utf-8"), compress_type=metodo)
    return salida


if __name__ == "__main__":
    if len(sys.argv) < 7:
        sys.exit(__doc__)

    filas = filas_ficticias(*sys.argv[2:8])
    escribir(sys.argv[1], filas)
    print(f"Generado: {sys.argv[1]} ({len(filas) - 1} filas, estructura SAGE .NET)")
