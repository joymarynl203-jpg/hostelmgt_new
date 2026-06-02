#!/bin/bash
set -euo pipefail

# Render (and similar platforms) assign a dynamic port.
PORT="${PORT:-8080}"
export PORT

if grep -q '^Listen 80' /etc/apache2/ports.conf 2>/dev/null; then
    sed -i "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
fi

if [ -f /etc/apache2/sites-available/000-default.conf ]; then
    sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
