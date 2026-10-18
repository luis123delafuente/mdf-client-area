"""Genera variantes maliciosas o falsas del Excel ficticio del 347 (#307).

Uso: python3 -I tests/generar-347-maliciosos.py <origen.xlsx> <carpeta> [<prefijo>]
  python3 -I tests/generar-347-maliciosos.py tests/fixtures/347-ficticio.xlsx tests/fixtures
  python3 -I tests/generar-347-maliciosos.py tests/fixtures/347-sage-net.xlsx tests/fixtures 347-sage

Con el formato de SAGE (sin Override para el libro, tipo por Default xml),
"xlsm-renombrado" cambia el Default; "override-macro" anade un Override
macroEnabled para /xl/workbook.xml sobre un Default main (gana el Override).

Todas deben rechazarse ENTERAS por el generador, sin escribir nada.
No se despliega (tests/).
"""
import re
import sys
import zipfile

origen, destino = sys.argv[1], sys.argv[2]
prefijo = sys.argv[3] if len(sys.argv) > 3 else "347"
src = zipfile.ZipFile(origen)


def copiar(nombre, cambios=None, extra=None):
    """Copia el xlsx aplicando cambios {entrada: funcion(bytes)->bytes} y anadiendo extra {entrada: bytes}."""
    cambios = cambios or {}
    with zipfile.ZipFile(f"{destino}/{nombre}", "w", zipfile.ZIP_DEFLATED) as z:
        for info in src.infolist():
            datos = src.read(info.filename)
            if info.filename in cambios:
                datos = cambios[info.filename](datos)
            z.writestr(info.filename, datos)
        for entrada, datos in (extra or {}).items():
            z.writestr(entrada, datos)
    print("generado", nombre)


hoja = next(n for n in src.namelist() if n.startswith("xl/worksheets/sheet1"))
# Cabecera de la columna RECC: inline (OpenSpout) o t="str" con prefijo x: (SAGE).
CABECERA_RECC = (rb"<t>RECC</t>", b"<t>OTRA</t>") if b"<t>RECC</t>" in src.read(hoja) else (rb"<x:v>RECC</x:v>", b"<x:v>OTRA</x:v>")

# 1. DOCTYPE con entidad externa en la hoja (XXE).
copiar(f"{prefijo}-doctype.xlsx", {
    hoja: lambda b: b.replace(
        b"?>", b'?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>', 1
    )
})

# 2. xlsm renombrado: libro principal con tipo macroEnabled.
copiar(f"{prefijo}-xlsm-renombrado.xlsx", {
    "[Content_Types].xml": lambda b: b.replace(
        b"spreadsheetml.sheet.main+xml", b"sheet.macroEnabled.main+xml"
    )
})

# 3. Proyecto VBA dentro.
copiar(f"{prefijo}-vba.xlsx", extra={"xl/vbaProject.bin": b"\0" * 1024})

# 4. Zip bomb: 210 MB de ceros declarados (pocos KB comprimidos).
with zipfile.ZipFile(f"{destino}/{prefijo}-zip-bomb.xlsx", "w", zipfile.ZIP_DEFLATED) as z:
    for info in src.infolist():
        z.writestr(info.filename, src.read(info.filename))
    with z.open("xl/media/bomba.bin", "w", force_zip64=True) as f:
        bloque = b"\0" * (1024 * 1024)
        for _ in range(210):
            f.write(bloque)
print(f"generado {prefijo}-zip-bomb.xlsx")

# 5. Falta la columna RECC en la cabecera.
copiar(f"{prefijo}-sin-columna.xlsx", {
    hoja: lambda b: re.sub(CABECERA_RECC[0], CABECERA_RECC[1], b, count=1)
})

# 6. Sin hoja "347".
copiar(f"{prefijo}-sin-hoja.xlsx", {
    "xl/workbook.xml": lambda b: b.replace(b'name="347"', b'name="Datos"')
})

# 8. Override macroEnabled para el libro, aunque el Default diga main: gana el Override.
copiar(f"{prefijo}-override-macro.xlsx", {
    "[Content_Types].xml": lambda b: b.replace(
        b"</Types>",
        b'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"/></Types>',
    ).replace(
        b'PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"', b'PartName="/xl/otro.xml" ContentType="application/xml"'
    )
})

# 7. Un PDF con extension .xlsx.
with open(f"{destino}/{prefijo}-pdf-renombrado.xlsx", "wb") as f:
    f.write(b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")
print(f"generado {prefijo}-pdf-renombrado.xlsx")
