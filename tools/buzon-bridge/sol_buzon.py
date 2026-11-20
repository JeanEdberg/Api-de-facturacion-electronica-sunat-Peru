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
4. GET  `MenuInternet.htm?action=buzon&s=<subdominio>` -> 302 al visor
       `ww1.sunat.gob.pe/ol-ti-itvisornoti/visor/master?hc=...`.
   - `subdominio` sale de `a/dominios.json` (`{"items":[{"ctx":"default","sdm":"ww1"}]}`).
   - Sin `&s=` el menú responde "Error en la invocación".
5. Los datos NO vienen en ese HTML: el visor los pide por AJAX a
       GET `/ol-ti-itvisornoti/visor/listNotiMenPag`
       con cabecera `X-Ruc: <RUC>` y query
       tipoMsj, codCarpeta, codEtiqueta, page, des_asunto, codMensaje, tipoOrden.
   - `tipoMsj=1` notificaciones, `tipoMsj=2` mensajes.
   - Responde `{"total":N,"records":M,"rows":[...]}`.
   - `/consultarAlertas` responde `{"listaAlertas":[...]}`.

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
import socket
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
import warnings
from datetime import datetime
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

# El buzón vive en el visor de notificaciones, no en el menú. El menú solo
# redirige a él cuando se le pasa el subdominio (`&s=`).
URL_MENU = "https://e-menu.sunat.gob.pe/cl-ti-itmenu/MenuInternet.htm"
URL_VISOR = "https://{sdm}.sunat.gob.pe/ol-ti-itvisornoti/visor"

# Casilla electrónica de SUNAFIL. Es otro sistema aunque comparta la Clave SOL:
# su pantalla de entrada no tiene login propio y delega en el SSO de SUNAT con
# este `client_id`. El SSO devuelve un `code` OAuth2 y la casilla lo canjea.
URL_SSO = "https://api-seguridad.sunat.gob.pe"
SUNAFIL_CLIENT_ID = "b6474e23-8a3b-4153-b301-dafcc9646250"
URL_CASILLA_SUNAFIL = "https://casillaelectronica.sunafil.gob.pe"
# Página que arma la URL del SSO. Su JS es la fuente de verdad del flujo.
RUTA_ENTRADA_SUNAFIL = "/si.inbox/Login/SUNAT"
# `redirect_uri` que usa esa página: el SSO devuelve el `code` aquí.
RUTA_REDIRECT_SUNAFIL = "/si.inbox/Login/Empresa"

# El host de la casilla suele estar inaccesible desde fuera de Perú y no corta
# la conexión: deja las peticiones colgadas hasta el timeout. Se sondea corto.
TIEMPO_SONDA = 5

# El Tomcat de SUNAFIL (Apache-Coyote/1.1) es antiguo y con OpenSSL 3 falla la
# negociación a SECLEVEL=2: el servidor responde SSLV3_ALERT_HANDSHAKE_FAILURE.
# Se prueban en orden hasta dar con la que negocia, y solo para este host: el
# Menú SOL de SUNAT sigue usando el contexto por defecto.
ESTRATEGIAS_TLS = (
    ("por defecto", None, None),
    ("SECLEVEL=1", "DEFAULT@SECLEVEL=1", None),
    ("SECLEVEL=0", "DEFAULT@SECLEVEL=0", None),
    ("SECLEVEL=0 + TLS1.0", "DEFAULT@SECLEVEL=0", "TLSv1"),
)


def _contexto_tls(cifrados=None, minimo=None):
    """Contexto TLS para hablar con la casilla de SUNAFIL."""
    contexto = ssl.create_default_context()
    if cifrados:
        try:
            contexto.set_ciphers(cifrados)
        except ssl.SSLError:
            pass
    if minimo:
        try:
            with warnings.catch_warnings():
                warnings.simplefilter("ignore", DeprecationWarning)
                contexto.minimum_version = getattr(ssl.TLSVersion, minimo)
        except (AttributeError, ValueError):
            pass
    return contexto


def detectar_contexto_tls(host, puerto=443, timeout=TIEMPO_SONDA):
    """Devuelve `(contexto, nombre)` de la primera configuración que negocia.

    Se prueba un saludo TLS real contra el host. Si todas fallan, se lanza
    `SolError` con el detalle de cada intento: distinguir "no llego" de "no
    negocio TLS" ahorra diagnosticar a ciegas.
    """
    fallos = []
    for nombre, cifrados, minimo in ESTRATEGIAS_TLS:
        contexto = _contexto_tls(cifrados, minimo)
        try:
            with socket.create_connection((host, puerto), timeout=timeout) as crudo:
                with contexto.wrap_socket(crudo, server_hostname=host) as tls:
                    tls.version()
            return contexto, nombre
        except ssl.SSLError as e:
            motivo = getattr(e, "reason", None) or str(e)
            fallos.append(f"{nombre}: TLS {motivo}")
        except OSError as e:
            raise SolError(
                f"No se pudo conectar con {host}. Comprueba que este servidor "
                "tiene salida a internet hacia ese host.",
                detalle=str(e),
            )
    raise SolError(
        f"El servidor de {host} rechaza todas las configuraciones TLS que "
        "probó el puente. Suele indicar que el host cambió su configuración.",
        detalle=" | ".join(fallos),
    )


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

    def __init__(self, timeout=60, tls=None, extras=None):
        self.timeout = timeout
        self.tls = tls
        self.extras = list(extras or ())
        self.cookies = http.cookiejar.CookieJar()
        self._construir_opener()

    def _construir_opener(self):
        """Arma el opener con el contexto TLS vigente.

        `add_handler` no sirve aquí: urllib se queda con el primer
        `HTTPSHandler` de la cadena y el que trae `build_opener` por defecto va
        delante, así que el contexto personalizado se ignoraría en silencio.
        """
        manejadores = [urllib.request.HTTPCookieProcessor(self.cookies)]
        if self.tls is not None:
            manejadores.append(urllib.request.HTTPSHandler(context=self.tls))
        manejadores.extend(self.extras)
        self.opener = urllib.request.build_opener(*manejadores)
        self.opener.addheaders = [
            ("User-Agent", NAVEGADOR),
            ("Accept-Language", "es-PE,es;q=0.9"),
            ("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"),
        ]

    def usar_tls(self, contexto):
        """Cambia el contexto TLS conservando cookies y handlers extra."""
        self.tls = contexto
        self._construir_opener()

    def agregar_handler(self, handler):
        """Añade un handler que sobrevive a los cambios de TLS.

        Los grabadores de tráfico deben registrarse así: `usar_tls` reconstruye
        el opener, y un handler añadido a mano se perdería sin avisar.
        """
        self.extras.append(handler)
        self.opener.add_handler(handler)

    def get(self, url):
        try:
            with self.opener.open(url, timeout=self.timeout) as r:
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
            with self.opener.open(peticion, timeout=self.timeout) as r:
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


def _subdominio(sesion):
    """Devuelve el subdominio del visor (`ww1`), que el menú exige en `&s=`."""
    try:
        _, crudo = sesion.get("https://e-menu.sunat.gob.pe/a/dominios.json")
        items = json.loads(crudo).get("items") or []
        for item in items:
            if item.get("ctx") == "default" and item.get("sdm"):
                return item["sdm"]
        if items and items[0].get("sdm"):
            return items[0]["sdm"]
    except (SolError, json.JSONDecodeError, AttributeError):
        pass
    return "ww1"


def abrir_visor(sesion, ruc):
    """Entra al visor del buzón y devuelve (url_visor, base_ajax)."""
    subdominio = _subdominio(sesion)
    url, cuerpo = sesion.get(URL_MENU + "?action=buzon&s=" + subdominio)
    if "/ol-ti-itvisornoti/visor" not in url:
        raise SolError(
            "La sesión SOL se abrió, pero SUNAT no llevó al visor del buzón. "
            "Puede que el buzón esté en mantenimiento.",
            detalle=cuerpo[:2000],
        )
    return url, URL_VISOR.format(sdm=subdominio)


def _pedir_ajax(sesion, base, ruta, params, url_referer, ruc):
    """GET a un endpoint AJAX del visor, con las cabeceras que exige SUNAT."""
    url = f"{base}{ruta}?{urllib.parse.urlencode(params)}"
    peticion = urllib.request.Request(url, method="GET")
    peticion.add_header("X-Ruc", ruc)
    peticion.add_header("X-Requested-With", "XMLHttpRequest")
    peticion.add_header("Accept", "application/json, text/javascript, */*; q=0.01")
    peticion.add_header("Referer", url_referer)
    try:
        with sesion.opener.open(peticion, timeout=sesion.timeout) as r:
            return r.read().decode("utf-8", "ignore")
    except urllib.error.HTTPError as e:
        return e.read().decode("utf-8", "ignore")
    except (urllib.error.URLError, OSError) as e:
        raise SolError(
            "SUNAT no respondió al pedir la lista del buzón.", detalle=str(e)
        )


def _leer_paginas(sesion, base, url_visor, ruc, tipo_msj):
    """Recorre las páginas del buzón y devuelve las filas ya normalizadas."""
    filas = []
    pagina = 1
    while True:
        crudo = _pedir_ajax(
            sesion,
            base,
            "/listNotiMenPag",
            {
                "tipoMsj": tipo_msj,
                "codCarpeta": "",
                "codEtiqueta": "",
                "page": pagina,
                "des_asunto": "",
                "codMensaje": "",
                "tipoOrden": "",
            },
            url_visor,
            ruc,
        )
        try:
            datos = json.loads(crudo)
        except json.JSONDecodeError:
            raise SolError(
                "SUNAT no devolvió la lista del buzón en el formato esperado. "
                "Puede que la sesión haya caducado.",
                detalle=crudo[:2000],
            )

        lote = datos.get("rows") or []
        filas.extend(lote)
        # SUNAT llama `total` al número de páginas y `records` al de registros.
        registros = datos.get("records") or 0
        if not lote or len(filas) >= registros:
            break
        pagina += 1
        if pagina > 50:  # tope de seguridad
            break
    return filas


def _fecha(valor):
    """Convierte `dd/mm/yyyy[ hh:mm:ss]` de SUNAT a `yyyy-mm-dd` (columna DATE)."""
    if not valor:
        return None
    texto = str(valor).strip()
    for formato in ("%d/%m/%Y %H:%M:%S", "%d/%m/%Y"):
        try:
            return datetime.strptime(texto, formato).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return texto[:10] if len(texto) >= 10 else texto


def _normalizar(fila, entidad, tipo):
    """Traduce una fila del visor al formato que espera el backend."""
    return {
        "entidad": entidad,
        "codigo": str(fila.get("codMensaje") or ""),
        "asunto": fila.get("desAsunto") or "",
        "fecha": _fecha(fila.get("fecPublica") or fila.get("fecEnvio")),
        "tipo": tipo,
        "remitente": fila.get("codUsremisor") or "",
        "adjuntos": fila.get("cantidadArchAdj") or 0,
        "leido": fila.get("indEstado") == 1,
        "urgente": fila.get("indUrg") == 1,
        "detalle": json.dumps(fila, ensure_ascii=False),
    }


def leer_buzon(ruc, usuario, clave, entidad):
    """Devuelve las notificaciones y mensajes del buzón de SUNAT."""
    sesion, _ = entrar_al_menu(ruc, usuario, clave)
    url_visor, base = abrir_visor(sesion, ruc)

    items = []
    for tipo_msj, etiqueta in (("1", "notificacion"), ("2", "mensaje")):
        for fila in _leer_paginas(sesion, base, url_visor, ruc, tipo_msj):
            items.append(_normalizar(fila, entidad, etiqueta))
    return items


class _SinRedireccion(urllib.request.HTTPRedirectHandler):
    """No sigue el 302: el destino final apunta al host de la casilla SUNAFIL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _leer_redireccion(sesion, url, datos):
    """POST que devuelve `(status, location)` sin seguir la redirección."""
    cuerpo = urllib.parse.urlencode(datos).encode()
    peticion = urllib.request.Request(url, data=cuerpo, method="POST")
    peticion.add_header("Content-Type", "application/x-www-form-urlencoded")
    peticion.add_header("Referer", url.rsplit("/", 1)[0] + "/login")
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(sesion.cookies), _SinRedireccion()
    )
    opener.addheaders = sesion.opener.addheaders
    try:
        with opener.open(peticion, timeout=sesion.timeout) as r:
            return r.status, r.headers.get("Location", "")
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Location", "")
    except urllib.error.URLError as e:
        raise SolError(
            "No se pudo conectar con el SSO de SUNAT para la casilla de SUNAFIL.",
            detalle=str(e.reason),
        )
    except OSError as e:
        raise SolError("El SSO de SUNAT cortó la conexión.", detalle=str(e))


def _url_authen_sunafil():
    """Arma la URL del SSO igual que el JS de `/si.inbox/Login/SUNAT`.

    La página de entrada de la casilla hace `window.location.replace()` a esta
    URL tras 2 s. Se replica tal cual, incluido `state=s` fijo.
    """
    parametros = urllib.parse.urlencode(
        {
            "client_id": SUNAFIL_CLIENT_ID,
            "response_type": "code",
            "state": "s",
            "redirect_uri": f"{URL_CASILLA_SUNAFIL}{RUTA_REDIRECT_SUNAFIL}",
        }
    )
    return (
        f"{URL_SSO}/v1/clientessol/{SUNAFIL_CLIENT_ID}/oauth2/authen?{parametros}"
    )


def autenticar_sunafil(ruc, usuario, clave, sesion=None):
    """Autentica en la casilla de SUNAFIL con la misma Clave SOL de SUNAT.

    El flujo es el del navegador, verificado contra el sistema real:

    1. `GET /si.inbox/Login/SUNAT` deja el `JSESSIONID` de la casilla y
       devuelve un JS que salta al SSO.
    2. `GET /v1/clientessol/<cid>/oauth2/authen?…` redirige a `/oauth2/login`,
       que trae el formulario `j_security_check` y su `state`.
    3. `POST j_security_check` con `tipo=2` (modo RUC). Credenciales buenas:
       302 al `redirect_uri` con un `code`. Malas: 302 a `/oauth2/error`.

    Se entra primero a la casilla a propósito: su `JSESSIONID` viaja en la URL
    (`;jsessionid=`) y sin esa cookie el canje del `code` falla.

    Devuelve `(code, state)`. No se sigue la redirección final porque apunta al
    host de la casilla, que puede no ser alcanzable desde este servidor.

    `sesion` permite continuar el flujo con las mismas cookies (lo usa el
    script de captura para entrar a la casilla después del login).
    """
    sesion = sesion or Sesion()

    # El Tomcat de SUNAFIL puede exigir cifrados que OpenSSL 3 ya no negocia a
    # SECLEVEL=2. Se busca una configuración válida antes de seguir.
    if sesion.tls is None:
        host = urllib.parse.urlparse(URL_CASILLA_SUNAFIL).hostname
        try:
            sesion.tls, _ = detectar_contexto_tls(host, timeout=sesion.timeout)
        except SolError as e:
            raise SolError(
                "La casilla de SUNAFIL no acepta ninguna configuración TLS de "
                "las que probó el puente. El servidor es antiguo y esta versión "
                "de Python ya no ofrece sus cifrados por defecto.",
                detalle=e.detalle,
            )
        sesion.usar_tls(sesion.tls)

    # 1. La casilla deja su JSESSIONID antes de mandar al SSO.
    try:
        sesion.get(f"{URL_CASILLA_SUNAFIL}{RUTA_ENTRADA_SUNAFIL}")
    except SolError as e:
        detalle = e.detalle or ""
        if "SSL" in detalle or "TLS" in detalle:
            raise SolError(
                "La casilla de SUNAFIL cortó la conexión segura. El servidor es "
                "antiguo y puede exigir cifrados que esta versión de Python ya "
                "no negocia.",
                detalle=detalle,
            )
        raise SolError(
            "No se pudo abrir la casilla de SUNAFIL. Comprueba que el servidor "
            "del puente tiene acceso a casillaelectronica.sunafil.gob.pe.",
            detalle=detalle,
        )

    # 2. Seguir el salto al SSO, como hace el navegador.
    url = _url_authen_sunafil()
    try:
        url_login, _ = sesion.get(url)
    except SolError as e:
        raise SolError(
            "No se pudo iniciar sesión en la casilla de SUNAFIL: el SSO de "
            "SUNAT no respondió.",
            detalle=e.detalle,
        )

    parametros = urllib.parse.parse_qs(urllib.parse.urlparse(url_login).query)
    state = (parametros.get("state") or [""])[0]
    original = (parametros.get("originalUrl") or [""])[0]
    if not state or not original:
        raise SolError(
            "El SSO de SUNAT no entregó el `state` de la casilla de SUNAFIL. "
            "El flujo de autenticación cambió y hay que actualizar el puente.",
            detalle=url_login,
        )

    # Un único intento: cada fallo suma al contador del SSO y a los 3 activa
    # el captcha, que el puente no puede resolver.
    _, destino = _leer_redireccion(
        sesion,
        f"{URL_SSO}/v1/clientessol/{SUNAFIL_CLIENT_ID}/oauth2/j_security_check",
        {
            "tipo": "2",
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

    if "/oauth2/error" in destino:
        raise SolError(
            f"SUNAFIL rechazó las credenciales SOL del RUC {ruc}. Revisa el "
            "usuario SOL y la Clave SOL en el Menú SOL. Ojo: son las mismas "
            "credenciales de SUNAT, no unas aparte.",
            detalle=destino,
        )

    devueltos = urllib.parse.parse_qs(urllib.parse.urlparse(destino).query)
    code = (devueltos.get("code") or [""])[0]
    if not code:
        raise SolError(
            "El SSO de SUNAT aceptó la sesión pero no devolvió el código de "
            "acceso de la casilla de SUNAFIL.",
            detalle=destino,
        )
    return code, (devueltos.get("state") or [state])[0]


def _sondear_sunafil():
    """Comprueba si la casilla de SUNAFIL responde y con qué TLS.

    Devuelve `(alcanzable, tls)`: `tls` es el nombre de la configuración que
    negoció, o el motivo del fallo. Distinguir "no llego" de "no negocio TLS"
    ahorra diagnosticar a ciegas, que es justo lo que pasó la primera vez.
    """
    partes = urllib.parse.urlparse(URL_CASILLA_SUNAFIL)
    try:
        with socket.create_connection(
            (partes.hostname, partes.port or 443), timeout=TIEMPO_SONDA
        ):
            pass
    except OSError as e:
        return False, f"sin conexión: {e}"

    try:
        _, nombre = detectar_contexto_tls(
            partes.hostname, partes.port or 443, timeout=TIEMPO_SONDA
        )
        return True, nombre
    except SolError as e:
        return True, f"no negocia TLS: {e.detalle}"


def leer_buzon_sunafil(ruc, usuario, clave):
    """Lee las notificaciones de la casilla electrónica de SUNAFIL.

    La autenticación está verificada contra el SSO real, pero la bandeja
    todavía no se puede leer: la casilla es una aplicación JSF (`si.inbox`,
    con `;jsessionid=`) y su host no responde desde fuera de Perú. Se avisa en
    vez de devolver una lista vacía: un buzón vacío y uno inaccesible no son
    lo mismo.
    """
    code, state = autenticar_sunafil(ruc, usuario, clave)

    alcanzable, tls = _sondear_sunafil()
    if not alcanzable:
        raise SolError(
            "La Clave SOL es válida, pero el servidor de la casilla de SUNAFIL "
            "no responde. Comprueba desde un navegador en Perú: si tampoco "
            "carga, el servicio está caído del lado de SUNAFIL.",
            detalle=tls,
        )

    raise SolError(
        "La autenticación con SUNAFIL funciona y la casilla responde, pero la "
        "lectura de la bandeja todavía no está implementada: falta capturar la "
        "petición de la lista con una sesión real.",
        detalle=f"code={code[:12]}… state={state}",
    )


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
            sunda = _sondear_sunafil()
            self._responder(
                200,
                {
                    "puente": "ok",
                    "sunat_alcanzable": alcanzable,
                    "sunafil_alcanzable": sunda[0],
                    "sunafil_tls": sunda[1],
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
            # Cada entidad es un sistema distinto: SUNAT se lee por el visor
            # del Menú SOL y SUNAFIL por su casilla. Nunca devolver datos de
            # uno con la etiqueta del otro.
            if entidad == "SUNAT":
                datos = leer_buzon(ruc, usuario, clave, entidad)
            elif entidad == "SUNAFIL":
                datos = leer_buzon_sunafil(ruc, usuario, clave)
            else:
                raise SolError(
                    f"El puente no lee el buzón de {entidad}. Solo soporta "
                    "SUNAT y SUNAFIL."
                )
            self._responder(200, {"data": datos})
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
