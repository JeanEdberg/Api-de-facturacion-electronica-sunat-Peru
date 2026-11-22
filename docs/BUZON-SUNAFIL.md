# Buzón SUNAFIL: acuse de recibo y plazos

## La regla que manda sobre todo lo demás

**Abrir el documento de una fila de la casilla de SUNAFIL registra el acuse de
recibo.** No es una descarga: es una declaración con efectos legales, y arranca
el plazo para responder al requerimiento. Por eso:

- **Sincronizar sólo lee.** `POST /api/v1/notificaciones/sincronizar` no pulsa
  ningún botón de documento.
- **La carta sólo se baja desde `abrir`.** Pedirla por la descarga normal
  devuelve 409 con `requiere_apertura: true`.
- **Abrir exige permiso y confirmación.** `Gate::allows('abrir-notificacion-sunafil')`
  y `confirmo=true`; sin ambos no se toca la casilla.
- **Las constancias no tienen efecto legal** y sí se pueden bajar bajo demanda.

## Flujo

1. `buzon:sincronizar` (o el botón Sincronizar) lee la bandeja y guarda las
   filas en `notificaciones`: registro, fechas, plazo, fecha límite y qué
   documentos ofrece cada fila.
2. El panel avisa de las notificaciones sin acuse a las que se les acaba el
   plazo estimado (`alertas.acuse`).
3. El responsable pulsa **Abrir y notificarse**, confirma, y el backend:
   - anota quién y cuándo en `apertura_solicitada_por` / `apertura_solicitada_en`
     **antes** de pulsar;
   - pide la carta al puente;
   - si sale bien, marca `con_acuse = true` y baja las constancias;
   - si la carta falla, **no** marca el acuse y avisa de que pudo quedar
     registrado igual en SUNAFIL.

## El índice de fila no identifica nada

El puente localiza la fila por su posición en la tabla. Si SUNAFIL reordena la
bandeja, el índice guardado apunta a otra notificación y se abriría la
equivocada. Para evitarlo, el backend manda `registro_esperado` y el puente
compara con el registro real de la fila **antes de pulsar**: si no coincide,
responde 409 y no pulsa nada.

## Plazos

Las fechas que calcula el ERP son estimaciones: días hábiles desde los
«efectos del depósito» (`config/buzon.php`). La fecha buena es la que publique
SUNAFIL.

- `BUZON_DIAS_ACUSE` (por defecto 5)
- `BUZON_DIAS_AVISO` (por defecto 2)
- `BUZON_FERIADOS` (`Y-m-d,Y-m-d,...`)

Mantén los feriados al día y confirma el cómputo con tu asesor laboral.

## Seguridad del puente

El puente recibe la Clave SOL y devuelve PDFs de la casilla. No lo expongas a
la red: `BUZON_BRIDGE_HOST=127.0.0.1`, token obligatorio y firewall. Excluye
`/bandeja` y `/documento` de Telescope, debugbar y logs HTTP.

## Límites conocidos

- Cada documento descargado es un login SOL completo: el puente no reutiliza
  sesión, así que abrir una notificación son tres logins (carta y constancias).
- No se ha visto todavía una fila sin acuse en la tabla real: la primera vez
  que aparezca, comprueba que quede `con_acuse = false`.
