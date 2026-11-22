#!/usr/bin/env python3
"""Resume una carpeta de captura de SUNAFIL: menús, formularios y ViewState.

Sirve para descubrir con qué POST `si.inbox` abre la bandeja sin volver a
ejecutar la captura: todo lo necesario está en el HTML ya guardado.

Uso:
    python3 analizar_captura.py captura-sunafil-20261120-000733
"""

import html
import os
import re
import sys


def _texto(trozo):
    limpio = re.sub(r"<[^>]+>", " ", trozo)
    return re.sub(r"\s+", " ", html.unescape(limpio)).strip()


def menus(h):
    """Empareja cada botón de menú con el texto que muestra."""
    salida = []
    for trozo in re.split(r'(?=<span id="formMenu2)', h)[1:]:
        ident = re.search(r'id="([^"]+)"', trozo)
        if not ident:
            continue
        etiqueta = _texto(trozo.split("</li>")[0])
        salida.append((ident.group(1), etiqueta[:70]))
    return salida


def formularios(h):
    """Devuelve cada formulario con su acción y su ViewState."""
    salida = []
    for trozo in re.split(r"(?=<form\b)", h)[1:]:
        bloque = trozo.split("</form>")[0]
        ident = re.search(r'<form[^>]*\bid="([^"]+)"', bloque)
        accion = re.search(r'<form[^>]*\baction="([^"]*)"', bloque)
        if not ident:
            continue
        vista = re.search(r'name="javax\.faces\.ViewState"[^>]*value="([^"]*)"', bloque)
        campos = re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"', bloque)
        salida.append(
            {
                "id": ident.group(1),
                "accion": accion.group(1) if accion else "",
                "viewstate": vista.group(1) if vista else "",
                "campos": campos,
            }
        )
    return salida


def tablas(h):
    """Tablas y datatables de PrimeFaces: donde suele venir la lista."""
    return sorted(set(re.findall(r'<table[^>]*\bid="([^"]+)"', h)))


def columnas(h, id_tabla):
    """Encabezados de una tabla, para saber qué trae cada fila."""
    bloque = h.split(f'id="{id_tabla}"', 1)
    if len(bloque) < 2:
        return []
    trozo = bloque[1].split("</table>", 1)[0]
    celdas = re.findall(r"<th[^>]*>(.*?)</th>", trozo, re.S)
    return [_texto(c) for c in celdas if _texto(c)]


def filas(h, id_tabla, limite=3):
    """Primeras filas de una tabla, ya en texto, para ver el formato real."""
    bloque = h.split(f'id="{id_tabla}"', 1)
    if len(bloque) < 2:
        return []
    trozo = bloque[1].split("</table>", 1)[0]
    salida = []
    for fila in re.findall(r"<tr[^>]*>(.*?)</tr>", trozo, re.S)[:limite]:
        celdas = [_texto(c) for c in re.findall(r"<td[^>]*>(.*?)</td>", fila, re.S)]
        if any(celdas):
            salida.append(celdas)
    return salida


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    carpeta = sys.argv[1]
    if not os.path.isdir(carpeta):
        print(f"No existe la carpeta: {carpeta}")
        return 1

    for nombre in sorted(os.listdir(carpeta)):
        if not nombre.endswith(".html"):
            continue
        ruta = os.path.join(carpeta, nombre)
        with open(ruta, encoding="utf-8", errors="ignore") as f:
            h = f.read()
        if len(h) < 20000:
            continue

        print("=" * 78)
        print(nombre)
        print("=" * 78)

        encontrados = menus(h)
        if encontrados:
            print("\nMENÚS (id => texto):")
            for ident, etiqueta in encontrados:
                print(f"  {ident}\n      {etiqueta}")

        print("\nFORMULARIOS:")
        for form in formularios(h):
            print(f"  {form['id']}  action={form['accion'] or '(ninguna)'}")
            print(f"      ViewState: {form['viewstate'] or '(no tiene)'}")
            if form["campos"]:
                print(f"      ocultos: {', '.join(form['campos'][:8])}")

        listas = tablas(h)
        if listas:
            print("\nTABLAS:")
            for ident in listas[:10]:
                print(f"  {ident}")
                encabezados = columnas(h, ident)
                if encabezados:
                    print(f"      columnas: {encabezados}")
                for fila in filas(h, ident):
                    print(f"      fila: {fila}")

        for clave in ("Notificaci", "Bandeja", "Acuse"):
            veces = len(re.findall(clave, h, re.I))
            if veces:
                print(f"\n'{clave}' aparece {veces} vez/veces")

    return 0


if __name__ == "__main__":
    sys.exit(main())
