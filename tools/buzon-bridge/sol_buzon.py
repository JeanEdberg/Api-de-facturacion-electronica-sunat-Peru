#!/usr/bin/env python3
"""Puente entre el backend Laravel y el buzón del Menú SOL de SUNAT.

SUNAT no publica un API del buzón electrónico, así que este proceso entra a
Menú SOL con el RUC y la Clave SOL de cada empresa, lee el buzón y lo devuelve
como JSON. El backend lo consulta como si fuera cualquier otro endpoint del
buzón (`POST /buzon` con ruc, usuario, clave y entidad).

Uso:
    BUZON_BRIDGE_TOKEN=secreto python3 sol_buzon.py

Variables de entorno:
    BUZON_BRIDGE_PORT    puerto de escucha (por defecto 12002)
    BUZON_BRIDGE_HOST    interfaz de escucha (por defecto 0.0.0.0)
    BUZON_BRIDGE_TOKEN   si se define, exige `Authorization: Bearer <token>`

Respuesta correcta:
    {"data": [{"entidad": "SUNAT", "codigo": "...", "asunto": "...", ...}]}

Respuesta de error (siempre con un mensaje legible para el usuario):
    {"error": "SUNAT rechazó las credenciales SOL ...", "detalle": "<texto crudo>"}

El campo `detalle` lleva el texto original de SUNAT (HTML incluido) para poder
diagnosticar sin que el usuario vea un volcado de HTML en la interfaz.
"""

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MENU = "https://menu.ebs.sunat.gob.pe/menu/"
LOGIN = "https://menu.ebs.sunat.gob.pe/menu/j_security_check"
BUZON = "https://menu.ebs.sunat.gob.pe/menu/buzon"

PUERTO = int(os.environ.get("BUZON_BRIDGE_PORT", "12002"))
HOST = os.environ.get("BUZON_BRIDGE_HOST", "0.0.0.0")
TOKEN = os.environ.get("BUZON_BRIDGE_TOKEN", "")


class SolError(Exception):
    """Fallo al hablar con SUNAT.

    `mensaje` es lo que ve el usuario; `detalle` guarda el texto crudo de SUNAT
    (normalmente HTML) para diagnóstico.
    """

    def __init__(self, mensaje, detalle=""):
        super().__init__(mensaje)
        self.mensaje = mensaje
        self.detalle = detalle


def _abrir(url, datos=None, cabeceras=None):
    peticion = urllib.request.Request(url, data=datos, headers=cabeceras or {})
    return urllib.request.urlopen(peticion, timeout=30)


def entrar_al_menu(ruc, usuario, clave):
    """Autentica contra Menú SOL y devuelve la cookie de sesión."""
    cuerpo = urllib.parse.urlencode(
        {"tipo": "2", "RUC": ruc, "usuario": usuario, "clave": clave}
    ).encode()

    try:
        respuesta = _abrir(
            LOGIN,
            datos=cuerpo,
            cabeceras={
                "Content-Type": "application/x-www-form-urlencoded",
                "User-Agent": "BuzonSUNAT/1.0",
            },
        )
    except urllib.error.HTTPError as e:
        raise SolError(
            "No se pudo contactar el Menú SOL de SUNAT. Revisa la conexión e intenta de nuevo.",
            detalle=e.read().decode("utf-8", "ignore"),
        )
    except urllib.error.URLError as e:
        raise SolError(f"No se pudo conectar con SUNAT: {e.reason}")

    # SUNAT responde 200 tanto si entra como si no; la diferencia está en si
    # entrega cookie de sesión y en el HTML de respuesta.
    cookies = respuesta.headers.get_all("Set-Cookie") or []
    if not cookies:
        html = respuesta.read().decode("utf-8", "ignore")
        raise SolError(
            f"SUNAT rechazó las credenciales SOL del RUC {ruc}: el usuario o la "
            "clave no son válidos. Revisa la Clave SOL real en sunat.gob.pe "
            "(Menú SOL) y vuelve a guardarla.",
            detalle=html,
        )

    return "; ".join(c.split(";", 1)[0] for c in cookies)


def leer_buzon(ruc, usuario, clave, entidad):
    """Devuelve las notificaciones del buzón como lista de diccionarios."""
    cookie = entrar_al_menu(ruc, usuario, clave)

    try:
        respuesta = _abrir(
            f"{BUZON}?entidad={urllib.parse.quote(entidad)}",
            cabeceras={
                "Cookie": cookie,
                "Accept": "application/json",
                "User-Agent": "BuzonSUNAT/1.0",
            },
        )
        crudo = respuesta.read().decode("utf-8", "ignore")
    except urllib.error.HTTPError as e:
        raise SolError(
            "SUNAT no devolvió el buzón. La sesión SOL pudo caducar; intenta de nuevo.",
            detalle=e.read().decode("utf-8", "ignore"),
        )
    except urllib.error.URLError as e:
        raise SolError(f"No se pudo leer el buzón en SUNAT: {e.reason}")

    try:
        datos = json.loads(crudo)
    except json.JSONDecodeError:
        raise SolError(
            "SUNAT respondió con una página en vez de datos del buzón. "
            "Suele significar que la sesión SOL no llegó a abrirse.",
            detalle=crudo,
        )

    items = datos.get("data") if isinstance(datos, dict) else datos
    return items if isinstance(items, list) else []


class Manejador(BaseHTTPRequestHandler):
    def _responder(self, codigo, cuerpo):
        carga = json.dumps(cuerpo, ensure_ascii=False).encode()
        self.send_response(codigo)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(carga)))
        self.end_headers()
        self.wfile.write(carga)

    def do_POST(self):  # noqa: N802 (nombre impuesto por BaseHTTPRequestHandler)
        if TOKEN and self.headers.get("Authorization") != f"Bearer {TOKEN}":
            self._responder(401, {"error": "Token del puente inválido o ausente."})
            return

        try:
            largo = int(self.headers.get("Content-Length") or 0)
            cuerpo = json.loads(self.rfile.read(largo) or b"{}")
        except (ValueError, json.JSONDecodeError):
            self._responder(400, {"error": "Cuerpo JSON inválido."})
            return

        ruc = str(cuerpo.get("ruc") or "").strip()
        usuario = str(cuerpo.get("usuario") or "").strip()
        clave = str(cuerpo.get("clave") or "")
        entidad = str(cuerpo.get("entidad") or "SUNAT").upper()

        if not ruc or not usuario or not clave:
            self._responder(
                400,
                {"error": "Faltan el RUC, el usuario SOL o la Clave SOL de la empresa."},
            )
            return

        try:
            self._responder(200, {"data": leer_buzon(ruc, usuario, clave, entidad)})
        except SolError as e:
            self._responder(502, {"error": e.mensaje, "detalle": e.detalle})

    def log_message(self, formato, *args):
        # El log por defecto escribe la query; aquí no hay secretos en la URL,
        # pero se mantiene un formato propio y sin cabeceras.
        sys.stderr.write("[sol-bridge] %s\n" % (formato % args))


def main():
    servidor = ThreadingHTTPServer((HOST, PUERTO), Manejador)
    print(
        f"[sol-bridge] escuchando en {HOST}:{PUERTO}/buzon "
        f"(token {'sí' if TOKEN else 'no'})",
        flush=True,
    )
    servidor.serve_forever()


if __name__ == "__main__":
    main()
