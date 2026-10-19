"""Regresion de la lectura de facturas en PHP (#333): compara el parser de Python
(Parser_facturas_SAGE) con el servicio del plugin, Factura_Lectura_Service
(a traves de analizar.php), factura a factura y campo a campo.

Pensado para ejecutarse con FACTURAS REALES: nunca imprime valores (ni CIF, ni
numeros, ni importes, ni fechas) y, por defecto, tampoco el nombre del fichero
(lleva la serie y el numero). Solo "coincide" o "no coincide en: <campos>", y
al final tiempos y memoria del servicio PHP. No escribe ningun fichero.

Uso (con el venv del parser, que trae PyMuPDF):
  <repo parser>/venv/bin/python comparar.py <carpeta con PDF> \
      --repo-parser <ruta a Parser_facturas_SAGE> [--php <binario php 8.4>] [--mostrar-nombres]

Busca *.pdf (mayusculas o minusculas) en la carpeta y sus subcarpetas.
Devuelve 0 si todas coinciden y 1 si alguna no.
"""

import argparse
import json
import statistics
import subprocess
import sys
from pathlib import Path

CAMPOS = ("estado", "emisor", "serie", "numero", "cif", "fecha", "importe_total_centimos", "paginas")
CAMPOS_EXTRA = ("es_abono", "campos_no_extraidos")
ETIQUETAS = {"importe_total_centimos": "total"}


def resultado_python(ruta: Path) -> dict:
    from parser_facturas.parser import parsear_factura
    import fitz

    r = parsear_factura(str(ruta)).a_dict()
    try:
        with fitz.open(str(ruta)) as doc:
            r["paginas"] = len(doc)
    except Exception:
        r["paginas"] = None
    return r


def resultado_php(ruta: Path, php: str, analizar: Path) -> dict:
    salida = subprocess.run([php, str(analizar), str(ruta)], capture_output=True, text=True, timeout=120)
    if salida.returncode != 0 or not salida.stdout.strip():
        # Nunca se reenvia stderr: podria citar contenido del PDF.
        return {"_error": f"el servicio PHP termino con codigo {salida.returncode}"}
    return json.loads(salida.stdout.strip().splitlines()[-1])


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("carpeta", type=Path)
    ap.add_argument("--repo-parser", type=Path, required=True)
    ap.add_argument("--php", default="php")
    ap.add_argument("--mostrar-nombres", action="store_true", help="imprime el nombre del fichero (solo con sinteticas)")
    args = ap.parse_args()

    sys.path.insert(0, str(args.repo_parser.resolve()))
    analizar = Path(__file__).resolve().parent / "analizar.php"
    pdfs = sorted(p for p in args.carpeta.rglob("*") if p.is_file() and p.suffix.lower() == ".pdf")
    if not pdfs:
        print("No hay ningun PDF en la carpeta.")
        return 1

    distintas = 0
    por_campo = {c: 0 for c in CAMPOS + CAMPOS_EXTRA}
    tiempos = {"1": [], "2-3": [], "4+": []}
    memorias = []

    for i, pdf in enumerate(pdfs, 1):
        etiqueta = f"factura {i:03d}" + (f" ({pdf.parent.name}/{pdf.name})" if args.mostrar_nombres else "")
        py = resultado_python(pdf)
        ph = resultado_php(pdf, args.php, analizar)

        if "_error" in ph:
            distintas += 1
            print(f"{etiqueta}: NO coincide ({ph['_error']})")
            continue

        difieren = [c for c in CAMPOS if py.get(c) != ph.get(c)]
        extra = [c for c in CAMPOS_EXTRA if py.get(c) != ph.get(c)]
        for c in difieren + extra:
            por_campo[c] += 1

        if difieren:
            distintas += 1
            print(f"{etiqueta}: NO coincide en: {', '.join(ETIQUETAS.get(c, c) for c in difieren)}"
                  + (f" (ademas: {', '.join(extra)})" if extra else ""))
        else:
            print(f"{etiqueta}: coincide" + (f" (salvo detalle: {', '.join(extra)})" if extra else ""))

        paginas = ph.get("paginas") or 0
        tramo = "1" if paginas <= 1 else ("2-3" if paginas <= 3 else "4+")
        tiempos[tramo].append(ph["ms"])
        memorias.append(ph["memoria_pico_bytes"])

    print()
    print(f"Total: {len(pdfs)} facturas, {len(pdfs) - distintas} coinciden, {distintas} no coinciden.")
    if any(por_campo.values()):
        print("Campos que difieren (numero de facturas): " + ", ".join(f"{ETIQUETAS.get(c, c)}={n}" for c, n in por_campo.items() if n))
    print("Servicio PHP, tiempo por factura (ms, sin contar el arranque de PHP):")
    for tramo, ms in tiempos.items():
        if ms:
            print(f"  {tramo} pagina(s): {len(ms)} facturas, media {statistics.mean(ms):.1f}, maximo {max(ms):.1f}")
    if memorias:
        print(f"Servicio PHP, memoria pico por factura: media {statistics.mean(memorias) / 1048576:.1f} MB, maximo {max(memorias) / 1048576:.1f} MB")
    return 0 if distintas == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
