#!/usr/bin/env python3
"""Averigua con qué petición la casilla de SUNAFIL abre la bandeja.

El POST de formulario normal devuelve la misma página de inicio: JSF restaura
la vista y no ejecuta la acción. Aquí se prueban varias formas de pedirla y se
informa de cuál trae la lista, además de mostrar el JavaScript `submitForm` que
usa el propio menú.

Cada intento vuelve a leer el inicio para usar un ViewState fresco.

Uso:
    python3 probar_bandeja.py 20614684772 APIINMOA
"""

import getpass
import os
import re
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402

RUTA_INICIO = "/si.inbox/Inicio/Empleador"
ETIQUETA = "Notificaciones de Fiscalización"
RECURSO_JS = "/si.inbox/faces/javax.faces.resource/js/bsf.js?ln=bsf"


def _resumen(nombre, html):
    """Dice si la respuesta parece la bandeja o la misma página de inicio."""
    tablas = re.findall(r'<table[^>]*\bid="([^"]+)"', html)
    filas = len(re.findall(r"<tr[^>]*>", html))
    es_parcial = "<partial-response" in html
    tiene_menu = "formMenu2:j_idt25:1:j_idt29:0:j_idt31" in html
    print(f"  {nombre}")
    print(f"    {len(html):>7} B | filas={filas:<4} menu={'sí' if tiene_menu else 'no'}"
          f" | parcial={'sí' if es_parcial else 'no'}")
    if tablas:
        print(f"    tablas: {tablas[:6]}")
    return html


def _mostrar_js(sesion):
    """Muestra `submitForm`, que es quien define los campos del POST."""
    try:
        _, js = sesion.get(puente.URL_CASILLA_SUNAFIL + RECURSO_JS)
    except puente.SolError as e:
        print(f"\nNo se pudo bajar bsf.js: {e.mensaje}")
        return

    cabeza = js.lstrip()[:300].lower()
    if "<html" in cabeza or "<!doctype" in cabeza:
        print("\nbsf.js no se pudo leer: la casilla devolvió HTML en vez de JS.")
        print("Suele significar que la sesión caducó entre las pruebas.")
        return

    print(f"\n{'=' * 72}\nbsf.js ({len(js)} B)\n{'=' * 72}")
    for nombre in ("submitForm", "ab"):
        m = re.search(rf"(?:function\s+{nombre}\s*\(|{nombre}\s*[:=]\s*function\s*\()", js)
        if not m:
            continue
        llave = js.find("{", m.start())
        profundidad, fin = 0, len(js)
        for i in range(llave, len(js)):
            if js[i] == "{":
                profundidad += 1
            elif js[i] == "}":
                profundidad -= 1
                if profundidad == 0:
                    fin = i + 1
                    break
        print(f"\n--- {nombre} ---\n{js[m.start():fin][:1600]}")


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1
    ruc, usuario = sys.argv[1].strip(), sys.argv[2].strip()
    clave = os.environ.get("SOL_CLAVE") or getpass.getpass(
        f"Clave SOL de {usuario} (no se muestra ni se guarda): "
    )

    sesion = puente.Sesion(timeout=60)
    try:
        sesion.tls, estrategia = puente.detectar_contexto_tls(
            "casillaelectronica.sunafil.gob.pe", timeout=20
        )
        sesion.usar_tls(sesion.tls)
        print(f"TLS: {estrategia}")
        code, state = puente.autenticar_sunafil(ruc, usuario, clave, sesion=sesion)
        print(f"Login aceptado. code={code[:12]}…")
        entrada = (
            f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_REDIRECT_SUNAFIL}"
            f"?state={urllib.parse.quote(state)}&code={urllib.parse.quote(code)}"
        )
        sesion.get(entrada)
    except puente.SolError as e:
        print(f"\nFALLÓ: {e.mensaje}")
        if e.detalle:
            print(f"Detalle: {e.detalle[:400]}")
        return 1

    destino = puente.URL_CASILLA_SUNAFIL + RUTA_INICIO

    def preparar():
        _, inicio = sesion.get(destino)
        return puente._viewstate(inicio), puente._boton_de_menu(inicio, ETIQUETA)

    print(f"\n{'=' * 72}\nPRUEBAS\n{'=' * 72}")

    # 1. POST de formulario completo, que ya sabemos que no navega.
    try:
        vista, boton = preparar()
        _, html = sesion.post(
            destino, {"formMenu2": "formMenu2", boton: boton,
                      "javax.faces.ViewState": vista}
        )
        _resumen("1) POST de formulario normal", html)
    except puente.SolError as e:
        print(f"1) falló: {e.mensaje}")

    # 2. POST parcial de JSF con source/execute/render, como PrimeFaces.
    try:
        vista, boton = preparar()
        _, html = sesion.post_ajax(
            destino,
            {
                "javax.faces.source": boton,
                "javax.faces.partial.execute": boton,
                "javax.faces.partial.render": "@all",
                "formMenu2": "formMenu2",
                boton: boton,
                "javax.faces.ViewState": vista,
            },
        )
        _resumen("2) POST parcial (source/execute/render)", html)
    except puente.SolError as e:
        print(f"2) falló: {e.mensaje}")

    # 3. POST parcial mínimo, por si el servidor solo mira la cabecera.
    try:
        vista, boton = preparar()
        _, html = sesion.post_ajax(
            destino,
            {"formMenu2": "formMenu2", boton: boton,
             "javax.faces.ViewState": vista},
        )
        _resumen("3) POST parcial mínimo", html)
    except puente.SolError as e:
        print(f"3) falló: {e.mensaje}")

    _mostrar_js(sesion)

    print(
        "\nCopia esta salida completa. Interesa saber cuál de las tres trae\n"
        "filas o tablas distintas de la página de inicio."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
