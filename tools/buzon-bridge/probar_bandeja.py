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
from datetime import datetime
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402

RUTA_INICIO = "/si.inbox/Inicio/Empleador"
ETIQUETA = "Notificaciones de Fiscalización"
RECURSO_JS = "/si.inbox/faces/javax.faces.resource/js/bsf.js?ln=bsf"


# Solo aparecen en la página de inicio, no en la bandeja.
MARCAS_INICIO = ("formInicio_parque", "image-1.jpg", "La modificaci")


def _resumen(nombre, html, carpeta):
    """Dice si la respuesta es la bandeja o la misma página de inicio."""
    tablas = re.findall(r'<table[^>]*\bid="([^"]+)"', html)
    filas = len(re.findall(r"<tr[^>]*>", html))
    es_parcial = "<partial-response" in html
    marcas = [m for m in MARCAS_INICIO if m in html]
    redirige = re.search(r"<redirect url=\"([^\"]+)\"", html)
    errores = re.findall(r"<error-name>([^<]*)</error-name>", html)

    print(f"  {nombre}")
    print(f"    {len(html):>7} B | filas={filas:<4} parcial={'sí' if es_parcial else 'no'}"
          f" | inicio={'sí' if marcas else 'no'}")
    if redirige:
        print(f"    REDIRIGE A: {redirige.group(1)}")
    if errores:
        print(f"    ERRORES JSF: {errores}")
    if tablas:
        print(f"    tablas: {tablas[:6]}")
    pistas = [
        i
        for i in re.findall(r'\bid="([^"]+)"', html)
        if re.search(r"notific|bandeja|inbox|dt|lista|tabla", i, re.I)
    ]
    if pistas:
        print(f"    ids con pinta de lista: {sorted(set(pistas))[:12]}")
    if marcas:
        print(f"    marcas de inicio: {marcas}")

    nombre_fich = re.sub(r"[^a-zA-Z0-9]+", "-", nombre)[:40].strip("-")
    with open(os.path.join(carpeta, nombre_fich + ".html"), "w", encoding="utf-8") as f:
        f.write(html)
    print(f"    guardado en {nombre_fich}.html")
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

    ruta_js = os.path.join(os.path.dirname(os.path.abspath(__file__)), "bsf.js")
    with open(ruta_js, "w", encoding="utf-8") as f:
        f.write(js)
    print(f"\n{'=' * 72}\nbsf.js ({len(js)} B) -> {ruta_js}\n{'=' * 72}")

    # `submitForm` solo hace form.submit(); los campos reales los pone
    # `addParametersToForm`, así que se muestran todas las funciones.
    nombres = re.findall(r"\b([A-Za-z_$][\w$]*)\s*[:=]\s*function\s*\(", js)
    vistos = set()
    for nombre in nombres:
        if nombre in vistos:
            continue
        vistos.add(nombre)
        m = re.search(rf"\b{re.escape(nombre)}\s*[:=]\s*function\s*\(", js)
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
        print(f"\n--- {nombre} ---\n{js[m.start():fin][:1200]}")


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

    carpeta = os.path.join(
        os.getcwd(), f"sondeo-bandeja-{datetime.now():%Y%m%d-%H%M%S}"
    )
    os.makedirs(carpeta, exist_ok=True)
    print(f"\nGuardando las respuestas en {carpeta}/")

    destino = puente.URL_CASILLA_SUNAFIL + RUTA_INICIO

    def preparar():
        _, inicio = sesion.get(destino)
        formulario, parametros = puente._parametros_del_menu(inicio, ETIQUETA)
        if formulario is None:
            print("  AVISO: el menú no tiene onclick utilizable; se adivina el botón.")
            formulario = "formMenu2"
            boton = puente._boton_de_menu(inicio, ETIQUETA)
            parametros = {boton: boton}
        else:
            print(f"  onclick -> formulario={formulario!r} parametros={parametros}")
        campos = puente._campos_ocultos(inicio, formulario)
        return campos, parametros

    print(f"\n{'=' * 72}\nPRUEBAS\n{'=' * 72}")

    # 1. POST con el formulario completo, como hace `submitForm`.
    try:
        campos, parametros = preparar()
        campos.update(parametros)
        _, html = sesion.post(destino, campos)
        _resumen("1) POST de formulario completo", html, carpeta)
    except puente.SolError as e:
        print(f"1) falló: {e.mensaje}")

    # 2. POST parcial de JSF con source/execute/render, como PrimeFaces.
    try:
        campos, parametros = preparar()
        campos.update(parametros)
        fuente = next(iter(parametros), "formMenu2")
        campos.update(
            {
                "javax.faces.source": fuente,
                "javax.faces.partial.execute": fuente,
                "javax.faces.partial.render": "@all",
            }
        )
        _, html = sesion.post_ajax(destino, campos)
        _resumen("2) POST parcial (source/execute/render)", html, carpeta)
    except puente.SolError as e:
        print(f"2) falló: {e.mensaje}")

    # 3. POST parcial mínimo, por si el servidor solo mira la cabecera.
    try:
        campos, parametros = preparar()
        _, html = sesion.post_ajax(
            destino,
            {**parametros, "javax.faces.ViewState": campos["javax.faces.ViewState"]},
        )
        _resumen("3) POST parcial mínimo", html, carpeta)
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
