#!/usr/bin/env python3
"""Lee una bandeja ya abierta y guardada, sin login ni POST.

Sirve para comprobar el parser contra el HTML real: se guarda la respuesta
del sondeo (o la página del navegador con Ctrl+S) y se ven las filas que
saldrían por el puente.

Uso:
    python ver_bandeja.py sondeo-bandeja-.../1-POST-de-formulario-completo.html
"""

import os
import sys

import sol_buzon as puente


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    ruta = sys.argv[1]
    if not os.path.isfile(ruta):
        print(f"No existe: {ruta}")
        print("Pasa la ruta de un HTML guardado, entre comillas si tiene espacios.")
        return 1

    with open(ruta, encoding="utf-8", errors="ignore") as f:
        html = f.read()

    if puente._es_inicio(html):
        print("Ese HTML es la página de inicio, no una bandeja abierta.")
        print("Abre la bandeja en el navegador, guarda esa página y pásala aquí.")
        return 1

    notificaciones = puente.extraer_notificaciones(html)
    acciones = puente.extraer_acciones_previas(html)

    print("=" * 72)
    print(f"NOTIFICACIONES: {len(notificaciones)}")
    print("=" * 72)
    for i, fila in enumerate(notificaciones, 1):
        print(f"\n{i})")
        for columna, valor in fila.items():
            print(f"   {columna}: {valor}")

    print()
    print("=" * 72)
    print(f"ACCIONES PREVIAS: {len(acciones)}")
    print("=" * 72)
    for i, fila in enumerate(acciones, 1):
        dias = fila.get("dias_restantes")
        aviso = ""
        if isinstance(dias, int):
            if dias < 0:
                aviso = f"  <<< VENCIDA hace {abs(dias)} días"
            elif dias <= 7:
                aviso = f"  <<< quedan {dias} días"
        print(f"\n{i}) {fila.get('tipo', '')}{aviso}")
        for columna, valor in fila.items():
            if columna in ("tipo", "dias_restantes"):
                continue
            print(f"   {columna}: {valor}")

    if not notificaciones and not acciones:
        print(
            "\nNo se encontró ninguna tabla reconocible.\n"
            "Pega esta salida y el nombre del archivo; puede que SUNAFIL haya\n"
            "cambiado los ids de las tablas."
        )
    return 0


if __name__ == "__main__":
    sys.exit(main())
