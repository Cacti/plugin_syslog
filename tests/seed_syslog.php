#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2025 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * seed_syslog.php - Insert test Syslog messages into the Syslog database.
 *
 * Simulates messages from many hosts (IPs), programs, facilities and
 * priorities spread over a range of days, for testing the Search page.
 *
 * Database credentials are imported automatically:
 *   1. Cacti's include/config.php (from --cacti=<path> or a few common paths)
 *   2. This plugin's config_local.php or config.php when a separate
 *      syslog database is configured ($use_cacti_db = false).
 * Any of --db, --user, --pass, --host, --port override the imported values.
 *
 * Usage:
 *   php tests/seed_syslog.php [--cacti=/path/to/cacti] [--count=1000]
 *       [--days=7] [--host=...] [--port=...] [--db=...] [--user=...]
 *       [--pass=...] [--seed=42] [--truncate]
 */

if (php_sapi_name() !== 'cli') {
	die("This script may only be run from the CLI.\n");
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$options = getopt('', [
	'cacti::', 'count::', 'days::', 'seed::', 'truncate::',
	'host::', 'port::', 'db::', 'user::', 'pass::', 'help'
]) ?: [];

if (isset($options['help'])) {
	display_help();
}

$count    = isset($options['count']) ? (int)$options['count'] : 500;
$days     = isset($options['days']) ? (int)$options['days'] : 7;
$seed     = isset($options['seed']) ? (int)$options['seed'] : 42;
$truncate = isset($options['truncate']);

if ($count <= 0 || $days < 0) {
	die("ERROR: --count must be > 0 and --days must be >= 0\n");
}

mt_srand($seed);

// ---------------------------------------------------------------------------
// Import credentials
// ---------------------------------------------------------------------------

// Cacti's configuration holds the standard $database_* variables.
$cacti_paths = [
	isset($options['cacti']) ? rtrim($options['cacti'], '/') : null,
	'/usr/share/cacti/site',
	'/usr/share/cacti',
	'/var/www/html/cacti',
	dirname(__DIR__) // the plugin living inside a cacti install
];

$database_configured = false;

foreach ($cacti_paths as $path) {
	if ($path === null || $path === '') {
		continue;
	}

	$include = $path . '/include/config.php';

	if (file_exists($include) && is_readable($include)) {
		// Capture only the database globals, no Cacti bootstrap.
		$database_configured = (bool)include($include);

		print "Using Cacti configuration: $include\n";
		break;
	}
}

if (!$database_configured) {
	print "WARNING: Unable to locate Cacti's include/config.php.\n";
	print "         Provide credentials manually or with --cacti=<path>.\n";
}

$syslogdb_type     = 'mysql';
$syslogdb_default  = isset($database_default) ? $database_default : 'cacti';
$syslogdb_hostname = isset($database_hostname) ? $database_hostname : 'localhost';
$syslogdb_username = isset($database_username) ? $database_username : 'cactiuser';
$syslogdb_password = isset($database_password) ? $database_password : '';
$syslogdb_port     = isset($database_port) ? $database_port : 3306;

// The plugin's own configuration may point to a separate syslog database.
$plugin_dir = dirname(__DIR__);

foreach ([$plugin_dir . '/config_local.php', $plugin_dir . '/config.php'] as $config_file) {
	if (file_exists($config_file) && is_readable($config_file)) {
		// $use_cacti_db true means the syslog tables live in the Cacti
		// database, otherwise the $syslogdb_* variables are authoritative.
		include($config_file);

		print "Using Syslog plugin configuration: $config_file\n";
		break;
	}
}

if (isset($use_cacti_db) && $use_cacti_db === false) {
	// $syslogdb_* values from the plugin configuration win.
} else {
	// Syslog tables share the Cacti database.
	$syslogdb_default  = isset($database_default) ? $database_default : 'cacti';
	$syslogdb_hostname = isset($database_hostname) ? $database_hostname : 'localhost';
	$syslogdb_username = isset($database_username) ? $database_username : 'cactiuser';
	$syslogdb_password = isset($database_password) ? $database_password : '';
	$syslogdb_port     = isset($database_port) ? $database_port : 3306;
}

// Command line overrides
if (isset($options['host'])) $syslogdb_hostname = $options['host'];
if (isset($options['port'])) $syslogdb_port     = (int)$options['port'];
if (isset($options['db']))   $syslogdb_default  = $options['db'];
if (isset($options['user'])) $syslogdb_username = $options['user'];
if (isset($options['pass'])) $syslogdb_password = $options['pass'];

print "\nConnecting to: $syslogdb_username@{$syslogdb_hostname}:$syslogdb_port/$syslogdb_default\n";

$mysqli = new mysqli($syslogdb_hostname, $syslogdb_username, $syslogdb_password, $syslogdb_default, (int)$syslogdb_port);

if ($mysqli->connect_error) {
	die('ERROR: Unable to connect to the Syslog database: ' . $mysqli->connect_error . "\n");
}

$mysqli->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// Verify the schema is installed
// ---------------------------------------------------------------------------

$tables = ['syslog', 'syslog_hosts', 'syslog_programs', 'syslog_facilities', 'syslog_priorities'];

foreach ($tables as $table) {
	$result = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");

	if ($result === false || $result->num_rows == 0) {
		die("ERROR: Table `$syslogdb_default`.`$table` does not exist. Install the Syslog plugin first.\n");
	}
}

// ---------------------------------------------------------------------------
// Optional cleanup
// ---------------------------------------------------------------------------

if ($truncate) {
	print "Truncating syslog and syslog_incoming tables\n";

	$mysqli->query('TRUNCATE TABLE `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog`');
	$mysqli->query('TRUNCATE TABLE `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog_incoming`');
	$mysqli->query('TRUNCATE TABLE `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog_host_facilities`');
	$mysqli->query('TRUNCATE TABLE `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog_programs`');
	$mysqli->query('TRUNCATE TABLE `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog_hosts`');

	// Reset the static lookup tables
	$mysqli->query("DELETE FROM `" . $mysqli->real_escape_string($syslogdb_default) . "`.`syslog_facilities` WHERE facility_id NOT BETWEEN 0 AND 23");
	$mysqli->query("DELETE FROM `" . $mysqli->real_escape_string($syslogdb_default) . "`.`syslog_priorities` WHERE priority_id NOT BETWEEN 0 AND 8");
}

// ---------------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------------

// facility_id => facility name (matches the syslog_facilities table)
$facilities = [
	0 => 'kern', 1 => 'user', 2 => 'mail', 3 => 'daemon', 4 => 'auth',
	5 => 'syslog', 9 => 'crond', 10 => 'authpriv', 12 => 'ntpd',
	16 => 'local0', 17 => 'local1', 18 => 'local2'
];

// priority_id => priority name (matches the syslog_priorities table)
$priorities = [
	0 => 'emerg', 1 => 'alert', 2 => 'crit', 3 => 'err',
	4 => 'warning', 5 => 'notice', 6 => 'info', 7 => 'debug'
];

// host => host_id
$hosts = ensure_dimension($mysqli, 'syslog_hosts', 'host_id', 'host', [
	'192.168.1.10', '192.168.1.11', '192.168.1.25', '192.168.4.100',
	'10.0.0.5', '10.0.0.6', '10.0.10.42', '10.20.30.40',
	'172.16.8.1', '172.16.8.2', '172.31.0.99', '203.0.113.55',
	'198.51.100.7', '127.0.0.1', 'fe80::1', '2001:db8::25'
]);

// program => program_id
$programs = ensure_dimension($mysqli, 'syslog_programs', 'program_id', 'program', [
	'sshd', 'kernel', 'nginx', 'mysqld', 'postfix', 'systemd', 'CRON',
	'dhcpd', 'snmpd', 'sudo', 'kubelet', 'dockerd', 'sshd[815]',
	'named', 'php-fpm', 'haproxy'
]);

// Message templates keyed by program, with placeholders for realism
$messages = [
	'sshd' => [
		'Accepted publickey for admin from %s port %d ssh2: RSA SHA256:xyz',
		'Failed password for root from %s port %d ssh2',
		'Invalid user test from %s port %d',
		'pam_unix(sshd:session): session opened for user deploy by (uid=0)',
		'Connection closed by authenticating user bob %s port %d [preauth]'
	],
	'kernel' => [
		'eth0: link up, 1000 Mbps full duplex',
		'Out of memory: Killed process 12345 (nginx)',
		'audit: type=1400 apparmor="DENIED" operation="open"',
		'br-lan: port 3(vlan20) entered forwarding state',
		'nf_conntrack: table full, dropping packet'
	],
	'nginx' => [
		'upstream timed out while reading response header from %s:8080',
		'limiting requests, excess: 5.20 by zone "api"',
		'access forbidden by rule, client: %s',
		'ssl_stapling ignored, no responder for OCSP of %s'
	],
	'mysqld' => [
		'InnoDB: Buffer pool(s) load completed',
		'Aborted connection 4821 to db: "cacti" user: "cactiuser" host: "%s"',
		'Slow query: SELECT * FROM syslog WHERE message LIKE %s',
		'Restarted master log file: mysql-bin.000123'
	],
	'postfix' => [
		'warning: %s: SASL authentication failed',
		'connect from unknown[%s]',
		'delivered to maildir',
		'timeout after DATA from %s[%s]'
	],
	'systemd' => [
		'Started Session %d of user www-data.',
		'Failed to start Cacti Syslog Service.',
		'Stopped Daily apt upgrade and clean activities.',
		'cacti-syslog.service: Succeeded.'
	],
	'CRON' => [
		'(root) CMD (command -v debian-sa1 > /dev/null && debian-sa1 1 1)',
		'(www-data) CMD (php /usr/share/cacti/site/poller.php --force)',
		'(root) CMD (   cd / && run-parts --report /etc/cron.hourly)'
	],
	'dhcpd' => [
		'DHCPDISCOVER on 0 to 255.255.255.255 via eth1',
		'DHCPACK on 1 to 00:11:22:33:44:55 via eth1',
		'pool %s: total 254; free 12; used 242'
	],
	'snmpd' => [
		'Connection from UDP: [%s]:47211',
		'error on subcontainer container index insert (-1)',
		'Cannot stat /proc/net/ip_tables_names'
	],
	'sudo' => [
		'  admin : TTY=pts/0 ; PWD=/home/admin ; USER=root ; COMMAND=/usr/bin/systemctl restart nginx',
		'  deploy : command not allowed ; TTY=pts/1 ; PWD=/ ; USER=root ; COMMAND=/bin/rm'
	],
	'kubelet' => [
		'Pod cacti-poller-7d9f status image is out of date',
		'node not ready: kubelet is posting ready events',
		'Failed to start container 3f2a1b: image not found'
	],
	'dockerd' => [
		'Container 8c21e3 (cacti-web) started',
		'Container 4d92ab (syslog-ng) exited with code 137',
		'Driver overlay2 successfully applied'
	],
	'sshd[815]' => [
		'server accepts key: pkalg rsa sha256',
		'Disconnected from authenticating user root %s port %d [preauth]'
	],
	'named' => [
		'client %s#53542 query timed out',
		'lame-servers: bad zone transfer request',
		'successful zone resolv'
	],
	'php-fpm' => [
		'[WARNING] [pool www] child 1234 exited on signal 15 (SIGTERM)',
		'[ERROR] [pool www] unable to read what child 5678 is saying',
		'executing too many requests'
	],
	'haproxy' => [
		'Server web1/%s is DOWN, reason: Layer7 timeout',
		'backend web1 has no server available!',
		'Server web2/%s is UP, reason: Layer7 check passed'
	]
];

// Weighted priority choices so severities vary but skew informational
$priority_pool = [6, 6, 6, 7, 7, 5, 4, 4, 3, 3, 2, 1, 0];

// ---------------------------------------------------------------------------
// Insert the messages
// ---------------------------------------------------------------------------

$now         = time();
$inserted    = 0;
$batch       = [];
$batch_size  = 250;
$last_update = '';

$host_ids = array_values($hosts);
$prog_ids = array_values($programs);

$stmt = $mysqli->prepare('INSERT INTO `' . $mysqli->real_escape_string($syslogdb_default) . '`.`syslog`
	(facility_id, priority_id, program_id, host_id, logtime, message)
	VALUES (?, ?, ?, ?, ?, ?)');

if ($stmt === false) {
	die('ERROR: Unable to prepare insert: ' . $mysqli->error . "\n");
}

for ($i = 0; $i < $count; $i++) {
	$host_id     = $host_ids[array_rand($host_ids)];
	$program_id  = $prog_ids[array_rand($prog_ids)];
	$program     = array_search($program_id, $programs);
	$priority_id = $priority_pool[array_rand($priority_pool)];

	// Choose a facility that suits the program when possible
	$facility_id = choose_facility($program, $facilities);

	// Spread the messages over the requested number of days
	$offset = $days > 0 ? mt_rand(0, $days * 86400 - 1) : mt_rand(0, 3600);
	$logtime = date('Y-m-d H:i:s', $now - $offset);

	// Pick a message template and fill in realistic placeholders
	$templates = $messages[$program];
	$message   = $templates[array_rand($templates)];
	$message   = render_message($message, $hosts);

	if ($stmt->bind_param('iiiiss', $facility_id, $priority_id, $program_id, $host_id, $logtime, $message) === false) {
		die('ERROR: Unable to bind parameters: ' . $stmt->error . "\n");
	}

	if ($stmt->execute() === false) {
		die('ERROR: Insert failed: ' . $stmt->error . "\n");
	}

	$inserted++;

	// Maintain the host/facility cross reference like the process script does
	$mysqli->query("INSERT INTO `" . $mysqli->real_escape_string($syslogdb_default) . "`.`syslog_host_facilities`
		(host_id, facility_id) VALUES ($host_id, $facility_id)
		ON DUPLICATE KEY UPDATE last_updated = CURRENT_TIMESTAMP");

	if ($i % 500 == 0 && $i > 0) {
		print "  inserted $i of $count\n";
	}
}

$stmt->close();

print "\nDone. Inserted $inserted message(s) into `$syslogdb_default`.`syslog`.\n";
print 'Hosts: ' . count($hosts) . ', Programs: ' . count($programs) . ", Days: $days\n";

$mysqli->close();

exit(0);

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

function display_help() {
	print "seed_syslog.php - Insert test Syslog messages into the Syslog database.\n\n";
	print "Optional:\n";
	print "  --cacti=<path>   Path to the Cacti installation for credential import\n";
	print "  --count=<n>      Number of messages to insert (default 500)\n";
	print "  --days=<n>       Spread messages over n days (default 7)\n";
	print "  --seed=<n>       Random seed for repeatable data (default 42)\n";
	print "  --truncate       Wipe syslog/syslog_incoming/hosts/programs first\n";
	print "  --host= --port= --db= --user= --pass=  Credential overrides\n";
	print "  --help           Show this message\n";

	exit(0);
}

/**
 * Insert any missing dimension rows and return a map of key => id.
 */
function ensure_dimension($mysqli, $table, $id_column, $name_column, $names) {
	$insert = $mysqli->prepare("INSERT INTO `$table` (`$name_column`)
		VALUES (?) ON DUPLICATE KEY UPDATE `$name_column` = VALUES(`$name_column`)");

	if ($insert === false) {
		die("ERROR: Unable to prepare insert for `$table`: " . $mysqli->error . "\n");
	}

	foreach ($names as $name) {
		$insert->bind_param('s', $name);
		$insert->execute();
	}

	$insert->close();

	$result = $mysqli->query("SELECT `$id_column` AS id, `$name_column` AS name FROM `$table`");

	$map = [];

	while ($row = $result->fetch_assoc()) {
		if (in_array($row['name'], $names)) {
			$map[$row['name']] = (int)$row['id'];
		}
	}

	return $map;
}

/**
 * Pick a facility appropriate to the program.
 */
function choose_facility($program, $facilities) {
	switch ($program) {
		case 'kernel':
			return 0;
		case 'sshd':
		case 'sudo':
		case 'sshd[815]':
			return array_rand([4 => 'auth', 10 => 'authpriv']);
		case 'postfix':
			return 2;
		case 'systemd':
		case 'dockerd':
		case 'kubelet':
		case 'haproxy':
			return 3;
		case 'CRON':
			return 9;
		case 'snmpd':
			return 10;
		case 'named':
		case 'dhcpd':
			return 3;
		default:
			return array_rand($facilities);
	}
}

/**
 * Fill message placeholders with random IPs, ports and numbers.
 */
function render_message($message, $hosts) {
	$host_names = array_keys($hosts);

	// Fill IP address placeholders first, then any remaining placeholders
	if (strpos($message, '%s') !== false) {
		$message = preg_replace_callback('/%s/', fn() => random_ip($host_names), $message);
	}

	while (strpos($message, '%d') !== false) {
		$message = preg_replace('/%d/', mt_rand(1, 65535), $message, 1);
	}

	return $message;
}

/**
 * Pick a random host from the seeded set, or generate a fresh one.
 */
function random_ip($hosts) {
	$pick = $hosts[mt_rand(0, count($hosts) - 1)];

	if (mt_rand(0, 4) === 0) {
		$pick = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
	}

	return $pick;
}