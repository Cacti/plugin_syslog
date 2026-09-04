<?php

/*
 * Coverage for the #259 purge-syslog-hosts CSRF guard.
 *
 * setup.php and functions.php are pure function definitions, so the guard and
 * the utilities fragment can be driven for real against stubbed Cacti helpers
 * in a throwaway plugin directory. syslog_config_safe() keys off a config.php
 * beside setup.php, which a checkout does not carry, hence the copy.
 *
 * What stays a source scan: the Location headers, because header() is a no-op
 * under the CLI SAPI; the absence of the old GET entry point; and syslog.php,
 * which is a page rather than a library and cannot be included here.
 */

/* mirrors include/global_constants.php so the harness and this file agree */
define('MESSAGE_LEVEL_ERROR', 3);

$root = dirname(__DIR__, 2);
$name = basename(__FILE__, '.php');

function issue259_fail($message) {
    fwrite(STDERR, "$message\n");
    exit(1);
}

$sandbox = sys_get_temp_dir() . '/' . uniqid('syslog259_', true);

if (!mkdir($sandbox, 0700)) {
    issue259_fail('Unable to create the sandbox plugin directory');
}

register_shutdown_function(function () use ($sandbox) {
    foreach (glob($sandbox . '/*') as $file) {
        unlink($file);
    }

    rmdir($sandbox);
});

foreach (['setup.php', 'functions.php'] as $file) {
    if (!copy($root . '/' . $file, $sandbox . '/' . $file)) {
        issue259_fail("Unable to stage $file");
    }
}

file_put_contents($sandbox . '/config.php', "<?php\n");

/*
 * The hostile title proves the encoder in place rather than in isolation: a
 * translation carrying </script> must not close the block it sits in.
 */
$payload = '</script><img src=x onerror=alert(1)>\'"&';

$harness = <<<'HARNESS'
<?php

define('MESSAGE_LEVEL_INFO', 1);
define('MESSAGE_LEVEL_ERROR', 3);

$scenario = $argv[1];
$payload  = $argv[2];

function __($text, ...$args) {
    global $payload;

    return $text === 'Confirm Purge' ? $payload : $text;
}

function __esc($text, ...$args) {
    return htmlspecialchars(__($text), ENT_QUOTES, 'UTF-8');
}

function cacti_log($message, $output = false, $facility = '') {
    print "LOG:$message\n";
}

function raise_message($id, $message = '', $level = MESSAGE_LEVEL_INFO) {
    print "MSG:$id|$message|$level\n";
}

function html_header($items, $span = 1) {
}

function syslog_db_execute($sql) {
    print "DBEXEC\n";

    return true;
}

function syslog_db_affected_rows() {
    return 1;
}

if ($scenario === 'action_valid_token') {
    function csrf_check($fatal = true) {
        return true;
    }
} elseif ($scenario === 'action_bad_token') {
    function csrf_check($fatal = true) {
        return false;
    }
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/setup.php';

switch ($scenario) {
    case 'render':
        syslog_utilities_list();

        break;
    case 'action_get':
        $_SERVER['REQUEST_METHOD'] = 'GET';
        syslog_utilities_action('purge_syslog_hosts');

        break;
    default:
        $_SERVER['REQUEST_METHOD'] = 'POST';
        syslog_utilities_action('purge_syslog_hosts');
}

print "REACHED_END\n";
HARNESS;

file_put_contents($sandbox . '/harness.php', $harness);

function issue259_run($sandbox, $scenario, $payload) {
    $command = escapeshellarg(PHP_BINARY) . ' ' .
        escapeshellarg($sandbox . '/harness.php') . ' ' .
        escapeshellarg($scenario) . ' ' .
        escapeshellarg($payload) . ' 2>&1';

    return shell_exec($command);
}

/* the encoder, on its own */

require_once $root . '/functions.php';

$encoded = syslog_json_safe($payload);

if (json_decode($encoded) !== $payload) {
    issue259_fail('syslog_json_safe() does not round-trip through json_decode()');
}

foreach (['<', '>', '&', '"', '\''] as $raw) {
    if (strpos(substr($encoded, 1, -1), $raw) !== false) {
        issue259_fail("syslog_json_safe() left a raw $raw in the JS literal");
    }
}

/* the utilities fragment, rendered */

$render = issue259_run($sandbox, 'render', $payload);

if (strpos($render, 'REACHED_END') === false) {
    issue259_fail("syslog_utilities_list() did not complete:\n$render");
}

if (stripos($render, '<html') !== false) {
    issue259_fail('The utilities fragment must not open a document');
}

if (substr_count($render, '</script>') !== 1) {
    issue259_fail('A translated string escaped its script block');
}

if (!preg_match('/title:\s*("[^"]*"),/', $render, $title)) {
    issue259_fail('The dialog title is not a JSON literal');
}

if (json_decode($title[1]) !== $payload) {
    issue259_fail('The dialog title does not decode back to the translated text');
}

if (strpos($render, "'utilities.php?header=false'") === false) {
    issue259_fail('The purge post must target the headerless utilities page');
}

if (strpos($render, 'json.__csrf_magic = csrfMagicToken;') === false) {
    issue259_fail('The purge post must carry the CSRF token');
}

/* the guard, driven */

$blocked = [
    'action_get'       => 'non-POST request',
    'action_no_csrf'   => 'CSRF validation unavailable',
    'action_bad_token' => 'CSRF token validation failed'
];

foreach ($blocked as $scenario => $reason) {
    $output = issue259_run($sandbox, $scenario, $payload);

    if (strpos($output, 'DBEXEC') !== false) {
        issue259_fail("$scenario reached the purge deletes");
    }

    if (strpos($output, "LOG:WARNING: syslog purge blocked -- $reason") === false) {
        issue259_fail("$scenario did not audit the block as '$reason':\n$output");
    }

    if (!preg_match('/^MSG:(\S+)\|([^|]*)\|(\d+)$/m', $output, $message)) {
        issue259_fail("$scenario did not raise a user-visible message:\n$output");
    }

    if ($message[3] != MESSAGE_LEVEL_ERROR) {
        issue259_fail("$scenario raised the block at level $message[3], not error");
    }

    if ($message[2] !== 'Invalid request. Please try again.') {
        issue259_fail("$scenario used the wrong user-facing text: $message[2]");
    }

    if (stripos($message[2], 'csrf') !== false) {
        issue259_fail("$scenario leaked CSRF internals to the user");
    }
}

$allowed = issue259_run($sandbox, 'action_valid_token', $payload);

if (substr_count($allowed, 'DBEXEC') !== 3) {
    issue259_fail("A POST with a valid token must run all three deletes:\n$allowed");
}

if (strpos($allowed, 'MSG:syslog_info|') === false) {
    issue259_fail("A completed purge must report the record count:\n$allowed");
}

/* source scan for what the CLI SAPI cannot observe */

$setup = file_get_contents($root . '/setup.php');

if ($setup === false) {
    issue259_fail('Failed to load setup.php');
}

if (str_contains($setup, "href='utilities.php?action=purge_syslog_hosts'")) {
    issue259_fail('The GET purge link is still present');
}

if (preg_match("/header\('Location: utilities\.php'\)/", $setup)) {
    issue259_fail('A purge redirect target is missing header=false');
}

if (substr_count($setup, "header('Location: utilities.php?header=false');") !== 4) {
    issue259_fail('Every purge exit path must redirect to the headerless page');
}

$syslog = file_get_contents($root . '/syslog.php');

if ($syslog === false) {
    issue259_fail('Failed to load syslog.php');
}

foreach ([
    "pageTab: <?php print syslog_json_safe(get_request_var('tab')); ?>,",
    "syslog_json_safe(__('Enter a search term', 'syslog'))",
    "syslog_json_safe(__('Select Device(s)', 'syslog'))",
    "syslog_json_safe(__('Devices Selected', 'syslog'))",
    "syslog_json_safe(__('All Devices Selected', 'syslog'))"
] as $snippet) {
    if (!str_contains($syslog, $snippet)) {
        issue259_fail("initSyslogMain is not JS-encoded: $snippet");
    }
}

print "$name passed\n";
