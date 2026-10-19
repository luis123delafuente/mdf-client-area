"""Regresion de la lectura de facturas en PHP (#333): compara las PALABRAS (texto y
caja) que saca el plugin (Pdf_Palabras, a traves de palabras.php) con las de PyMuPDF
(`page.get_text("words")`), pagina a pagina. Sirve para saber, ante una factura que
no coincide en comparar.py, si el problema esta en la lectura del PDF y cuanto se
desvian las coordenadas.

Pensado tambien para FACTURAS REALES: nunca imprime texto ni valores, solo
recuentos y desviaciones en puntos.

Uso (con el venv del parser):
  <repo parser>/venv/bin/python comparar_palabras.py <carpeta o PDF> [--php <php 8.4>]
  <repo parser>/venv/bin/python comparar_palabras.py <carpeta o PDF> --php <php 8.4> --diagnostico --repo-parser <repo>

Emparejamiento: cada palabra de PyMuPDF con la de PHP del mismo texto en su misma
fila y con la x mas cercana (si no hay en su fila, la mas cercana). Con el primer
texto igual, sin mas, dos palabras repetidas en una fila (p. ej. dos importes
iguales) se cruzaban y salian desviaciones falsas de casi un ancho de pagina. Ademas
se cuentan las palabras que PHP da en otro ORDEN que PyMuPDF: el parser se queda con
la primera aparicion de cada rotulo.

Con --diagnostico, por pagina: MediaBox/CropBox/Rotate, Form XObjects y matrices,
operadores del flujo y, de las palabras desviadas: cuantas, su desviacion en x,
filas, columnas, fuente, tamano, matrices CTM/Tm y operador, ademas de los rotulos
del parser cuya primera aparicion cambia. Solo numeros y nombres de rotulos del
parser (constantes del codigo): nunca texto ni valores de la factura.
"""

import argparse
import collections
import json
import subprocess
import sys
from pathlib import Path

import fitz

AQUI = Path(__file__).resolve().parent

# Rotulos que busca el parser (constantes de parser_facturas/parser.py, no datos de la factura).
ROTULOS = ("CIF:", "FECHA", "FACTURA", "CLIENTE", "N.I.F", "TELÉFONO", "PÁG.", "TOTAL", "FRA", "(EUR)", "Es", "SUMA")


def php_json(php: str, script: str, pdf: Path, timeout: int = 120):
    salida = subprocess.run([php, str(AQUI / script), str(pdf)], capture_output=True, text=True, timeout=timeout)
    if salida.returncode != 0:
        # Nunca se reenvia stderr: podria citar contenido del PDF.
        raise RuntimeError(f"PHP termino con codigo {salida.returncode}")
    return json.loads(salida.stdout)


def emparejar(ref: list, otras: list) -> list:
    """Pares (palabra de referencia, palabra emparejada o None): mismo texto, misma
    fila (|dy0| < 0.5) y la x mas cercana; si no hay en la fila, la mas cercana."""
    libres = list(range(len(otras)))
    pares = []
    for w in ref:
        candidatos = [j for j in libres if otras[j][4] == w[4]]
        if candidatos:
            en_fila = [j for j in candidatos if abs(otras[j][1] - w[1]) < 0.5] or candidatos
            elegida = min(en_fila, key=lambda j: abs(otras[j][0] - w[0]) + abs(otras[j][1] - w[1]))
            libres.remove(elegida)
            pares.append((w, otras[elegida]))
        else:
            pares.append((w, None))
    return pares


def fuera_de_orden(pares: list, otras: list) -> tuple:
    """Cuantas palabras de PHP aparecen antes que otra que PyMuPDF pone delante, y la primera."""
    indice = {id(w): k for k, w in enumerate(otras)}
    orden = [indice[id(b)] for _, b in pares if b is not None]
    maximo, n, primera = -1, 0, None
    for k, v in enumerate(orden):
        if v < maximo:
            n += 1
            primera = k if primera is None else primera
        maximo = max(maximo, v)
    return n, primera


def resumen_numeros(valores: list) -> str:
    if not valores:
        return "-"
    v = sorted(valores)
    return f"min {v[0]:.2f}, mediana {v[len(v) // 2]:.2f}, max {v[-1]:.2f}"


def sin_digitos(texto: str) -> str:
    """Los motivos son textos fijos, pero alguno de Python cita serie y numero: fuera digitos."""
    return "".join("#" if c.isdigit() else c for c in (texto or ""))


def informe(mu: list, ph: list, diag: dict) -> None:
    pares = emparejar(mu, ph)
    sin_pareja = sum(1 for _, b in pares if b is None)
    desviadas = [(a, b) for a, b in pares if b and max(abs(b[k] - a[k]) for k in range(4)) > 0.5]
    n_orden, primera = fuera_de_orden(pares, ph)

    print(f"    PHP: {len(ph)} palabras; fuera del orden de PyMuPDF: {n_orden}"
          + ("" if primera is None else f" (la primera en la posicion {primera} de PyMuPDF)"))
    print(f"      sin pareja {sin_pareja}, desviadas {len(desviadas)}")
    if not desviadas:
        return
    dx0 = [b[0] - a[0] for a, b in desviadas]
    dx1 = [b[2] - a[2] for a, b in desviadas]
    dy = [b[1] - a[1] for a, b in desviadas]
    filas = sorted({round(a[1]) for a, _ in desviadas})
    columnas = sorted({round(a[0] / 5) * 5 for a, _ in desviadas})
    print(f"      dx0 (PHP - PyMuPDF): {resumen_numeros(dx0)} | dx1: {resumen_numeros(dx1)} | dy0: {resumen_numeros(dy)}")
    print(f"      filas (y0 PyMuPDF): {filas[:15]}{' ...' if len(filas) > 15 else ''} ({len(filas)} filas)")
    print(f"      columnas (x0 PyMuPDF, de 5 en 5): {columnas[:15]}{' ...' if len(columnas) > 15 else ''} ({len(columnas)} columnas)")
    xs = [a[0] for a, _ in desviadas] + [a[2] for a, _ in desviadas]
    ys = [a[1] for a, _ in desviadas] + [a[3] for a, _ in desviadas]
    print(f"      bloque que ocupan (PyMuPDF): x {min(xs):.1f}-{max(xs):.1f}, y {min(ys):.1f}-{max(ys):.1f}")
    # Datos de PHP para esas mismas palabras (matrices, fuente, tamano, operador).
    rasgos = collections.Counter()
    for _, b in desviadas:
        d = diag.get(tuple(round(v, 1) for v in b[:4]))
        if d:
            rasgos[(d["base_font"], d["tam"], d["tam_efectivo"], d["operador"], d["tj_con_numeros"], d["tj_empieza_num"], d["origen"], tuple(d["ctm"]), tuple(d["tm"][:4]))] += 1
    for (fuente, tam, tam_ef, op, tj_nums, tj_ini, origen, ctm, tm_abcd), n in rasgos.most_common(8):
        print(f"      {n} palabra(s): fuente {fuente or '?'} {tam} (efectivo {tam_ef}), {op}"
              f"{' con numeros' if tj_nums else ''}{', empieza por numero' if tj_ini else ''}, {origen}, CTM {list(ctm)}, Tm[a b c d] {list(tm_abcd)}")


def diagnostico(pdf: Path, i: int, php: str) -> None:
    try:
        d = php_json(php, "diagnostico.php", pdf, timeout=300)
    except Exception as e:
        print(f"pdf {i:03d}: el diagnostico PHP falla ({e})")
        return

    from parser_facturas.parser import parsear_factura  # noqa: E402  (requiere --repo-parser)

    py = parsear_factura(str(pdf))
    print(f"pdf {i:03d}: estado Python {py.estado} / PHP {d['estado']}")
    print(f"  motivo Python: {sin_digitos(py.motivo) or '-'}")
    print(f"  motivo PHP:    {sin_digitos(d['motivo']) or '-'}")

    with fitz.open(str(pdf)) as doc:
        if len(doc) != len(d["paginas"]):
            print(f"  paginas: PyMuPDF {len(doc)}, PHP {len(d['paginas'])}")
        for n_pag, (page, p) in enumerate(zip(doc, d["paginas"]), 1):
            mu = [list(w[:5]) for w in page.get_text("words")]
            ph = [w[:5] for w in p["palabras"]]
            diag = {tuple(round(v, 1) for v in w[:4]): w[5] for w in p["palabras"]}
            xo = p["xobjects"]
            print(f"  pagina {n_pag}: MediaBox {p['mediabox']} CropBox {p['cropbox']} Rotate {p['rotate']} "
                  f"(PyMuPDF: rect {[round(v, 2) for v in page.rect]}, rotation {page.rotation})")
            print(f"    Form XObjects: {sum(1 for x in xo if x['subtipo'] == 'Form')} (con texto {sum(1 for x in xo if x['con_texto'])}), "
                  f"imagenes {sum(1 for x in xo if x['subtipo'] == 'Image')}"
                  + "".join(f"; Form con texto, Matrix {x['matrix']}, BBox {x['bbox']}" for x in xo if x["con_texto"]))
            print(f"    operadores: {json.dumps(p['rasgos']['operadores'], ensure_ascii=False)}")
            print(f"    TJ: {json.dumps(p['rasgos']['tj'])}")
            print(f"    palabras PyMuPDF: {len(mu)}")
            informe(mu, ph, diag)
            # Primer rotulo de cada clase: el parser se queda con el PRIMERO en orden.
            distintos = []
            for r in ROTULOS:
                pm = next((w for w in mu if w[4] == r), None)
                pp = next((w for w in ph if w[4] == r), None)
                veces_mu, veces_ph = sum(w[4] == r for w in mu), sum(w[4] == r for w in ph)
                if (pm is None) != (pp is None) or (pm and pp and (abs(pm[0] - pp[0]) > 0.5 or abs(pm[1] - pp[1]) > 0.5)) or veces_mu != veces_ph:
                    distintos.append(f"{r} [veces {veces_mu}/{veces_ph}]")
            print(f"    rotulos del parser con distinta primera aparicion o recuento: {', '.join(distintos) or 'ninguno'}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("ruta", type=Path)
    ap.add_argument("--php", default="php")
    ap.add_argument("--diagnostico", action="store_true", help="informe detallado por pagina (sin texto ni valores)")
    ap.add_argument("--repo-parser", type=Path, help="obligatorio con --diagnostico")
    args = ap.parse_args()

    pdfs = [args.ruta] if args.ruta.is_file() else sorted(p for p in args.ruta.rglob("*") if p.suffix.lower() == ".pdf")

    if args.diagnostico:
        if not args.repo_parser:
            ap.error("--diagnostico necesita --repo-parser")
        sys.path.insert(0, str(args.repo_parser.resolve()))
        for i, pdf in enumerate(pdfs, 1):
            diagnostico(pdf, i, args.php)
            print()
        return 0

    total_ok = 0
    for i, pdf in enumerate(pdfs, 1):
        try:
            with fitz.open(str(pdf)) as doc:
                mu = [[list(w[:5]) for w in page.get_text("words")] for page in doc]
        except Exception:
            print(f"pdf {i:03d}: PyMuPDF no lo abre")
            continue
        try:
            ph = php_json(args.php, "palabras.php", pdf)
        except Exception as e:
            print(f"pdf {i:03d}: PHP falla ({e})")
            continue

        n_mu = sum(len(p) for p in mu)
        n_ph = sum(len(p) for p in ph)
        iguales = 0
        orden = 0
        desv = [0.0, 0.0, 0.0, 0.0]
        for pm, pp in zip(mu, ph):
            pares = emparejar(pm, pp)
            orden += fuera_de_orden(pares, pp)[0]
            for a, b in pares:
                if b is None:
                    continue
                iguales += 1
                for k in range(4):
                    desv[k] = max(desv[k], abs(b[k] - a[k]))
        paginas = f"{len(mu)} pag" + ("" if len(mu) == len(ph) else f" (PHP {len(ph)})")
        ok = len(mu) == len(ph) and n_mu == n_ph == iguales and orden == 0 and max(desv) <= 0.5
        total_ok += ok
        print(
            f"pdf {i:03d}: {paginas}, palabras PyMuPDF {n_mu}, PHP {n_ph}, con el mismo texto {iguales}, fuera de orden {orden}; "
            f"desviacion maxima x0 {desv[0]:.2f} y0 {desv[1]:.2f} x1 {desv[2]:.2f} y1 {desv[3]:.2f} pt"
            + ("" if ok else "  <-- distinto")
        )
    print(f"\n{total_ok} de {len(pdfs)} PDF con las mismas palabras, en el mismo orden y a menos de 0,5 pt.")
    return 0 if total_ok == len(pdfs) else 1


if __name__ == "__main__":
    sys.exit(main())
