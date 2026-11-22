#!/usr/bin/env python3
"""Muestra el HTML exacto del menú de la casilla, para ver cómo se pulsa.

Cada opción del menú puede tener varios elementos con id (un `<a>` y algo
dentro), y solo uno de ellos dispara la acción. Adivinar cuál es lo que ha
hecho fallar el POST, así que esto imprime las etiquetas tal cual vienen.

No pide credenciales: lee un archivo guardado con Ctrl+S desde el navegador.

Uso:
    python ver_menu.py sondeo-bandeja-.../1-POST-de-formulario-completo.html
"""

import html as _html
import os
import re
import sys


def bloque_formulario(pagina, id_formulario):
    """Devuelve el HTML interno del `<form id="...">` pedido."""
    for trozo in re.split(r"(?=<form\b)", pagina)[1:]:
        ident = re.search(r'<form[^>]*\bid="([^"]+)"', trozo)
        if ident and ident.group(1) == id_formulario:
            return trozo.split("</form>")[0]
    return None


def texto_visible(trozo):
    limpio = re.sub(r"<[^>]+>", " ", trozo)
    return re.sub(r"\s+", " ", _html.unescape(limpio)).strip()


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    ruta = sys.argv[1]
    if not os.path.isfile(ruta):
        print(f"No existe: {ruta}")
        return 1

    with open(ruta, encoding="utf-8", errors="ignore") as f:
        pagina = f.read()

    bloque = bloque_formulario(pagina, "formMenu2")
    if bloque is None:
        print("Ese archivo no contiene el formulario formMenu2.")
        print("¿Es la página de inicio de la casilla? Ábrela, pulsa el menú y")
        print("guarda con Ctrl+S.")
        return 1

    print("=" * 72)
    print("CAMPOS OCULTOS DE formMenu2")
    print("=" * 72)
    for etiqueta in re.findall(r"<input\b[^>]*>", bloque):
        if "hidden" in etiqueta:
            print("  " + re.sub(r"\s+", " ", etiqueta))

    print()
    print("=" * 72)
    print("OPCIONES DEL MENÚ")
    print("=" * 72)
    for trozo in re.split(r"(?=<li\b)", bloque)[1:]:
        texto = texto_visible(trozo.split("</li>")[0])
        if not texto:
            continue

        print(f"\n[{texto}]")
        # Se muestran las etiquetas de apertura de los elementos pulsables.
        for etiqueta in re.findall(r"<(?:a|span|button|input)\b[^>]*>", trozo):
            recortada = re.sub(r"\s+", " ", etiqueta)
            print(f"  {recortada[:900]}")

        onclick = re.findall(r'onclick\s*=\s*"([^"]*)"', trozo)
        if onclick:
            print("  onclick completo:")
            for valor in onclick:
                print(f"    {_html.unescape(valor)[:900]}")
        else:
            print("  (sin onclick)")

        href = re.findall(r'href\s*=\s*"([^"]*)"', trozo)
        if href:
            print(f"  href: {[h[:200] for h in href]}")

    print(
        "\nPega esta salida. Interesa la opción «Notificaciones de Fiscalización»\n"
        "y la de «Acciones Previas», con su onclick y su id."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
