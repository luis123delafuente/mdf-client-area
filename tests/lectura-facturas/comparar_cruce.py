"""Regresion de la lectura de facturas (#333): el cruce con las farmacias
(Factura_Cruce_Service) tiene que dar lo mismo con el resultado del servicio del
plugin que con el del parser de Python, contra la base de datos del sitio.

Necesita WordPress en marcha (wp-cli). Nunca imprime valores: solo coincide o los
campos del cruce que difieren. El fichero intermedio con los resultados de Python
va a un temporal propio que se borra siempre.

Uso (con el venv del parser):
  <repo parser>/venv/bin/python comparar_cruce.py <carpeta con PDF> --repo-parser <repo> --wp <orden de wp-cli> [--crear-farmacias]

--crear-farmacias (SOLO en local y con sinteticas) da de alta farmacias temporales con
los CIF/NIF de las facturas, para probar tambien "asignada", y las borra al terminar.
"""

import argparse
import json
import shlex
import subprocess
import sys
import tempfile
from pathlib import Path


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("carpeta", type=Path)
    ap.add_argument("--repo-parser", type=Path, required=True)
    ap.add_argument("--wp", required=True, help="orden de wp-cli, p. ej. 'wp --path=/ruta/al/sitio'")
    ap.add_argument("--crear-farmacias", action="store_true")
    args = ap.parse_args()

    sys.path.insert(0, str(args.repo_parser.resolve()))
    from parser_facturas.parser import parsear_factura

    pdfs = sorted(p for p in args.carpeta.rglob("*") if p.is_file() and p.suffix.lower() == ".pdf")
    casos = [{"pdf": str(p.resolve()), "python": parsear_factura(str(p)).a_dict()} for p in pdfs]

    with tempfile.TemporaryDirectory() as carpeta:
        fichero = Path(carpeta) / "casos.json"
        fichero.write_text(json.dumps(casos), encoding="utf-8")
        salida = subprocess.run([*shlex.split(args.wp), "eval-file", str(Path(__file__).resolve().parent / "cruce.php"), str(fichero)]
                                + (["crear-farmacias"] if args.crear_farmacias else []),
                                capture_output=True, text=True, timeout=600)
    if salida.returncode != 0:
        print(f"wp-cli termino con codigo {salida.returncode}")
        return 1
    print(salida.stdout, end="")
    return 0 if "0 no coinciden" in salida.stdout else 1


if __name__ == "__main__":
    sys.exit(main())
