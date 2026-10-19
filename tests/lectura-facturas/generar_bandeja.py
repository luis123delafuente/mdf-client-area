"""Facturas SINTETICAS para probar la Bandeja de facturas (#335). Nunca datos reales.

Crea en <destino>/bandeja/ una de cada motivo que entra en la bandeja y algunas para
probar asignar, descartar y "Volver a comprobar todas":
- FAA9201: CIF valido sin farmacia (CIF_A) -> asignar a una farmacia con ese CIF (coincide).
- FAA9202: CIF valido sin farmacia (CIF_B) -> asignar a otra farmacia (pide confirmacion).
- FAA9203: sin texto (escaneo) -> asignar pidiendo sociedad, serie, numero y fecha.
- FAA9204: CIF valido sin farmacia (CIF_C) -> dar de alta la farmacia y "Volver a comprobar".
- FAA9205: sin CIF.  FAA9206: CIF invalido.  FAA9207: numero distinto del nombre.
- otro-documento.pdf: no parece una factura de SAGE (formato inesperado) -> descartar.
y en <destino>/repetidas/: FAA9201 con otros bytes (otro total): misma factura, no entra dos veces.

Imprime los CIF ficticios A, B y C para dar de alta las farmacias de prueba.

Uso (con el venv del parser):
  <repo parser>/venv/bin/python generar_bandeja.py <repo parser> <destino>
"""

import sys
from pathlib import Path

repo, destino = Path(sys.argv[1]).resolve(), Path(sys.argv[2])
sys.path.insert(0, str(repo))

from tests.sintetica import CIF_INVALIDO, EMISOR, crear_factura  # noqa: E402

LETRAS = "JABCDEFGHI"


def cif_valido(digitos: str) -> str:
    """CIF ficticio de sociedad limitada (B) con su digito de control correcto."""
    suma = 0
    for i, c in enumerate(digitos):
        d = int(c)
        if i % 2 == 0:
            d *= 2
            d = d - 9 if d > 9 else d
        suma += d
    return "B" + digitos + str((10 - suma % 10) % 10)


CIF_A, CIF_B, CIF_C = cif_valido("9100001"), cif_valido("9100002"), cif_valido("9100003")
MDF = {"emisor": EMISOR, "serie": "A", "fecha": "15/09/26", "total": "121,00"}

bandeja = destino / "bandeja"
repetidas = destino / "repetidas"
bandeja.mkdir(parents=True, exist_ok=True)
repetidas.mkdir(parents=True, exist_ok=True)

crear_factura(bandeja / "FAA9201.pdf", **MDF, numero="9201", cif=CIF_A)
crear_factura(bandeja / "FAA9202.pdf", **MDF, numero="9202", cif=CIF_B)
crear_factura(bandeja / "FAA9203.pdf", **MDF, numero="9203", cif=CIF_A, sin_texto=True)
crear_factura(bandeja / "FAA9204.pdf", **MDF, numero="9204", cif=CIF_C)
crear_factura(bandeja / "FAA9205.pdf", **MDF, numero="9205", cif="")
crear_factura(bandeja / "FAA9206.pdf", **MDF, numero="9206", cif=CIF_INVALIDO)
crear_factura(bandeja / "FAA9207.pdf", **MDF, numero="9208", cif=CIF_A)
crear_factura(bandeja / "otro-documento.pdf", con_plantilla=False)
crear_factura(repetidas / "FAA9201.pdf", **{**MDF, "total": "122,00"}, numero="9201", cif=CIF_A)

print(f"CIF_A={CIF_A} CIF_B={CIF_B} CIF_C={CIF_C}")
