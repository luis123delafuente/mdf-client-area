"""Genera el conjunto de facturas SINTETICAS de la regresion de la lectura de
facturas en PHP (#333; nacio en la prueba de concepto #332).

Reutiliza el generador de los tests del parser (Parser_facturas_SAGE/tests/sintetica.py):
mismos casos que tests/test_parser.py, los cinco de ~/Pruebas-robotito/generar.py
(con un CIF ficticio en lugar del de prueba) y algunos extra (CIF mal formado, PDF
sin texto, fichero que no es PDF). Nunca datos reales.

Uso (con el venv del parser, que trae PyMuPDF):
  <repo parser>/venv/bin/python generar_sinteticas.py <repo parser> <carpeta destino>
"""

import sys
from pathlib import Path

repo, destino = Path(sys.argv[1]).resolve(), Path(sys.argv[2])
sys.path.insert(0, str(repo))

from tests.sintetica import (  # noqa: E402
    CIF_CLIENTE,
    CIF_INVALIDO,
    EMISOR,
    EMISOR_FORMACION,
    NIF_CLIENTE,
    crear_factura,
)

FORMACION = {"plantilla": "formacion", "emisor": EMISOR_FORMACION, "serie": "SF", "numero": "186", "paginas": 1}

# (nombre del caso, nombre del fichero, argumentos de crear_factura)
CASOS = [
    # --- tests/test_parser.py (Mediformplus) ---
    ("cif_correcto", "FAA123.pdf", {}),
    ("nif_persona_fisica", "FAA123.pdf", {"cif": NIF_CLIENTE}),
    ("emisor_en_columna_cliente", "FAA123.pdf", {"cif": EMISOR}),
    ("emisor_no_configurado", "FAA123.pdf", {"emisor": "A28000057"}),
    ("cif_control_erroneo", "FAA123.pdf", {"cif": CIF_INVALIDO}),
    ("cif_guiones_minusculas", "FAA123.pdf", {"cif": "b-61000014"}),
    ("sin_cif", "FAA123.pdf", {"cif": ""}),
    ("no_es_plantilla", "FAA123.pdf", {"con_plantilla": False}),
    ("sin_texto", "FAA123.pdf", {"sin_texto": True}),
    ("numero_distinto_nombre", "FAA124.pdf", {}),
    ("nombre_sin_patron", "factura.pdf", {}),
    ("abono", "FAA123.pdf", {"total": "-1.234,56"}),
    ("copia_con_otros_datos", "FAA123.pdf", {"cif_copia": NIF_CLIENTE}),
    ("una_sola_pagina", "FAA123.pdf", {"paginas": 1}),
    ("fecha_imposible", "FAA123.pdf", {"fecha": "31/02/26"}),
    ("fecha_iso", "FAA123.pdf", {"fecha": "2026-09-15"}),
    ("importe_sin_formato", "FAA123.pdf", {"total": "1234.56"}),
    ("numero_con_letra", "FAA123.pdf", {"numero": "12X"}),
    ("moneda_doble_mdf", "FAA123.pdf", {"simbolo_eur": True}),
    ("moneda_ninguna_mdf", "FAA123.pdf", {"rotulo_eur": False}),
    ("varias_paginas_2x2", "FAA123.pdf", {"copias": 2, "paginas_por_copia": 2}),
    ("varias_paginas_1x3", "FAA123.pdf", {"copias": 1, "paginas_por_copia": 3}),
    ("varias_paginas_abono", "FAA123.pdf", {"paginas_por_copia": 2, "total": "-1.234,56"}),
    ("total_en_intermedia", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {0: {"con_total": True}}}),
    ("total_solo_en_intermedia", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {0: {"con_total": True}, 1: {"con_total": False}}}),
    ("intermedia_sin_suma_y_sigue", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {2: {"suma_y_sigue": False}}}),
    ("ultima_con_suma_y_sigue", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {1: {"suma_y_sigue": True}}}),
    ("cabecera_distinta_en_copia", "FAA123.pdf", {"copias": 1, "paginas_por_copia": 2, "ajustes": {1: {"cif": NIF_CLIENTE}}}),
    ("cabecera_distinta_entre_copias", "FAA123.pdf", {"paginas_por_copia": 2, "cif_copia": NIF_CLIENTE}),
    ("total_distinto_entre_copias", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {3: {"total": "1.234,57"}}}),
    ("copias_de_distinto_largo", "FAA123.pdf", {"paginas_por_copia": [2, 1]}),
    ("numeracion_salta", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {1: {"pag": 3}}}),
    ("marcador_cambia", "FAA123.pdf", {"paginas_por_copia": 2, "ajustes": {3: {"marcador": 2}}}),
    # --- Formacion ---
    ("formacion_cif", "FASF186.pdf", FORMACION),
    ("formacion_nif", "FASF186.pdf", {**FORMACION, "cif": NIF_CLIENTE}),
    ("formacion_abono_con_copia", "FASF186.pdf", {**FORMACION, "total": "-1.234,56", "paginas": 2}),
    ("formacion_varias_paginas", "FASF186.pdf", {**FORMACION, "paginas_por_copia": 2}),
    ("formacion_emisor_en_columna", "FASF186.pdf", {**FORMACION, "cif": EMISOR_FORMACION}),
    ("formacion_serie_de_otro", "FAA186.pdf", {**FORMACION, "serie": "A"}),
    ("mdf_serie_de_formacion", "FASF123.pdf", {"serie": "SF"}),
    ("formacion_serie_tres_letras", "FASFX186.pdf", {**FORMACION, "serie": "SFX"}),
    ("formacion_nombre_otro", "FASF187.pdf", FORMACION),
    ("formacion_moneda_doble", "FASF186.pdf", {**FORMACION, "rotulo_eur": True}),
    ("formacion_moneda_ninguna", "FASF186.pdf", {**FORMACION, "simbolo_eur": False}),
    # --- ~/Pruebas-robotito/generar.py, con CIF ficticio ---
    ("robotito_A9001", "FAA9001.pdf", {"emisor": EMISOR, "serie": "A", "numero": "9001", "fecha": "14/08/26", "total": "121,00", "cif": CIF_CLIENTE}),
    ("robotito_SF9001", "FASF9001.pdf", {**FORMACION, "numero": "9001", "fecha": "30/09/26", "total": "242,00", "cif": CIF_CLIENTE}),
    ("robotito_SF9002_abono", "FASF9002.pdf", {**FORMACION, "numero": "9002", "fecha": "01/10/26", "total": "-242,00", "cif": CIF_CLIENTE}),
    ("robotito_A9003_3_paginas", "FAA9003.pdf", {"emisor": EMISOR, "serie": "A", "numero": "9003", "fecha": "15/09/26", "total": "1.234,56", "cif": CIF_CLIENTE, "copias": 2, "paginas_por_copia": 3}),
    ("robotito_A9004_sin_farmacia", "FAA9004.pdf", {"emisor": EMISOR, "serie": "A", "numero": "9004", "fecha": "20/09/26", "total": "60,50", "cif": "B00000000"}),
    # --- extra ---
    ("cif_mal_formado", "FAA123.pdf", {"cif": "XX-12"}),
    ("cif_largo", "FAA123.pdf", {"cif": "B610000141"}),
    ("importe_grande", "FAA123.pdf", {"total": "123.456.789,01"}),
]

destino.mkdir(parents=True, exist_ok=True)
for caso, fichero, kwargs in CASOS:
    carpeta = destino / caso
    carpeta.mkdir(exist_ok=True)
    crear_factura(carpeta / fichero, **kwargs)

# Un fichero que no es un PDF.
(destino / "no_es_pdf").mkdir(exist_ok=True)
(destino / "no_es_pdf" / "FAA123.pdf").write_bytes(b"esto no es un PDF\n" * 20)

print(f"{len(CASOS) + 1} facturas sinteticas en {destino}")
