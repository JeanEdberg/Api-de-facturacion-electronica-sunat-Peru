#!/usr/bin/env python3
"""Lee las Acciones Previas de una página de la casilla guardada con Ctrl+S.

El puente todavía no logra abrir la bandeja por POST, pero el HTML que el
navegador ya muestra sí se puede leer. Esto sirve para tener el dato hoy:
guarda la página desde el navegador y pásasela a este script.

Uso:
    python3 leer_acciones.py pagina.html
    python3 leer_acciones.py pagina.html --json
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402


def main():
    argumentos = [a for a in sys.argv[1:] if not a.startswith("--")]
    como_json = "--json" in sys.argv

    if not argumentos:
        print(__doc__)
        return 1

    ruta = argumentos[0]
    if not os.path.isfile(ruta):
        print(f"No existe el archivo: {ruta}")
        return 1

    with open(ruta, encoding="utf-8", errors="ignore") as f:
        html = f.read()

    acciones = puente.extraer_acciones_previas(html)

    if como_json:
        print(json.dumps(acciones, ensure_ascii=False, indent=2))
        return 0 if acciones else 2

    if not acciones:
        print(
            "No se encontraron Acciones Previas en ese archivo.\n\n"
            "Comprueba que guardaste la página con la tabla a la vista (menú\n"
            "«Acciones Previas»), no la de inicio. Si el listado sale vacío,\n"
            "es que no hay acciones pendientes."
        )
        return 2

    print(f"{len(acciones)} acción(es) previa(s):\n")
    for accion in acciones:
        print(f"  {accion.get('tipo', '(sin tipo)')}")
        print(f"    Registro      : {accion.get('registro', '')}")
        print(f"    Depósito      : {accion.get('fecha_deposito', '')}")
        if accion.get("fecha_efectos"):
            print(f"    Efectos       : {accion['fecha_efectos']}")
        print(f"    Acuse         : {accion.get('fecha_acuse', '')}")
        print(f"    Plazo         : {accion.get('plazo', '')} días")
        print(f"    Fecha límite  : {accion.get('fecha_limite', '')}")

        dias = accion.get("dias_restantes")
        if dias is None:
            print("    Estado        : fecha límite no reconocida")
        elif dias < 0:
            print(f"    Estado        : VENCIDA hace {abs(dias)} día(s)")
        elif dias == 0:
            print("    Estado        : VENCE HOY")
        else:
            print(f"    Estado        : quedan {dias} día(s)")
        print()

    return 0


if __name__ == "__main__":
    sys.exit(main())
