"""Pasa los PDF de generar_maliciosos.py (o los de fuzz.py) por el servicio del plugin
(analizar.php) y comprueba, para cada uno, que:

- PHP termina bien (sin error fatal) con memory_limit=128M, el valor por defecto de PHP;
- tarda menos del tope de tiempo (10 s) y lo que mide el propio PHP;
- el estado es formato_inesperado o sin_texto (o, con --admitir-ok, cualquiera);
- no se escribe ningun fichero en el temporal: cada PHP corre con un TMPDIR propio,
  vacio, que tiene que seguir vacio al acabar (el temporal comun de macOS lo usan
  otras aplicaciones).

Solo para PDF sinteticos: imprime el motivo de cada uno.

Uso:
  python3 -I probar_maliciosos.py <carpeta> --php <php 8.4> [--por-proceso N] [--admitir-ok]

Con --por-proceso N > 1 se analizan N PDF en el mismo proceso PHP, como en una subida
de muchas facturas: la memoria de una no puede acumularse en las siguientes.
"""

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

AQUI = Path(__file__).resolve().parent
ESPERADOS = {"formato_inesperado", "sin_texto"}
TOPE_MS = 10_000
MEMORIA = "128M"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("carpeta", type=Path)
    ap.add_argument("--php", default="php")
    ap.add_argument("--por-proceso", type=int, default=1)
    ap.add_argument("--admitir-ok", action="store_true", help="cualquier estado vale (fuzz de facturas validas)")
    ap.add_argument("--silencioso", action="store_true", help="solo los fallos y el resumen")
    args = ap.parse_args()

    pdfs = sorted(args.carpeta.glob("*.pdf"))
    temporal = Path(tempfile.mkdtemp(prefix="lectura-facturas-"))
    entorno = dict(os.environ, TMPDIR=str(temporal))
    fallos = 0
    peor_ms = 0.0
    peor_mb = 0.0
    estados = {}

    for i in range(0, len(pdfs), args.por_proceso):
        lote = pdfs[i : i + args.por_proceso]
        inicio = time.monotonic()
        try:
            salida = subprocess.run(
                [args.php, "-d", f"memory_limit={MEMORIA}", "-d", "display_errors=stderr", str(AQUI / "analizar.php"), *map(str, lote)],
                capture_output=True, text=True, timeout=60 + 15 * len(lote), env=entorno,
            )
        except subprocess.TimeoutExpired:
            fallos += len(lote)
            print(f"FALLO {lote[0].name}...: PHP no ha terminado (colgado)")
            continue
        segundos = time.monotonic() - inicio
        lineas = [l for l in salida.stdout.splitlines() if l.startswith("{")]

        if salida.returncode != 0 or len(lineas) != len(lote):
            fallos += len(lote) - len(lineas) or 1
            ultimo = salida.stderr.strip().splitlines()[-1:] or [""]
            print(f"FALLO {lote[len(lineas)].name if len(lineas) < len(lote) else lote[-1].name}: PHP termino con codigo {salida.returncode} ({ultimo[0][:200]})")

        for pdf, linea in zip(lote, lineas):
            r = json.loads(linea)
            mb = r["memoria_pico_bytes"] / 1048576
            peor_ms, peor_mb = max(peor_ms, r["ms"]), max(peor_mb, mb)
            estados[r["estado"]] = estados.get(r["estado"], 0) + 1
            mal = []
            if not args.admitir_ok and r["estado"] not in ESPERADOS:
                mal.append(f"estado {r['estado']}")
            if r["ms"] > TOPE_MS:
                mal.append(f"{r['ms']:.0f} ms")
            fallos += bool(mal)
            if mal or not args.silencioso:
                print(f"{'FALLO ' if mal else ''}{pdf.name}: {r['estado']} | {r['motivo']} | {r['ms']:.0f} ms, pico {mb:.1f} MB"
                      + (f" | {', '.join(mal)}" if mal else ""))
        if args.por_proceso > 1 and not args.silencioso:
            print(f"  (lote de {len(lote)} en un proceso: {segundos:.1f} s)")

    nuevos = list(temporal.iterdir())
    shutil.rmtree(temporal, ignore_errors=True)
    if nuevos:
        fallos += 1
        print(f"FALLO: ficheros escritos en el temporal: {len(nuevos)}")

    print(f"\n{len(pdfs)} PDF, {fallos} fallos. Estados: {estados}. Peor tiempo {peor_ms:.0f} ms, peor pico de memoria {peor_mb:.1f} MB (memory_limit {MEMORIA}).")
    return 0 if fallos == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
