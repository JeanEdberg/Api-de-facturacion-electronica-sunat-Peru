"""Tests del puente SOL: autenticación de SUNAFIL y enrutado por entidad.

Se prueba la lógica sin red: el SSO y la casilla se simulan con dobles que
replican lo que se observó en el sistema real (302 con `code`, 302 a
`/oauth2/error`, host que no acepta conexión).
"""

import contextlib
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


class NotificacionesTest(unittest.TestCase):
    """La bandeja de notificaciones se nombra con sus propios encabezados."""

    TABLA = """
    <table id="formNotificacion:dtNotificaciones">
    <thead><tr><th>N&uacute;mero</th><th>Tipo</th><th>Fecha de Notificaci&oacute;n</th></tr></thead>
    <tbody>
    <tr><td>0000122241</td><td>CARTA INDUCTIVA</td><td>29/10/2026</td></tr>
    <tr><td>0000122242</td><td>REQUERIMIENTO</td><td>30/10/2026</td></tr>
    </tbody></table>
    """

    def test_lee_las_filas_con_los_nombres_de_sus_columnas(self):
        filas = puente.extraer_notificaciones(self.TABLA)

        self.assertEqual(len(filas), 2)
        self.assertEqual(filas[0]["numero"], "0000122241")
        self.assertEqual(filas[0]["tipo"], "CARTA INDUCTIVA")
        self.assertEqual(filas[1]["fecha de notificacion"], "30/10/2026")

    def test_una_bandeja_sin_tabla_no_inventa_filas(self):
        self.assertEqual(puente.extraer_notificaciones("<html></html>"), [])

    def test_sin_encabezados_usa_columnas_numeradas(self):
        html = """<table id="formNotificacion:dtNotificaciones">
        <tr><td>uno</td><td>dos</td></tr></table>"""

        filas = puente.extraer_notificaciones(html)

        self.assertEqual(filas[0]["columna_1"], "uno")
        self.assertEqual(filas[0]["columna_2"], "dos")

    def test_encuentra_la_tabla_si_el_id_esta_en_el_div_que_la_envuelve(self):
        """PrimeFaces pone el id en el <div> y deja la <table> sin id."""
        html = """
        <div id="formNotificacion:dtNotificaciones" class="ui-datatable">
          <div class="ui-datatable-header">Notificaciones</div>
          <table role="grid"><thead><tr><th>N&uacute;mero</th></tr></thead>
          <tbody><tr><td>0000122241</td></tr></tbody></table>
        </div>
        """

        filas = puente.extraer_notificaciones(html)

        self.assertEqual(len(filas), 1)
        self.assertEqual(filas[0]["numero"], "0000122241")

    def test_una_tabla_vacia_se_reconoce_y_no_se_confunde_con_un_fallo(self):
        """Bandeja vacía y bandeja ilegible no son lo mismo."""
        html = """
        <table id="formNotificacion:dtNotificaciones">
        <thead><tr><th>N&uacute;mero</th></tr></thead><tbody></tbody></table>
        """

        self.assertIsNotNone(puente._tabla_por_id(html, "dtNotificaciones"))
        self.assertEqual(puente.extraer_notificaciones(html), [])

    def test_sin_la_tabla_no_se_reconoce_nada(self):
        self.assertIsNone(puente._tabla_por_id("<html></html>", "dtNotificaciones"))

    def test_distingue_el_inicio_de_una_bandeja_abierta(self):
        self.assertTrue(puente._es_inicio("<div id='formInicio_parque'></div>"))
        self.assertFalse(puente._es_inicio(self.TABLA))


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

    def test_si_la_casilla_responde_pero_devuelve_el_inicio_lo_avisa(self):
        """Una lista vacía y una bandeja que no se abrió no son lo mismo."""
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", return_value="<div id='formInicio_parque'></div>"
        ):
            with self.assertRaises(puente.SolError) as ctx:
                puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertIn("página de inicio", ctx.exception.detalle)

    def test_una_bandeja_vacia_devuelve_lista_vacia_sin_error(self):
        """Sin notificaciones es un resultado válido, no un fallo."""
        html = """
        <table id="formNotificacion:dtNotificaciones">
        <thead><tr><th>N&uacute;mero</th></tr></thead><tbody></tbody></table>
        """
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", return_value=html
        ):
            datos = puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertEqual(datos["notificaciones"], [])
        self.assertEqual(datos["acciones_previas"], [])

    def test_si_la_bandeja_no_trae_su_tabla_lo_avisa(self):
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", return_value="<html><body>otra cosa</body></html>"
        ):
            datos = puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertIn("notificaciones", datos["errores"])
        self.assertIn("tabla", datos["errores"]["notificaciones"])

    def test_usa_una_sola_sesion_para_login_y_bandejas(self):
        """El JSESSIONID del login es lo que autoriza el POST de la bandeja."""
        sesiones = []
        original = puente.Sesion

        def fabricar():
            sesion = original()
            sesiones.append(sesion)
            return sesion

        vista = []

        def autenticar(ruc, usuario, clave, sesion=None):
            vista.append(sesion)
            return ("code", "state")

        with mock.patch.object(puente, "Sesion", side_effect=fabricar), mock.patch.object(
            puente, "autenticar_sunafil", side_effect=autenticar
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", return_value="<html></html>"
        ):
            puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertEqual(len(sesiones), 1, "se creó más de una sesión")
        self.assertIs(vista[0], sesiones[0], "el login no recibió esa sesión")

    def test_abre_cada_bandeja_con_su_propia_etiqueta_del_menu(self):
        """Leer una bandeja y parsear otra daba siempre cero acciones previas."""
        vacia = """
        <table id="formNotificacion:dtNotificaciones">
        <thead><tr><th>N&uacute;mero</th></tr></thead><tbody></tbody></table>
        """
        acciones = """
        <table><thead><tr><th>Registro</th><th>Plazo</th><th>Fecha L&iacute;mite</th></tr></thead>
        <tbody><tr><td>0000122241 - 2026 - SUNAFIL</td><td>20</td><td>26/11/2026</td></tr>
        </tbody></table>
        """
        respuestas = {
            "Notificaciones de Fiscalización": vacia,
            "Acciones Previas": acciones,
        }
        pedidas = []

        def abrir(sesion, etiqueta="Notificaciones de Fiscalización"):
            pedidas.append(etiqueta)
            return respuestas[etiqueta]

        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", side_effect=abrir
        ):
            datos = puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertEqual(pedidas, ["Notificaciones de Fiscalización", "Acciones Previas"])
        self.assertEqual(datos["notificaciones"], [])
        self.assertEqual(len(datos["acciones_previas"]), 1)
        self.assertEqual(
            datos["acciones_previas"][0]["registro"],
            "0000122241 - 2026 - SUNAFIL",
        )

    def test_devuelve_las_dos_bandejas_cuando_la_bandeja_se_abre(self):
        html = """
        <table id="formNotificacion:dtNotificaciones">
        <tr><th>N&uacute;mero</th><th>Fecha</th></tr>
        <tr><td>0000123</td><td>23/10/2026</td></tr>
        </table>
        """
        with mock.patch.object(
            puente, "autenticar_sunafil", return_value=("code", "state")
        ), mock.patch.object(
            puente, "_sondear_sunafil", return_value=(True, "por defecto")
        ), mock.patch.object(
            puente, "abrir_bandeja_sunafil", return_value=html
        ):
            datos = puente.leer_buzon_sunafil("20614684772", "MODDATOS", "clave")

        self.assertEqual(len(datos["notificaciones"]), 1)
        self.assertEqual(datos["notificaciones"][0]["numero"], "0000123")
        self.assertIn("acciones_previas", datos)


class ArranqueTest(unittest.TestCase):
    """El puerto del argumento manda: antes se ignoraba en silencio."""

    def test_el_puerto_del_argumento_es_el_que_se_usa(self):
        with mock.patch.object(puente, "ThreadingHTTPServer") as servidor:
            servidor.return_value.serve_forever.side_effect = KeyboardInterrupt
            with contextlib.redirect_stdout(io.StringIO()):
                puente.main(["8080"])

        direccion = servidor.call_args[0][0]
        self.assertEqual(direccion[1], 8080)

    def test_un_puerto_que_no_es_numero_se_rechaza(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(puente.main(["abc"]), 2)

    def test_un_puerto_fuera_de_rango_se_rechaza(self):
        with contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(puente.main(["99999"]), 2)

    def test_la_ayuda_no_levanta_el_servidor(self):
        with mock.patch.object(puente, "ThreadingHTTPServer") as servidor:
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(puente.main(["--help"]), 0)
        servidor.assert_not_called()


class EnrutadoTest(unittest.TestCase):
    """Ejercita `do_POST` de verdad, con los lectores simulados."""

    def _post(self, entidad, ruta="/buzon", crudo=None):
        manejador = puente.Manejador.__new__(puente.Manejador)
        manejador._responder = mock.Mock()
        manejador.path = ruta
        if crudo is not None:
            manejador.headers = {"Content-Length": str(len(crudo))}
            manejador.rfile = io.BytesIO(crudo)
            return manejador
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

    def test_un_post_a_otra_ruta_dice_que_la_ruta_no_existe(self):
        """Un POST mal escrito daba «JSON inválido» y ocultaba el problema."""
        manejador = self._post("SUNAT", ruta="/new")
        manejador.do_POST()

        codigo, cuerpo = manejador._responder.call_args[0]
        self.assertEqual(codigo, 404)
        self.assertIn("/new", cuerpo["error"])
        self.assertIn("/buzon", cuerpo["detalle"])

    def test_un_cuerpo_que_no_es_json_se_muestra_para_diagnosticar(self):
        manejador = self._post("SUNAT", crudo=b"esto no es json")
        manejador.do_POST()

        codigo, cuerpo = manejador._responder.call_args[0]
        self.assertEqual(codigo, 400)
        self.assertIn("esto no es json", cuerpo["detalle"])

    def test_una_lista_en_vez_de_objeto_se_rechaza(self):
        manejador = self._post("SUNAT", crudo=b'["ruc","clave"]')
        manejador.do_POST()

        codigo, _ = manejador._responder.call_args[0]
        self.assertEqual(codigo, 400)

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


class AnalizarCapturaTest(unittest.TestCase):
    """El analizador empareja cada botón de menú con su texto visible."""

    HTML = """
    <form id="formMenu2" action="/si.inbox/Inicio/Empleador">
    <input type="hidden" name="javax.faces.ViewState" value="V1:V2" />
    <li><span id="formMenu2:a:0">Notificaciones de Fiscalizaci&oacute;n</span></li>
    <li><span id="formMenu2:a:1">Buz&oacute;n de Sugerencias</span></li>
    </form>
    <form id="formNotificaciones" action="/si.inbox/Inicio/Empleador">
    <input type="hidden" name="formNotificaciones" value="formNotificaciones" />
    <input type="hidden" name="javax.faces.ViewState" value="V3:V4" />
    <table id="formNotificaciones:dt"></table>
    </form>
    """

    def test_empareja_id_con_texto_del_menu(self):
        import analizar_captura as analisis

        encontrados = analisis.menus(self.HTML)

        self.assertEqual(len(encontrados), 2)
        self.assertEqual(encontrados[0][0], "formMenu2:a:0")
        self.assertEqual(encontrados[0][1], "Notificaciones de Fiscalización")
        self.assertEqual(encontrados[1][1], "Buzón de Sugerencias")

    def test_saca_accion_y_viewstate_de_cada_formulario(self):
        import analizar_captura as analisis

        forms = {f["id"]: f for f in analisis.formularios(self.HTML)}

        self.assertEqual(forms["formMenu2"]["viewstate"], "V1:V2")
        self.assertEqual(forms["formMenu2"]["accion"], "/si.inbox/Inicio/Empleador")
        self.assertEqual(forms["formNotificaciones"]["viewstate"], "V3:V4")
        self.assertIn("formNotificaciones", forms["formNotificaciones"]["campos"])

    def test_lista_las_tablas_candidatas(self):
        import analizar_captura as analisis

        self.assertEqual(analisis.tablas(self.HTML), ["formNotificaciones:dt"])


class AbrirBandejaSunafilTest(unittest.TestCase):
    """La bandeja se abre con un POST de JSF, no con un GET."""

    # Menú real de la casilla: el enlace lleva el onclick y un contador al lado
    # comparte prefijo de id, que es justo lo que confundía al puente.
    INICIO = """
    <form id="formMenu2" action="/si.inbox/Inicio/Empleador">
    <input type="hidden" name="formMenu2" value="formMenu2" />
    <input type="hidden" name="javax.faces.ViewState" value="VS:1" />
    <input type="hidden" name="javax.faces.ClientWindow" value="CW:9" />
    <li><a href="#" id="formMenu2:a:0" onclick="mojarra.ab(this,event,'action','@this','@all');return false">
    <span>P&aacute;gina de inicio</span></a></li>
    <li><a href="#" id="formMenu2:a:1" onclick="mojarra.ab(this,event,'action','@this','@all');return false">
    <span>Notificaciones de Fiscalizaci&oacute;n</span></a>
    <span id="formMenu2:a:9">7</span></li>
    <li><a href="#" id="formMenu2:a:2" onclick="mojarra.ab(this,event,'action','@this','@all');return false">
    <span>Notificaciones de Cobranza</span></a></li>
    </form>
    """

    def test_busca_el_boton_por_texto_y_no_por_j_idt(self):
        boton = puente._boton_de_menu(self.INICIO, "Notificaciones de Fiscalización")
        self.assertEqual(boton, "formMenu2:a:1")

    def test_avisa_si_el_menu_no_ofrece_la_bandeja(self):
        with self.assertRaises(puente.SolError) as ctx:
            puente._boton_de_menu(self.INICIO, "Notificaciones de Multas")
        self.assertIn("no ofrece", ctx.exception.mensaje)

    def test_falla_si_la_pagina_no_trae_viewstate(self):
        with self.assertRaises(puente.SolError) as ctx:
            puente._viewstate("<html>sin estado</html>")
        self.assertIn("sin ViewState", ctx.exception.mensaje)

    def test_abre_la_bandeja_con_viewstate_y_boton(self):
        sesion = mock.Mock()
        sesion.get.return_value = ("url", self.INICIO)
        sesion.post_ajax.return_value = ("url", "<html>bandeja</html>")

        html = puente.abrir_bandeja_sunafil(sesion, "Notificaciones de Cobranza")

        self.assertEqual(html, "<html>bandeja</html>")
        destino, datos = sesion.post_ajax.call_args[0]
        self.assertTrue(destino.endswith("/si.inbox/Inicio/Empleador"))
        self.assertEqual(datos["javax.faces.ViewState"], "VS:1")
        self.assertEqual(datos["javax.faces.source"], "formMenu2:a:2")

    def test_envia_todos_los_ocultos_no_solo_el_viewstate(self):
        """JSF puede exigir ClientWindow; el navegador manda el form entero."""
        sesion = mock.Mock()
        sesion.get.return_value = ("url", self.INICIO)
        sesion.post_ajax.return_value = ("url", "<html/>")

        puente.abrir_bandeja_sunafil(sesion)

        datos = sesion.post_ajax.call_args[0][1]
        self.assertEqual(datos["javax.faces.ClientWindow"], "CW:9")

    def test_usa_el_enlace_y_no_el_contador_de_al_lado(self):
        """Cada opción tiene dos ids; el que dispara la acción es el enlace."""
        sesion = mock.Mock()
        sesion.get.return_value = ("url", self.INICIO)
        sesion.post_ajax.return_value = ("url", "<html/>")

        puente.abrir_bandeja_sunafil(sesion)

        datos = sesion.post_ajax.call_args[0][1]
        self.assertEqual(datos["javax.faces.source"], "formMenu2:a:1")
        self.assertNotIn("formMenu2:a:9", datos)

    def test_por_defecto_abre_la_bandeja_de_fiscalizacion(self):
        sesion = mock.Mock()
        sesion.get.return_value = ("url", self.INICIO)
        sesion.post_ajax.return_value = ("url", "<html/>")

        puente.abrir_bandeja_sunafil(sesion)

        datos = sesion.post_ajax.call_args[0][1]
        self.assertEqual(datos["javax.faces.source"], "formMenu2:a:1")


class PostAjaxTest(unittest.TestCase):
    """JSF solo ejecuta la acción si el POST se declara parcial."""

    def _capturar(self, metodo):
        sesion = puente.Sesion()
        capturado = {}

        class Falso:
            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

            def read(self):
                return b"ok"

            def geturl(self):
                return "https://x"

        def abrir(peticion, timeout=None):
            capturado["cabeceras"] = dict(peticion.header_items())
            capturado["cuerpo"] = urllib.parse.parse_qs(
                peticion.data.decode()
            )
            return Falso()

        sesion.opener.open = abrir
        metodo(sesion)
        return capturado

    def test_post_ajax_declara_la_peticion_parcial(self):
        capturado = self._capturar(
            lambda s: s.post_ajax("https://x", {"a": "1"})
        )

        self.assertEqual(capturado["cabeceras"]["Faces-request"], "partial/ajax")
        self.assertEqual(capturado["cuerpo"]["javax.faces.partial.ajax"], ["true"])
        self.assertEqual(capturado["cuerpo"]["a"], ["1"])

    def test_el_post_normal_no_se_declara_parcial(self):
        capturado = self._capturar(lambda s: s.post("https://x", {"a": "1"}))

        self.assertNotIn("Faces-request", capturado["cabeceras"])
        self.assertNotIn("javax.faces.partial.ajax", capturado["cuerpo"])


class ParametrosDelMenuTest(unittest.TestCase):
    """El `onclick` del menú dice los campos reales del POST."""

    HTML = """
    <form id="formMenu2">
    <input type="hidden" name="javax.faces.ViewState" value="VS:1"/>
    <li><a href="#" onclick="BsF.submitForm('formMenu2',{'formMenu2:a:1':'formMenu2:a:1','param_uuid':'abc-123'});return false">
    <span id="formMenu2:a:1">Notificaciones de Fiscalizaci&oacute;n</span></a></li>
    </form>
    """

    def test_lee_formulario_y_parametros_del_onclick(self):
        bloque, _ = puente._bloque_de_menu(self.HTML, "Notificaciones de Fiscalización")

        self.assertIsNotNone(bloque)
        _, formulario, parametros, es_ajax = puente._accion_del_menu(bloque)
        self.assertEqual(formulario, "formMenu2")
        self.assertEqual(parametros["param_uuid"], "abc-123")
        self.assertFalse(es_ajax)

    def test_sin_onclick_devuelve_nada(self):
        self.assertEqual(puente._elemento_pulsable("<li><a><span>X</span></a></li>", "Y"), None)

    SUELTO = """
    <form id="formMenu2">
    <ul>
    <li><a href="#"><span>Notificaciones de Fiscalizaci&oacute;n</span></a>
        <span id="formMenu2:j:0" onclick="mojarra.ab(this,event,'action','@this','@all')">7</span></li>
    </ul></form>
    """

    def test_encuentra_el_onclick_aunque_el_enlace_no_lo_tenga(self):
        """El <a> puede ir pelado: el onclick vive en otro elemento del <li>."""
        bloque, _ = puente._bloque_de_menu(self.SUELTO, "Notificaciones de Fiscalización")

        self.assertIsNotNone(bloque)
        id_elemento, formulario, _, es_ajax = puente._accion_del_menu(bloque)
        self.assertEqual(id_elemento, "formMenu2:j:0")
        self.assertEqual(formulario, "formMenu2")
        self.assertTrue(es_ajax)

    def test_sin_onclick_cae_al_formulario_que_contiene_el_menu(self):
        pagina = '<form id="formMenu2"><ul><li><a href="#"><span>Acciones Previas</span></a></li></ul></form>'
        bloque, posicion = puente._bloque_de_menu(pagina, "Acciones Previas")

        self.assertEqual(puente._formulario_que_contiene(pagina, posicion), "formMenu2")

    FORMAS = {
        "li con enlace y contador": """
        <form id="formMenu2"><ul><li><a href="#" id="formMenu2:a:1"
        onclick="mojarra.ab(this,event,'action','@this','@all')">
        <span>Notificaciones de Fiscalizaci&oacute;n</span></a>
        <span id="formMenu2:a:9">7</span></li></ul></form>""",
        "enlace pelado y onclick aparte": """
        <form id="formMenu2"><ul><li><a href="#"><span>Notificaciones de Fiscalizaci&oacute;n</span></a>
        <span id="formMenu2:b:0" onclick="mojarra.ab(this,event,'action','@this','@all')">7</span></li></ul></form>""",
        "PrimeFaces con source": """
        <form id="formMenu2"><ul><li><a href="#" id="formMenu2:c:1"
        onclick="PrimeFaces.ab({source:'formMenu2:c:1',process:'@this'});return false">
        <span>Notificaciones de Fiscalizaci&oacute;n</span></a></li></ul></form>""",
        "divs sin li": """
        <form id="formMenu2"><div><div id="formMenu2:d:1"
        onclick="mojarra.ab(this,event,'action','@this','@all')">
        Notificaciones de Fiscalizaci&oacute;n</div></div></form>""",
        "menu anidado": """
        <form id="formMenu2"><ul><li>Notificaciones<ul>
        <li><a href="#" id="formMenu2:f:1" onclick="mojarra.ab(this,event,'action','@this','@all')">
        Notificaciones de Fiscalizaci&oacute;n</a></li></ul></li></ul></form>""",
    }

    def test_reconoce_las_formas_de_menu_de_la_casilla(self):
        """El menú no siempre usa <li><a onclick>: hay que aguantar variantes."""
        for nombre, html in self.FORMAS.items():
            with self.subTest(nombre):
                elemento, _ = puente._bloque_de_menu(
                    html, "Notificaciones de Fiscalización"
                )
                self.assertIsNotNone(elemento, f"no encontró el menú: {nombre}")

                _, formulario, _, _ = puente._accion_del_menu(elemento)
                self.assertEqual(formulario, "formMenu2", nombre)

    def test_sin_prefijo_de_formulario_usa_el_que_lo_contiene(self):
        html = """<form id="formMenu2">
        <input type="hidden" name="javax.faces.ViewState" value="VS"/>
        <div id="j_idt31" onclick="mojarra.ab(this,event,'action','@this','@all')">
        Notificaciones de Fiscalizaci&oacute;n</div></form>"""
        sesion = mock.Mock()
        sesion.get.return_value = ("url", html)
        sesion.post_ajax.return_value = ("url", "<html/>")

        puente.abrir_bandeja_sunafil(sesion)

        datos = sesion.post_ajax.call_args[0][1]
        self.assertEqual(datos["javax.faces.ViewState"], "VS")
        self.assertEqual(datos["javax.faces.source"], "j_idt31")

    def test_abrir_bandeja_envia_los_parametros_del_onclick(self):
        sesion = mock.Mock()
        sesion.get.return_value = ("url", self.HTML)
        sesion.post.return_value = ("url", "<html/>")

        puente.abrir_bandeja_sunafil(sesion)

        datos = sesion.post.call_args[0][1]
        self.assertEqual(datos["param_uuid"], "abc-123")
        self.assertEqual(datos["javax.faces.ViewState"], "VS:1")
        self.assertEqual(datos["formMenu2:a:1"], "formMenu2:a:1")


class AccionesPreviasTest(unittest.TestCase):
    """Datos reales de la casilla de INMOA (una carta inductiva)."""

    FILA = """
    <tr>
    <td>CARTAS INDUCTIVAS GENERAL</td>
    <td>0000122241 - 2026 - SUNAFIL/DPPR/SDPA</td>
    <td>23/10/2026 23:37<br/>Efectos del Dep&oacute;sito: 26/10/2026</td>
    <td>29/10/2026 12:51<br/>29/10/2026</td>
    <td></td>
    <td>20</td>
    <td>26/11/2026</td>
    <td></td><td></td><td></td>
    </tr>
    """

    def test_extrae_la_fila_completa(self):
        acciones = puente.extraer_acciones_previas(f"<table>{self.FILA}</table>")

        self.assertEqual(len(acciones), 1)
        accion = acciones[0]
        self.assertEqual(accion["tipo"], "CARTAS INDUCTIVAS GENERAL")
        self.assertEqual(accion["registro"], "0000122241 - 2026 - SUNAFIL/DPPR/SDPA")
        self.assertEqual(accion["fecha_deposito"], "23/10/2026 23:37")
        self.assertEqual(accion["fecha_efectos"], "26/10/2026")
        self.assertEqual(accion["plazo"], "20")
        self.assertEqual(accion["fecha_limite"], "26/11/2026")

    def test_separa_la_fecha_de_efectos_del_deposito(self):
        accion = puente.extraer_acciones_previas(self.FILA)[0]

        self.assertNotIn("Efectos", accion["fecha_deposito"])
        self.assertEqual(accion["fecha_efectos"], "26/10/2026")

    def test_calcula_los_dias_que_faltan(self):
        accion = puente.extraer_acciones_previas(self.FILA)[0]

        self.assertIsInstance(accion["dias_restantes"], int)

    def test_una_tabla_vacia_no_inventa_filas(self):
        vacia = "<table><tr><td>No hay registros</td></tr></table>"

        self.assertEqual(puente.extraer_acciones_previas(vacia), [])

    def test_ignora_la_fila_de_cabecera(self):
        cabecera = """
        <tr><th>Tipo de Requerimiento</th><th>Registro</th><th>Fecha</th></tr>
        """

        self.assertEqual(puente.extraer_acciones_previas(cabecera), [])

    PAGINA = """
    <table id="layout"><tr><td>
      <table id="menu"><tr><td>Men&uacute;</td></tr></table>
    </td><td>
      <table id="formAcciones:dtAcciones">
        <thead><tr>
          <th>Tipo de Requerimiento</th><th>Registro</th>
          <th>Fecha de Dep&oacute;sito</th><th>Fecha Acuse de Recibo</th>
          <th>Fecha de Notificaci&oacute;n</th><th>Plazo</th>
          <th>Fecha L&iacute;mite de Presentaci&oacute;n</th><th>Opci&oacute;n</th>
        </tr></thead>
        <tbody>
          <tr><td>CARTAS INDUCTIVAS GENERAL</td>
          <td>0000122241 - 2026 - SUNAFIL/DPPR/SDPA</td>
          <td>23/10/2026 23:37<br/>Efectos del Dep&oacute;sito: 26/10/2026</td>
          <td>29/10/2026 12:51<br/>29/10/2026</td>
          <td></td><td>20</td><td>26/11/2026</td><td></td></tr>
        </tbody>
      </table>
    </td></tr></table>
    """

    def test_encuentra_la_tabla_entre_tablas_anidadas(self):
        """La página trae varias tablas: hay que dar con la de acciones."""
        acciones = puente.extraer_acciones_previas(self.PAGINA)

        self.assertEqual(len(acciones), 1)
        self.assertEqual(acciones[0]["fecha_limite"], "26/11/2026")

    def test_usa_los_encabezados_para_ubicar_cada_columna(self):
        """Si SUNAFIL reordena columnas, los encabezados mandan."""
        reordenada = """
        <table>
        <thead><tr><th>Plazo</th><th>Registro</th><th>Fecha L&iacute;mite</th></tr></thead>
        <tbody><tr><td>15</td><td>0000999999 - 2026 - SUNAFIL/X</td>
        <td>01/12/2026</td></tr></tbody>
        </table>
        """
        accion = puente.extraer_acciones_previas(reordenada)[0]

        self.assertEqual(accion["plazo"], "15")
        self.assertEqual(accion["fecha_limite"], "01/12/2026")
        self.assertEqual(accion["registro"], "0000999999 - 2026 - SUNAFIL/X")

    def test_una_fecha_limite_ilegible_no_rompe(self):
        self.assertIsNone(puente._dias_hasta(""))
        self.assertIsNone(puente._dias_hasta("pendiente"))


if __name__ == "__main__":
    unittest.main()
