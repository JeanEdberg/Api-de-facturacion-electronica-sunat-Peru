# Descarga de documentos de la casilla SUNAFIL

Cómo funciona `descargar_documento()` en `sol_buzon.py`, paso a paso, para
poder revisarlo a mano contra el navegador.

## El problema

La casilla de SUNAFIL no tiene una URL por documento. No existe algo como
`/documentos/123.pdf`. Todo es un formulario JSF/PrimeFaces: el servidor
guarda el estado de la pantalla y espera que el cliente le devuelva ese estado
en cada petición.

Eso obliga a **imitar al navegador**: abrir la pantalla, leer el estado, y
enviar el formulario con el botón pulsado.

## Los pasos que da el código

Cada paso es una petición HTTP real. El script `trazar_descarga.py` los imprime
todos, con lo que se envía y lo que llega.

### 1. Login (OAuth2 del Menú SOL)

```
GET  /si.inbox/Login/Empresa?state=...&code=...
```

Se obtiene el `code` y el `state` del flujo OAuth2 de SUNAT. La sesión queda en
una cookie, que `Sesion` conserva para el resto del proceso.

### 2. Abrir la bandeja

```
GET  /si.inbox/Inicio/Empleador        -> página de inicio con el menú
POST /si.inbox/Inicio/Empleador        -> «Acciones Previas»
```

La bandeja no tiene ruta GET propia: se abre pulsando la opción del menú, que
es un POST con el `name` del enlace y el `ViewState` de la página de inicio.

**Lo que devuelve** es el HTML de la bandeja, con la tabla de acciones y los
botones de documento de cada fila.

### 3. Localizar la fila y su botón

Se parsea la tabla `formactosAdministrativos:dtAlertas` y se saca, por fila,
qué documentos ofrece:

| tipo | cómo se reconoce |
|---|---|
| `carta` | icono `fa-file-pdf-o`, **sin** `title` |
| `constancia_deposito` | `title` con «Constancia de Depósito» |
| `constancia_acuse` | `title` con «Acuse de Recibo» |

Los botones deshabilitados se descartan: el del acuse de lectura ya se usó.

### 4. Pulsar el botón del documento

Aquí está la parte que más problemas ha dado. Hay **dos modos** y hay que
distinguirlos:

**Constancias** — no tienen `onclick`. Son submit normal:

```
POST <url de la bandeja>
  javax.faces.ViewState = <el de la bandeja>
  formactosAdministrativos:dtAlertas:0:j_idt110 = (mismo nombre)
```

**Carta** — su `onclick` acaba en `return false`, que cancela el submit normal.
Exige POST AJAX:

```
POST <url de la bandeja>
  javax.faces.ViewState = <el de la bandeja>
  formactosAdministrativos:dtAlertas:0:j_idt108 = (mismo nombre)
  javax.faces.source          = <mismo nombre>
  javax.faces.partial.execute = <mismo nombre>
  javax.faces.partial.render  = @all
```

El `Content-Type` se reconoce por la cabecera y por los primeros bytes. **Un
PDF de verdad empieza por `%PDF-`**; si no, no se acepta.

### 5. Seguir el diálogo (solo la carta)

La carta no devuelve el PDF en el paso 4: devuelve un diálogo
(`formDescargaDoc`) con el aviso *«para poder leer el documento, favor de
descargar el documento»* y un botón «Descargar».

```
POST <action del formulario formDescargaDoc>
  javax.faces.ViewState = <el más reciente>
  formDescargaDoc:j_idt179 = (mismo nombre)
```

Ese formulario trae **tres botones** y no se pueden distinguir por el `name`
(todos cuelgan de `formDescargaDoc`, así que todos contienen «descarg»). Se
ordenan por su **texto visible** y se prueban en orden hasta que uno devuelva
un PDF:

1. «Descargar»
2. icono suelto, sin texto
3. «Ver Documento» (último recurso)

«Cerrar», «Cancelar» y «Volver» se descartan.

## Cómo revisarlo a mano

### Traza de todas las peticiones

```powershell
python trazar_descarga.py 20614684772 APIINMOA
```

Imprime cada petición con sus campos y guarda cada respuesta en `traza/`.
Es lo más útil para comparar con el navegador.

### Respuesta cruda cuando falla

Si algo falla, el error guarda la respuesta en `respuesta-<tipo>.html`:

```powershell
python mirar_respuesta.py respuesta-carta.html
```

Dice si es HTML, XML de JSF o binario, y lista las direcciones candidatas.

### Comparar con el navegador

En Chrome o Edge, con F12 abierto en la pestaña **Red**:

1. Entra a la casilla y abre «Acciones Previas».
2. Pulsa el icono de PDF de la carta.
3. Mira la petición que aparece: método, URL, y **la pestaña «Carga útil»**
   (los campos enviados).
4. Compara con lo que imprime `trazar_descarga.py` en el paso 4.

Si la URL o los campos no coinciden, ahí está el fallo.

## La carta usa campos fijos (carta_sunafil.py)

Deducir los campos del HTML no funcionaba: SUNAFIL respondía 500 dentro de la
vista `/jsfDocs/modulos/...`. Los campos reales están copiados de la petición
del navegador y viven en `carta_sunafil.py`.

**Paso 4** — además del botón y el `ViewState`, el navegador envía:

| campo | valor |
|---|---|
| `formactosAdministrativos` | `formactosAdministrativos` |
| `...:fecInicial`, `...:fecFinal` | vacío |
| `...:cbEstadoInner`, `...:cbTipReqInner` | `-1` |
| `...:dtAlertas_length` | `10` |
| `javax.faces.partial.ajax` | `true` |
| `javax.faces.partial.event` | `click` |
| `javax.faces.partial.execute` | `<botón> formactosAdministrativos` |

Y **no** envía `javax.faces.partial.render`, que el puente mandaba de más.

**Paso 5** — el botón es siempre `formDescargaDoc:btnverDocumento` con valor
**vacío**. No es un `j_idtNN` generado, y no hay que adivinarlo entre varios.
Además se envía `formDescargaDoc` y
`formDescargaDoc_panelDescargaDoc_collapsed=false`.

La hipótesis del 500: si el paso 4 no se procesa como AJAX de PrimeFaces, el
servidor no registra qué fila se eligió; el paso 5 llega sin documento
seleccionado y la vista falla por dentro.

Las constancias no pasan por aquí: siguen con el envío normal del formulario.

## Estado conocido

| documento | estado |
|---|---|
| `constancia_deposito` | descarga un PDF válido |
| `constancia_acuse` | descarga un PDF válido |
| `carta` | usa los campos exactos del navegador (pendiente de confirmar contra SUNAFIL real) |

El 500 viene del servidor de SUNAFIL, no del cliente: la acción **se ejecuta**
(por eso ya no devuelve la página de la bandeja), pero falla dentro. Falta
averiguar qué espera esa vista.

## Detalles que importan

- **`ViewState`**: es de la vista, no del formulario. Los diálogos de
  PrimeFaces no lo llevan dentro; hay que tomarlo de la página. Y hay que usar
  el **más reciente**: el de una respuesta anterior ya caducó.
- **`Content-Type`**: no basta para decidir si llegó un PDF. Se comprueban los
  bytes.
- **Una sesión por descarga**: el login se repite en cada llamada porque no se
  guarda entre peticiones.
