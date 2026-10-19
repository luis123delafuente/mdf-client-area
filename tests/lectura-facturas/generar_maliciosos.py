"""PDF MALICIOSOS o rotos para la lectura de facturas en PHP (#333): uno por cada
tope de Pdf_Limites y por cada camino de smalot/pdfparser que se ha acotado, mas los
casos de siempre (no es un PDF, truncado, cifrado). Todos son sinteticos.

Uso:
  <repo parser>/venv/bin/python -I generar_maliciosos.py <carpeta destino> [<factura sintetica valida>]

La factura valida (opcional) sirve de base para los truncados y el cifrado; sin ella
se usa un PDF minimo con texto. El cifrado necesita PyMuPDF (venv del parser).
Despues: probar_maliciosos.py <carpeta destino> --php <php 8.4>.
"""

import io
import random
import struct
import sys
import zipfile
import zlib
from pathlib import Path

MB = 1024 * 1024
FUENTE = b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"


def flujo(diccionario: bytes, datos: bytes) -> bytes:
    return b"<< " + diccionario + b" /Length %d >>\nstream\n" % len(datos) + datos + b"\nendstream"


def pdf(objetos: list, raiz: int = 1, extra_trailer: bytes = b"") -> bytes:
    """objetos[i] es el cuerpo del objeto i+1. Tabla xref clasica."""
    out = bytearray(b"%PDF-1.7\n%\xe2\xe3\xcf\xd3\n")
    offsets = []
    for i, cuerpo in enumerate(objetos, 1):
        offsets.append(len(out))
        out += b"%d 0 obj\n" % i + cuerpo + b"\nendobj\n"
    xref = len(out)
    out += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objetos) + 1)
    out += b"".join(b"%010d 00000 n \n" % o for o in offsets)
    out += b"trailer\n<< /Size %d /Root %d 0 R %s>>\nstartxref\n%d\n%%%%EOF\n" % (len(objetos) + 1, raiz, extra_trailer, xref)
    return bytes(out)


def una_pagina(contenido: bytes, recursos: bytes = b"<< /Font << /F1 4 0 R >> >>", diccionario_contenido: bytes = b"", extra: list = ()) -> bytes:
    """Catalogo (1), Pages (2), Page (3), fuente (4), contenido (5) y objetos extra (6...)."""
    return pdf([
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources " + recursos + b" /Contents 5 0 R >>",
        FUENTE,
        flujo(diccionario_contenido, contenido),
        *extra,
    ])


def bomba_flate(bytes_descomprimidos: int, relleno: bytes = b"0 ") -> bytes:
    """Flujo zlib que se descomprime a bytes_descomprimidos, sin tenerlos en memoria."""
    c = zlib.compressobj(9)
    trozo = relleno * (MB // len(relleno))
    partes = [c.compress(trozo) for _ in range(bytes_descomprimidos // len(trozo))]
    return b"".join(partes) + c.flush()


def png_minimo() -> bytes:
    def trozo(tipo, datos):
        return struct.pack(">I", len(datos)) + tipo + datos + struct.pack(">I", zlib.crc32(tipo + datos) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + trozo(b"IHDR", struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0)) + trozo(b"IDAT", zlib.compress(b"\0\0\0\0")) + trozo(b"IEND", b"")


TEXTO = b"BT /F1 12 Tf 72 720 Td (Factura de prueba) Tj ET"


def casos(base: bytes | None) -> dict:
    c = {}

    # --- Lo que pide la tarea -----------------------------------------------------
    c["bomba_flate_1gb"] = una_pagina(bomba_flate(1024 * MB), diccionario_contenido=b"/Filter /FlateDecode")
    c["paginas_1000"] = pdf(
        [b"<< /Type /Catalog /Pages 2 0 R >>",
         b"<< /Type /Pages /Kids [" + b" ".join(b"%d 0 R" % (5 + i) for i in range(1000)) + b"] /Count 1000 >>",
         FUENTE,
         flujo(b"", TEXTO)]
        + [b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents 4 0 R >>"] * 1000
    )
    referencia = base or una_pagina(TEXTO)
    c["truncado_mitad"] = referencia[: len(referencia) // 2]
    c["truncado_sin_final"] = referencia[:-40]
    c["truncado_solo_cabecera"] = b"%PDF-1.7\n"
    c["no_pdf_png"] = png_minimo()
    c["no_pdf_aleatorio"] = random.Random(333).randbytes(20000)
    c["no_pdf_vacio"] = b""
    zip_mem = io.BytesIO()
    with zipfile.ZipFile(zip_mem, "w") as z:
        z.writestr("a.txt", "hola")
    c["no_pdf_zip"] = zip_mem.getvalue()
    c["no_pdf_texto"] = b"Esto no es un PDF, aunque se llame .pdf\n" * 100

    # --- Un caso por cada tope o camino acotado ----------------------------------
    c["tamano_11mb"] = una_pagina(TEXTO) + b"%" + b"x" * (11 * MB) + b"\n"
    c["bomba_flate_muchos_flujos"] = pdf(
        [b"<< /Type /Catalog /Pages 2 0 R >>",
         b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
         b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents [" + b" ".join(b"%d 0 R" % (5 + i) for i in range(100)) + b"] >>",
         FUENTE]
        + [flujo(b"/Filter /FlateDecode", bomba_flate(8 * MB))] * 100
    )
    c["bomba_cadena_hex_flate"] = una_pagina(bomba_flate(512 * MB).hex().encode() + b">", diccionario_contenido=b"/Filter [/ASCIIHexDecode /FlateDecode]")
    c["bomba_runlength"] = una_pagina(b"\x81\x20" * (2 * MB) + b"\x80", diccionario_contenido=b"/Filter /RunLengthDecode")
    indice = b" ".join(b"%d %d" % (10 + i, i) for i in range(300000))
    c["bomba_object_stream"] = una_pagina(TEXTO, extra=[flujo(b"/Type /ObjStm /N 300000 /First %d /Filter /FlateDecode" % (len(indice) + 1), zlib.compress(indice + b" " + b"0 " * 300000))])
    c["array_enorme"] = una_pagina(TEXTO, extra=[b"<< /Basura [" + b"0 " * (2 * MB) + b"] >>"])
    c["anidamiento_objeto"] = una_pagina(TEXTO, extra=[b"<< /Basura " + b"[" * 100000 + b"]" * 100000 + b" >>"])
    c["anidamiento_contenido"] = una_pagina(zlib.compress(b"[" * 200000), diccionario_contenido=b"/Filter /FlateDecode")
    c["operandos_sin_operador"] = una_pagina(bomba_flate(64 * MB, b"1 "), diccionario_contenido=b"/Filter /FlateDecode")
    c["operaciones_muchas"] = una_pagina(bomba_flate(15 * MB, b"q Q "), diccionario_contenido=b"/Filter /FlateDecode")
    c["palabras_muchas"] = una_pagina(zlib.compress(b"BT /F1 1 Tf 0 800 Td " + b"(a) Tj 3 0 Td " * 30000 + b"ET"), diccionario_contenido=b"/Filter /FlateDecode")
    c["form_recursivo"] = una_pagina(
        b"/X1 Do",
        recursos=b"<< /Font << /F1 4 0 R >> /XObject << /X1 6 0 R >> >>",
        extra=[flujo(b"/Type /XObject /Subtype /Form /BBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> /XObject << /X1 6 0 R /X2 7 0 R >> >>", TEXTO + b" /X1 Do /X2 Do /X1 Do"),
               flujo(b"/Type /XObject /Subtype /Form /BBox [0 0 595 842] /Resources << /XObject << /X1 6 0 R /X2 7 0 R >> >>", b"/X1 Do /X2 Do /X1 Do /X2 Do")],
    )
    c["tounicode_4000_millones"] = pdf([
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 6 0 R >>",
        flujo(b"", TEXTO),
        flujo(b"", b"/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n1 begincodespacerange <00000000> <FFFFFFFF> endcodespacerange\n"
                   b"1 beginbfrange\n<00000000> <FFFFFFFF> <0000>\nendbfrange\nendcmap end end\n"),
    ])
    c["w_4000_millones"] = pdf([
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
        b"<< /Type /Font /Subtype /Type0 /BaseFont /Helvetica /Encoding /Identity-H /DescendantFonts [6 0 R] >>",
        flujo(b"", b"BT /F1 12 Tf 72 720 Td <00410042> Tj ET"),
        b"<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Helvetica /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /W [0 4000000000 500] >>",
    ])
    # Arbol de paginas con nodos compartidos: cada nivel tiene dos hijos que son el
    # mismo nodo; 2^40 paginas si se recorre entero.
    niveles = 40
    arbol = [b"<< /Type /Catalog /Pages 2 0 R >>"]
    for k in range(niveles):
        hijo = 3 + k
        arbol.append(b"<< /Type /Pages /Kids [%d 0 R %d 0 R] /Count %d >>" % (hijo, hijo, 2 ** (niveles - k)))
    arbol.append(b"<< /Type /Page /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>" % (niveles + 3, niveles + 4))
    arbol += [FUENTE, flujo(b"", TEXTO)]
    c["arbol_paginas_compartido"] = pdf(arbol)
    # Flujo de referencias cruzadas con /W y /Columns enormes (smalot hace array_fill y
    # recorre /W byte a byte).
    for nombre, dic in (("xref_w_enorme", b"/W [1 100000000 1]"), ("xref_columnas_enormes", b"/W [1 2 1] /DecodeParms << /Columns 1000000000 /Predictor 12 >>")):
        cuerpo = bytearray(una_pagina(TEXTO).split(b"xref\n")[0])
        n = cuerpo.count(b" 0 obj\n") + 1
        offset = len(cuerpo)
        cuerpo += b"%d 0 obj\n" % n + flujo(b"/Type /XRef /Size %d /Root 1 0 R " % (n + 1) + dic, b"\x01\x00\x0f\x00" * 8) + b"\nendobj\n"
        cuerpo += b"startxref\n%d\n%%%%EOF\n" % offset
        c[nombre] = bytes(cuerpo)
    c["sin_texto_solo_imagen"] = una_pagina(b"q 100 0 0 100 0 0 cm /Im1 Do Q", recursos=b"<< /XObject << /Im1 6 0 R >> >>",
                                            extra=[flujo(b"/Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8", b"\0")])
    return c


def main() -> int:
    destino = Path(sys.argv[1])
    base = Path(sys.argv[2]).read_bytes() if len(sys.argv) > 2 else None
    destino.mkdir(parents=True, exist_ok=True)
    generados = casos(base)
    for nombre, datos in generados.items():
        (destino / f"{nombre}.pdf").write_bytes(datos)

    try:
        import fitz
    except ImportError:
        print("Sin PyMuPDF: no se generan los cifrados.")
    else:
        origen = fitz.open(stream=base or una_pagina(TEXTO), filetype="pdf")
        origen.save(str(destino / "cifrado_sin_contrasena_de_apertura.pdf"), encryption=fitz.PDF_ENCRYPT_AES_256, owner_pw="propietario", user_pw="")
        origen.save(str(destino / "cifrado_con_contrasena.pdf"), encryption=fitz.PDF_ENCRYPT_AES_256, owner_pw="propietario", user_pw="usuario")
        origen.close()
    print(f"{len(list(destino.glob('*.pdf')))} PDF maliciosos o rotos en {destino}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
