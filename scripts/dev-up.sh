#!/usr/bin/env bash
# Levanta los tres servicios del entorno local: backend (12001), puente del
# buzón (12002) y frontend (12000). Los logs quedan en /tmp/*.log.
#
#   ./scripts/dev-up.sh          # arranca
#   ./scripts/dev-up.sh stop     # detiene
#
# La Clave SOL no se pasa aquí: se guarda una vez con
#   php artisan sunat:clave-sol <RUC> --usuario=<USUARIO_SOL> --probar
# y el puente la lee de la base de datos en cada consulta.

set -u
exec < /dev/null

RAIZ_BACKEND="${RAIZ_BACKEND:-/tmp/api-sunat}"
RAIZ_FRONTEND="${RAIZ_FRONTEND:-/workspace/project}"
PUERTO_BACKEND=12001
PUERTO_PUENTE=12002
PUERTO_FRONTEND=12000
TOKEN_PUENTE="${BUZON_BRIDGE_TOKEN:-puente-local}"

detener() {
    for patron in "artisan serve --port=${PUERTO_BACKEND}" \
                  "buzon-bridge/sol_buzon.py" \
                  "vite"; do
        pids=$(pgrep -f "$patron" || true)
        if [ -n "$pids" ]; then
            echo "deteniendo: $patron"
            kill $pids 2>/dev/null || true
        fi
    done
}

if [ "${1:-}" = "stop" ]; then
    detener
    exit 0
fi

detener
sleep 1

# `setsid` desacopla cada servicio de esta terminal: así el script termina y
# los procesos siguen vivos con su salida redirigida al log.
echo "== backend (${PUERTO_BACKEND}) =="
(cd "$RAIZ_BACKEND" && setsid php artisan serve --port="$PUERTO_BACKEND" \
    < /dev/null > /tmp/backend.log 2>&1 &)

echo "== puente del buzon (${PUERTO_PUENTE}) =="
(cd "$RAIZ_BACKEND" && BUZON_BRIDGE_PORT="$PUERTO_PUENTE" \
    BUZON_BRIDGE_TOKEN="$TOKEN_PUENTE" \
    setsid python3 tools/buzon-bridge/sol_buzon.py < /dev/null > /tmp/puente.log 2>&1 &)

echo "== frontend (${PUERTO_FRONTEND}) =="
(cd "$RAIZ_FRONTEND" && setsid npm run dev < /dev/null > /tmp/frontend.log 2>&1 &)

echo "esperando a que levanten..."
sleep 8

echo
echo "-- backend --"
curl -s -o /dev/null -w "  /api/auth/login responde %{http_code}\n" \
    -X POST "http://127.0.0.1:${PUERTO_BACKEND}/api/auth/login" \
    -H "Content-Type: application/json" -d '{}' || echo "  sin respuesta"

echo "-- puente --"
curl -s --max-time 60 "http://127.0.0.1:${PUERTO_PUENTE}/salud" | head -c 200
echo

echo "-- frontend --"
curl -s -o /dev/null -w "  / responde %{http_code}\n" \
    "http://127.0.0.1:${PUERTO_FRONTEND}/" || echo "  sin respuesta"

echo
echo "logs: /tmp/backend.log /tmp/puente.log /tmp/frontend.log"
echo "token del puente: ${TOKEN_PUENTE}"
