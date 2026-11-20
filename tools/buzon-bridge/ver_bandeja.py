#!/usr/bin/env python3
"""Lee una bandeja ya abierta y guardada, sin login ni POST.

Sirve para comprobar el parser contra el HTML real: se guarda la respuesta
del sondeo (o la página del navegador con Ctrl+S) y se ven las filas que
saldrían por el puente.

Uso:
    python ver_bandeja.py sondeo-bandeja-.../1-POST-de-formulario-completo.html
"""

import os
import re
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

    hay_tabla_notificaciones = (
        puente._tabla_por_id(html, "dtNotificaciones") is not None
    )

    print("=" * 72)
    print(f"NOTIFICACIONES: {len(notificaciones)}")
    if hay_tabla_notificaciones and not notificaciones:
        print("  (la tabla existe y está vacía: no hay notificaciones)")
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

    # Solo se diagnostica si no se reconoció NINGUNA tabla: una bandeja vacía
    # es un resultado válido y no un fallo del lector.
    if not hay_tabla_notificaciones and not acciones:
        _diagnostico(html)
    return 0


def _diagnostico(html):
    """Muestra qué tablas e ids trae el archivo, para no adivinar."""
    print()
    print("=" * 72)
    print("DIAGNÓSTICO: no se reconoció ninguna tabla")
    print("=" * 72)
    print(f"Tamaño del archivo: {len(html)} caracteres")

    tablas = puente._tablas(html)
    print(f"\nTablas encontradas: {len(tablas)}")
    for i, tabla in enumerate(tablas[:15], 1):
        apertura = re.match(r"<table[^>]*>", tabla, re.S)
        etiqueta = apertura.group(0) if apertura else "(sin apertura)"
        filas = len(re.findall(r"<tr[^>]*>", tabla))
        print(f"  {i}) {re.sub(chr(10), ' ', etiqueta)[:160]}")
        print(f"     filas={filas}  tamaño={len(tabla)}")

    ids = re.findall(r'\bid\s*=\s*"([^"]+)"', html)
    candidos = [i for i in ids if re.search(r"notific|accion|dt|tabla|lista", i, re.I)]
    print(f"\nIds con pinta de lista ({len(candidos)}):")
    for ident in sorted(set(candidos))[:20]:
        print(f"  {ident}")

    print("\nPrimeros 400 caracteres del archivo:")
    print("  " + re.sub(r"\s+", " ", html[:400]))


if __name__ == "__main__":
    sys.exit(main())
