#!/usr/bin/env python3
"""Descarga el documento de una fila de la casilla de SUNAFIL.

Va directo a SUNAFIL, sin pasar por el puente, para que funcione aunque el
servidor esté apagado. La sesión se abre, se usa y se descarta aquí.

Uso:
    python descargar_carta.py 20614684772 APIINMOA
    python descargar_carta.py 20614684772 APIINMOA --fila 2
    python descargar_carta.py 20614684772 APIINMOA --bandeja notificaciones
    python descargar_carta.py 20614684772 APIINMOA --tipo constancia_deposito
    python descargar_carta.py 20614684772 APIINMOA --tipo constancia_acuse
    python descargar_carta.py 20614684772 APIINMOA --todos

Tipos: carta, constancia_deposito, constancia_acuse.
`--todos` prueba los tres y dice cuál funciona: útil cuando no se sabe qué
botón responde con el archivo y cuál abre una pantalla intermedia.

La clave se pide sin mostrarla y no se guarda en ningún sitio.
"""

import getpass
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    ruc, usuario = sys.argv[1].strip(), sys.argv[2].strip()
    bandeja = "acciones_previas"
    indice = 0
    tipo = "carta"
    todos = "--todos" in sys.argv

    resto = sys.argv[3:]
    for i, arg in enumerate(resto):
        if arg == "--bandeja" and i + 1 < len(resto):
            bandeja = resto[i + 1]
        if arg == "--fila" and i + 1 < len(resto):
            indice = int(resto[i + 1]) - 1
        if arg == "--tipo" and i + 1 < len(resto):
            tipo = resto[i + 1]

    etiquetas = dict(puente.BANDEJAS_SUNAFIL)
    if bandeja not in etiquetas:
        print(f"Bandeja desconocida: {bandeja}. Usa: {', '.join(etiquetas)}")
        return 1

    clave = os.environ.get("SOL_CLAVE") or getpass.getpass(
        f"Clave SOL de {usuario} (no se muestra ni se guarda): "
    )

    tipos = ["carta", "constancia_deposito", "constancia_acuse"] if todos else [tipo]
    fallos = 0

    for cual in tipos:
        print(f"\n--- {cual} ---")
        try:
            nombre, contenido = puente.descargar_documento(
                ruc, usuario, clave, etiquetas[bandeja], indice, cual
            )
        except puente.SolError as e:
            print(f"FALLÓ: {e.mensaje}")
            if e.detalle:
                print(f"Detalle: {e.detalle[:300]}")
            fallos += 1
            continue

        ruta = os.path.join(os.getcwd(), nombre)
        with open(ruta, "wb") as f:
            f.write(contenido)

        print(f"Guardado: {ruta}")
        print(f"Tamaño:   {len(contenido)} bytes")
        if contenido.startswith(b"%PDF"):
            print("Parece un PDF válido.")
        else:
            print("OJO: no empieza por %PDF. Ábrelo antes de darlo por bueno.")

    return 1 if fallos == len(tipos) else 0


if __name__ == "__main__":
    sys.exit(main())
