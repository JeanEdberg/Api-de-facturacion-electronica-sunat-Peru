# Contexto del proyecto

Backend Laravel 12 + Sanctum para facturación electrónica SUNAT (Perú).
SQLite en `database/database.sqlite`. Frontend aparte en `/workspace/project`
(Vite + React, proxy `/api` → `127.0.0.1:12001`).

## Comandos

```bash
php artisan test                      # suite completa (126 tests)
php artisan test --filter=BuzonTest   # solo el buzón
./scripts/dev-up.sh                   # backend 12001 + puente 12002 + frontend 12000
./scripts/dev-up.sh stop              # detener los tres
```

Login de los usuarios seed: `POST /api/auth/login` (**sin** `v1`), token en
`access_token`. `admin@sunatapi.com` / `admin123456`.

## Buzón SUNAT: cosas que cuestan tiempo redescubrir

- **El Menú SOL es OAuth2.** Un POST directo a `j_security_check` con
  `RUC/usuario/clave` devuelve **404**. El flujo real está documentado en
  `tools/buzon-bridge/README.md` y lo implementa `sol_buzon.py`.
- **`state` y `originalUrl` no están en el HTML** del formulario: hay que
  sacarlos de la query de la URL `loginMenuSol`.
- **SUNAT corta la conexión (RST) con User-Agent que no parezca navegador.**
- **Captcha tras 3 intentos fallidos.** Nunca reintentar el login en bucle:
  un solo intento por petición. Probado: insistir bloquea la cuenta.
- **Nunca probar credenciales falsas contra el RUC real.** Gasta intentos de
  la cuenta del cliente. Usar un RUC ficticio con dígito verificador válido,
  por ejemplo `20123456786`.
- `GET /salud` del puente comprueba el acceso a SUNAT sin credenciales.
- **El buzón no es un enlace del menú.** `#aOpcionBuzon` tiene `href="#"` y lo
  abre por JavaScript en un iframe. La ruta real es
  `MenuInternet.htm?action=buzon&s=<subdominio>` → visor
  `ww1.sunat.gob.pe/ol-ti-itvisornoti/visor/master`. El subdominio sale de
  `a/dominios.json` (`sdm`); sin `&s=` el menú responde "Error en la invocación".
- **Los datos del buzón se piden por AJAX**, no vienen en el HTML:
  `GET /ol-ti-itvisornoti/visor/listNotiMenPag` con `X-Ruc: <RUC>` y
  `tipoMsj` (1 = notificaciones, 2 = mensajes). Raspar tablas HTML del visor
  no devuelve nada y produce el 502 "no devolvió datos legibles".
- **En la respuesta de `listNotiMenPag`, `total` son páginas y `records` son
  registros.** Paginar con `total` corta la lista en la primera página.
- **`notificaciones.fecha` es DATE.** Hay que convertir las fechas de SUNAT
  (`dd/mm/yyyy hh:mm:ss`) a `yyyy-mm-dd` antes de insertar.

## Secretos

`clave_sol` y `buzon_token` van cifrados en `companies` (cast `encrypted`).
Se escriben con `php artisan sunat:clave-sol <RUC> --usuario=<USUARIO_SOL>
--probar`, que pide la clave sin hacer eco. No pasarlas por chat ni por
argumentos de línea de comandos.

Al usar el cast `encrypted` en una migración hay que emplear
`Crypt::encryptString` / `Crypt::decryptString`, no `encrypt`/`decrypt`: estos
últimos serializan y el modelo acaba devolviendo `s:8:"clave";`.
