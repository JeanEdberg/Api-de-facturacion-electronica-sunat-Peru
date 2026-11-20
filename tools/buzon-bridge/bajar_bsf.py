#!/usr/bin/env python3
"""Descarga el JavaScript de la casilla de SUNAFIL y muestra sus funciones.

`BsF.submitForm` es quien decide qué campos lleva el POST del menú, así que sus
funciones dicen qué falta en la petición. No pide credenciales: es un recurso
estático. Si el servidor exige sesión, el script lo dice y se copia a mano.

Uso:
    python3 bajar_bsf.py
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402

HOST = "casillaelectronica.sunafil.gob.pe"
RUTA = "/si.inbox/faces/javax.faces.resource/js/bsf.js?ln=bsf"


def funciones(js):
    """Devuelve (nombre, código) de cada función definida en el archivo."""
    salida, vistos = [], set()
    for m in re.finditer(r"\b([A-Za-z_$][\w$]*)\s*[:=]\s*function\s*\(", js):
        nombre = m.group(1)
        if nombre in vistos:
            continue
        vistos.add(nombre)
        llave = js.find("{", m.end())
        if llave < 0:
            continue
        profundidad, fin = 0, len(js)
        for i in range(llave, len(js)):
            if js[i] == "{":
                profundidad += 1
            elif js[i] == "}":
                profundidad -= 1
                if profundidad == 0:
                    fin = i + 1
                    break
        salida.append((nombre, js[m.start():fin]))
    return salida


def main():
    sesion = puente.Sesion(timeout=45)
    try:
        sesion.tls, estrategia = puente.detectar_contexto_tls(HOST, timeout=20)
        sesion.usar_tls(sesion.tls)
        print(f"TLS: {estrategia}")
        _, js = sesion.get(puente.URL_CASILLA_SUNAFIL + RUTA)
    except puente.SolError as e:
        print(f"No se pudo conectar con la casilla: {e.mensaje}")
        print(f"Detalle: {e.detalle[:300]}")
        return 1

    cabeza = js.lstrip()[:400].lower()
    if "<html" in cabeza or "<!doctype" in cabeza:
        print(
            "El servidor devolvió HTML en vez del JavaScript: exige sesión.\n\n"
            "Ábrelo en el navegador con la casilla ya abierta y copia todo:\n"
            f"  {puente.URL_CASILLA_SUNAFIL}{RUTA}"
        )
        return 1

    ruta = os.path.join(os.getcwd(), "bsf.js")
    with open(ruta, "w", encoding="utf-8") as f:
        f.write(js)
    print(f"Guardado en {ruta} ({len(js)} B)\n")

    encontradas = funciones(js)
    print(f"{len(encontradas)} funciones:")
    for nombre, codigo in encontradas:
        print(f"\n--- {nombre} ---\n{codigo[:1500]}")

    print(
        "\nPega el contenido completo de bsf.js o el bloque de arriba.\n"
        "Lo que busco es qué campos añade al formulario antes de enviarlo."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
