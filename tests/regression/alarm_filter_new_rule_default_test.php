<?php

$source = file_get_contents(dirname(__DIR__, 2) . '/syslog_alerts.php');

if (!preg_match("/'default'\\s*=>\\s*'filter'/", $source)) {
	throw new RuntimeException('New alarm forms must default to the visual filter builder.');
}

if (substr_count($source, "\$alert['type'] = 'filter';") < 2) {
	throw new RuntimeException('Blank and message-seeded new alarms must initialize as filter rules.');
}

if (!str_contains($source, "get_nfilter_request_var('action') === 'newedit'")) {
	throw new RuntimeException('Message-seeded alarms must initialize a builder condition.');
}

print "alarm_filter_new_rule_default_test passed\n";
