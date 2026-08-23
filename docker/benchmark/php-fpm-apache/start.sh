#!/bin/sh
# Starts php-fpm in the background, then runs Apache in the foreground so
# the container's PID 1 tracks the web server's lifecycle.
set -e
php-fpm -D
exec apache2ctl -D FOREGROUND
