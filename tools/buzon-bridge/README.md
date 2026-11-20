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

## Diagnóstico

`GET /salud` no usa credenciales: comprueba que el puente llega al Menú SOL.

```bash
curl http://127.0.0.1:12002/salud
# {"puente": "ok", "sunat_alcanzable": true, "detalle": ""}
```

Si `sunat_alcanzable` es `false`, el problema es de red y no de la Clave SOL.

## Cómo autentica (verificado el 2026-11-10)

El Menú SOL migró a OAuth2. Un POST directo a `j_security_check` con
`RUC/usuario/clave` **ya no funciona** (devuelve 404). El flujo real:

1. `GET MenuInternet.htm` → un JS con `redirect("<url>")`.
2. `GET` esa URL → 302 a `/oauth2/loginMenuSol?state=...`, deja cookies.
3. `POST /oauth2/j_security_check` con `tipo=2`, `custom_ruc`, `j_username`,
   `j_password`, `originalUrl`, `lang` y `state`.
   Credenciales buenas → `AutenticaMenuInternet.htm`; malas → `/oauth2/error`.
4. Con la sesión abierta se busca el enlace del buzón en el menú.

Dos detalles que cuestan tiempo si no se saben:

- `state` y `originalUrl` no están en el HTML del formulario: hay que sacarlos
  de la query del paso 2.
- SUNAT corta la conexión (RST) si el `User-Agent` no parece un navegador. El
  puente fija uno de Chrome; con el UA por defecto de Python no hay respuesta.

## Cuidado con el captcha

Tras **3 intentos fallidos** SUNAT empieza a pedir captcha y el login
automatizado deja de funcionar. Por eso el puente hace **un solo intento** por
petición y no reintenta nunca. Si la clave es incorrecta, hay que corregirla;
insistir solo bloquea la cuenta.

## Estado

Autenticación verificada contra SUNAT: el rechazo de credenciales inválidas se
detecta correctamente. La lectura del buzón (`buscar_buzon`) localiza el enlace
del buzón en el menú y tolera tanto JSON como tablas HTML, pero **no se ha
podido probar con credenciales válidas**. Si SUNAT cambia el menú, ajusta
`PISTAS_BUZON`.
