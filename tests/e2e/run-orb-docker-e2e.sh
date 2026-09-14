#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CACTI_REPO="${CACTI_REPO:-$(cd "${ROOT_DIR}/../cacti" && pwd)}"
TEMP_DIR="${TEMP_DIR:-/tmp/cacti-syslog-e2e}"
PW_IMAGE="${PW_IMAGE:-mcr.microsoft.com/playwright:v1.52.0-jammy}"

case "${TEMP_DIR}" in
	/tmp/?*)
		;;
	*)
		echo "Refusing to rm -rf TEMP_DIR outside /tmp: ${TEMP_DIR}" >&2
		exit 1
		;;
esac

rm -rf "${TEMP_DIR}"
mkdir -p "${TEMP_DIR}/plugins/syslog"

rsync -a --delete "${CACTI_REPO}/" "${TEMP_DIR}/"
rsync -a --delete "${ROOT_DIR}/" "${TEMP_DIR}/plugins/syslog/"

cat > "${TEMP_DIR}/.env" <<'EOF'
WEB_PORT=18080
PHP_MEMORY_LIMIT=512M
DB_ROOT_PASSWORD=root
DB_NAME=cacti
DB_USER=cacti
DB_PASSWORD=cacti
DB_PORT=13306
DB_MAX_CONNECTIONS=200
DB_BUFFER_POOL_SIZE=512M
TIMEZONE=UTC
EOF

cat > "${TEMP_DIR}/plugins/syslog/config.php" <<'EOF'
<?php

global $config, $database_type, $database_default, $database_hostname;
global $database_username, $database_password, $database_port, $database_retries;
global $database_ssl, $database_ssl_key, $database_ssl_cert, $database_ssl_ca;

$use_cacti_db = true;

if (!$use_cacti_db) {
	$syslogdb_type     = 'mysql';
	$syslogdb_default  = 'syslog';
	$syslogdb_hostname = 'localhost';
	$syslogdb_username = 'cactiuser';
	$syslogdb_password = 'cactiuser';
	$syslogdb_port     = 3306;
	$syslogdb_retries  = 5;
	$syslogdb_ssl      = false;
	$syslogdb_ssl_key  = '';
	$syslogdb_ssl_cert = '';
	$syslogdb_ssl_ca   = '';
} else {
	$syslogdb_type     = $database_type;
	$syslogdb_default  = $database_default;
	$syslogdb_hostname = $database_hostname;
	$syslogdb_username = $database_username;
	$syslogdb_password = $database_password;
	$syslogdb_port     = $database_port;
	$syslogdb_retries  = $database_retries;
	$syslogdb_ssl      = $database_ssl;
	$syslogdb_ssl_key  = $database_ssl_key;
	$syslogdb_ssl_cert = $database_ssl_cert;
	$syslogdb_ssl_ca   = $database_ssl_ca;
}

$syslog_install_options['upgrade_type'] = 'truncate';
$syslog_install_options['engine']       = 'innodb';
$syslog_install_options['db_type']      = 'trad';
$syslog_install_options['days']         = '30';
$syslog_install_options['mode']         = 'install';
$syslog_install_options['id']           = 'syslog';

$syslog_incoming_config['priorityField'] = 'priority_id';
$syslog_incoming_config['facilityField'] = 'facility_id';
$syslog_incoming_config['programField']  = 'program';
$syslog_incoming_config['timeField']     = 'logtime';
$syslog_incoming_config['hostField']     = 'host';
$syslog_incoming_config['textField']     = 'message';
$syslog_incoming_config['id']            = 'seq';
EOF

docker compose -f "${TEMP_DIR}/docker-compose.yml" down -v >/dev/null 2>&1 || true
docker compose -f "${TEMP_DIR}/docker-compose.yml" up -d --build

docker exec cacti_web ln -sf /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
docker exec cacti_web sh -lc 'mkdir -p /var/www/html/cacti/cache/boost /var/www/html/cacti/cache/mibcache /var/www/html/cacti/cache/realtime /var/www/html/cacti/cache/spikekill && chown -R www-data:www-data /var/www/html/cacti/cache'
docker exec cacti_web sh -lc 'apt-get update >/dev/null && apt-get install -y --no-install-recommends fping >/dev/null'
docker exec cacti_web sh -lc 'touch /var/www/html/cacti/log/cacti.log && chown www-data:www-data /var/www/html/cacti/log/cacti.log'

docker exec cacti_web php cli/install_cacti.php --accept-eula --install --force
docker exec cacti_web php cli/plugin_manage.php --plugin=syslog --install --enable --allperms

docker run --rm \
  --network cacti-syslog-e2e_default \
  --ipc=host \
  -v "${ROOT_DIR}/tests/e2e/playwright:/work" \
  -w /work \
  "${PW_IMAGE}" \
  npm install

"${ROOT_DIR}/tests/e2e/playwright/run-e2e.sh"
