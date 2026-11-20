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

## Cómo se lee el buzón

El buzón no es un enlace estático: el menú lo abre por JavaScript en un iframe.
La ruta real es el visor de notificaciones:

1. `GET MenuInternet.htm?action=buzon&s=<subdominio>` redirige al visor
   `https://ww1.sunat.gob.pe/ol-ti-itvisornoti/visor/master`. El subdominio sale
   de `https://e-menu.sunat.gob.pe/a/dominios.json` (campo `sdm`). Sin `&s=` el
   menú responde "Error en la invocación".
2. El HTML del visor no trae datos: la lista se pide por AJAX a
   `GET /ol-ti-itvisornoti/visor/listNotiMenPag` con la cabecera
   `X-Ruc: <RUC>` y los parámetros `tipoMsj`, `codCarpeta`, `codEtiqueta`,
   `page`, `des_asunto`, `codMensaje`, `tipoOrden`.
   - `tipoMsj=1` notificaciones, `tipoMsj=2` mensajes.
   - Responde `{"total":<páginas>,"records":<registros>,"rows":[...]}`.
     Ojo: `total` es el número de páginas, no de registros.

`leer_buzon()` recorre ambas categorías y normaliza cada fila a
`{entidad, codigo, asunto, fecha, tipo, remitente, adjuntos, leido, urgente,
detalle}`. La fecha se convierte a `yyyy-mm-dd` porque la columna
`notificaciones.fecha` es de tipo `DATE`.

## Estado

Autenticación y lectura verificadas contra SUNAT con credenciales reales: el
puente devuelve las notificaciones y mensajes del buzón (probado con 51
elementos). Si SUNAT cambia el visor, el punto a revisar es `URL_VISOR` y la
ruta de `listNotiMenPag`.
