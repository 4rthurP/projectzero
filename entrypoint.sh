#!/bin/bash
set -e

# Start cron
service cron start

# Outside dev (same rule as pz\Config::env(): anything but ENV=dev|development is prod), keep PHP
# warnings/deprecations out of responses, where they corrupt JSON endpoints; they go to the
# container logs instead.
if ! grep -qiE "^ENV=['\"]?(dev|development)['\"]?[[:space:]]*$" .env 2>/dev/null; then
    cat > /usr/local/etc/php-fpm.d/zz-prod.conf <<'EOF'
[www]
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
catch_workers_output = yes
EOF
fi

# Start PHP-FPM in the foreground
exec php-fpm --nodaemonize
