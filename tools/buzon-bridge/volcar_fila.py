#!/usr/bin/env python3
"""Muestra el HTML crudo de una fila de la casilla, sin limpiarlo.

Sirve para saber cómo dibuja SUNAFIL el enlace del documento. El texto de la
celda no lo dice: hay que ver las etiquetas, que es justo lo que el parser
normal borra.

Uso:
    python volcar_fila.py 20614684772 APIINMOA
    python volcar_fila.py 20614684772 APIINMOA --fila 1

La clave se pide sin mostrarla y no se guarda.
"""

import getpass
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sol_buzon as puente  # noqa: E402


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    ruc, usuario = sys.argv[1].strip(), sys.argv[2].strip()
    indice = 0
    if "--fila" in sys.argv:
        indice = int(sys.argv[sys.argv.index("--fila") + 1]) - 1

    clave = os.environ.get("SOL_CLAVE") or getpass.getpass(
        f"Clave SOL de {usuario} (no se muestra ni se guarda): "
    )

    sesion = puente.Sesion(timeout=60)
    try:
        sesion.tls, _ = puente.detectar_contexto_tls(
            "casillaelectronica.sunafil.gob.pe", timeout=20
        )
        sesion.usar_tls(sesion.tls)
        code, state = puente.autenticar_sunafil(ruc, usuario, clave, sesion=sesion)
        entrada = (
            f"{puente.URL_CASILLA_SUNAFIL}{puente.RUTA_REDIRECT_SUNAFIL}"
            f"?state={__import__('urllib.parse', fromlist=['quote']).quote(state)}"
            f"&code={__import__('urllib.parse', fromlist=['quote']).quote(code)}"
        )
        sesion.get(entrada)
        html = puente.abrir_bandeja_sunafil(sesion, "Acciones Previas")
    except puente.SolError as e:
        print(f"\nFALLÓ: {e.mensaje}")
        if e.detalle:
            print(f"Detalle: {e.detalle[:500]}")
        return 1

    filas = [f for f in re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.S) if "<td" in f]
    print(f"\nFilas encontradas: {len(filas)}")
    if not filas:
        print("La tabla está vacía: no hay nada que volcar.")
        return 0

    if indice >= len(filas):
        print(f"No existe la fila {indice + 1}. Usa 1..{len(filas)}.")
        return 1

    fila = filas[indice]
    print(f"\n{'=' * 72}\nFILA {indice + 1} — HTML CRUDO\n{'=' * 72}\n")
    print(fila.strip())

    print(f"\n{'=' * 72}\nCELDAS UNA A UNA\n{'=' * 72}")
    for numero, celda in enumerate(re.findall(r"<td[^>]*>(.*?)</td>", fila, re.S), 1):
        limpio = re.sub(r"\s+", " ", puente._texto_celda(celda))[:100]
        etiquetas = sorted(set(re.findall(r"<(\w+)", celda)))
        tiene_onclick = "onclick" in celda.lower()
        print(f"\n{numero}) texto: {limpio!r}")
        print(f"   etiquetas: {etiquetas}  onclick: {'sí' if tiene_onclick else 'no'}")
        if tiene_onclick or "href" in celda.lower():
            print(f"   crudo: {re.sub(chr(10), ' ', celda).strip()[:400]}")

    accion = puente._descarga_de_fila(fila)
    print(f"\n{'=' * 72}")
    print(f"Acción detectada: {accion if accion else 'NINGUNA'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
