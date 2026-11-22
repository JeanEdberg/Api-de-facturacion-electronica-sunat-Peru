"""
Pasos 4 y 5 de la CARTA, con los campos EXACTOS que envía el navegador
(copiados de la pestaña Red/Carga útil de Chrome). Solo librería estándar.

Se engancha a tu sol_buzon.py: login y apertura de la bandeja (pasos 1-3)
se quedan como ya los tienes. Solo reemplazas lo que hace la carta.

Contrato con tu código: `enviar` es una función tuya que hace el POST con la
sesión (cookie) ya abierta:

    enviar(url, campos, ajax=False) -> (estado_http, cabeceras: dict, cuerpo: bytes)

  - campos: lista de tuplas (nombre, valor), en orden. Codifícala con
    urllib.parse.urlencode(campos, quote_via=urllib.parse.quote).
  - Content-Type: application/x-www-form-urlencoded
  - Referer: la URL de la bandeja; Origin: https://casillaelectronica.sunafil.gob.pe
  - Si ajax=True añade además las cabeceras:
        Faces-Request: partial/ajax
        X-Requested-With: XMLHttpRequest
        Accept: application/xml, text/xml, */*; q=0.01
"""
import re

FORM_LISTA = "formactosAdministrativos"
TABLA = FORM_LISTA + ":dtAlertas"
FORM_DESCARGA = "formDescargaDoc"


class ErrorCarta(Exception):
    pass


# --------------------------------------------------------------------------
# Paso 4: clic en el botón rojo de la carta (AJAX)
# --------------------------------------------------------------------------
def campos_clic_carta(viewstate, boton):
    """`boton` = name completo, p. ej. 'formactosAdministrativos:dtAlertas:0:j_idt108'."""
    return [
        (FORM_LISTA, FORM_LISTA),                    # marca de formulario enviado (FALTABA)
        (FORM_LISTA + ":fecInicial", ""),            # filtros: el navegador los envía (FALTABAN)
        (FORM_LISTA + ":fecFinal", ""),
        (FORM_LISTA + ":cbEstadoInner", "-1"),
        (FORM_LISTA + ":cbTipReqInner", "-1"),
        (TABLA + "_length", "10"),
        ("javax.faces.ViewState", viewstate),
        ("javax.faces.source", boton),
        ("javax.faces.partial.event", "click"),      # FALTABA
        ("javax.faces.partial.execute", boton + " " + FORM_LISTA),  # antes: solo el botón
        (boton, boton),
        ("javax.faces.partial.ajax", "true"),        # FALTABA
        # NO enviar javax.faces.partial.render: el navegador no lo manda
    ]


# --------------------------------------------------------------------------
# Paso 5: clic en "Descargar" del diálogo (submit normal, devuelve el PDF)
# --------------------------------------------------------------------------
def campos_descarga(viewstate):
    return [
        (FORM_DESCARGA, FORM_DESCARGA),                                # FALTABA
        (FORM_DESCARGA + "_panelDescargaDoc_collapsed", "false"),      # FALTABA
        (FORM_DESCARGA + ":btnverDocumento", ""),   # el botón real; valor VACÍO (no j_idt179)
        ("javax.faces.ViewState", viewstate),
    ]


def viewstate_de(texto):
    """ViewState más reciente de una respuesta AJAX (XML) o de una página HTML."""
    m = re.search(r'<update id="[^"]*ViewState[^"]*"><!\[CDATA\[(.*?)\]\]></update>', texto, re.S)
    if m:
        return m.group(1)
    m = re.search(r'name="javax\.faces\.ViewState"[^>]*value="([^"]*)"', texto)
    if not m:
        m = re.search(r'value="([^"]*)"[^>]*name="javax\.faces\.ViewState"', texto)
    return m.group(1) if m else None


def nombre_de(cabeceras, por_defecto="carta.pdf"):
    cd = ""
    for k, v in cabeceras.items():
        if k.lower() == "content-disposition":
            cd = v
    m = re.search(r'filename\*?=(?:UTF-8\'\')?"?([^";]+)"?', cd)
    nombre = re.sub(r"[^\w.\-]+", "_", m.group(1).split("/")[-1]) if m else por_defecto
    return nombre if nombre.lower().endswith(".pdf") else nombre + ".pdf"


def descargar_carta(enviar, url_bandeja, viewstate_bandeja, boton):
    """Devuelve (nombre_archivo, bytes_pdf). Lanza ErrorCarta si algo no cuadra."""
    # Paso 4 ------------------------------------------------------------------
    estado, _, cuerpo = enviar(url_bandeja, campos_clic_carta(viewstate_bandeja, boton), ajax=True)
    xml = cuerpo.decode("utf-8", "replace")
    if estado != 200:
        raise ErrorCarta("Paso 4: HTTP %s" % estado)
    if "<partial-response" not in xml:
        raise ErrorCarta(
            "Paso 4: la respuesta no es XML parcial de JSF (debería serlo). Si es HTML "
            "completo, el servidor no tomó la petición como AJAX. Inicio: %r" % xml[:200])
    if "<error-name>" in xml or "<error>" in xml:
        raise ErrorCarta("Paso 4: error JSF en la respuesta")
    vs = viewstate_de(xml) or viewstate_bandeja  # usar el más reciente si vino uno nuevo

    # Paso 5 ------------------------------------------------------------------
    estado, cab, datos = enviar(url_bandeja, campos_descarga(vs), ajax=False)
    if estado != 200:
        raise ErrorCarta("Paso 5: HTTP %s (inicio: %r)" % (estado, datos[:120]))
    if not datos.startswith(b"%PDF-"):
        raise ErrorCarta("Paso 5: no llegó un PDF (inicio: %r)" % datos[:120])
    return nombre_de(cab), datos
