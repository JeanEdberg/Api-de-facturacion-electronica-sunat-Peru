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

Cómo funciona el login (verificado contra SUNAT el 2026-11-10)
--------------------------------------------------------------
El Menú SOL ya NO acepta un POST directo a `j_security_check` con
`RUC/usuario/clave`. Hoy es un flujo OAuth2:

1. GET  MenuInternet.htm            -> devuelve un JS con `redirect("<url>")`.
2. GET  esa url                     -> 302 a `/oauth2/loginMenuSol?state=...`
                                       y deja cookies de sesión.
3. POST `<...>/oauth2/j_security_check` con
       tipo=2, dni=, custom_ruc=<RUC>, j_username=<usuario>,
       j_password=<clave>, captcha=, originalUrl=<...>, lang=es-PE, state=<...>
   - Credenciales buenas -> redirige a `AutenticaMenuInternet.htm`
   - Credenciales malas  -> redirige a `/oauth2/error?...`
4. Con la sesión abierta se localiza el enlace del buzón en el menú.

`state` y `originalUrl` no vienen en el HTML del formulario: hay que sacarlos
de la query del paso 2. Por eso el POST directo a `j_security_check` del host
del menú devuelve 404.

Ojo con el captcha: tras 3 intentos fallidos SUNAT empieza a pedir captcha y
el login automatizado deja de funcionar. El puente NO reintenta nunca: un solo
intento por petición. Si la clave está mal, hay que corregirla, no insistir.
"""

import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from html import unescape
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MENU = "https://e-menu.sunat.gob.pe/cl-ti-itmenu/MenuInternet.htm"

# SUNAT corta la conexión (RST) si el User-Agent no parece un navegador.
NAVEGADOR = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)

PUERTO = int(os.environ.get("BUZON_BRIDGE_PORT", "12002"))
HOST = os.environ.get("BUZON_BRIDGE_HOST", "0.0.0.0")
TOKEN = os.environ.get("BUZON_BRIDGE_TOKEN", "")

# Textos del menú que apuntan al buzón electrónico.
PISTAS_BUZON = ("buzon", "buzón", "notificacion", "notificación", "mensaje")


class SolError(Exception):
    """Fallo al hablar con SUNAT.

    `mensaje` es lo que ve el usuario; `detalle` guarda el texto crudo de SUNAT
    (normalmente HTML) para diagnóstico.
    """

    def __init__(self, mensaje, detalle=""):
        super().__init__(mensaje)
        self.mensaje = mensaje
        self.detalle = detalle


class Sesion:
    """Sesión HTTP con cookies contra el Menú SOL."""

    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cookies)
        )
        self.opener.addheaders = [
            ("User-Agent", NAVEGADOR),
            ("Accept-Language", "es-PE,es;q=0.9"),
            ("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"),
        ]

    def get(self, url):
        try:
            with self.opener.open(url, timeout=60) as r:
                return r.geturl(), r.read().decode("utf-8", "ignore")
        except urllib.error.HTTPError as e:
            return url, e.read().decode("utf-8", "ignore")
        except urllib.error.URLError as e:
            raise SolError(
                "No se pudo conectar con SUNAT. Revisa la conexión a internet "
                "del servidor donde corre el puente.",
                detalle=str(e.reason),
            )
        except OSError as e:
            raise SolError(
                "SUNAT cortó la conexión. Suele pasar cuando la red del servidor "
                "está bloqueando el acceso al Menú SOL.",
                detalle=str(e),
            )

    def post(self, url, datos):
        cuerpo = urllib.parse.urlencode(datos).encode()
        peticion = urllib.request.Request(url, data=cuerpo, method="POST")
        peticion.add_header("Content-Type", "application/x-www-form-urlencoded")
        peticion.add_header("Referer", url)
        try:
            with self.opener.open(peticion, timeout=60) as r:
                return r.geturl(), r.read().decode("utf-8", "ignore")
        except urllib.error.HTTPError as e:
            return e.geturl(), e.read().decode("utf-8", "ignore")
        except urllib.error.URLError as e:
            raise SolError("No se pudo enviar el login a SUNAT.", detalle=str(e.reason))
        except OSError as e:
            raise SolError("SUNAT cortó la conexión durante el login.", detalle=str(e))


def _base_oauth(url):
    """Devuelve la base `/oauth2/` a partir de la URL de `loginMenuSol`."""
    partes = urllib.parse.urlparse(url)
    return f"{partes.scheme}://{partes.netloc}{partes.path.rsplit('/', 1)[0]}/"


def entrar_al_menu(ruc, usuario, clave):
    """Autentica contra el Menú SOL y devuelve (sesión, url_final)."""
    sesion = Sesion()

    # 1. La portada solo trae un JS con la URL de autenticación.
    _, portada = sesion.get(MENU)
    destino = re.search(r'redirect\("([^"]+)"\)', portada)
    if not destino:
        raise SolError(
            "SUNAT no devolvió la página de login esperada. Puede haber cambiado "
            "el Menú SOL o la red estar devolviendo otra página.",
            detalle=portada[:2000],
        )

    # 2. Seguir la autenticación: deja cookies y lleva a `loginMenuSol`.
    url_login, _ = sesion.get(unescape(destino.group(1)))
    parametros = urllib.parse.parse_qs(urllib.parse.urlparse(url_login).query)
    state = (parametros.get("state") or [""])[0]
    original = (parametros.get("originalUrl") or [""])[0]
    if not state or not original:
        raise SolError(
            "SUNAT no entregó el `state` de la sesión de login. El flujo de "
            "autenticación del Menú SOL cambió y hay que actualizar el puente.",
            detalle=url_login,
        )

    # 3. Un único intento. Reintentar activa el captcha y bloquea la cuenta.
    url_final, cuerpo = sesion.post(
        _base_oauth(url_login) + "j_security_check",
        {
            "tipo": "2",  # 2 = RUC + usuario SOL + clave
            "dni": "",
            "custom_ruc": ruc,
            "j_username": usuario,
            "j_password": clave,
            "captcha": "",
            "originalUrl": original,
            "lang": "es-PE",
            "state": state,
        },
    )

    if "/oauth2/error" in url_final:
        raise SolError(
            f"SUNAT rechazó las credenciales SOL del RUC {ruc}. Revisa el usuario "
            "SOL y la Clave SOL en el Menú SOL (sunat.gob.pe). Si los cambiaste "
            "hace poco, guarda la nueva clave en el panel de la empresa.",
            detalle=cuerpo,
        )

    if not any(c.name for c in sesion.cookies):
        raise SolError(
            "SUNAT no abrió sesión. No se recibieron cookies tras el login.",
            detalle=cuerpo[:2000],
        )

    return sesion, url_final


def _enlaces(html, base):
    """Devuelve (texto, url absoluta) de los enlaces del menú."""
    for href, texto in re.findall(r'<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>', html, re.S | re.I):
        limpio = re.sub(r"<[^>]+>", " ", texto)
        limpio = re.sub(r"\s+", " ", unescape(limpio)).strip()
        yield limpio, urllib.parse.urljoin(base, unescape(href))


def buscar_buzon(sesion, html_menu, url_menu):
    """Localiza en el menú la página del buzón y la descarga."""
    candidatos = [
        (texto, url)
        for texto, url in _enlaces(html_menu, url_menu)
        if any(pista in (texto + " " + url).lower() for pista in PISTAS_BUZON)
    ]
    if not candidatos:
        raise SolError(
            "La sesión SOL se abrió, pero no se encontró el enlace del buzón en "
            "el menú. SUNAT cambió el menú y hay que ajustar `PISTAS_BUZON`.",
            detalle=html_menu[:3000],
        )

    # Se prueban los primeros enlaces por si el menú repite la palabra.
    errores = []
    for _, url in candidatos[:5]:
        _, cuerpo = sesion.get(url)
        items = _interpretar(cuerpo)
        if items is not None:
            return items
        errores.append(url)
    raise SolError(
        "Se encontró el buzón pero SUNAT no devolvió datos legibles. "
        "Puede que el buzón use otra ruta o que la sesión haya caducado.",
        detalle="Enlaces probados: " + ", ".join(errores),
    )


def _interpretar(crudo):
    """Intenta leer una lista de notificaciones; None si no lo parece."""
    texto = crudo.strip()
    if not texto:
        return None
    try:
        datos = json.loads(texto)
    except json.JSONDecodeError:
        # Algunos buzones vienen dentro de una tabla HTML.
        return _desde_tabla(crudo)
    items = datos.get("data") if isinstance(datos, dict) else datos
    return items if isinstance(items, list) else None


def _desde_tabla(html):
    """Extrae filas de una tabla HTML como lista de diccionarios."""
    filas = re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.S | re.I)
    if len(filas) < 2:
        return None

    def celdas(fila):
        return [
            re.sub(r"\s+", " ", unescape(re.sub(r"<[^>]+>", " ", c))).strip()
            for c in re.findall(r"<t[dh][^>]*>(.*?)</t[dh]>", fila, re.S | re.I)
        ]

    encabezado = [c.lower() for c in celdas(filas[0])]
    if not any("asunto" in c or "documento" in c or "descrip" in c for c in encabezado):
        return None

    items = []
    for fila in filas[1:]:
        valores = celdas(fila)
        if not valores or not any(valores):
            continue
        registro = dict(zip(encabezado, valores))
        items.append(
            {
                "codigo": registro.get("documento") or registro.get("nro") or "",
                "asunto": registro.get("asunto") or registro.get("descripcion") or "",
                "fecha": registro.get("fecha") or "",
                "detalle": json.dumps(registro, ensure_ascii=False),
            }
        )
    return items


def leer_buzon(ruc, usuario, clave, entidad):
    """Devuelve las notificaciones del buzón como lista de diccionarios."""
    sesion, url_final = entrar_al_menu(ruc, usuario, clave)
    _, html_menu = sesion.get(url_final)
    items = buscar_buzon(sesion, html_menu, url_final)
    for item in items:
        item.setdefault("entidad", entidad)
    return items


class Manejador(BaseHTTPRequestHandler):
    def _responder(self, codigo, cuerpo):
        carga = json.dumps(cuerpo, ensure_ascii=False).encode()
        self.send_response(codigo)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(carga)))
        self.end_headers()
        self.wfile.write(carga)

    def do_GET(self):  # noqa: N802 (nombre impuesto por BaseHTTPRequestHandler)
        # Diagnóstico: comprueba que el puente llega al Menú SOL sin usar
        # credenciales de ninguna empresa.
        if not self.path.startswith("/salud"):
            self._responder(404, {"error": "Ruta no encontrada."})
            return
        try:
            sesion = Sesion()
            _, portada = sesion.get(MENU)
            alcanzable = bool(re.search(r'redirect\("([^"]+)"\)', portada))
            self._responder(
                200,
                {
                    "puente": "ok",
                    "sunat_alcanzable": alcanzable,
                    "detalle": "" if alcanzable else portada[:500],
                },
            )
        except SolError as e:
            self._responder(
                502,
                {
                    "puente": "ok",
                    "sunat_alcanzable": False,
                    "error": e.mensaje,
                    "detalle": e.detalle,
                },
            )

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
        # Sin volcado de cabeceras ni de credenciales en el log.
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
