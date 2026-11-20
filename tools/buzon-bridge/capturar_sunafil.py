#!/usr/bin/env python3
"""Captura el tráfico de la casilla de SUNAFIL para poder implementar su lectura.

La autenticación de SUNAFIL ya funciona en el puente, pero falta saber con qué
petición `si.inbox` lista las notificaciones. Eso solo se puede ver desde una
máquina que alcance `casillaelectronica.sunafil.gob.pe`, normalmente una con
salida a internet en Perú.

Uso:
    python3 capturar_sunafil.py 20614684772 MODDATOS

Pide la Clave SOL sin mostrarla y sin dejarla en el historial del shell. Guarda
cada petición y su respuesta en una carpeta `captura-sunafil-<fecha>/`.

**La carpeta contiene cookies de sesión**: son credenciales. Compártela solo con
quien vaya a implementar el lector y bórrala al terminar.
"""

import getpass
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402


class Grabador(urllib.request.BaseHandler):
    """Anota cada respuesta, incluidas las redirecciones intermedias."""

    handler_order = 100  # después del resto: ve la respuesta ya procesada

    def __init__(self, carpeta):
        self.carpeta = carpeta
        self.n = 0
        self.lineas = []

    def http_response(self, request, response):
        self.n += 1
        url = response.geturl()
        estado = getattr(response, "status", None) or response.code
        tipo = response.headers.get("Content-Type", "")
        cuerpo = response.read()
        # El cuerpo ya se consumió: se devuelve tal cual a quien lo pida después,
        # para no dejar vacío al código que sigue el flujo.
        response.read = lambda *a, **k: cuerpo

        nombre = f"{self.n:02d}_{re.sub(r'[^a-zA-Z0-9]+', '-', url)[-70:]}"
        extension = ".json" if "json" in tipo else ".html"
        ruta = os.path.join(self.carpeta, nombre + extension)
        with open(ruta, "wb") as f:
            f.write(cuerpo)

        self.lineas.append(
            f"{self.n:02d}  {estado}  {tipo.split(';')[0]:<28} "
            f"{len(cuerpo):>8} B  {url}"
        )
        return response

    https_response = http_response


def _informe(carpeta, grabador):
    ruta = os.path.join(carpeta, "index.txt")
    with open(ruta, "w", encoding="utf-8") as f:
        f.write("\n".join(grabador.lineas) + "\n")
    print(f"\n{len(grabador.lineas)} respuestas guardadas en {carpeta}/")
    print(f"Índice: {ruta}\n")
    for linea in grabador.lineas:
        print("  " + linea)


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    ruc, usuario = sys.argv[1].strip(), sys.argv[2].strip()
    clave = os.environ.get("SOL_CLAVE") or getpass.getpass(
        f"Clave SOL de {usuario} (no se muestra ni se guarda): "
    )
    if not clave:
        print("Falta la Clave SOL.")
        return 1

    carpeta = os.path.join(
        os.getcwd(), f"captura-sunafil-{datetime.now():%Y%m%d-%H%M%S}"
    )
    os.makedirs(carpeta, exist_ok=True)

    print(f"\n1. Autenticando {usuario} / RUC {ruc} en el SSO de SUNAT…")
    sesion = puente.Sesion(timeout=60)
    grabador = Grabador(carpeta)
    sesion.opener.add_handler(grabador)

    try:
        code, state = puente.autenticar_sunafil(ruc, usuario, clave, sesion=sesion)
    except puente.SolError as e:
        print(f"\nFALLÓ: {e.mensaje}")
        if e.detalle:
            print(f"Detalle: {e.detalle[:400]}")
        _informe(carpeta, grabador)
        return 1

    print(f"   Login aceptado. code={code[:12]}… state={state}")

    print("2. Entrando a la casilla con ese código…")
    entrada = (
        f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_LOGIN_SUNAFIL}"
        f"?state={urllib.parse.quote(state)}&code={urllib.parse.quote(code)}"
    )
    try:
        sesion.get(entrada)
    except puente.SolError as e:
        print(f"   No se pudo abrir la casilla: {e.mensaje}")
        print(f"   Detalle: {e.detalle[:300]}")
        _informe(carpeta, grabador)
        return 1

    print("3. Abriendo la bandeja…")
    for ruta in ("/si.inbox/", "/si.inbox/Bandeja.htm", "/si.inbox/Bandeja"):
        try:
            sesion.get(puente.URL_CASILLA_SUNAFIL + ruta)
        except puente.SolError as e:
            print(f"   {ruta}: {e.mensaje}")
            continue

    _informe(carpeta, grabador)
    print(
        "\nRevisa index.txt: la bandeja suele cargarse por AJAX, así que busca\n"
        "la respuesta JSON o la que traiga la lista de notificaciones.\n"
        "Si no aparece, repite la captura con las Herramientas de Desarrollo del\n"
        "navegador (pestaña Red) abiertas en la bandeja.\n\n"
        "Comparte la carpeta solo con quien implemente el lector: contiene\n"
        "cookies de sesión. Bórrala al terminar."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
