"""Fuzz por mutacion de la lectura de facturas (#333): variantes rotas de PDF
sinteticos validos (cortes, bytes cambiados, trozos duplicados o borrados, numeros
de /Length y del xref alterados, flujos descomprimidos con basura). Despues se pasan
por probar_maliciosos.py --admitir-ok: ninguna puede dar un error fatal, colgar PHP
ni escribir en el temporal.

Uso:
  python3 -I fuzz.py <carpeta destino> <carpeta con PDF validos> [--n 1500] [--semilla 333]
"""

import argparse
import random
import re
import zlib
from pathlib import Path


def mutar(datos: bytes, rnd: random.Random) -> bytes:
    b = bytearray(datos)
    tipo = rnd.randrange(7)
    if tipo == 0:  # corte
        return bytes(b[: rnd.randrange(1, len(b))])
    if tipo == 1:  # bytes cambiados
        for _ in range(rnd.randint(1, 60)):
            b[rnd.randrange(len(b))] = rnd.randrange(256)
        return bytes(b)
    if tipo == 2:  # trozo duplicado
        i = rnd.randrange(len(b))
        j = min(len(b), i + rnd.randint(1, 4000))
        return bytes(b[:j] + b[i:j] * rnd.randint(1, 20) + b[j:])
    if tipo == 3:  # trozo borrado
        i = rnd.randrange(len(b))
        return bytes(b[:i] + b[i + rnd.randint(1, 4000):])
    if tipo == 4:  # numeros alterados (Length, xref, referencias)
        numeros = list(re.finditer(rb"\d+", datos))
        for m in rnd.sample(numeros, min(len(numeros), rnd.randint(1, 15))):
            b[m.start():m.end()] = str(rnd.choice([0, 1, 2 ** 31, 2 ** 63, 10 ** 12, rnd.randrange(10 ** 6)])).encode()[: m.end() - m.start()].ljust(m.end() - m.start(), b"0")
        return bytes(b)
    if tipo == 5:  # palabras clave cambiadas
        for clave in rnd.sample([b"obj", b"endobj", b"stream", b"endstream", b"xref", b"trailer", b"/Kids", b"/Parent", b"/Contents", b"/Font", b"<<", b">>", b"["], 3):
            datos = datos.replace(clave, rnd.choice([b"", b"xx", clave * 2, b"[[[[", b"<<<<"]), rnd.randint(1, 3))
        return datos
    # flujo comprimido sustituido por basura comprimida
    flujos = list(re.finditer(rb"stream\r?\n(.*?)endstream", datos, re.S))
    if not flujos:
        return bytes(b)
    m = rnd.choice(flujos)
    basura = zlib.compress(bytes(rnd.randrange(256) for _ in range(rnd.randint(10, 20000))))
    return datos[: m.start(1)] + basura + datos[m.end(1):]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("destino", type=Path)
    ap.add_argument("origen", type=Path)
    ap.add_argument("--n", type=int, default=1500)
    ap.add_argument("--semilla", type=int, default=333)
    args = ap.parse_args()

    rnd = random.Random(args.semilla)
    bases = [p.read_bytes() for p in sorted(args.origen.rglob("*.pdf"))]
    bases = [b for b in bases if b.startswith(b"%PDF")]
    args.destino.mkdir(parents=True, exist_ok=True)
    for i in range(args.n):
        datos = rnd.choice(bases)
        for _ in range(rnd.randint(1, 3)):
            datos = mutar(datos, rnd) or datos[:10]
        (args.destino / f"fuzz_{i:04d}.pdf").write_bytes(datos)
    print(f"{args.n} variantes en {args.destino} a partir de {len(bases)} PDF")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
