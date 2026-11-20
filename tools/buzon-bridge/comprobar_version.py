#!/usr/bin/env python3
"""Comprueba que el puente que responde es el código que acabas de copiar.

Copiar los `.py` no cambia un proceso que ya está corriendo: el servidor sigue
con el código viejo en memoria y devuelve los mismos errores de siempre. Este
script compara la huella del archivo en disco con la que responde el servidor.

Uso:
    python comprobar_version.py            # puerto 8080
    python comprobar_version.py 8081
"""

import hashlib
import json
import os
import sys
import urllib.error
import urllib.request


def huella_del_archivo():
    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "sol_buzon.py"), "rb") as f:
        return hashlib.sha256(f.read()).hexdigest()[:12]


def main():
    puerto = sys.argv[1] if len(sys.argv) > 1 else "8080"
    disco = huella_del_archivo()
    print(f"Archivo en disco : {disco}")

    try:
        with urllib.request.urlopen(
            f"http://localhost:{puerto}/salud", timeout=45
        ) as r:
            datos = json.loads(r.read().decode("utf-8", "ignore"))
    except urllib.error.URLError as e:
        print(f"Servidor         : no responde en el puerto {puerto} ({e.reason})")
        print("\nArráncalo con:  python sol_buzon.py " + puerto)
        return 1

    vivo = datos.get("huella", "(sin huella: versión vieja)")
    print(f"Servidor en {puerto}  : {vivo}")

    if vivo != disco:
        print("\nNO COINCIDEN. El servidor que responde es código viejo.")
        print("Para el servidor con Ctrl+C y arráncalo otra vez:")
        print(f"    python sol_buzon.py {puerto}")
        return 1

    print("\nCoinciden: el servidor corre el código que tienes en disco.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
