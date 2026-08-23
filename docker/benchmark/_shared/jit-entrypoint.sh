#!/bin/sh
# Shared entrypoint for every benchmark runtime image. Toggles opcache.jit
# from the BENCH_JIT env var (on|off, default off) by writing a conf.d file,
# then execs the runtime's own CMD (php-fpm+webserver, frankenphp, swoole).
set -e

JIT_INI=/usr/local/etc/php/conf.d/zz-jit.ini
mkdir -p "$(dirname "$JIT_INI")"

if [ "${BENCH_JIT:-off}" = "on" ]; then
    cat > "$JIT_INI" <<'EOF'
opcache.enable = 1
opcache.enable_cli = 1
opcache.jit_buffer_size = 64M
opcache.jit = tracing
EOF
else
    cat > "$JIT_INI" <<'EOF'
opcache.enable = 1
opcache.enable_cli = 1
opcache.jit = disable
EOF
fi

exec "$@"
