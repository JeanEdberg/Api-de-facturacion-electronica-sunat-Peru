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

### Descargar el documento de una fila

`POST /documento` con el mismo JSON y dos campos más:

```json
{ "ruc": "...", "usuario": "...", "clave": "...",
  "bandeja": "acciones_previas", "indice": 0 }
```

`bandeja` es `notificaciones` o `acciones_previas`; `indice` empieza en 0 y es
el mismo orden que devuelve `POST /buzon`. Responde el archivo con su
`Content-Type` real y `Content-Disposition`, o un error JSON si esa fila no
trae documento.

La sesión no se guarda entre peticiones: cada descarga repite el login. Es más
lento, pero no deja credenciales ni cookies vivas en el puente.

Las filas que traen documentos lo indican en `documentos`, con los tipos que
ofrecen:

```json
{ "registro": "...", "documentos": ["carta", "constancia_acuse", "constancia_deposito"] }
```

Tipos posibles: `carta` (el PDF de la carta inductiva), `constancia_deposito`
y `constancia_acuse`. Para descargar uno, añade `"tipo"` al POST:

```json
{ "ruc": "...", "usuario": "...", "clave": "...",
  "bandeja": "acciones_previas", "indice": 0, "tipo": "constancia_deposito" }
```

Si una fila no trae `documentos`, SUNAFIL no ofrece ninguno por esa vía.

Los documentos son botones `<button type="submit" name="...">`, no enlaces:
se descargan enviando el formulario con ese botón pulsado. El `name` lleva un
`j_idtNN` que cambia en cada despliegue, así que se lee de la página en el
momento y no se guarda.

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

## La casilla de SUNAFIL es otro sistema

El buzón de SUNAFIL **no** se lee por el visor de SUNAT. Su casilla
(`https://casillaelectronica.sunafil.gob.pe/si.inbox/`) es una aplicación JSF
sobre Tomcat —de ahí el `;jsessionid=` en las URLs— con un flujo de entrada
distinto, aunque use la misma Clave SOL de la empresa.

La pantalla `/si.inbox/Login/Empresa` no tiene login propio: redirige al SSO de
SUNAT con el `client_id` de SUNAFIL
(`b6474e23-8a3b-4153-b301-dafcc9646250`). El SSO devuelve un `code` OAuth2 que
la casilla canjea. El puente reproduce ese paso:

1. `GET /v1/clientessol/<client_id>/oauth2/login?originalUrl=<casilla>/si.inbox/Login/Empresa&state=s`
   devuelve el formulario `j_security_check` y su `state`.
2. `POST /v1/clientessol/<client_id>/oauth2/j_security_check` con `tipo=2`
   (modo RUC), `custom_ruc`, `j_username`, `j_password`, `originalUrl` y
   `state`. Con credenciales válidas responde `302` al `originalUrl` con un
   `code`; con credenciales rechazadas, `302` a `/oauth2/error`.

**Un detalle importante:** el SSO cuenta los fallos por IP/sesión y a los 3
activa un captcha que el puente no puede resolver. Por eso
`autenticar_sunafil()` hace **un solo intento** y no reintenta. Si aparece el
captcha, hay que esperar a que caduque antes de volver a probar.

`/salud` informa `sunat_alcanzable` y `sunafil_alcanzable` por separado: son
hosts distintos y el de SUNAFIL suele estar inaccesible desde fuera de Perú.
Incluye además `sunafil_tls`, que dice qué configuración TLS negoció (o por qué
no lo consiguió).

### El TLS antiguo de SUNAFIL

La casilla corre sobre un Tomcat viejo (`Apache-Coyote/1.1`) que solo negocia
cifrados que OpenSSL 3 ya no ofrece a su nivel por defecto. Con Python 3.13+ la
conexión falla con `SSLV3_ALERT_HANDSHAKE_FAILURE` aunque el host esté
accesible y `curl` funcione.

`detectar_contexto_tls()` prueba varias configuraciones en orden —por defecto,
`SECLEVEL=1`, `SECLEVEL=0` y `SECLEVEL=0` con TLS 1.0— y usa la primera que
negocie. Se aplica **solo a la sesión de SUNAFIL**: el Menú SOL de SUNAT sigue
usando el contexto por defecto, y la verificación de certificados nunca se
desactiva. Los grabadores de tráfico deben registrarse con
`Sesion.agregar_handler()`, porque `usar_tls()` reconstruye el opener.

### Estado de la lectura de SUNAFIL

La autenticación está **verificada contra el SSO real**: una clave incorrecta
devuelve el mensaje de credenciales rechazadas y una correcta obtendría el
`code`. La **lectura de la bandeja no está implementada**: falta capturar con
una sesión real la petición con la que `si.inbox` lista las notificaciones.
Mientras tanto `leer_buzon_sunafil()` falla con un mensaje explícito en vez de
devolver una lista vacía, porque un buzón vacío y uno inaccesible no son lo
mismo. Para cerrarlo hace falta ejecutar el puente desde una máquina con salida
a internet en Perú y capturar esa petición.

### Cómo desbloquear la lectura desde un PC en Perú

`capturar_sunafil.py` hace el login y guarda todo el tráfico de la casilla en
una carpeta, para poder ver con qué petición `si.inbox` lista las
notificaciones:

```bash
python3 capturar_sunafil.py 20614684772 MODDATOS
```

Pide la Clave SOL con `getpass` (no se muestra ni queda en el historial) y crea
`captura-sunafil-<fecha>/` con un `index.txt` y un archivo por respuesta. Si la
bandeja se carga por AJAX, la lista aparece como un `.json` en esa carpeta.

La carpeta **contiene cookies de sesión**: son credenciales. Compártela solo con
quien implemente el lector y bórrala al terminar. Si el script no ve la lista,
repite la captura con las Herramientas de Desarrollo del navegador en la
pestaña Red mientras abres la bandeja.
