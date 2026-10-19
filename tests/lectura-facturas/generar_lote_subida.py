"""Facturas SINTETICAS para probar "Subir facturas" (#334). Nunca datos reales.

Crea en <destino>:
- carpeta/: una exportacion de prueba con subcarpetas: Mediformplus y Formacion, un
  abono, una de varias paginas, un CIF sin farmacia, una con numero distinto del
  nombre del fichero, un PDF que no es factura, un .DS_Store, un Excel y una carpeta
  oculta con un PDF (que se tiene que ignorar).
- lote200/: 200 facturas validas seguidas (una de cada 10, de Formacion).

Todas llevan el CIF ficticio de las sinteticas (tests/sintetica.py del parser), salvo
la del CIF sin farmacia. Para que salgan "subidas", ese CIF tiene que ser de una
farmacia del portal local (una farmacia de prueba temporal).

Uso (con el venv del parser, que trae PyMuPDF):
  <repo parser>/venv/bin/python generar_lote_subida.py <repo parser> <destino> [<excel de prueba>]
"""

import sys
from pathlib import Path

repo, destino = Path(sys.argv[1]).resolve(), Path(sys.argv[2])
excel = Path(sys.argv[3]) if len(sys.argv) > 3 else None
sys.path.insert(0, str(repo))

from tests.sintetica import CIF_CLIENTE, EMISOR, EMISOR_FORMACION, crear_factura  # noqa: E402

MDF = {"emisor": EMISOR, "serie": "A", "cif": CIF_CLIENTE}
FORMACION = {"plantilla": "formacion", "emisor": EMISOR_FORMACION, "serie": "SF", "cif": CIF_CLIENTE, "paginas": 1}

carpeta = destino / "carpeta"
(carpeta / "formacion" / "abonos").mkdir(parents=True, exist_ok=True)
(carpeta / ".oculta").mkdir(exist_ok=True)

crear_factura(carpeta / "FAA9101.pdf", **MDF, numero="9101", fecha="15/09/26", total="121,00")
crear_factura(carpeta / "FAA9102.pdf", **MDF, numero="9102", fecha="16/09/26", total="1.234,56", copias=2, paginas_por_copia=3)
crear_factura(carpeta / "FAA9103.pdf", **{**MDF, "cif": "B00000000"}, numero="9103", fecha="17/09/26", total="60,50")
crear_factura(carpeta / "FAA9104.pdf", **MDF, numero="9105", fecha="18/09/26", total="80,00")
crear_factura(carpeta / "formacion" / "FASF9101.pdf", **FORMACION, numero="9101", fecha="30/09/26", total="242,00")
crear_factura(carpeta / "formacion" / "abonos" / "FASF9102.pdf", **FORMACION, numero="9102", fecha="01/10/26", total="-242,00")
crear_factura(carpeta / "no-es-factura.pdf", con_plantilla=False)
crear_factura(carpeta / ".oculta" / "FAA9199.pdf", **MDF, numero="9199", fecha="19/09/26", total="10,00")
(carpeta / ".DS_Store").write_bytes(b"\x00\x00\x00\x01Bud1" + b"\x00" * 64)
if excel:
    (carpeta / "resumen.xlsx").write_bytes(excel.read_bytes())

lote = destino / "lote200"
lote.mkdir(parents=True, exist_ok=True)
for i in range(200):
    numero = str(20000 + i)
    if i % 10 == 0:
        crear_factura(lote / f"FASF{numero}.pdf", **FORMACION, numero=numero, fecha="20/09/26", total=f"{100 + i},00")
    else:
        crear_factura(lote / f"FAA{numero}.pdf", **MDF, numero=numero, fecha="20/09/26", total=f"{100 + i},50")

print(f"Lote de prueba en {destino}: carpeta/ y lote200/ (200 facturas)")
