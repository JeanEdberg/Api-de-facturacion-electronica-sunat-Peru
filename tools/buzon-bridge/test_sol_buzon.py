"""Tests del puente SOL: autenticación de SUNAFIL y enrutado por entidad.

Se prueba la lógica sin red: el SSO y la casilla se simulan con dobles que
replican lo que se observó en el sistema real (302 con `code`, 302 a
`/oauth2/error`, host que no acepta conexión).
"""

import io
import json
import os
import unittest
import urllib.parse
from unittest import mock

import sol_buzon as puente


class AutenticarSunafilTest(unittest.TestCase):
    def setUp(self):
        # La detección TLS abre un socket real; en los tests se da por resuelta.
        parche = mock.patch.object(
            puente, "detectar_contexto_tls", return_value=(None, "por defecto")
        )
        parche.start()
        self.addCleanup(parche.stop)

    def _respuesta(self, url_login, destino):
        """Simula `Sesion.get` y `_leer_redireccion` del flujo real."""
        get = mock.Mock(return_value=(url_login, "<html>login</html>"))
        redir = mock.Mock(return_value=(302, destino))
        return get, redir

    def test_entra_a_la_casilla_antes_de_ir_al_sso(self):
        """El JSESSIONID de la casilla se necesita para canjear el `code`."""
        login = (
            f"{puente.URL_SSO}/v1/clientessol/{puente.SUNAFIL_CLIENT_ID}"
            f"/oauth2/login?originalUrl={puente.URL_CASILLA_SUNAFIL}"
            f"{puente.RUTA_REDIRECT_SUNAFIL}&state=abc"
        )
        destino = (
            f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_REDIRECT_SUNAFIL}"
            "?state=abc&code=CODIGO123"
        )
        get, redir = self._respuesta(login, destino)

        with mock.patch.object(puente.Sesion, "get", get), mock.patch.object(
            puente, "_leer_redireccion", redir
        ):
            puente.autenticar_sunafil("20614684772", "MODDATOS", "clave")

        primera, segunda = get.call_args_list[0][0][0], get.call_args_list[1][0][0]
        self.assertEqual(primera, f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_ENTRADA_SUNAFIL}")
        self.assertIn("/oauth2/authen", segunda)
        self.assertIn(f"client_id={puente.SUNAFIL_CLIENT_ID}", segunda)
        self.assertIn("response_type=code", segunda)
        self.assertIn(urllib.parse.quote(puente.RUTA_REDIRECT_SUNAFIL, safe=""), segunda)

    def test_envia_el_formulario_del_sso_con_el_client_id_de_sunafil(self):
        login = (
            f"{puente.URL_SSO}/v1/clientessol/{puente.SUNAFIL_CLIENT_ID}"
            f"/oauth2/login?originalUrl={puente.URL_CASILLA_SUNAFIL}"
            f"{puente.RUTA_REDIRECT_SUNAFIL}&state=abc"
        )
        destino = (
            f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_REDIRECT_SUNAFIL}"
            "?state=abc&code=CODIGO123"
        )
        get, redir = self._respuesta(login, destino)

        with mock.patch.object(puente.Sesion, "get", get), mock.patch.object(
            puente, "_leer_redireccion", redir
        ):
            code, state = puente.autenticar_sunafil("20614684772", "MODDATOS", "clave")

        self.assertEqual(code, "CODIGO123")
        self.assertEqual(state, "abc")

        url_pedida = get.call_args_list[1][0][0]
        self.assertIn(puente.SUNAFIL_CLIENT_ID, url_pedida)
        self.assertIn(
            urllib.parse.quote(puente.URL_CASILLA_SUNAFIL, safe=""), url_pedida
        )

        datos = redir.call_args[0][2]
        self.assertEqual(datos["tipo"], "2")
        self.assertEqual(datos["custom_ruc"], "20614684772")
        self.assertEqual(datos["j_username"], "MODDATOS")
        self.assertEqual(datos["j_password"], "clave")
        self.assertEqual(datos["state"], "abc")

    def test_credenciales_rechazadas_si_el_sso_redirige_a_error(self):
        login = (
            f"{puente.URL_SSO}/v1/clientessol/{puente.SUNAFIL_CLIENT_ID}"
            f"/oauth2/login?originalUrl={puente.URL_CASILLA_SUNAFIL}"
            f"{puente.RUTA_REDIRECT_SUNAFIL}&state=abc"
        )
        destino = f"{puente.URL_SSO}/v1/clientessol/x/oauth2/error?state=abc"
        get, redir = self._respuesta(login, destino)

        with mock.patch.object(puente.Sesion, "get", get), mock.patch.object(
            puente, "_leer_redireccion", redir
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.autenticar_sunafil("20614684772", "MALO", "mala")

        self.assertIn("rechazó las credenciales", ctx.exception.mensaje)

    def test_sin_state_avisa_de_que_el_flujo_cambio(self):
        login = f"{puente.URL_SSO}/v1/clientessol/{puente.SUNAFIL_CLIENT_ID}/oauth2/login"
        get, redir = self._respuesta(login, "")

        with mock.patch.object(puente.Sesion, "get", get), mock.patch.object(
            puente, "_leer_redireccion", redir
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.autenticar_sunafil("20614684772", "MODDATOS", "clave")

        self.assertIn("cambió", ctx.exception.mensaje)

    def test_sesion_aceptada_sin_code_es_un_error_explicito(self):
        login = (
            f"{puente.URL_SSO}/v1/clientessol/{puente.SUNAFIL_CLIENT_ID}"
            f"/oauth2/login?originalUrl={puente.URL_CASILLA_SUNAFIL}"
            f"{puente.RUTA_REDIRECT_SUNAFIL}&state=abc"
        )
        destino = f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_REDIRECT_SUNAFIL}?state=abc"
        get, redir = self._respuesta(login, destino)

        with mock.patch.object(puente.Sesion, "get", get), mock.patch.object(
            puente, "_leer_redireccion", redir
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.autenticar_sunafil("20614684772", "MODDATOS", "clave")

        self.assertIn("código", ctx.exception.mensaje)


class LeerBuzonSunafilTest(unittest.TestCase):
    def test_si_la_casilla_no_responde_lo_dice_en_vez_de_devolver_vacio(self):
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(False, "sin conexión")
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        mensaje = ctx.exception.mensaje
        self.assertIn("no responde", mensaje)
        self.assertIn("Clave SOL es válida", mensaje)

    def test_si_la_casilla_responde_pero_falta_la_bandeja_lo_avisa(self):
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertIn("no está implementada", ctx.exception.mensaje)


class EnrutadoTest(unittest.TestCase):
    """Ejercita `do_POST` de verdad, con los lectores simulados."""

    def _post(self, entidad):
        manejador = puente.Manejador.__new__(puente.Manejador)
        manejador._responder = mock.Mock()
        manejador.headers = {"Content-Length": "0"}
        cuerpo = json.dumps(
            {
                "ruc": "20614684772",
                "usuario": "MODDATOS",
                "clave": "clave",
                "entidad": entidad,
            }
        ).encode()
        manejador.rfile = io.BytesIO(cuerpo)
        manejador.headers = {"Content-Length": str(len(cuerpo))}
        return manejador

    def test_entidad_sunat_usa_el_lector_de_sunat(self):
        manejador = self._post("SUNAT")
        with mock.patch.object(
            puente, "leer_buzon", return_value=[{"id": 1}]
        ) as leer, mock.patch.object(puente, "leer_buzon_sunafil") as leer_sunafil:
            manejador.do_POST()

        leer.assert_called_once()
        leer_sunafil.assert_not_called()
        manejador._responder.assert_called_once_with(200, {"data": [{"id": 1}]})

    def test_entidad_sunafil_usa_el_lector_de_sunafil(self):
        manejador = self._post("SUNAFIL")
        with mock.patch.object(puente, "leer_buzon") as leer, mock.patch.object(
            puente, "leer_buzon_sunafil", return_value=[{"id": 2}]
        ) as leer_sunafil:
            manejador.do_POST()

        leer_sunafil.assert_called_once()
        leer.assert_not_called()
        manejador._responder.assert_called_once_with(200, {"data": [{"id": 2}]})

    def test_entidad_desconocida_no_devuelve_datos_de_otra(self):
        manejador = self._post("SUNARP")
        with mock.patch.object(puente, "leer_buzon") as leer, mock.patch.object(
            puente, "leer_buzon_sunafil"
        ) as leer_sunafil:
            manejador.do_POST()

        leer.assert_not_called()
        leer_sunafil.assert_not_called()
        codigo, cuerpo = manejador._responder.call_args[0]
        self.assertEqual(codigo, 502)
        self.assertIn("SUNARP", cuerpo["error"])


class GrabadorTest(unittest.TestCase):
    """El grabador no debe consumir el cuerpo que necesita el flujo."""

    def test_guarda_la_respuesta_y_la_deja_legible_para_el_llamador(self):
        import tempfile

        import capturar_sunafil as captura

        carpeta = tempfile.mkdtemp()
        grabador = captura.Grabador(carpeta)

        respuesta = mock.Mock()
        respuesta.geturl.return_value = "https://ejemplo.gob.pe/x"
        respuesta.status = 200
        respuesta.code = 200
        respuesta.headers = {"Content-Type": "application/json; charset=utf-8"}
        respuesta.read.return_value = b'{"rows": [1, 2]}'

        devuelta = grabador.http_response(mock.Mock(), respuesta)

        self.assertIs(devuelta, respuesta)
        self.assertEqual(len(grabador.lineas), 1)
        archivos = os.listdir(carpeta)
        self.assertEqual(len(archivos), 1)
        self.assertTrue(archivos[0].endswith(".json"))
        with open(os.path.join(carpeta, archivos[0]), "rb") as f:
            self.assertEqual(f.read(), b'{"rows": [1, 2]}')

    def _respuesta(self, url):
        respuesta = mock.Mock()
        respuesta.geturl.return_value = url
        respuesta.status = 302
        respuesta.code = 302
        respuesta.headers = {"Content-Type": "text/html"}
        respuesta.read.return_value = b""
        return respuesta

    def test_el_jwt_no_acaba_en_el_nombre_del_archivo(self):
        """Compartir la carpeta no debe filtrar el `code` del SSO."""
        import tempfile

        import capturar_sunafil as captura

        carpeta = tempfile.mkdtemp()
        grabador = captura.Grabador(carpeta)
        url = (
            "https://casillaelectronica.sunafil.gob.pe/si.inbox/Login/Empresa"
            "?state=s&code=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.SECRETO"
        )

        grabador.http_response(mock.Mock(), self._respuesta(url))

        archivo = os.listdir(carpeta)[0]
        self.assertNotIn("eyJ0eXAi", archivo)
        self.assertNotIn("SECRETO", archivo)

    def test_el_indice_tapa_el_code_y_el_jsessionid(self):
        import tempfile

        import capturar_sunafil as captura

        carpeta = tempfile.mkdtemp()
        grabador = captura.Grabador(carpeta)
        url = "https://x.gob.pe/si.inbox/a?code=eyJhbGciOi.SECRETO&state=s"

        grabador.http_response(mock.Mock(), self._respuesta(url))

        linea = grabador.lineas[0]
        self.assertNotIn("SECRETO", linea)
        self.assertIn("code=<oculto>", linea)
        self.assertIn("state=s", linea)


class DetectarContextoTlsTest(unittest.TestCase):
    """El Tomcat de SUNAFIL falla a SECLEVEL=2; hay que bajar de nivel."""

    def _simular(self, resultados):
        """`resultados`: lista de excepciones o None por cada estrategia."""
        intentos = []

        def create_connection(*a, **k):
            return mock.MagicMock()

        def wrap(*args, **kwargs):
            resultado = resultados[len(intentos)]
            intentos.append(resultado)
            if resultado is not None:
                raise resultado
            tls = mock.MagicMock()
            tls.__enter__ = mock.Mock(return_value=tls)
            tls.__exit__ = mock.Mock(return_value=False)
            return tls

        return create_connection, wrap, intentos

    def test_usa_la_primera_configuracion_que_negocia(self):
        import ssl as _ssl

        fallo = _ssl.SSLError(1, "HANDSHAKE_FAILURE")
        create, wrap, intentos = self._simular([fallo, None])

        with mock.patch.object(puente.socket, "create_connection", create), \
             mock.patch.object(puente.ssl.SSLContext, "wrap_socket", wrap):
            contexto, nombre = puente.detectar_contexto_tls("casilla.test")

        self.assertEqual(nombre, "SECLEVEL=1")
        self.assertEqual(len(intentos), 2)

    def test_usa_el_primero_cuando_negocia_a_la_primera(self):
        create, wrap, intentos = self._simular([None])

        with mock.patch.object(puente.socket, "create_connection", create), \
             mock.patch.object(puente.ssl.SSLContext, "wrap_socket", wrap):
            _, nombre = puente.detectar_contexto_tls("casilla.test")

        self.assertEqual(nombre, "por defecto")
        self.assertEqual(len(intentos), 1)

    def test_falla_explicando_si_ninguna_negocia(self):
        import ssl as _ssl

        fallo = _ssl.SSLError(1, "HANDSHAKE_FAILURE")
        create, wrap, _ = self._simular([fallo] * len(puente.ESTRATEGIAS_TLS))

        with mock.patch.object(puente.socket, "create_connection", create), \
             mock.patch.object(puente.ssl.SSLContext, "wrap_socket", wrap):
            with self.assertRaises(puente.SolError) as ctx:
                puente.detectar_contexto_tls("casilla.test")

        self.assertIn("TLS", ctx.exception.mensaje)
        self.assertIn("SECLEVEL", ctx.exception.detalle)

    def test_distingue_no_llegar_de_no_negociar(self):
        def create(*a, **k):
            raise OSError("timed out")

        with mock.patch.object(puente.socket, "create_connection", create):
            with self.assertRaises(puente.SolError) as ctx:
                puente.detectar_contexto_tls("casilla.test")

        self.assertIn("No se pudo conectar", ctx.exception.mensaje)
        self.assertIn("timed out", ctx.exception.detalle)


class SesionTlsTest(unittest.TestCase):
    """urllib se queda con el primer HTTPSHandler: hay que reconstruir el opener."""

    def test_el_contexto_personalizado_queda_activo(self):
        contexto = puente._contexto_tls("DEFAULT@SECLEVEL=1")
        sesion = puente.Sesion(tls=contexto)

        https = sesion.opener.handle_open["https"]
        self.assertEqual(len(https), 1)
        self.assertIs(https[0]._context, contexto)

    def test_usar_tls_reemplaza_el_contexto_sin_perder_cookies(self):
        import http.cookiejar

        sesion = puente.Sesion()
        sesion.cookies.set_cookie(
            http.cookiejar.Cookie(
                0, "JSESSIONID", "abc", None, False, "casilla.test", False, False,
                "/", True, False, None, False, None, None, {},
            )
        )
        contexto = puente._contexto_tls("DEFAULT@SECLEVEL=0")
        sesion.usar_tls(contexto)

        https = sesion.opener.handle_open["https"]
        self.assertEqual(len(https), 1)
        self.assertIs(https[0]._context, contexto)
        self.assertEqual(len(sesion.cookies), 1)

    def test_una_sesion_normal_no_toca_el_tls_de_sunat(self):
        self.assertIsNone(puente.Sesion().tls)

    def test_el_grabador_sobrevive_al_cambio_de_tls(self):
        """Sin esto la captura saldría vacía: usar_tls reconstruye el opener."""
        import capturar_sunafil as captura

        grabador = captura.Grabador("/tmp")
        sesion = puente.Sesion()
        sesion.agregar_handler(grabador)

        sesion.usar_tls(puente._contexto_tls("DEFAULT@SECLEVEL=1"))

        self.assertTrue(any(h is grabador for h in sesion.opener.handlers))


class SondearSunafilTest(unittest.TestCase):
    """`_sondear_sunafil` devuelve (alcanzable, tls); una tupla siempre es truthy."""

    def test_informa_no_alcanzable_cuando_no_hay_conexion(self):
        with mock.patch.object(
            puente.socket, "create_connection", side_effect=OSError("timed out")
        ):
            alcanzable, detalle = puente._sondear_sunafil()

        self.assertFalse(alcanzable)
        self.assertIn("sin conexión", detalle)

    def test_informa_alcanzable_y_el_tls_negociado(self):
        conexion = mock.MagicMock()
        conexion.__enter__ = mock.Mock(return_value=conexion)
        conexion.__exit__ = mock.Mock(return_value=False)

        with mock.patch.object(
            puente.socket, "create_connection", return_value=conexion
        ), mock.patch.object(
            puente, "detectar_contexto_tls", return_value=(None, "SECLEVEL=1")
        ):
            alcanzable, detalle = puente._sondear_sunafil()

        self.assertTrue(alcanzable)
        self.assertEqual(detalle, "SECLEVEL=1")

    def test_leer_buzon_sunafil_falla_si_la_casilla_no_responde(self):
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "s")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(False, "sin conexión")
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.leer_buzon_sunafil("20614684772", "APIINMOA", "clave")

        self.assertIn("no responde", ctx.exception.mensaje)
        self.assertEqual(ctx.exception.detalle, "sin conexión")


if __name__ == "__main__":
    unittest.main()
