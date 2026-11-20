# Puente del buzón SUNAT (Menú SOL)

SUNAT no publica un API del buzón electrónico. Este proceso pequeño entra al
Menú SOL con el RUC y la Clave SOL de cada empresa, lee el buzón y lo devuelve
en JSON. El backend lo consulta como si fuera cualquier otro endpoint del
buzón, así que no hay nada especial en Laravel.

## Arranque

```bash
BUZON_BRIDGE_TOKEN=un-secreto-largo python3 sol_buzon.py
```

Escucha en `0.0.0.0:12002` por defecto. Solo usa la biblioteca estándar, así que
no hay que instalar dependencias.

| Variable | Por defecto | Para qué |
| --- | --- | --- |
| `BUZON_BRIDGE_PORT` | `12002` | Puerto de escucha |
| `BUZON_BRIDGE_HOST` | `0.0.0.0` | Interfaz de escucha |
| `BUZON_BRIDGE_TOKEN` | *(vacío)* | Si se define, exige `Authorization: Bearer` |

## Contrato

`POST /buzon` con JSON:

```json
{ "ruc": "20614684772", "usuario": "USUARIO_SOL", "clave": "CLAVE_SOL", "entidad": "SUNAT" }
```

Éxito:

```json
{ "data": [ { "entidad": "SUNAT", "codigo": "...", "asunto": "...", "fecha": "..." } ] }
```

Error, siempre con un mensaje pensado para el usuario:

```json
{ "error": "SUNAT rechazó las credenciales SOL del RUC ...", "detalle": "<html crudo>" }
```

El campo `detalle` conserva el texto original de SUNAT para diagnóstico. El
backend no lo muestra en la interfaz: guarda `error` como mensaje y descarta el
HTML.

## Configuración en el ERP

1. Guarda la fuente en el panel del buzón: endpoint `https://<host>:12002/buzon`,
   el token del puente y la Clave SOL real de la empresa.
2. Pulsa **Probar conexión**. Un 200 con `conectado: true` confirma que el
   puente entró a SUNAT con esas credenciales.

La Clave SOL viaja en el cuerpo del POST, nunca en la query, para que no quede
escrita en los logs de acceso.

## Estado

El puente resuelve la autenticación y la lectura del buzón. Las rutas concretas
del Menú SOL (`LOGIN` y `BUZON` en el script) son las que hay que ajustar si
SUNAT cambia su HTML o sus endpoints, porque no están documentadas.
