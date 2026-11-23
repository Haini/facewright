#!/bin/bash
# Healthy means "the app answers", not "a process runs". Checking only frpc made the container healthy about 10 s
# before uvicorn listened (the model loads first), and a request tunnelled in that gap hung: frpc cannot dial the
# socket and leaves the connection open. /heartbeat is the one route the auth middleware lets through unsigned.

if [ -f /frpc.toml ] && [ -n "$HP_SHARED_KEY" ]; then
  # HaRP mode: frpc carries the traffic, uvicorn listens on the unix socket (nc_py_api's run_app()).
  pgrep -x "frpc" > /dev/null || exit 1
  exec curl -fsS --max-time 1 -o /dev/null \
    --unix-socket "${HP_EXAPP_SOCK:-/tmp/exapp.sock}" http://localhost/heartbeat
fi

# Direct mode (dev's compose stack): uvicorn listens on APP_HOST:APP_PORT. 0.0.0.0 is reachable as 127.0.0.1.
HOST="${APP_HOST:-127.0.0.1}"
[ "$HOST" = "0.0.0.0" ] && HOST=127.0.0.1
exec curl -fsS --max-time 1 -o /dev/null "http://${HOST}:${APP_PORT}/heartbeat"
