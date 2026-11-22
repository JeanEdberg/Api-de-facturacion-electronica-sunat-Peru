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
import hashlib
import json
import os
import re
import socket
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import warnings
from datetime import date, datetime, timedelta
from html import unescape

try:
    import carta_sunafil
except ImportError:  # el módulo es opcional: sin él sólo se pierde la carta
    carta_sunafil = None
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MENU = "https://e-menu.sunat.gob.pe/cl-ti-itmenu/MenuInternet.htm"

# SUNAT corta la conexión (RST) si el User-Agent no parece un navegador.
NAVEGADOR = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)

PUERTO = int(os.environ.get("BUZON_BRIDGE_PORT", "12002"))
RUTA_BUZON = "/buzon"
RUTA_DOCUMENTO = "/documento"

# Se calcula al importar, así que cambiar el código sin reiniciar el proceso
# deja una huella distinta en cada respuesta y el error se ve al instante.
HUELLA = hashlib.sha256(open(__file__, "rb").read()).hexdigest()[:12]
ARRANQUE = time.strftime("%Y-%m-%d %H:%M:%S")
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


def _contexto_tls(cifrados=None, minimo=None, verificar=True):
    """Contexto TLS para hablar con la casilla de SUNAFIL.

    `verificar=False` sólo se usa cuando el saludo TLS funciona pero el
    certificado no valida: ahí el cifrado sí se negocia y lo único que impide
    seguir es la cadena del certificado.
    """
    if verificar:
        contexto = ssl.create_default_context()
    else:
        contexto = ssl.create_default_context()
        contexto.check_hostname = False
        contexto.verify_mode = ssl.CERT_NONE
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
    # `CERTIFICATE_VERIFY_FAILED` no es un fallo de cifrado: el saludo TLS se
    # negoció y sólo falló la validación del certificado. Se reintenta sin
    # verificar en vez de descartar la configuración, que era lo que dejaba
    # al puente sin ninguna opción válida.
    for nombre, cifrados, minimo in ESTRATEGIAS_TLS:
        for verificar in (True, False):
            contexto = _contexto_tls(cifrados, minimo, verificar)
            etiqueta = nombre if verificar else f"{nombre} (sin verificar)"
            try:
                with socket.create_connection((host, puerto), timeout=timeout) as crudo:
                    with contexto.wrap_socket(crudo, server_hostname=host) as tls:
                        tls.version()
                return contexto, etiqueta
            except ssl.SSLCertVerificationError as e:
                motivo = getattr(e, "verify_message", None) or "certificado no válido"
                fallos.append(f"{etiqueta}: certificado ({motivo})")
                continue
            except ssl.SSLError as e:
                motivo = getattr(e, "reason", None) or str(e)
                fallos.append(f"{etiqueta}: TLS {motivo}")
                break
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


class ConflictoRegistro(SolError):
    """La fila del índice ya no es la que se pidió: SUNAFIL reordenó.

    No es un fallo: el puente se niega a pulsar para no abrir otro documento.
    """

    def __init__(self, real, esperado):
        super().__init__(
            "La bandeja cambió de orden: la fila ya no es esa notificación. "
            "Vuelve a sincronizar antes de abrir.",
            detalle=f"esperado={esperado!r} real={real!r}",
        )
        self.real = real
        self.esperado = esperado


# Código de registro de la casilla: "0000122241 - 2026 - SUNAFIL/DPPR/SDPA".
REGISTRO_RE = re.compile(r"\d{6,}\s*-\s*\d{4}(?:\s*-\s*[\w/]+)*")


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

    def _enviar(self, peticion):
        try:
            with self.opener.open(peticion, timeout=self.timeout) as r:
                return r.geturl(), r.read().decode("utf-8", "ignore")
        except urllib.error.HTTPError as e:
            return e.geturl(), e.read().decode("utf-8", "ignore")
        except urllib.error.URLError as e:
            raise SolError("No se pudo enviar el login a SUNAT.", detalle=str(e.reason))
        except OSError as e:
            raise SolError("SUNAT cortó la conexión durante el login.", detalle=str(e))

    def post(self, url, datos):
        """POST de formulario normal: JSF responde con la página completa."""
        peticion = urllib.request.Request(
            url, data=urllib.parse.urlencode(datos).encode(), method="POST"
        )
        peticion.add_header("Content-Type", "application/x-www-form-urlencoded")
        peticion.add_header("Referer", url)
        return self._enviar(peticion)

    def post_crudo(self, url, datos):
        """POST de formulario que devuelve bytes y cabeceras.

        Para descargar un documento no sirve decodificar como texto: un PDF
        se corrompe al pasar por `decode("utf-8", "ignore")`.
        """
        peticion = urllib.request.Request(
            url, data=urllib.parse.urlencode(datos).encode(), method="POST"
        )
        peticion.add_header("Content-Type", "application/x-www-form-urlencoded")
        peticion.add_header("Referer", url)
        try:
            with self.opener.open(peticion, timeout=self.timeout) as r:
                return r.geturl(), dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.geturl(), dict(e.headers), e.read()
        except (urllib.error.URLError, OSError) as e:
            raise SolError("No se pudo descargar el documento.", detalle=str(e))

    def post_ajax_crudo(self, url, datos):
        """POST AJAX de JSF que devuelve bytes y cabeceras.

        Un `PrimeFaces.ab` puede responder un archivo directamente o un XML de
        actualización parcial; hay que poder distinguirlos, así que no se
        decodifica como texto.
        """
        datos = dict(datos)
        datos["javax.faces.partial.ajax"] = "true"
        peticion = urllib.request.Request(
            url, data=urllib.parse.urlencode(datos).encode(), method="POST"
        )
        peticion.add_header(
            "Content-Type", "application/x-www-form-urlencoded; charset=UTF-8"
        )
        peticion.add_header("Faces-Request", "partial/ajax")
        peticion.add_header("X-Requested-With", "XMLHttpRequest")
        peticion.add_header("Referer", url)
        try:
            with self.opener.open(peticion, timeout=self.timeout) as r:
                return r.geturl(), dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.geturl(), dict(e.headers), e.read()
        except (urllib.error.URLError, OSError) as e:
            raise SolError("No se pudo descargar el documento.", detalle=str(e))

    def get_crudo(self, url):
        """GET que devuelve bytes y cabeceras (documentos enlazados por href)."""
        try:
            with self.opener.open(url, timeout=self.timeout) as r:
                return r.geturl(), dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.geturl(), dict(e.headers), e.read()
        except (urllib.error.URLError, OSError) as e:
            raise SolError("No se pudo descargar el documento.", detalle=str(e))

    def post_ajax(self, url, datos):
        """POST de JSF parcial: responde XML con solo los trozos actualizados.

        Es lo que hace `PrimeFaces.ab` y sus equivalentes: sin estas cabeceras
        el servidor restaura la vista y devuelve la misma página, que es
        justo el síntoma de que la acción no se ejecutó.
        """
        datos = dict(datos)
        datos["javax.faces.partial.ajax"] = "true"
        peticion = urllib.request.Request(
            url, data=urllib.parse.urlencode(datos).encode(), method="POST"
        )
        peticion.add_header(
            "Content-Type", "application/x-www-form-urlencoded; charset=UTF-8"
        )
        peticion.add_header("Faces-Request", "partial/ajax")
        peticion.add_header("X-Requested-With", "XMLHttpRequest")
        peticion.add_header("Referer", url)
        return self._enviar(peticion)


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


def _viewstate(html_casilla):
    """Saca el `javax.faces.ViewState` que exige cada POST de JSF."""
    m = re.search(
        r'name="javax\.faces\.ViewState"[^>]*value="([^"]+)"', html_casilla
    )
    if not m:
        raise SolError(
            "La casilla respondió sin ViewState. La sesión no está iniciada o "
            "la página cambió de formato.",
            detalle=html_casilla[:300],
        )
    return m.group(1)


def _boton_de_menu(html_casilla, etiqueta):
    """Id del enlace del menú cuyo texto contiene `etiqueta`.

    Se busca por el texto visible, no por el `j_idtNN`: esos ids los genera JSF
    en cada compilación de la página y cambian sin avisar.
    """
    id_elemento = _elemento_pulsable(html_casilla, etiqueta)
    if id_elemento:
        return id_elemento
    raise SolError(
        f"El menú de la casilla no ofrece «{etiqueta}», o su enlace no tiene "
        "id. Puede que SUNAFIL haya cambiado la pantalla o que este usuario no "
        "tenga ese permiso.",
        detalle=re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html_casilla))[:500],
    )


def _objeto_js(texto):
    """Convierte un objeto literal de JavaScript en un dict.

    Los `onclick` de PrimeFaces traen `{'campo':'valor','otro':'valor'}`, con
    comillas simples y sin anidamiento. Se acepta también sin comillas.
    """
    campos = {}
    for clave, valor in re.findall(
        r"""['"]?([\w:.\[\]-]+)['"]?\s*:\s*['"]([^'"]*)['"]""", texto
    ):
        campos[clave] = unescape(valor)
    return campos


def _formulario_que_contiene(html_casilla, posicion):
    """Id del último `<form>` abierto antes de `posicion`."""
    formulario = None
    for m in re.finditer(r"<form\b[^>]*>", html_casilla[:posicion]):
        ident = re.search(r"""\bid\s*=\s*["']([^"']+)["']""", m.group(0))
        if ident:
            formulario = ident.group(1)
    return formulario


def _elemento_en(html_casilla, inicio):
    """HTML del elemento cuya etiqueta de apertura empieza en `inicio`."""
    etiqueta = re.match(r"<(\w+)", html_casilla[inicio:])
    if not etiqueta:
        return html_casilla[inicio:inicio + 400]
    nombre = etiqueta.group(1).lower()

    if nombre in ("input", "br", "img", "hr", "meta", "link"):
        fin = html_casilla.find(">", inicio)
        return html_casilla[inicio:fin + 1] if fin > 0 else html_casilla[inicio:]

    # Se equilibra el anidamiento para no cortar en un hijo igual.
    patron = re.compile(rf"<(/?){nombre}\b", re.I)
    profundidad = 0
    for m in patron.finditer(html_casilla, inicio):
        if m.group(1):
            profundidad -= 1
            if profundidad == 0:
                cierre = html_casilla.find(">", m.start())
                return html_casilla[inicio:cierre + 1 if cierre > 0 else len(html_casilla)]
        else:
            profundidad += 1
    return html_casilla[inicio:inicio + 1500]


def _candidatos(html_casilla):
    """Elementos que pueden disparar la acción de una opción del menú.

    Se buscan etiquetas con `onclick` o con un id de JSF (los ids con `:`), que
    es donde vive la acción. Así no hay que acertar si el menú usa `<li>`,
    `<a>` o `<span>`.
    """
    vistos = set()
    for m in re.finditer(r"<[a-zA-Z][^>]*>", html_casilla):
        apertura = m.group(0)
        if "onclick" not in apertura.lower() and ":" not in apertura:
            continue
        if m.start() in vistos:
            continue
        vistos.add(m.start())
        yield m.start(), _elemento_en(html_casilla, m.start())


def _bloque_de_menu(html_casilla, etiqueta):
    """Elemento pulsable del menú más pequeño cuyo texto contiene `etiqueta`.

    Devuelve `(elemento, posicion)`. Se prefiere el más pequeño porque un
    contenedor grande (el `<ul>` del menú, por ejemplo) también contiene el
    texto de la opción y ganaría por aparecer antes en el documento.
    """
    buscada = etiqueta.lower()

    def coincide(elemento):
        visible = re.sub(r"<[^>]+>", " ", elemento)
        visible = re.sub(r"\s+", " ", unescape(visible)).strip()
        return buscada in visible.lower()

    # Un elemento con `onclick` manda sobre uno sin él, aunque sea más grande:
    # el `<span>` con el texto no sabe cómo se pulsa la opción.
    encontrados = [
        (0 if "onclick" in elemento[:2000].lower() else 1, len(elemento), posicion, elemento)
        for posicion, elemento in _candidatos(html_casilla)
        if coincide(elemento)
    ]
    if encontrados:
        _, _, posicion, elemento = min(encontrados, key=lambda t: t[:3])
        return elemento, posicion

    for m in re.finditer(r"<li\b", html_casilla):
        elemento = _elemento_en(html_casilla, m.start())
        if coincide(elemento):
            return elemento, m.start()

    return None, None


def _elemento_pulsable(html_casilla, etiqueta):
    """Id del elemento del menú que dispara la acción de `etiqueta`."""
    elemento, _ = _bloque_de_menu(html_casilla, etiqueta)
    if elemento is None:
        return None
    return _accion_del_menu(elemento)[0]


def _accion_del_menu(elemento):
    """Cómo hay que pedir la pantalla al pulsar una opción del menú.

    Devuelve `(id_elemento, formulario, parametros, es_ajax)`.

    La acción puede estar en la propia etiqueta del elemento o en un hijo (el
    `<a>` con el texto y el `onclick` en otro sitio es habitual), así que se
    toma la primera etiqueta que traiga `onclick` y, si no hay, la del elemento.

    Los handlers posibles son `BsF.submitForm('formMenu2',{...})` (submit normal
    con campos extra), `PrimeFaces.ab({source:'id'})` y
    `mojarra.ab(this,event,...)` (los dos últimos, AJAX).
    """
    accionable = re.search(r"<[^>]*\bonclick\s*=[^>]*>", elemento, re.S)
    if accionable is None:
        accionable = re.search(r"<[^>]*>", elemento, re.S)
    etiqueta_accion = accionable.group(0) if accionable else elemento

    ident = re.search(r"""\bid\s*=\s*["']([^"']+)["']""", etiqueta_accion)
    id_elemento = ident.group(1) if ident else None
    if id_elemento is None:
        for candidato in re.findall(r"""\bid\s*=\s*["']([^"']+)["']""", elemento):
            if ":" in candidato:
                id_elemento = candidato
                break

    # El onclick puede venir entre comillas dobles con simples dentro.
    codigo = ""
    for doble, simple in re.findall(
        r"""onclick\s*=\s*(?:"([^"]*)"|'([^']*)')""", etiqueta_accion, re.S
    ):
        codigo += unescape(doble or simple)

    llamada = re.search(
        r"""submitForm\(\s*['"]([^'"]+)['"]\s*,\s*(\{.*?\})\s*\)""", codigo, re.S
    )
    if llamada:
        return id_elemento, llamada.group(1), _objeto_js(llamada.group(2)), False

    formulario = None
    if id_elemento and ":" in id_elemento:
        formulario = id_elemento.split(":")[0]
    return id_elemento, formulario, {}, True


def _campos_ocultos(html_casilla, id_formulario):
    """Todos los `<input type="hidden">` de un formulario.

    El navegador envía el formulario entero, no solo el botón: JSF puede exigir
    campos que no se ven en el HTML a primera vista, como
    `javax.faces.ClientWindow`. Enviarlos todos es lo más fiel.
    """
    bloque = None
    for trozo in re.split(r"(?=<form\b)", html_casilla)[1:]:
        ident = re.search(r'<form[^>]*\bid="([^"]+)"', trozo)
        if ident and ident.group(1) == id_formulario:
            bloque = trozo.split("</form>")[0]
            break
    if bloque is None:
        raise SolError(
            f"La casilla no tiene el formulario «{id_formulario}». "
            "Puede que la pantalla haya cambiado.",
            detalle=re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html_casilla))[:400],
        )

    campos = {}
    for etiqueta in re.findall(r"<input\b[^>]*>", bloque):
        if "hidden" not in etiqueta:
            continue
        nombre = re.search(r'\bname="([^"]*)"', etiqueta)
        valor = re.search(r'\bvalue="([^"]*)"', etiqueta)
        if nombre:
            campos[unescape(nombre.group(1))] = (
                unescape(valor.group(1)) if valor else ""
            )

    # El `ViewState` pertenece a la vista, no al formulario: los diálogos de
    # PrimeFaces no lo llevan dentro. Sin él JSF no ejecuta la acción y sólo
    # re-renderiza la página, que es justo lo que devolvía SUNAFIL.
    if "javax.faces.ViewState" not in campos:
        global_vs = re.search(
            r'<input[^>]*\bname="javax\.faces\.ViewState"[^>]*\bvalue="([^"]*)"',
            html_casilla,
        )
        if global_vs:
            campos["javax.faces.ViewState"] = unescape(global_vs.group(1))
    return campos


def abrir_bandeja_sunafil(sesion, etiqueta="Notificaciones de Fiscalización"):
    """Abre una bandeja y devuelve sólo el HTML. Ver `_abrir_bandeja`."""
    return _abrir_bandeja(sesion, etiqueta)[1]


def _abrir_bandeja(sesion, etiqueta="Notificaciones de Fiscalización"):
    """Abre una pantalla del menú de la casilla y devuelve la respuesta.

    No hay ruta GET para la bandeja: se pulsa la opción del menú, que es un
    POST. Según la pantalla el handler es un submit normal (`BsF.submitForm`) o
    AJAX (`PrimeFaces.ab`, `mojarra.ab`), así que se envía lo que corresponda
    leyéndolo del propio `onclick`, más los campos ocultos del formulario.
    """
    url = f"{URL_CASILLA_SUNAFIL}/si.inbox/Inicio/Empleador"
    _, inicio = sesion.get(url)

    bloque, posicion = _bloque_de_menu(inicio, etiqueta)
    if bloque is None:
        raise SolError(
            f"El menú de la casilla no ofrece «{etiqueta}». Puede que SUNAFIL "
            "haya cambiado la pantalla o que este usuario no tenga ese permiso.",
            detalle=re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", inicio))[:500],
        )

    id_elemento, formulario, parametros, es_ajax = _accion_del_menu(bloque)
    if not formulario:
        formulario = _formulario_que_contiene(inicio, posicion)
    if not formulario:
        raise SolError(
            f"No se pudo saber el formulario de «{etiqueta}»: ni el id del "
            "enlace ni el formulario que lo contiene lo indican.",
            detalle=re.sub(r"\s+", " ", bloque)[:400],
        )

    datos = _campos_ocultos(inicio, formulario)
    datos.update(parametros)
    if id_elemento:
        datos[id_elemento] = id_elemento

    if es_ajax:
        datos.update(
            {
                "javax.faces.source": id_elemento or formulario,
                "javax.faces.partial.execute": "@this",
                "javax.faces.partial.render": "@all",
            }
        )
        url_final, html = sesion.post_ajax(url, datos)
    else:
        url_final, html = sesion.post(url, datos)
    # La URL importa: el POST del documento tiene que ir a la vista que quedó
    # abierta. Enviarlo a /Inicio/Empleador restaura la vista de inicio y
    # SUNAFIL devuelve la página de bienvenida en vez del archivo.
    return url_final, html


# Marcas que solo aparecen en la página de inicio de la casilla. Si el POST
# las devuelve es que no abrió la bandeja y hay que avisar, no dar lista vacía.
MARCAS_INICIO = ("formInicio_parque", "image-1.jpg")


def _es_inicio(html_casilla):
    """True si la respuesta es la página de inicio y no una bandeja."""
    return any(marca in html_casilla for marca in MARCAS_INICIO)


# Claves de las columnas que interesan de la tabla de Acciones Previas.
COLUMNAS_ACCIONES = (
    "tipo",
    "registro",
    "fecha_deposito",
    "fecha_acuse",
    "fecha_notificacion",
    "plazo",
    "fecha_limite",
)

# Cómo reconocer cada columna por su encabezado visible. Se busca por texto y
# no por posición porque SUNAFIL añade y quita columnas (Opción, Ver
# Documento, Envío Req.) sin avisar.
PISTAS_COLUMNA = (
    ("tipo", ("tipo de requerimiento", "tipo")),
    ("registro", ("registro",)),
    ("fecha_deposito", ("fecha de deposito", "deposito")),
    ("fecha_acuse", ("acuse",)),
    ("fecha_notificacion", ("notificacion",)),
    ("plazo", ("plazo",)),
    ("fecha_limite", ("limite",)),
)


def _texto_celda(celda):
    """Texto plano de una celda, con los `<br>` como separación."""
    texto = re.sub(r"<br\s*/?>", " ", celda)
    texto = re.sub(r"<[^>]+>", " ", texto)
    return unescape(re.sub(r"\s+", " ", texto)).strip()


def _celdas(fila):
    """Texto de cada `<td>` de una fila, con los saltos de línea respetados."""
    return [_texto_celda(c) for c in re.findall(r"<td[^>]*>(.*?)</td>", fila, re.S)]


# Los tres botones de «Ver Documento», tal como los dibuja la casilla. Se
# reconocen por el `title` (que es el tooltip que ve el usuario) y no por el id,
# que es un `j_idtNN` generado y cambia en cada despliegue de SUNAFIL.
BOTONES_DOCUMENTO = (
    ("constancia_deposito", ("constancia de deposito",)),
    ("constancia_acuse", ("constancia de acuse", "acuse de recibo")),
)

# El botón de la carta no lleva `title`: sólo un icono de PDF. Es el único de
# los tres sin texto, así que se reconoce por el icono.
ICONO_CARTA = ("fa-file-pdf-o", "fa-file-pdf")


def _botones_de_fila(fila):
    """Botones de documento de una fila, con su nombre de formulario.

    La casilla no usa enlaces: dibuja `<button type="submit" name="...">` y
    envía el formulario con ese botón pulsado. El `onclick` sólo llama a
    `BsF.ajax.callAjax`, que es un detalle de la interfaz; lo que el servidor
    necesita es el `name` del botón.

    Devuelve `{"carta": {...}, "constancia_deposito": {...}, ...}`.
    """
    encontrados = {}
    for boton in re.findall(r"<button\b.*?</button>", fila, re.S | re.I):
        apertura = boton.split(">", 1)[0]
        nombre = re.search(r"\bname\s*=\s*\"([^\"]+)\"", apertura)
        if not nombre:
            continue
        # Un botón deshabilitado no se puede pulsar: el de «Leído» ya se usó.
        if re.search(r"\bdisabled\b", apertura, re.I):
            continue

        titulo = re.search(r"\btitle\s*=\s*\"([^\"]*)\"", apertura)
        texto = _sin_acentos(unescape(titulo.group(1))) if titulo else ""

        clave = None
        for candidata, pistas in BOTONES_DOCUMENTO:
            if any(pista in texto for pista in pistas):
                clave = candidata
                break
        if clave is None and any(icono in boton for icono in ICONO_CARTA):
            clave = "carta"
        if clave is None or clave in encontrados:
            continue

        # `BsF.ajax.callAjax(...); return false` cancela el submit normal: ese
        # botón sólo funciona por AJAX. Los de constancia no lo llevan y van
        # por submit normal. Confundirlos devuelve la página de inicio.
        exige_ajax = bool(
            re.search(r"BsF\.ajax\.callAjax", apertura) and re.search(r"return\s+false", apertura)
        )
        encontrados[clave] = {
            "boton": unescape(nombre.group(1)),
            "titulo": unescape(titulo.group(1)) if titulo else "",
            "ajax": exige_ajax,
        }
    return encontrados


def _descarga_de_fila(fila):
    """Acciones de documento de una fila.

    Se devuelven todas las que haya, no una: la carta y las constancias son
    documentos distintos y el usuario elige cuál quiere.
    """
    return _botones_de_fila(fila)


def _accion_de_onclick(onclick):
    """Traduce un `onclick` de la casilla a una acción de descarga.

    Se mantiene para las pantallas que sí usan enlaces: el POST de JSF clásico
    (`mojarra.jsfcljs`) y el parcial de PrimeFaces (`PrimeFaces.ab`).
    """
    if not onclick:
        return None

    jsf = re.search(r"jsfcljs\s*\(\s*(.+?)\s*,\s*\{(.*?)\}\s*,", onclick, re.S)
    if jsf:
        argumento = jsf.group(1).strip()
        por_id = re.search(r"getElementById\s*\(\s*['\"]([^'\"]+)['\"]", argumento)
        parametros = _pares_js(jsf.group(2))
        if parametros:
            return {
                "tipo": "jsf",
                "destino": por_id.group(1) if por_id else argumento,
                "parametros": parametros,
            }

    ab = re.search(r"PrimeFaces\.ab\s*\(\s*\{(.*?)\}\s*\)", onclick, re.S)
    if ab:
        opciones = _pares_js(ab.group(1))
        if opciones:
            return {
                "tipo": "ajax",
                "destino": opciones.get("f") or opciones.get("s") or "",
                "parametros": opciones,
            }

    return None


def _pares_js(texto):
    """Pares clave/valor de un objeto JS literal.

    La clave puede ir entrecomillada (`'param_uuid':'x'`, lo que escribe
    `jsfcljs`) o desnuda (`s:'x'`, lo que escribe `PrimeFaces.ab`). Exigir
    comillas en la clave hacía que PrimeFaces no se reconociera nunca.
    """
    pares = {}
    patron = r"""(?:['"]([^'"]+)['"]|([A-Za-z_$][\w$]*))\s*:\s*['"]([^'"]*)['"]"""
    for entrecomillada, desnuda, valor in re.findall(patron, texto):
        pares[entrecomillada or desnuda] = valor
    return pares


def _sin_acentos(texto):
    reemplazos = str.maketrans("áéíóúÁÉÍÓÚñÑ", "aeiouAEIOUnN")
    return texto.translate(reemplazos).lower()


def _tablas(html_casilla):
    """HTML interno de cada tabla, equilibrando el anidamiento.

    Si el texto no trae `<table>` (un fragmento pegado a mano, por ejemplo) se
    devuelve entero para no perder las filas.
    """
    salida = []
    for apertura in re.finditer(r"<table\b", html_casilla):
        profundidad = 0
        for marca in re.finditer(r"</?table\b", html_casilla[apertura.start():]):
            if marca.group(0).startswith("</"):
                profundidad -= 1
                if profundidad == 0:
                    fin = apertura.start() + marca.end()
                    salida.append(html_casilla[apertura.start():fin])
                    break
            else:
                profundidad += 1
    return salida or [html_casilla]


def _mapa_columnas(tabla):
    """Índice de cada columna conocida, según los encabezados de la tabla.

    Si la tabla no trae `<th>` utilizables se devuelve el orden conocido, que es
    el que usa la casilla hoy.
    """
    encabezados = [
        _sin_acentos(_texto_celda(c))
        for c in re.findall(r"<th[^>]*>(.*?)</th>", tabla, re.S)
    ]

    mapa = {}
    for indice, texto in enumerate(encabezados):
        for clave, pistas in PISTAS_COLUMNA:
            if clave in mapa:
                continue
            if any(pista in texto for pista in pistas):
                mapa[clave] = indice
                break

    if "registro" in mapa:
        return mapa
    if not encabezados:
        return {clave: i for i, clave in enumerate(COLUMNAS_ACCIONES)}
    return {}


def _dias_hasta(fecha):
    """Días que faltan para una fecha `dd/mm/aaaa`, o None si no se entiende."""
    if not fecha:
        return None
    try:
        limite = datetime.strptime(fecha.strip(), "%d/%m/%Y").date()
    except ValueError:
        return None
    return (limite - date.today()).days


def _habiles_hasta(fecha):
    """Días hábiles que faltan, sin contar hoy.

    SUNAFIL da los plazos en días hábiles, así que el número de días naturales
    engaña: del viernes 20 al jueves 26 hay 6 naturales pero solo 4 hábiles.
    No se descuentan feriados: sin un calendario oficial eso sería peor que no
    hacerlo, porque daría una cifra falsa con apariencia de exacta.
    """
    if not fecha:
        return None
    try:
        limite = datetime.strptime(fecha.strip(), "%d/%m/%Y").date()
    except ValueError:
        return None
    hoy = date.today()
    if limite <= hoy:
        return 0
    dias = 0
    cursor = hoy
    while cursor < limite:
        cursor += timedelta(days=1)
        if cursor.weekday() < 5:
            dias += 1
    return dias


def extraer_acciones_previas(html_casilla):
    """Saca las filas de la tabla de Acciones Previas de la casilla.

    Busca la tabla por sus encabezados (Registro + Plazo o Fecha Límite) en vez
    de asumir que es la primera: la página trae varias. Si no encuentra los
    encabezados, cae al orden conocido de columnas.
    """
    for tabla in _tablas(html_casilla):
        mapa = _mapa_columnas(tabla)
        if "registro" not in mapa:
            continue
        if "plazo" not in mapa and "fecha_limite" not in mapa:
            continue

        acciones = []
        for fila in re.findall(r"<tr[^>]*>(.*?)</tr>", tabla, re.S):
            if "<td" not in fila:
                continue
            celdas = _celdas(fila)
            if len(celdas) <= max(mapa.values()):
                continue

            datos = {
                clave: celdas[indice]
                for clave, indice in mapa.items()
                if indice < len(celdas)
            }
            if not re.search(r"\d{6,}", datos.get("registro", "")):
                continue

            deposito = datos.get("fecha_deposito", "")
            efectos = re.search(r"Efectos del Dep[oó]sito:?\s*([0-9/]+)", deposito)
            datos["fecha_efectos"] = efectos.group(1) if efectos else ""
            datos["fecha_deposito"] = deposito.split("Efectos")[0].strip()
            documentos = _botones_de_fila(fila)
            if documentos:
                datos["documentos"] = sorted(documentos)
            datos["dias_restantes"] = _dias_hasta(datos.get("fecha_limite", ""))
            datos["dias_habiles_restantes"] = _habiles_hasta(
                datos.get("fecha_limite", "")
            )
            # `/documento` identifica la fila por su posición en la tabla, no
            # por el código: sin este índice no se puede pedir el archivo.
            datos["indice"] = len(acciones)
            acciones.append(datos)

        if acciones:
            return acciones
    return []


def _tabla_por_id(html_casilla, fragmento):
    """Tabla cuyo id contiene `fragmento`, o la que va dentro de ese elemento.

    PrimeFaces a veces pone el id en el `<div>` que envuelve la tabla y deja la
    `<table>` sin id, así que no basta con mirar las etiquetas `<table>`.
    """
    buscado = fragmento.lower()

    for tabla in _tablas(html_casilla):
        apertura = re.match(r"<table[^>]*>", tabla, re.S)
        if apertura and buscado in apertura.group(0).lower():
            return tabla

    for m in re.finditer(r"<[a-zA-Z][^>]*\bid\s*=\s*[\"'][^\"']*\b" + re.escape(buscado), html_casilla, re.I):
        envoltorio = _elemento_en(html_casilla, m.start())
        dentro = re.search(r"<table\b", envoltorio)
        if dentro:
            tablas = _tablas(envoltorio[dentro.start():])
            if tablas:
                return tablas[0]
    return None


def extraer_notificaciones(html_casilla):
    """Filas de la tabla de notificaciones de la bandeja abierta.

    Se buscan por el id `dtNotificaciones` y se nombran con los encabezados de
    la propia tabla, porque SUNAFIL añade y quita columnas entre pantallas.
    Si la tabla no trae encabezados utilizables, las columnas salen numeradas.
    """
    tabla = _tabla_por_id(html_casilla, "dtNotificaciones")
    if tabla is None:
        return []

    encabezados = [
        _sin_acentos(_texto_celda(c))
        for c in re.findall(r"<th[^>]*>(.*?)</th>", tabla, re.S)
    ]

    filas = []
    for fila in re.findall(r"<tr[^>]*>(.*?)</tr>", tabla, re.S):
        if "<td" not in fila:
            continue
        celdas = _celdas(fila)
        if not any(celdas):
            continue
        if encabezados and len(celdas) >= len(encabezados):
            filas.append(dict(zip(encabezados, celdas)))
        else:
            filas.append({f"columna_{i + 1}": c for i, c in enumerate(celdas)})
    return filas


# Cada bandeja es una pantalla distinta del menú, con su propio POST. Se
# declaran aquí para no confundirlas: leer una y parsear la otra daba siempre
# cero acciones previas.
BANDEJAS_SUNAFIL = (
    ("notificaciones", "Notificaciones de Fiscalización"),
    ("acciones_previas", "Acciones Previas"),
)


def leer_buzon_sunafil(ruc, usuario, clave):
    """Lee la casilla electrónica de SUNAFIL y devuelve sus bandejas.

    El orden importa: el `code` de OAuth2 caduca en segundos, así que se canjea
    en cuanto el login lo devuelve. Cualquier comprobación de red entre esos dos
    pasos lo invalida, y la casilla acaba devolviendo el login sin menú.

    Cada bandeja se abre por separado porque son pantallas distintas. Si una
    falla, las demás se devuelven igual y el fallo va en `errores`.
    """
    # La comprobación de red va ANTES del login: entre el login y el canje del
    # `code` no puede haber nada, porque el código caduca en segundos.
    alcanzable, tls = _sondear_sunafil()
    if not alcanzable:
        raise SolError(
            "La Clave SOL es válida, pero el servidor de la casilla de SUNAFIL "
            "no responde. Comprueba desde un navegador en Perú: si tampoco "
            "carga, el servicio está caído del lado de SUNAFIL.",
            detalle=tls,
        )

    sesion = Sesion()
    code, state = autenticar_sunafil(ruc, usuario, clave, sesion=sesion)

    # Canje inmediato, igual que el navegador: sin esto la sesión no queda
    # autenticada aunque el login haya sido aceptado.
    entrada = (
        f"{URL_CASILLA_SUNAFIL}{RUTA_REDIRECT_SUNAFIL}"
        f"?state={urllib.parse.quote(state)}&code={urllib.parse.quote(code)}"
    )
    try:
        _, bienvenida = sesion.get(entrada)
    except SolError as e:
        raise SolError(
            "El login fue aceptado pero la casilla no canjeó el código de "
            "acceso. Puede que el código caduque muy rápido o que SUNAFIL haya "
            "cambiado el flujo de entrada.",
            detalle=e.detalle,
        )

    resultado = {clave_datos: [] for clave_datos, _ in BANDEJAS_SUNAFIL}
    errores = {}

    for clave_datos, etiqueta in BANDEJAS_SUNAFIL:
        try:
            html = abrir_bandeja_sunafil(sesion, etiqueta)
            if os.environ.get("BUZON_GUARDAR"):
                _guardar(clave_datos, html)

            if _es_inicio(html):
                raise SolError(
                    f"SUNAFIL devolvió la página de inicio en vez de «{etiqueta}».",
                    detalle=_pista_de_login(bienvenida),
                )

            if clave_datos == "notificaciones":
                resultado[clave_datos] = extraer_notificaciones(html)
                if not resultado[clave_datos] and _tabla_por_id(html, "dtNotificaciones") is None:
                    raise SolError(
                        "La bandeja se abrió pero no trae la tabla de "
                        "notificaciones. Puede que SUNAFIL haya cambiado el id.",
                        detalle=_resumen_pagina(html),
                    )
            else:
                resultado[clave_datos] = extraer_acciones_previas(html)

        except SolError as e:
            # Se guarda el detalle: sin él no se sabe si falló el menú, la
            # sesión o el parseo, y se acaba adivinando.
            errores[clave_datos] = (
                f"{e.mensaje} Detalle: {e.detalle}" if e.detalle else e.mensaje
            )
        except Exception as e:  # una bandeja rota no debe tumbar las demás
            errores[clave_datos] = f"Fallo inesperado al leer «{etiqueta}»: {e!r}"

    if errores and len(errores) == len(BANDEJAS_SUNAFIL):
        raise SolError(
            "No se pudo leer ninguna bandeja de la casilla de SUNAFIL.",
            detalle=(
                f"[puente {HUELLA} arrancado {ARRANQUE}] "
                f"code={code[:12]}… state={state}. "
                + " | ".join(f"{k}: {v}" for k, v in errores.items())
            ),
        )

    if errores:
        resultado["errores"] = errores
    return resultado


def _guardar(nombre, html):
    """Deja la respuesta en disco cuando se pide diagnóstico.

    Se activa con BUZON_GUARDAR=<carpeta>. Es la única forma de ver qué
    devuelve SUNAFIL de verdad: desde el sandbox del puente no se llega a la
    casilla, así que sin este volcado se acaba adivinando.
    """
    carpeta = os.environ.get("BUZON_GUARDAR") or "."
    os.makedirs(carpeta, exist_ok=True)
    ruta = os.path.join(carpeta, f"bandeja-{nombre}.html")
    with open(ruta, "w", encoding="utf-8", errors="ignore") as f:
        f.write(html)
    sys.stderr.write(f"[sol-bridge] guardado {ruta} ({len(html)} B)\n")


def _enviar_documento(sesion, url_bandeja):
    """Adaptador `enviar(url, campos, ajax=False)` para `carta_sunafil`.

    Recibe los campos como lista de tuplas, en orden, porque JSF es sensible
    al orden y a los duplicados. Devuelve `(estado, cabeceras, cuerpo)`.
    """

    def enviar(url, campos, ajax=False):
        cuerpo = urllib.parse.urlencode(campos, quote_via=urllib.parse.quote).encode()
        peticion = urllib.request.Request(url, data=cuerpo, method="POST")
        peticion.add_header(
            "Content-Type", "application/x-www-form-urlencoded; charset=UTF-8"
        )
        peticion.add_header("Referer", url_bandeja)
        peticion.add_header("Origin", URL_CASILLA_SUNAFIL)
        if ajax:
            peticion.add_header("Faces-Request", "partial/ajax")
            peticion.add_header("X-Requested-With", "XMLHttpRequest")
            peticion.add_header("Accept", "application/xml, text/xml, */*; q=0.01")
        try:
            with sesion.opener.open(peticion, timeout=sesion.timeout) as r:
                return r.status, dict(r.headers), r.read()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read()
        except (urllib.error.URLError, OSError) as e:
            raise SolError(
                "No se pudo descargar el documento de la casilla.", detalle=str(e)
            )

    return enviar


def _carta_con_campos_exactos(sesion, url_bandeja, html_bandeja, boton):
    """Descarga la carta con los campos que envía el navegador, no con los que
    el puente deducía del HTML.

    Los campos están copiados de la petición real (ver `carta_sunafil.py`).
    Devolver `None` significa «no se pudo por esta vía»: el llamador sigue con
    el camino genérico.
    """
    if carta_sunafil is None:
        return None

    viewstate = _viewstate_opcional(html_bandeja)
    if not viewstate:
        return None

    try:
        nombre, contenido = carta_sunafil.descargar_carta(
            _enviar_documento(sesion, url_bandeja), url_bandeja, viewstate, boton
        )
    except carta_sunafil.ErrorCarta as e:
        raise SolError(
            "No se pudo descargar la carta con los campos del navegador.",
            detalle=str(e),
        )
    return nombre, contenido


def _registro_de_fila(fila):
    """El código de registro de una fila («0000122241 - 2026 - ...»), o "".

    Es lo único que identifica la notificación: el índice es sólo su posición
    y cambia en cuanto SUNAFIL reordena la bandeja.
    """
    for celda in _celdas(fila):
        encontrado = REGISTRO_RE.search(celda)
        if encontrado:
            return encontrado.group(0).strip()
    return ""


def descargar_documento(
    ruc, usuario, clave, etiqueta, indice, tipo="carta", registro_esperado=None
):
    """Descarga un documento de una fila de la casilla de SUNAFIL.

    La casilla no expone una URL por documento: hay que abrir la bandeja,
    localizar el botón de esa fila y enviar el formulario con ese botón
    pulsado, que es lo que hace el navegador. `tipo` es `carta`,
    `constancia_deposito` o `constancia_acuse`.

    Repite el login entero porque la sesión no se guarda entre peticiones.
    Devuelve `(nombre_sugerido, bytes)`.

    Si se pasa `registro_esperado`, se compara con el registro de la fila
    ANTES de pulsar nada: si no coincide, se lanza `ConflictoRegistro` en vez
    de abrir el documento de otra notificación.
    """
    sesion = Sesion()
    code, state = autenticar_sunafil(ruc, usuario, clave, sesion=sesion)
    entrada = (
        f"{URL_CASILLA_SUNAFIL}{RUTA_REDIRECT_SUNAFIL}"
        f"?state={urllib.parse.quote(state)}&code={urllib.parse.quote(code)}"
    )
    sesion.get(entrada)

    url_bandeja, html = _abrir_bandeja(sesion, etiqueta)
    filas = [
        f for f in re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.S) if "<td" in f
    ]
    if indice < 0 or indice >= len(filas):
        raise SolError(
            f"La bandeja «{etiqueta}» tiene {len(filas)} filas y se pidió la "
            f"número {indice + 1}."
        )

    if registro_esperado:
        real = _registro_de_fila(filas[indice])
        if real != str(registro_esperado).strip():
            raise ConflictoRegistro(real, str(registro_esperado).strip())

    botones = _botones_de_fila(filas[indice])
    if not botones:
        raise SolError(
            "Esa fila no trae botones de documento. Puede que SUNAFIL haya "
            "cambiado la pantalla o que el documento no esté disponible.",
            detalle=re.sub(r"\s+", " ", _texto_celda(filas[indice]))[:300],
        )
    if tipo not in botones:
        raise SolError(
            f"Esa fila no ofrece «{tipo}». Ofrece: {', '.join(sorted(botones))}.",
            detalle="Botones con title: "
            + ", ".join(b["titulo"] for b in botones.values()),
        )

    # La carta tiene un flujo propio: su clic exige AJAX con unos filtros
    # concretos y su descarga usa un botón fijo. Los campos están copiados de
    # la petición real del navegador, porque deducirlos del HTML llevaba a un
    # 500 dentro de SUNAFIL. Las constancias no pasan por aquí.
    if tipo == "carta":
        resultado = _carta_con_campos_exactos(
            sesion, url_bandeja, html, botones["carta"]["boton"]
        )
        if resultado:
            return resultado

    # El formulario se envía con el botón pulsado: `name` del botón, sus campos
    # ocultos y el ViewState de la página de la bandeja (no el de inicio).
    datos = _campos_ocultos(html, "formactosAdministrativos")
    nombre_boton = botones[tipo]["boton"]
    datos[nombre_boton] = nombre_boton

    if botones[tipo].get("ajax"):
        datos.update(
            {
                "javax.faces.source": nombre_boton,
                "javax.faces.partial.execute": nombre_boton,
                "javax.faces.partial.render": "@all",
            }
        )
        _, cabeceras, contenido = sesion.post_ajax_crudo(url_bandeja, datos)
    else:
        _, cabeceras, contenido = sesion.post_crudo(url_bandeja, datos)

    if _es_pdf(contenido):
        nombre = _nombre_de_cabecera(cabeceras.get("Content-Disposition", ""))
        return nombre or f"{tipo}.pdf", contenido

    # No es un PDF. La carta abre un visor: SUNAFIL responde la vista del
    # documento y la descarga real es otra petición. Se busca esa dirección en
    # lo que llegó antes de rendirse.
    destino = _url_de_documento(contenido, cabeceras)
    if destino:
        url_doc = urllib.parse.urljoin(url_bandeja, destino)
        _, cab2, contenido2 = sesion.get_crudo(url_doc)
        if _es_pdf(contenido2):
            nombre = _nombre_de_cabecera(cab2.get("Content-Disposition", ""))
            return nombre or f"{tipo}.pdf", contenido2

    # La carta abre un diálogo (`formDescargaDoc`) y dentro está el botón
    # «Descargar» que hace la descarga real. Es un segundo POST.
    # El diálogo puede venir en la respuesta o estar ya en la página de la
    # bandeja (PrimeFaces lo deja montado y sólo lo muestra).
    origen_dialogo = contenido
    boton_dialogo = _boton_descarga_en_dialogo(contenido)
    if not boton_dialogo:
        origen_dialogo = html.encode("utf-8")
        boton_dialogo = _boton_descarga_en_dialogo(origen_dialogo)

    if boton_dialogo:
        # Se prueban todos los botones del diálogo, en orden de preferencia.
        # Cuál descarga de verdad no se puede saber leyendo el HTML: los dos
        # llevan el mismo icono y `monitorDownload`. Probarlos es más fiable
        # que elegir uno y confiar en que sea el bueno.
        for candidato in boton_dialogo:
            datos2 = _campos_ocultos(
                origen_dialogo.decode("utf-8", "ignore"), candidato["formulario"]
            )
            # El ViewState más reciente es el de la última respuesta: mandar el
            # de la página anterior hace que JSF rechace la acción.
            vs_reciente = _viewstate_opcional(contenido) or _viewstate_opcional(
                origen_dialogo
            )
            if vs_reciente:
                datos2["javax.faces.ViewState"] = vs_reciente
            datos2[candidato["boton"]] = candidato["boton"]
            # El POST va al `action` del propio formulario: JSF necesita la
            # vista del diálogo para ejecutar su acción.
            url_dialogo = urllib.parse.urljoin(
                url_bandeja, candidato["accion"] or url_bandeja
            )
            _, cab3, contenido3 = sesion.post_crudo(url_dialogo, datos2)
            if _es_pdf(contenido3):
                nombre = _nombre_de_cabecera(cab3.get("Content-Disposition", ""))
                return nombre or f"{tipo}.pdf", contenido3
            # Se guarda la respuesta que mejor explica el fallo: un 500 o la
            # página del visor dicen más que un re-render de la bandeja.
            if _es_mas_util(contenido3, contenido):
                contenido, cabeceras = contenido3, cab3

    # Se guarda siempre la respuesta cruda junto al script: es lo único que
    # dice qué contestó SUNAFIL cuando no fue el archivo, y sin ella sólo se
    # puede adivinar. Va al directorio de trabajo, que es donde se ejecuta.
    ruta = os.path.abspath(f"respuesta-{tipo}.html")
    try:
        with open(ruta, "wb") as f:
            f.write(contenido)
    except OSError:
        ruta = ""

    texto_respuesta = contenido.decode("utf-8", "ignore")
    botones_vistos = _candidatos_de_dialogo(texto_respuesta)

    raise SolError(
        f"SUNAFIL respondió algo que no es un PDF ({len(contenido)} bytes, "
        f"{cabeceras.get('Content-Type') or 'sin tipo'}). Suele pasar con la "
        "carta, que se abre en un visor antes de descargarse."
        + (f"\n\nBotones que trae la respuesta: {', '.join(botones_vistos)}"
           if botones_vistos else "")
        + (f"\n\nLa respuesta se guardó en:\n  {ruta}\n"
           f"Analízala con:  python mirar_respuesta.py \"{os.path.basename(ruta)}\"" if ruta else ""),
        detalle=_resumen_pagina(texto_respuesta),
    )


def _es_pdf(contenido):
    """True si los bytes son un PDF de verdad, no una página disfrazada."""
    return contenido[:5].lstrip() == b"%PDF-"


# Marcas de la vista del documento. Si la respuesta las trae, la descarga es
# otra petición y hay que sacar su dirección de aquí.
MARCAS_VISOR = ("visor", "embed", "iframe", "pdfjs", "reportviewer", "jasper")


def _viewstate_opcional(texto):
    """`javax.faces.ViewState` de un HTML o de un XML de actualización parcial.

    Devuelve "" si no lo encuentra: aquí se usa para refrescar el estado, y no
    tenerlo no es un error. La versión estricta es `_viewstate`.
    """
    if isinstance(texto, bytes):
        texto = texto.decode("utf-8", "ignore")
    m = re.search(
        r"""<input[^>]*\bname\s*=\s*["']javax\.faces\.ViewState["'][^>]*\bvalue\s*=\s*["']([^"']*)["']""",
        texto,
        re.I,
    )
    if m:
        return unescape(m.group(1))
    # En un `<partial-response>` viene como <update id="javax.faces.ViewState">.
    m = re.search(
        r"""<update[^>]*\bid\s*=\s*["'][^"']*ViewState["'][^>]*>(.*?)</update>""",
        texto,
        re.S | re.I,
    )
    return unescape(m.group(1).strip()) if m else ""


def _boton_descarga_en_dialogo(contenido):
    """Botón «Descargar» del diálogo que abre la carta.

    Al pulsar el icono de PDF, SUNAFIL no devuelve el archivo: devuelve un
    diálogo (`formDescargaDoc`) con un aviso y un botón «Descargar» que sí
    descarga. Se busca ese botón por el formulario y por su texto, y se
    descarta el que no se puede pulsar.
    """
    texto = contenido.decode("utf-8", "ignore") if isinstance(contenido, bytes) else contenido
    candidatos = []

    for formulario in re.findall(r"""<form[^>]*\bid\s*=\s*["']([^"']*DescargaDoc[^"']*)["']""", texto, re.I):
        bloque = None
        for trozo in re.split(r"(?=<form\b)", texto)[1:]:
            ident = re.search(r"""<form[^>]*\bid\s*=\s*["']([^"']+)["']""", trozo)
            if ident and ident.group(1) == formulario:
                bloque = trozo.split("</form>")[0]
                break
        if not bloque:
            continue

        for boton in re.findall(r"<button\b.*?</button>", bloque, re.S | re.I):
            apertura = boton.split(">", 1)[0]
            nombre = re.search(r"""\bname\s*=\s*["']([^"']+)["']""", apertura)
            if not nombre or re.search(r"\bdisabled\b", apertura, re.I):
                continue
            etiqueta = _sin_acentos(_texto_celda(boton))
            ident = unescape(nombre.group(1))
            accion = re.search(r"""<form[^>]*\baction\s*=\s*["']([^"']+)["']""", bloque, re.I)
            candidatos.append(
                {
                    "formulario": formulario,
                    "boton": ident,
                    "texto": etiqueta,
                    "accion": unescape(accion.group(1)) if accion else "",
                    # `PrimeFaces.monitorDownload` es una descarga normal: el
                    # navegador envía el formulario y recibe el archivo.
                    "ajax": False,
                }
            )

    return _mejor_descarga(candidatos)


def _mejor_descarga(candidatos):
    """De los botones del diálogo, el que descarga de verdad.

    El diálogo trae varios botones con el mismo icono de PDF: uno «Ver
    Documento» y otro «Descargar». Pulsar el de ver abre el visor
    `/jsfDocs/modulos/...` y SUNAFIL responde 500, así que se descarta por su
    nombre y por su texto antes de elegir.
    """
    # Se decide por el texto visible del botón, nunca por su `name`: todos
    # cuelgan del formulario `formDescargaDoc`, así que el nombre siempre
    # contiene «descarg» y no distingue «Descargar» de «Cerrar».
    ordenados = []
    for c in candidatos:
        texto = c["texto"]
        if "cerrar" in texto or "cancelar" in texto or "volver" in texto:
            continue
        if "descargar" in texto:
            ordenados.append((0, c))
        elif "ver documento" in texto:
            ordenados.append((2, c))
        elif not texto:
            # Sin texto: el icono de PDF es la única pista que queda.
            ordenados.append((1, c))
    ordenados.sort(key=lambda par: par[0])
    return [c for _, c in ordenados] or None


def _es_mas_util(nuevo, actual):
    """True si `nuevo` describe mejor el fallo que `actual`.

    Un error del servidor o la página del visor explican qué pasó; un
    re-render de la bandeja no dice nada.
    """

    def valor(datos):
        if not isinstance(datos, bytes) or not datos:
            return 0
        texto = datos.decode("utf-8", "ignore")
        if re.search(r"Error interno|Error Code: 500|Algo sali[oó] mal", texto, re.I):
            return 3
        if "jsfDocs" in texto or "visor" in texto.lower():
            return 2
        return 1

    return valor(nuevo) > valor(actual)


def _candidatos_de_dialogo(contenido):
    """Sólo los nombres de los botones del diálogo, para el mensaje de error."""
    if isinstance(contenido, bytes):
        contenido = contenido.decode("utf-8", "ignore")
    nombres = re.findall(r"""<button[^>]*\bname\s*=\s*["']([^"']+)["']""", contenido, re.I)
    return nombres


def _url_de_documento(contenido, cabeceras):
    """Dirección del archivo real dentro de una vista de visor.

    Se buscan primero los enlaces con pinta de descarga y, si no hay, un
    `iframe`/`embed`/`object` con un `src` que no sea una página del menú.
    Devuelve la ruta o `None`; nunca inventa una.
    """
    texto = contenido.decode("utf-8", "ignore")

    for href in re.findall(r"""\b(?:src|href|data)\s*=\s*["']([^"']+)["']""", texto, re.I):
        if not href or href.startswith("#") or href.lower().startswith("javascript:"):
            continue
        if re.search(r"\.pdf(\?|$)", href, re.I):
            return unescape(href)

    for etiqueta in ("iframe", "embed", "object"):
        for bloque in re.findall(rf"<{etiqueta}\b[^>]*>", texto, re.I):
            src = re.search(r"""\b(?:src|data)\s*=\s*["']([^"']+)["']""", bloque, re.I)
            if not src:
                continue
            valor = unescape(src.group(1))
            if any(marca in valor.lower() for marca in MARCAS_VISOR):
                return valor
            if "servlet" in valor.lower() or "descarg" in valor.lower():
                return valor

    # Algunas veces el nombre del archivo llega sólo en la cabecera y el visor
    # pide el documento por un servlet con ese nombre.
    nombre = _nombre_de_cabecera(cabeceras.get("Content-Disposition", ""))
    if nombre and nombre.lower().endswith(".pdf"):
        return nombre
    return None


def _nombre_de_cabecera(valor):
    """Nombre de archivo de un `Content-Disposition`, si lo trae."""
    m = re.search(r"filename\*?=(?:UTF-8''|\")?([^\";]+)", valor or "", re.I)
    if not m:
        return ""
    return os.path.basename(unescape(m.group(1).strip().strip('"')))


def _resumen_pagina(html):
    """Texto de la página, corto, para saber qué devolvió SUNAFIL."""
    texto = re.sub(r"<script.*?</script>", " ", html, flags=re.S | re.I)
    texto = re.sub(r"<style.*?</style>", " ", texto, flags=re.S | re.I)
    texto = re.sub(r"<[^>]+>", " ", texto)
    return re.sub(r"\s+", " ", unescape(texto)).strip()[:300]


def _pista_de_login(html_bienvenida):
    """Dice si la casilla quedó autenticada tras el canje.

    Si la bienvenida sigue siendo un formulario de login, el problema es la
    sesión; si trae el menú, el problema es la bandeja.
    """
    texto = _resumen_pagina(html_bienvenida)
    parece_login = re.search(
        r"iniciar sesi[oó]n|j_security_check|usuario|contrase[nñ]a", texto, re.I
    )
    if parece_login:
        return (
            "Tras canjear el código, la casilla devolvió el formulario de "
            f"acceso, así que la sesión no quedó abierta. Página: {texto}"
        )
    return f"Página tras el canje: {texto}"


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
                    "huella": HUELLA,
                    "arrancado": ARRANQUE,
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
                    "huella": HUELLA,
                    "arrancado": ARRANQUE,
                    "sunat_alcanzable": False,
                    "error": e.mensaje,
                    "detalle": e.detalle,
                },
            )

    def do_POST(self):  # noqa: N802 (nombre impuesto por BaseHTTPRequestHandler)
        # Sin esto, un POST a cualquier ruta entraba y fallaba por el JSON, y
        # el usuario veía «cuerpo inválido» cuando el problema era la URL.
        if not self.path.startswith((RUTA_BUZON, RUTA_DOCUMENTO)):
            self._responder(
                404,
                {
                    "error": f"Ruta no encontrada: {self.path}",
                    "detalle": (
                        f"El puente lee buzones en POST {RUTA_BUZON} y descarga "
                        f"documentos en POST {RUTA_DOCUMENTO}."
                    ),
                },
            )
            return

        if TOKEN and self.headers.get("Authorization") != f"Bearer {TOKEN}":
            self._responder(401, {"error": "Token del puente inválido o ausente."})
            return

        try:
            largo = int(self.headers.get("Content-Length") or 0)
            crudo = self.rfile.read(largo) or b"{}"
            cuerpo = json.loads(crudo)
        except (ValueError, json.JSONDecodeError):
            self._responder(
                400,
                {
                    "error": "El cuerpo no es JSON válido.",
                    "detalle": (
                        "Recibido: " + crudo[:200].decode("utf-8", "replace")
                        if "crudo" in locals()
                        else "No llegó cuerpo."
                    ),
                },
            )
            return

        if not isinstance(cuerpo, dict):
            self._responder(
                400, {"error": "El cuerpo tiene que ser un objeto JSON, no una lista."}
            )
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

        if self.path.startswith(RUTA_DOCUMENTO):
            self._descargar(cuerpo, ruc, usuario, clave)
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

    def _descargar(self, cuerpo, ruc, usuario, clave):
        """Devuelve el documento como binario, no como JSON."""
        bandeja = str(cuerpo.get("bandeja") or "acciones_previas")
        etiquetas = dict(BANDEJAS_SUNAFIL)
        if bandeja not in etiquetas:
            self._responder(
                400,
                {"error": f"Bandeja desconocida: {bandeja}", "detalle": list(etiquetas)},
            )
            return
        try:
            indice = int(cuerpo.get("indice", 0))
        except (TypeError, ValueError):
            self._responder(400, {"error": "El índice de fila tiene que ser un número."})
            return

        tipo = str(cuerpo.get("tipo") or "carta")
        registro_esperado = cuerpo.get("registro_esperado") or None
        try:
            nombre, contenido = descargar_documento(
                ruc,
                usuario,
                clave,
                etiquetas[bandeja],
                indice,
                tipo,
                registro_esperado=registro_esperado,
            )
        except ConflictoRegistro as e:
            self._responder(409, {"error": e.mensaje, "detalle": e.detalle})
            return
        except SolError as e:
            self._responder(502, {"error": e.mensaje, "detalle": e.detalle})
            return

        self.send_response(200)
        self.send_header("Content-Type", "application/pdf")
        self.send_header(
            "Content-Disposition", f'attachment; filename="{nombre}"'
        )
        self.send_header("Content-Length", str(len(contenido)))
        self.end_headers()
        self.wfile.write(contenido)

    def log_message(self, formato, *args):
        # Sin volcado de cabeceras ni de credenciales en el log.
        sys.stderr.write("[sol-bridge] %s\n" % (formato % args))


def main(argv=None):
    """Levanta el puente. El puerto se puede pasar como argumento.

    El argumento manda sobre `BUZON_BRIDGE_PORT`: antes se ignoraba en
    silencio, así que `python sol_buzon.py 8080` escuchaba en otro puerto.
    """
    argv = sys.argv[1:] if argv is None else argv
    puerto = PUERTO

    if argv:
        if argv[0] in ("-h", "--help"):
            print(
                "Uso: python sol_buzon.py [puerto]\n\n"
                "  puerto   puerto donde escuchar (por defecto "
                f"{PUERTO}, o BUZON_BRIDGE_PORT)\n\n"
                "Variables: BUZON_BRIDGE_PORT, BUZON_BRIDGE_HOST, "
                "BUZON_BRIDGE_TOKEN"
            )
            return 0
        try:
            puerto = int(argv[0])
        except ValueError:
            print(f"El puerto tiene que ser un número, no «{argv[0]}».")
            return 2
        if not 1 <= puerto <= 65535:
            print(f"El puerto {puerto} está fuera del rango 1-65535.")
            return 2

    servidor = ThreadingHTTPServer((HOST, puerto), Manejador)
    print(
        f"[sol-bridge] escuchando en {HOST}:{puerto}/buzon "
        f"(token {'sí' if TOKEN else 'no'})",
        flush=True,
    )
    print(f"[sol-bridge] huella {HUELLA} · arrancado {ARRANQUE}", flush=True)
    try:
        servidor.serve_forever()
    except KeyboardInterrupt:
        print("\n[sol-bridge] cerrando.")
    finally:
        servidor.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
