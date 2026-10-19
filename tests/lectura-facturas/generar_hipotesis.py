"""Facturas SINTETICAS escritas a mano, operador a operador, para reproducir cada
hipotesis de por que una factura real puede leerse mal (prueba de concepto #332;
ahora parte de la regresion de la #333).

Todas pintan la MISMA factura (plantilla de Mediformplus de
Parser_facturas_SAGE/tests/sintetica.py): el parser de Python debe dar "ok" en
todas las que no cambian la orientacion, y el servicio del plugin debe coincidir con el.
Lo unico que cambia es COMO se coloca el texto en el PDF. Nunca datos reales.

Uso: python3 -I generar_hipotesis.py <carpeta destino>
"""

import sys
import zlib
from pathlib import Path

destino = Path(sys.argv[1])

# (x, linea base, texto, tamano): misma geometria que tests/sintetica.py (mdf, 1 pagina).
Y_ROT, Y_VAL, Y_TOT = 214, 238, 554
TEXTOS = [
    (57.8, 75, "Mediformplus, S.L.", 10),
    (57.8, 141, "CIF: B82827635", 8),
    (300, 90, "FARMACIA FICTICIA DE PRUEBA", 8),
    (53.9, Y_ROT, "FECHA", 8), (129.4, Y_ROT, "FACTURA", 8), (238.6, Y_ROT, "CLIENTE", 8),
    (351.5, Y_ROT, "N.I.F", 8), (443.0, Y_ROT, "TELÉFONO", 8), (530.6, Y_ROT, "PÁG.", 8),
    (48.1, Y_VAL, "15/09/26", 8), (139.3, Y_VAL, "A 123", 8), (235.5, Y_VAL, "43000001", 8),
    (337.8, Y_VAL, "B61000014", 8), (443.0, Y_VAL, "910000000", 8), (538.0, Y_VAL, "1", 8),
    (60, 320, "Linea de producto ficticia", 8),
    (42.5, Y_TOT, "TOTAL IMP.", 8), (267.0, Y_TOT, "BASE", 8), (368.4, Y_TOT, "IVA", 8),
    (499.9, 548, "TOTAL FRA", 8), (511.3, Y_TOT + 3, "(EUR)", 8),
    (65, Y_TOT + 21, "1.234,56", 8), (274, Y_TOT + 21, "1.234,56", 8), (513.5, Y_TOT + 44, "1.234,56", 10),
]

# Anchos AFM de Helvetica (milesimas) para los caracteres que se usan: hacen falta para
# colocar a mano texto encadenado o una fila con numeros de TJ exactamente donde va.
ANCHO = {**{c: 556 for c in "0123456789"}, " ": 278, ",": 278, ".": 278, "/": 278, ":": 278, "(": 333, ")": 333,
         "A": 667, "B": 667, "C": 722, "D": 722, "E": 667, "F": 611, "G": 778, "H": 722, "I": 278, "J": 500, "K": 667,
         "L": 556, "M": 833, "N": 722, "O": 778, "P": 667, "Q": 778, "R": 722, "S": 667, "T": 611, "U": 722, "V": 667,
         "W": 944, "X": 667, "Y": 667, "Z": 611, "É": 667, "Á": 667, "a": 556, "c": 500, "d": 556, "e": 556, "f": 278,
         "i": 222, "l": 222, "m": 833, "n": 556, "o": 556, "p": 556, "r": 333, "s": 500, "t": 278, "u": 556, "x": 500}


def ancho(texto, tam):
    return sum(ANCHO[c] for c in texto) * tam / 1000


def lit(texto):
    b = texto.encode("cp1252")
    return b"(" + b.replace(b"\\", b"\\\\").replace(b"(", b"\\(").replace(b")", b"\\)") + b")"


def bt(x, y, texto, tam, extra=b""):
    return b"BT /F1 %g Tf %s%g %g Td %s Tj ET\n" % (tam, extra, x, y, lit(texto))


def contenido_normal(textos=TEXTOS, dx=0.0, dy=0.0):
    return b"".join(bt(x + dx, 842 - y + dy, t, s) for x, y, t, s in textos)


def pdf(contenido, *, mediabox="0 0 595 842", cropbox=None, rotate=None, xobject=None):
    """PDF minimo de una pagina con Helvetica (WinAnsi) como /F1 y, opcional, un Form XObject /X1."""
    objs = []

    def obj(cuerpo):
        objs.append(cuerpo)
        return len(objs)

    def stream(datos, dicc=b""):
        comprimido = zlib.compress(datos)
        return b"<< %s /Length %d /Filter /FlateDecode >>\nstream\n" % (dicc, len(comprimido)) + comprimido + b"\nendstream"

    fuente = obj(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>")
    recursos = b"/Font << /F1 %d 0 R >>" % fuente
    if xobject is not None:
        datos_xo, matriz = xobject
        xo = obj(stream(datos_xo, b"/Type /XObject /Subtype /Form /BBox [-2000 -2000 4000 4000] /Matrix [%s] /Resources << %s >>" % (matriz.encode(), recursos)))
        recursos += b" /XObject << /X1 %d 0 R >>" % xo
    cont = obj(stream(contenido))
    extra = b""
    if cropbox:
        extra += b" /CropBox [%s]" % cropbox.encode()
    if rotate is not None:
        extra += b" /Rotate %d" % rotate
    pagina = len(objs) + 2
    paginas = obj(b"<< /Type /Pages /Kids [%d 0 R] /Count 1 >>" % pagina)
    obj(b"<< /Type /Page /Parent %d 0 R /MediaBox [%s]%s /Resources << %s >> /Contents %d 0 R >>" % (paginas, mediabox.encode(), extra, recursos, cont))
    catalogo = obj(b"<< /Type /Catalog /Pages %d 0 R >>" % paginas)

    salida = b"%PDF-1.7\n%\xe2\xe3\xcf\xd3\n"
    offsets = []
    for i, cuerpo in enumerate(objs, 1):
        offsets.append(len(salida))
        salida += b"%d 0 obj\n" % i + cuerpo + b"\nendobj\n"
    xref = len(salida)
    salida += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objs) + 1) + b"".join(b"%010d 00000 n \n" % o for o in offsets)
    salida += b"trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objs) + 1, catalogo, xref)
    return salida


def tj_numero_inicial(desplazamiento):
    """Cada texto en un TJ que EMPIEZA por un numero: [-N (texto)] TJ, con el Td corregido
    para que se pinte en el mismo sitio. desplazamiento = N/1000 * tamano (en pt)."""
    out = b""
    for x, y, t, s in TEXTOS:
        n = desplazamiento * 1000 / s
        out += b"BT /F1 %g Tf %g %g Td [%g %s] TJ ET\n" % (s, x - desplazamiento, 842 - y, -n, lit(t))
    return out


def tj_numeros_seguidos():
    """Dos numeros seguidos dentro del TJ: [(A) -100 -200 (B)] (equivalen a -300)."""
    out = b""
    for x, y, t, s in TEXTOS:
        if " " in t:
            a, b = t.split(" ", 1)
            hueco = 1000 * ANCHO[" "] / 1000  # en milesimas de em: el ancho del espacio
            out += b"BT /F1 %g Tf %g %g Td [%s %g %g %s] TJ ET\n" % (s, x, 842 - y, lit(a), -hueco / 2, -hueco / 2, lit(b))
        else:
            out += bt(x, 842 - y, t, s)
    return out


def fila_con_tj():
    """Cada fila de cabecera y de valores en UN solo TJ, con las columnas colocadas por
    numeros del array (como hacen algunos generadores de informes)."""
    filas = {}
    for x, y, t, s in TEXTOS:
        filas.setdefault((y, s), []).append((x, t))
    out = b""
    for (y, s), celdas in filas.items():
        celdas.sort()
        x0, t0 = celdas[0]
        partes = [lit(t0)]
        cursor = x0 + ancho(t0, s)
        for x, t in celdas[1:]:
            partes.append(b"%g" % (-(x - cursor) * 1000 / s))
            partes.append(lit(t))
            cursor = x + ancho(t, s)
        out += b"BT /F1 %g Tf %g %g Td [%s] TJ ET\n" % (s, x0, 842 - y, b" ".join(partes))
    return out


def tj_encadenados():
    """Varios Tj seguidos en el mismo BT sin Td entre ellos: el cursor avanza solo."""
    out = b""
    for x, y, t, s in TEXTOS:
        trozos = t.split(" ")
        cuerpo = b" ".join(lit(tr + (" " if i < len(trozos) - 1 else "")) + b" Tj" for i, tr in enumerate(trozos))
        out += b"BT /F1 %g Tf %g %g Td %s ET\n" % (s, x, 842 - y, cuerpo)
    return out


def comillas_y_tstar():
    """Posicion con TL + T* y los operadores ' y "."""
    out = b""
    for i, (x, y, t, s) in enumerate(TEXTOS):
        # Td a la linea de encima y luego bajar una linea de TL = 12.
        if i % 2:
            out += b"BT /F1 %g Tf 12 TL %g %g Td %s ' ET\n" % (s, x, 842 - y + 12, lit(t))
        else:
            out += b"BT /F1 %g Tf 12 TL %g %g Td 0 0 %s \" ET\n" % (s, x, 842 - y + 12, lit(t))
    return out


def con_tz_tc_tw():
    """Tz (escala horizontal), Tc y Tw: el texto se estrecha y se separa, el inicio no cambia."""
    return b"".join(b"BT /F1 %g Tf 90 Tz 0.2 Tc 0.5 Tw %g %g Td %s Tj ET\n" % (s, x, 842 - y, lit(t)) for x, y, t, s in TEXTOS)


def parentesis():
    """Literales con parentesis anidados sin escapar y con escapes octales."""
    out = b""
    for x, y, t, s in TEXTOS:
        if t == "(EUR)":
            out += b"BT /F1 %g Tf %g %g Td [(\\(EUR) (\\051)] TJ ET\n" % (s, x, 842 - y)  # "(EUR" + ")" octal
        elif t == "Linea de producto ficticia":
            out += b"BT /F1 %g Tf %g %g Td ((Linea) de (producto) ficticia) Tj ET\n" % (s, x - ancho("(", s), 842 - y)
        else:
            out += bt(x, 842 - y, t, s)
    return out


CASOS = {
    "h00_base": pdf(contenido_normal()),
    "h01_tj_numero_inicial_577": pdf(tj_numero_inicial(577.5)),
    "h02_tj_numero_inicial_10": pdf(tj_numero_inicial(10.0)),
    "h03_tj_numeros_seguidos": pdf(tj_numeros_seguidos()),
    "h04_fila_con_tj": pdf(fila_con_tj()),
    "h05_tj_encadenados": pdf(tj_encadenados()),
    "h06_comillas_y_tstar": pdf(comillas_y_tstar()),
    "h07_tz_tc_tw": pdf(con_tz_tc_tw()),
    "h08_parentesis": pdf(parentesis()),
    "h09_cm_global": pdf(b"q 1 0 0 1 -577.5 0 cm\n" + contenido_normal(dx=577.5) + b"Q\n"),
    "h10_xobject_matrix": pdf(b"q /X1 Do Q\n", xobject=(contenido_normal(dx=577.5), "1 0 0 1 -577.5 0")),
    "h11_xobject_cm": pdf(b"q 1 0 0 1 -577.5 0 cm /X1 Do Q\n", xobject=(contenido_normal(dx=577.5), "1 0 0 1 0 0")),
    "h12_mediabox_origen": pdf(contenido_normal(dx=100, dy=50), mediabox="100 50 695 892"),
    "h13_cropbox": pdf(contenido_normal(dx=20, dy=-30), mediabox="0 -100 700 900", cropbox="20 -30 615 812"),
    "h14_rotate_90": pdf(contenido_normal(), rotate=90),
    "h15_rotate_180": pdf(contenido_normal(), rotate=180),
    "h16_rotate_270": pdf(contenido_normal(), rotate=270),
}

for caso, datos in CASOS.items():
    (destino / caso).mkdir(parents=True, exist_ok=True)
    (destino / caso / "FAA123.pdf").write_bytes(datos)
print(f"{len(CASOS)} facturas de hipotesis en {destino}")
