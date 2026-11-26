#!/usr/bin/env bash
# Instala paquetes de apt en los runners de CI sin que un mirror lento agote el tiempo del job.
# Uso: scripts/ci/apt-install.sh ffmpeg [poppler-utils ...]
#
# El mirror de Ubuntu del runner va lento a ratos: una descarga colgada llegó a tardar 25 minutos en instalar ffmpeg y
# el job se canceló por su timeout. Aquí cada petición HTTP tiene un timeout corto y apt reintenta, así que un fallo de
# red se corta y se repite en vez de bloquear. La configuración se deja en /etc/apt/apt.conf.d para que la usen también
# los apt-get posteriores del mismo job (por ejemplo `npx playwright install --with-deps`).
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "Uso: $0 paquete [paquete ...]" >&2
    exit 2
fi

sudo tee /etc/apt/apt.conf.d/80-ci-network >/dev/null <<'EOF'
Acquire::Retries "5";
Acquire::http::Timeout "30";
Acquire::https::Timeout "30";
Acquire::ftp::Timeout "30";
APT::Install-Recommends "false";
EOF

for attempt in 1 2 3; do
    if sudo apt-get update -q && sudo apt-get install -y -q "$@"; then
        exit 0
    fi
    echo "apt-get falló (intento $attempt de 3); se reintenta en 15 s." >&2
    sleep 15
done

echo "No se pudieron instalar: $*" >&2
exit 1
