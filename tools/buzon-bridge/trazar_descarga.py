#!/usr/bin/env python3
"""Traza cada petición que hace el puente al descargar un documento.

Sirve para revisar a mano qué se envía y qué responde SUNAFIL en cada paso,
sin tener que leer el código. Guarda cada respuesta en `traza/NN-*.txt`.

Uso:
    python trazar_descarga.py 20614684772 APIINMOA
    python trazar_descarga.py 20614684772 APIINMOA --tipo constancia_deposito

Cada paso imprime:
  - método y URL
  - los campos enviados (nombre y valor recortado)
  - el código, tipo y tamaño de la respuesta
  - los primeros bytes y los botones/formularios que trae
"""

import os
import re
import sys
import urllib.parse

import sol_buzon as puente

CARPETA = "traza"
_paso = [0]


def _recorta(valor, tope=70):
    valor = str(valor)
    if len(valor) <= tope:
        return valor
    return f"{valor[:tope]}…({len(valor)} car.)"


def _guardar(etiqueta, contenido):
    os.makedirs(CARPETA, exist_ok=True)
    _paso[0] += 1
    nombre = f"{_paso[0]:02d}-{etiqueta}.txt"
    ruta = os.path.join(CARPETA, nombre)
    modo = "wb" if isinstance(contenido, bytes) else "w"
    with open(ruta, modo) as f:
        f.write(contenido)
    return ruta


def _describe(contenido):
    """Qué trae la respuesta: formularios, botones y si es PDF."""
    if isinstance(contenido, bytes):
        if contenido[:5].lstrip() == b"%PDF-":
            return "PDF válido"
        texto = contenido.decode("utf-8", "ignore")
    else:
        texto = contenido

    partes = []
    # De cada formulario interesa el `action`: es a dónde hay que mandar el POST.
    for etiqueta in re.findall(r"<form\b[^>]*>", texto, re.I):
        ident = re.search(r"""\bid\s*=\s*["']([^"']+)["']""", etiqueta)
        accion = re.search(r"""\baction\s*=\s*["']([^"']+)["']""", etiqueta)
        partes.append(
            f"form {ident.group(1) if ident else '?'}"
            f" -> action={accion.group(1) if accion else '(sin action)'}"
        )
    # En HTML viene como campo oculto; en una respuesta AJAX, como `<update>`.
    estado = re.search(
        r"""name\s*=\s*["']javax\.faces\.ViewState["'][^>]*value\s*=\s*["']([^"']{0,24})""",
        texto,
        re.I,
    ) or re.search(
        r"""<update id="[^"]*ViewState[^"]*"><!\[CDATA\[([^\]]{0,24})""", texto, re.I
    )
    if estado:
        partes.append(f"ViewState={estado.group(1)}…")
    else:
        partes.append("ViewState=(no encontrado)")
    botones = re.findall(r"""<button[^>]*\bname\s*=\s*["']([^"']+)["']""", texto, re.I)
    if botones:
        partes.append("botones: " + ", ".join(dict.fromkeys(botones)))
    if re.search(r"Error interno|Error Code: 500|Algo sali[oó] mal", texto, re.I):
        partes.append(">>> ERROR 500 DEL SERVIDOR")
    return " | ".join(partes) if partes else "(sin formularios ni botones)"


def _traza_peticion(metodo, url, datos):
    print(f"\n  {metodo} {url}")
    if datos:
        for clave, valor in datos.items():
            print(f"      {clave} = {_recorta(valor)}")
    else:
        print("      (sin campos)")


def _traza_respuesta(url_final, cabeceras, contenido, etiqueta):
    tipo = cabeceras.get("Content-Type", "sin tipo")
    print(f"      -> {tipo}, {len(contenido)} bytes")
    print(f"      -> {_describe(contenido)}")
    ruta = _guardar(etiqueta, contenido)
    print(f"      -> guardado en {ruta}")


def instrumentar(sesion):
    """Envuelve los métodos de la sesión para imprimir cada petición."""
    originales = {
        "get": sesion.get,
        "post": sesion.post,
        "post_ajax": sesion.post_ajax,
        "post_crudo": sesion.post_crudo,
        "post_ajax_crudo": sesion.post_ajax_crudo,
        "get_crudo": sesion.get_crudo,
    }

    def get(url):
        _traza_peticion("GET", url, None)
        final, html = originales["get"](url)
        _traza_respuesta(final, {}, html.encode(), "get")
        return final, html

    def post(url, datos):
        _traza_peticion("POST", url, datos)
        final, html = originales["post"](url, datos)
        _traza_respuesta(final, {}, html.encode(), "post")
        return final, html

    def post_ajax(url, datos):
        _traza_peticion("POST-AJAX", url, datos)
        final, html = originales["post_ajax"](url, datos)
        _traza_respuesta(final, {}, html.encode(), "post-ajax")
        return final, html

    def post_crudo(url, datos):
        _traza_peticion("POST", url, datos)
        final, cab, cuerpo = originales["post_crudo"](url, datos)
        _traza_respuesta(final, cab, cuerpo, "post")
        return final, cab, cuerpo

    def post_ajax_crudo(url, datos):
        _traza_peticion("POST-AJAX", url, datos)
        final, cab, cuerpo = originales["post_ajax_crudo"](url, datos)
        _traza_respuesta(final, cab, cuerpo, "post-ajax")
        return final, cab, cuerpo

    def get_crudo(url):
        _traza_peticion("GET", url, None)
        final, cab, cuerpo = originales["get_crudo"](url)
        _traza_respuesta(final, cab, cuerpo, "get")
        return final, cab, cuerpo

    sesion.get = get
    sesion.post = post
    sesion.post_ajax = post_ajax
    sesion.post_crudo = post_crudo
    sesion.post_ajax_crudo = post_ajax_crudo
    sesion.get_crudo = get_crudo


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    ruc, usuario = sys.argv[1], sys.argv[2]
    tipo = "carta"
    if "--tipo" in sys.argv:
        tipo = sys.argv[sys.argv.index("--tipo") + 1]

    clave = os.environ.get("BUZON_CLAVE")
    if not clave:
        import getpass

        clave = getpass.getpass("Clave SOL (no se muestra ni se guarda): ")

    os.makedirs(CARPETA, exist_ok=True)
    print(f"Traza en: {os.path.abspath(CARPETA)}")
    print(f"Documento: {tipo}\n")
    print("=" * 70)

    # La sesión real, instrumentada. `descargar_documento` crea la suya, así
    # que se envuelve el constructor para instrumentar la que use.
    original = puente.Sesion

    class SesionTrazada(original):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, **kwargs)
            instrumentar(self)

    puente.Sesion = SesionTrazada

    # La carta no usa los métodos de Sesion: `_enviar_documento` devuelve un
    # `enviar()` que abre la petición a mano. Sin envolverlo, los pasos 4 y 5
    # —los que de verdad fallan— no salían en la traza.
    original_enviar = puente._enviar_documento

    def enviar_documento_trazado(sesion, url_bandeja):
        enviar = original_enviar(sesion, url_bandeja)

        def enviar_trazado(url, campos, ajax=False):
            etiqueta = "POST-AJAX (carta)" if ajax else "POST (carta)"
            print(f"\n  {etiqueta} {url}")
            for clave, valor in campos:
                print(f"      {clave} = {_recorta(valor)}")
            estado, cabeceras, cuerpo = enviar(url, campos, ajax)
            print(f"      -> HTTP {estado}, {cabeceras.get('Content-Type', 'sin tipo')}, "
                  f"{len(cuerpo)} bytes")
            print(f"      -> {_describe(cuerpo)}")
            ruta = _guardar("carta", cuerpo)
            print(f"      -> guardado en {ruta}")
            return estado, cabeceras, cuerpo

        return enviar_trazado

    puente._enviar_documento = enviar_documento_trazado

    try:
        nombre, contenido = puente.descargar_documento(
            ruc, usuario, clave, "Acciones Previas", 0, tipo
        )
    except puente.SolError as e:
        print("\n" + "=" * 70)
        print(f"FALLÓ: {e.mensaje}")
        if e.detalle:
            print(f"\nDetalle:\n{e.detalle}")
        print(f"\nCada respuesta está en {os.path.abspath(CARPETA)}")
        return 1
    finally:
        puente.Sesion = original
        puente._enviar_documento = original_enviar

    print("\n" + "=" * 70)
    print(f"OK: {nombre} ({len(contenido)} bytes)")
    with open(nombre, "wb") as f:
        f.write(contenido)
    print(f"Guardado en: {os.path.abspath(nombre)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
