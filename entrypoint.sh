#!/bin/bash
set -e

# Start cron
service cron start

# Start PHP-FPM in the foreground
exec php-fpm --nodaemonize
