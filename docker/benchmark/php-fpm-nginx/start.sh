#!/bin/sh
# Starts php-fpm in the background, then runs Nginx in the foreground so
# the container's PID 1 tracks the web server's lifecycle.
set -e
php-fpm -D
exec nginx -g 'daemon off;'
