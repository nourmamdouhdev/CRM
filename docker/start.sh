#!/bin/bash
set -euo pipefail

PORT="${PORT:-80}"
APP_ENV="${APP_ENV:-production}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-tagom_crm}"
DB_USER="${DB_USER:-tagom}"
DB_CHARSET="${DB_CHARSET:-utf8mb4}"

export APP_ENV
export DB_HOST DB_NAME DB_USER DB_CHARSET
export APP_SECURE="${APP_SECURE:-1}"
export APP_SAMESITE="${APP_SAMESITE:-Lax}"

if [ -z "${APP_KEY:-}" ]; then
  if [ -f /var/lib/mysql/.crm_app_key ]; then
    APP_KEY="$(cat /var/lib/mysql/.crm_app_key)"
  else
    APP_KEY="$(openssl rand -hex 32)"
  fi
fi
export APP_KEY

if [ -z "${DB_PASS:-}" ]; then
  if [ -f /var/lib/mysql/.crm_db_pass ]; then
    DB_PASS="$(cat /var/lib/mysql/.crm_db_pass)"
  else
    DB_PASS="$(openssl rand -hex 12)"
  fi
fi
export DB_PASS

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/run/mysqld /var/lib/mysql /var/www/html/storage/logs
chown -R mysql:mysql /var/run/mysqld /var/lib/mysql
chmod 777 /var/www/html/storage /var/www/html/storage/logs

if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --skip-test-db >/tmp/mariadb-install.log 2>&1
fi

echo -n "${APP_KEY}" > /var/lib/mysql/.crm_app_key
echo -n "${DB_PASS}" > /var/lib/mysql/.crm_db_pass
chmod 600 /var/lib/mysql/.crm_app_key /var/lib/mysql/.crm_db_pass

cat >/etc/mysql/mariadb.conf.d/99-crm.cnf <<'EOF'
[mysqld]
bind-address=127.0.0.1
skip-networking=0
innodb_buffer_pool_size=64M
performance_schema=OFF
skip-log-bin
max_connections=30
EOF

mysqld --user=mysql --datadir=/var/lib/mysql --bind-address=127.0.0.1 &
MYSQL_PID=$!

echo "Waiting for MariaDB..."
for i in $(seq 1 60); do
  if mysqladmin --socket=/run/mysqld/mysqld.sock ping --silent; then
    break
  fi
  if mysqladmin -h 127.0.0.1 -uroot ping --silent; then
    break
  fi
  sleep 1
  if [ "$i" -eq 60 ]; then
    echo "MariaDB failed to start" >&2
    tail -n 50 /tmp/mariadb-install.log 2>/dev/null || true
    exit 1
  fi
done

mysql --socket=/run/mysqld/mysqld.sock -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

TABLE_COUNT="$(mysql --socket=/run/mysqld/mysqld.sock -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")"
if [ "${TABLE_COUNT}" = "0" ]; then
  echo "Importing CRM schema..."
  mysql --socket=/run/mysqld/mysqld.sock -uroot "${DB_NAME}" < /var/www/html/database/schema.sql
fi

echo "Starting Apache on port ${PORT}"
exec apache2-foreground
