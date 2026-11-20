<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * syslog_process.php holds the processing lock on a connection of its own, so
 * the nested GET_LOCK calls on the Syslog connection cannot release it on
 * servers that keep one named lock per session.
 */

if (!function_exists('db_connect_real')) {
	/**
	 * Proxy database connection creation through the test override registry.
	 *
	 * @param mixed ...$args Connection arguments.
	 *
	 * @return mixed
	 */
	function db_connect_real(...$args) {
		return test_call_override('db_connect_real', $args, false);
	}
}

/**
 * Connection details as Cacti records them for $syslog_cnn.
 *
 * @param object $cnn    The Syslog connection.
 * @param bool   $cacti13 Whether to include the fields Cacti 1.3 adds.
 *
 * @return array<string, mixed>
 */
function syslog_test_lock_details(object $cnn, bool $cacti13): array {
	$details = [
		'database_conn'     => $cnn,
		'database_hostname' => 'syslog-db',
		'database_username' => 'syslog',
		'database_password' => 'secret',
		'database_default'  => 'syslog',
		'database_type'     => 'mysql',
		'database_port'     => 3307,
		'database_retries'  => 2,
		'database_ssl'      => true,
		'database_ssl_key'  => 'key.pem',
		'database_ssl_cert' => 'cert.pem',
		'database_ssl_ca'   => 'ca.pem',
		'database_persist'  => false,
	];

	if ($cacti13) {
		$details['database_ssl_capath']             = '/etc/ssl/certs';
		$details['database_ssl_verify_server_cert'] = false;
	}

	return $details;
}

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');

	$this->saved = [];

	foreach (['syslog_cnn', 'syslogdb_default', 'database_sessions', 'database_details', 'database_persist', 'syslog_processing_lock_cnn'] as $name) {
		$this->saved[$name] = $GLOBALS[$name] ?? null;
	}

	$GLOBALS['syslogdb_default'] = 'syslog';
	$GLOBALS['syslog_cnn']       = new stdClass();
	unset($GLOBALS['syslog_processing_lock_cnn']);
});

afterEach(function () {
	foreach ($this->saved as $name => $value) {
		if ($value === null) {
			unset($GLOBALS[$name]);
		} else {
			$GLOBALS[$name] = $value;
		}
	}
});

it('takes the processing lock on a new connection to the Syslog server', function () {
	$lock_cnn = new stdClass();
	$seen     = [];

	$GLOBALS['database_details']  = ['other' => ['database_conn' => new stdClass()], 'syslog' => syslog_test_lock_details($GLOBALS['syslog_cnn'], true)];
	$GLOBALS['database_sessions'] = ['syslog-db:3307:syslog' => $GLOBALS['syslog_cnn']];
	$GLOBALS['database_persist']  = true;

	test_override('db_connect_real', function (...$args) use (&$seen, $lock_cnn) {
		// The cached session and persistent handles would reuse $syslog_cnn.
		$seen = [$args, isset($GLOBALS['database_sessions']['syslog-db:3307:syslog']), $GLOBALS['database_persist']];

		return $lock_cnn;
	});
	test_override('db_fetch_cell_prepared', fn($sql, $params, $col, $log, $cnn) => $sql === 'SELECT GET_LOCK(?, 0)' && $params === [syslog_processing_lock_name()] && $cnn === $lock_cnn ? '1' : '0');
	test_override('db_fetch_cell', fn($sql, $col, $log, $cnn) => $sql === 'SELECT @@SESSION.wait_timeout' && $cnn === $lock_cnn ? '86400' : '0');
	$GLOBALS['__test_db_calls'] = [];

	expect(syslog_processing_lock_acquire())->toBeTrue()
		->and(array_column($GLOBALS['__test_db_calls'], 'sql'))->toContain('SET SESSION wait_timeout = GREATEST(@@SESSION.wait_timeout, 86400)')
		->and($seen)->toBe([['syslog-db', 'syslog', 'secret', 'syslog', 'mysql', 3307, 2, true, 'key.pem', 'cert.pem', 'ca.pem', '/etc/ssl/certs', false], false, false])
		->and($GLOBALS['syslog_processing_lock_cnn'])->toBe($lock_cnn)
		->and($GLOBALS['database_sessions']['syslog-db:3307:syslog'])->toBe($GLOBALS['syslog_cnn'])
		->and($GLOBALS['database_persist'])->toBeTrue();
});

it('reports a lock held elsewhere and a lock connection that cannot be opened', function () {
	$GLOBALS['database_details']  = [syslog_test_lock_details($GLOBALS['syslog_cnn'], false)];
	$GLOBALS['database_sessions'] = [];
	$args = [];

	test_override('db_connect_real', function (...$connect) use (&$args) {
		$args = $connect;

		return new stdClass();
	});
	test_override('db_fetch_cell_prepared', fn() => '0');
	test_override('db_fetch_cell', fn() => '86400');

	// Cacti 1.2 records no CA path, so only its eleven connection arguments are passed.
	expect(syslog_processing_lock_acquire())->toBeFalse()
		->and($args)->toHaveCount(11)
		->and($GLOBALS)->not->toHaveKey('syslog_processing_lock_cnn')
		->and($GLOBALS['database_sessions'])->toBe([]);

	// A server that keeps a short idle timeout would drop the lock mid-run.
	test_override('db_fetch_cell', fn() => '600');
	test_override('db_fetch_cell_prepared', fn() => '1');

	expect(syslog_processing_lock_acquire())->toBeNull()
		->and($GLOBALS)->not->toHaveKey('syslog_processing_lock_cnn');

	test_override('db_connect_real', fn() => false);

	expect(syslog_processing_lock_acquire())->toBeNull();

	// Without recorded details the Syslog server cannot be reached again.
	$GLOBALS['database_details'] = [];

	expect(syslog_processing_lock_acquire())->toBeNull();
});
