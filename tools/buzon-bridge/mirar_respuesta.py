#!/usr/bin/env python3
"""Dice qué es un archivo que no abre como PDF.

Cuando SUNAFIL responde la vista del visor en vez del documento, el archivo
guardado no es un PDF. Esto mira dentro para saber qué llegó y de dónde sale
el archivo real.

Uso:
    python mirar_respuesta.py carta.pdf
"""

import os
import re
import sys
from html import unescape


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 1

    ruta = sys.argv[1]
    if not os.path.exists(ruta):
        print(f"No existe: {ruta}")
        return 1

    with open(ruta, "rb") as f:
        datos = f.read()

    print(f"Archivo: {ruta}")
    print(f"Tamaño:  {len(datos)} bytes")
    print(f"Primeros bytes: {datos[:40]!r}")

    if datos[:5].lstrip() == b"%PDF-":
        print("\nEs un PDF válido. No hay nada que arreglar.")
        return 0

    if b"PK\x03\x04" in datos[:8]:
        print("\nEs un ZIP. Si es un .docx, el documento llegó bien con otro formato.")
        return 0

    texto = datos.decode("utf-8", "ignore")
    if not texto.strip():
        print("\nNo es texto: parece binario. Saca los primeros bytes de arriba.")
        return 0

    print("\n--- Qué contiene ---")
    print(f"¿Es XML de JSF? {'sí' if '<partial-response' in texto else 'no'}")
    print(f"¿Es HTML?      {'sí' if '<html' in texto.lower() else 'no'}")

    titulo = re.search(r"<title[^>]*>(.*?)</title>", texto, re.S | re.I)
    if titulo:
        print(f"Título: {unescape(titulo.group(1)).strip()[:120]}")

    print("\n--- Direcciones candidatas al documento ---")
    vistas = set()
    for etiqueta in ("iframe", "embed", "object", "a", "form"):
        for bloque in re.findall(rf"<{etiqueta}\b[^>]*>", texto, re.I):
            for clave in ("src", "data", "href", "action"):
                m = re.search(rf"""\b{clave}\s*=\s*["']([^"']+)["']""", bloque, re.I)
                if not m:
                    continue
                valor = unescape(m.group(1))
                if valor.startswith("#") or valor.lower().startswith("javascript:"):
                    continue
                if valor not in vistas:
                    vistas.add(valor)
                    print(f"  [{etiqueta}.{clave}] {valor}")

    if not vistas:
        print("  (ninguna)")

    print("\n--- Enlaces y textos con .pdf o 'descarg' ---")
    pistas = set()
    for m in re.finditer(r"""["']([^"']*(?:\.pdf|descarg|servlet|documento)[^"']*)["']""", texto, re.I):
        pistas.add(unescape(m.group(1)))
    for pista in sorted(pistas)[:20]:
        print(f"  {pista}")
    if not pistas:
        print("  (ninguna)")

    print("\n--- Trozos de JavaScript con 'pdf' ---")
    for m in re.finditer(r".{60}pdf.{60}", texto, re.I | re.S):
        limpio = re.sub(r"\s+", " ", m.group(0))
        print(f"  …{limpio}…")
    return 0


if __name__ == "__main__":
    sys.exit(main())
