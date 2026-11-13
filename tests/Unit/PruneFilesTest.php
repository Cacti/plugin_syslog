<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for syslog_prune_files(): tombstone/tests removal, whitelist
 * and .git protection, traversal/symlink refusal, and logging of
 * unaccounted-for entries. These exercise the upgrade "housecleaning" that
 * removes stale top-level modules left behind by an older plugin layout.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	if (function_exists('syslog_test_reset_globals')) {
		syslog_test_reset_globals();
	}

	$GLOBALS['__prune_log'] = [];

	test_override('cacti_log', function ($message, $output = false, $environ = '', $level = 0) {
		$GLOBALS['__prune_log'][] = $message;
	});
});

function syslog_prune_fixture(array $manifest): string {
	$base   = sys_get_temp_dir() . '/syslog-prune-' . uniqid();
	$plugin = $base . '/plugins/syslog';

	mkdir($plugin . '/include', 0777, true);
	file_put_contents($plugin . '/include/old.php', "<?php\n");
	mkdir($plugin . '/tests/Unit', 0777, true);
	file_put_contents($plugin . '/tests/Unit/SomeTest.php', "<?php\n");
	file_put_contents($plugin . '/config.php', "<?php\n");
	mkdir($plugin . '/includes', 0777, true);
	file_put_contents($plugin . '/INFO', "[info]\n");
	file_put_contents($plugin . '/setup.php', "<?php\n");
	file_put_contents($plugin . '/functions.php', "<?php\n");
	file_put_contents($plugin . '/stray.php', "<?php\n");
	file_put_contents($plugin . '/phpunit.xml', '');
	file_put_contents($plugin . '/.mdlrc', '');
	mkdir($plugin . '/.git', 0777, true);
	file_put_contents($plugin . '/.git/config', '');
	file_put_contents($plugin . '/manifest.json', json_encode($manifest));

	return $base;
}

it('removes tombstoned paths and the tests/ tree, keeps whitelist/.git/expected, logs strays', function () {
	$manifest = [
		'tombstones' => ['include/', 'functions.php', 'gone.png'],
		'expected'   => ['INFO', 'setup.php', 'includes/', 'manifest.json'],
		'whitelist'  => ['config.php'],
	];

	$base    = syslog_prune_fixture($manifest);
	$plugin  = $base . '/plugins/syslog';
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// Tombstone and the dev-only tests/ tree are gone.
	expect(is_dir($plugin . '/include'))->toBeFalse();
	expect(is_dir($plugin . '/tests'))->toBeFalse();
	expect(is_file($plugin . '/functions.php'))->toBeFalse();
	expect(is_file($plugin . '/phpunit.xml'))->toBeFalse();

	// Whitelisted user data, VCS metadata, dotfiles and expected files untouched.
	expect(is_file($plugin . '/config.php'))->toBeTrue();
	expect(is_dir($plugin . '/.git'))->toBeTrue();
	expect(is_file($plugin . '/INFO'))->toBeTrue();
	expect(is_dir($plugin . '/includes'))->toBeTrue();
	expect(is_file($plugin . '/.mdlrc'))->toBeTrue();

	// An unexpected, non-whitelisted stray is left in place but logged.
	expect(is_file($plugin . '/stray.php'))->toBeTrue();

	$logged = implode("\n", $GLOBALS['__prune_log']);
	expect($logged)->toContain('stray.php');
	expect($logged)->not->toContain('config.php');
	expect($logged)->not->toContain('.git');
	expect($logged)->not->toContain('.mdlrc');
});

it('is a safe no-op when the manifest is missing', function () {
	$base = sys_get_temp_dir() . '/syslog-prune-missing-' . uniqid();
	mkdir($base . '/plugins/syslog', 0777, true);
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	expect($GLOBALS['__prune_log'])->toBe([]);
});

it('logs and skips pruning when the manifest is malformed', function () {
	$base   = sys_get_temp_dir() . '/syslog-prune-bad-' . uniqid();
	$plugin = $base . '/plugins/syslog';
	mkdir($plugin, 0777, true);
	file_put_contents($plugin . '/manifest.json', 'not json');
	mkdir($plugin . '/tests', 0777, true);
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// A malformed manifest must not delete anything.
	expect(is_dir($plugin . '/tests'))->toBeTrue();
	expect(implode("\n", $GLOBALS['__prune_log']))->toContain('could not be parsed');
});

it('refuses to remove a tombstone that resolves outside the plugin directory', function () {
	$manifest = [
		'tombstones' => ['../escapee.txt'],
		'expected'   => ['manifest.json'],
		'whitelist'  => [],
	];

	$base    = syslog_prune_fixture($manifest);
	$plugin  = $base . '/plugins/syslog';
	$outside = $base . '/plugins/escapee.txt';
	file_put_contents($outside, 'precious user data');
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// The out-of-tree file is untouched and the refusal is logged.
	expect(is_file($outside))->toBeTrue();
	expect(implode("\n", $GLOBALS['__prune_log']))->toContain('a traversal segment');
});

it('warns when a tombstoned path cannot be removed', function () {
	$manifest = [
		'tombstones' => ['locked/'],
		'expected'   => ['manifest.json'],
		'whitelist'  => [],
	];

	$base   = syslog_prune_fixture($manifest);
	$plugin = $base . '/plugins/syslog';
	mkdir($plugin . '/locked/sub', 0777, true);
	file_put_contents($plugin . '/locked/sub/data', 'x');
	chmod($plugin . '/locked/sub', 0500); // read-only dir: its child cannot be unlinked
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	set_error_handler(static fn () => true); // swallow the expected unlink warning

	try {
		syslog_prune_files();
	} finally {
		restore_error_handler();
		$GLOBALS['config']['base_path'] = $restore;
		@chmod($plugin . '/locked/sub', 0700);
	}

	expect(implode("\n", $GLOBALS['__prune_log']))->toContain('could not remove');
})->skip(function () {
	return function_exists('posix_getuid') && posix_getuid() === 0;
}, 'permission checks are bypassed for the root user');

it('refuses a tombstone that escapes through a symlinked directory', function () {
	$manifest = [
		'tombstones' => ['escdir/secret.txt'],
		'expected'   => ['manifest.json'],
		'whitelist'  => [],
	];

	$base    = syslog_prune_fixture($manifest);
	$plugin  = $base . '/plugins/syslog';
	$outside = $base . '/outside';
	mkdir($outside, 0777, true);
	file_put_contents($outside . '/secret.txt', 'precious user data');
	@symlink($outside, $plugin . '/escdir');
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// The out-of-tree file reached through the symlink is untouched and logged.
	expect(is_file($outside . '/secret.txt'))->toBeTrue();
	expect(implode("\n", $GLOBALS['__prune_log']))->toContain('outside the plugin directory');
})->skip(function () {
	$probe = sys_get_temp_dir() . '/.prune-symlink-probe-' . uniqid();
	$ok    = @symlink(__FILE__, $probe);
	@unlink($probe);

	return $ok === false;
}, 'symlinks are not supported on this filesystem');

it('refuses a tombstone whose parent is a symlink aliasing a protected file', function () {
	// 'alias' points back at the plugin root, so 'alias/config.php' resolves,
	// through the symlinked parent, to the whitelisted config.php. The literal
	// whitelist check does not catch the aliased path, so the parent-symlink
	// guard must reject it before deletion.
	$manifest = [
		'tombstones' => ['alias/config.php'],
		'expected'   => ['manifest.json', 'config.php'],
		'whitelist'  => ['config.php'],
	];

	$base   = syslog_prune_fixture($manifest);
	$plugin = $base . '/plugins/syslog';
	@symlink($plugin, $plugin . '/alias');
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// The protected configuration reached through the symlinked parent survives.
	expect(is_file($plugin . '/config.php'))->toBeTrue();
	expect(implode("\n", $GLOBALS['__prune_log']))->toContain('outside the plugin directory');
})->skip(function () {
	$probe = sys_get_temp_dir() . '/.prune-symlink-probe-' . uniqid();
	$ok    = @symlink(__FILE__, $probe);
	@unlink($probe);

	return $ok === false;
}, 'symlinks are not supported on this filesystem');

it('preserves nested .git metadata while removing a tombstoned directory', function () {
	$manifest = [
		'tombstones' => ['include/'],
		'expected'   => ['manifest.json'],
		'whitelist'  => [],
	];

	$base   = syslog_prune_fixture($manifest);
	$plugin = $base . '/plugins/syslog';
	mkdir($plugin . '/include/.git', 0777, true);
	file_put_contents($plugin . '/include/.git/config', 'nested checkout');
	$restore = $GLOBALS['config']['base_path'];

	$GLOBALS['config']['base_path'] = $base;

	try {
		syslog_prune_files();
	} finally {
		$GLOBALS['config']['base_path'] = $restore;
	}

	// Non-protected content is removed, the nested VCS metadata and its parent
	// are retained, and the intentional retention is not logged as a failure.
	expect(is_file($plugin . '/include/old.php'))->toBeFalse();
	expect(is_file($plugin . '/include/.git/config'))->toBeTrue();
	expect(is_dir($plugin . '/include'))->toBeTrue();
	expect(implode("\n", $GLOBALS['__prune_log']))->not->toContain('could not remove');
});

it('prunes from the shared version-aware upgrade path, gated to a genuine upgrade', function () {
	// The re-enable path and cli/syslog_schema.php call syslog_check_upgrade()
	// directly; pruning lives there (before the version bump) rather than only
	// in plugin_syslog_upgrade() so no entry point can record a new version
	// while leaving stale modules behind. It is gated on an older installed
	// version so a fresh install or same-version repair never removes the
	// dev-only tests/ tree.
	$check_upgrade = plugin_test_read_source('setup.php');
	$check_upgrade = substr($check_upgrade, strpos($check_upgrade, 'function syslog_check_upgrade'));
	$check_upgrade = substr($check_upgrade, 0, strpos($check_upgrade, "\nfunction "));

	$prune_pos = strpos($check_upgrade, 'syslog_prune_files();');

	expect($prune_pos)->not->toBeFalse();
	expect($prune_pos)->toBeLessThan(strpos($check_upgrade, 'syslog_refresh_plugin_version();'));

	// The prune call must be guarded by an older-version check.
	$guard = substr($check_upgrade, 0, $prune_pos);
	$guard = substr($guard, strrpos($guard, 'if ('));

	expect($guard)->toContain("version_compare((string) \$installed, \$version['version'], '<')");
});
