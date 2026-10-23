"""Genera un Excel FICTICIO del Modelo 347 de Formacion (B88471149) con la
estructura de SAGE (.NET, igual que tests/generar-347-sage-net.py) y las 13
columnas de Formacion, sin Email. Nunca datos reales. No se despliega (tests/).

Uso (desde la raiz del plugin):
  python3 -I tests/generar-347-formacion.py <salida.xlsx> <CIF normal> <CIF libre> <CIF importe cero> <CIF trimestres vacios> <CIF DEV y RECC>

Cinco filas, una por caso:
- normal: 100 + 200 + 300 + 400 = DEV 1000;
- NIF que no esta en el portal (<CIF libre>);
- importe cero: todo 0;
- trimestres vacios: T1 = 500, T2 a T4 sin celda, DEV 500;
- DEV y RECC: 10 + 20 + 30 + 40 = DEV 100, Importe RECC 25,50 y RECC True.
"""
import importlib.util
import sys
from pathlib import Path

sys.dont_write_bytecode = True  # sin tests/__pycache__ al importar el escritor

if len(sys.argv) != 7:
    sys.exit(__doc__)

salida, normal, libre, cero, vacios, recc = sys.argv[1:7]

# Mismo escritor que el Excel de Mediformplus: misma estructura de SAGE.
_spec = importlib.util.spec_from_file_location("sage_net", Path(__file__).resolve().parent / "generar-347-sage-net.py")
sage = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(sage)
p = sage.p

filas = [
    ["Código", "Nombre", "NIF", "Dirección", "Población", "Código postal", "Trimestre 1", "Trimestre 2", "Trimestre 3", "Trimestre 4", "Importe DEV", "Importe RECC", "RECC"],
    [p("430101"), p("Farmacia Formación Normal"), p(normal), p("Calle Ficticia, 1"), p("Villaprueba"), p("28010"), 100, 200, 300, 400, 1000, 0, "False"],
    [p("430102"), p("Cliente Fuera Del Portal"), p(libre), p("Calle Inventada, 2"), p("Pueblo Ficticio"), p("28011"), 50, 50, 50, 50, 200, 0, "False"],
    [p("430103"), p("Farmacia Importe Cero"), p(cero), p("Plaza Prueba, 3"), p("Villaprueba"), p("28012"), 0, 0, 0, 0, 0, 0, "False"],
    [p("430104"), p("Farmacia Trimestres Vacíos"), p(vacios), p("Avenida Prueba, 4"), p("Otro Pueblo"), p("28013"), 500, None, None, None, 500, 0, "False"],
    [p("430105"), p("Farmacia DEV Y RECC"), p(recc), p("Camino Prueba, 5"), p("Otro Pueblo"), p("28014"), 10, 20, 30, 40, 100, 25.5, "True"],
]

sage.escribir(salida, filas)
print(f"Generado: {salida} ({len(filas) - 1} filas, Formacion, estructura SAGE .NET)")
