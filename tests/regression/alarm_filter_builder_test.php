<?php

$GLOBALS['syslogdb_default']       = 'syslogdb';
$GLOBALS['syslog_incoming_config'] = [
	'hostField'     => 'host',
	'programField'  => 'program',
	'facilityField' => 'facility_id',
	'priorityField' => 'priority_id',
	'textField'     => 'message'
];

require_once dirname(__DIR__, 2) . '/functions.php';

function alarm_filter_assert($condition, $message) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$document = json_encode([
	'version' => 1,
	'conditions' => [
		['join' => 'AND', 'negative' => false, 'field' => 'host', 'operator' => '=', 'value' => 'router01'],
		['join' => 'AND', 'negative' => false, 'rows' => [
			['join' => 'AND', 'negative' => false, 'field' => 'message', 'operator' => 'contains', 'value' => "failed%_' OR 1=1"],
			['join' => 'OR', 'negative' => true, 'field' => 'priority_id', 'operator' => '=', 'value' => '7']
		]]
	]
]);

$alert = ['type' => 'filter', 'message' => $document];
$query = syslog_get_alert_sql($alert, 55);

alarm_filter_assert(str_contains($query['sql'], '`host` = ?'), 'Host predicate must be parameterized.');
alarm_filter_assert(str_contains($query['sql'], "`message` LIKE ? ESCAPE '!'"), 'Contains must escape LIKE wildcards.');
alarm_filter_assert(str_contains($query['sql'], 'NOT (`priority_id` = ?)'), 'Nested negation must compile.');
alarm_filter_assert(!str_contains($query['sql'], 'OR 1=1'), 'Filter values must never be interpolated into SQL.');
alarm_filter_assert($query['params'] === ['router01', "%failed!%!_' OR 1=1%", '7', 1, 55], 'Parameters must retain expression order and processing bounds.');

$invalid = ['type' => 'filter', 'message' => '{"version":1,"conditions":[{"join":"AND","negative":false,"field":"unknown","operator":"=","value":"x"}]}'];
alarm_filter_assert(syslog_get_alert_sql($invalid, 55) === [], 'Unknown fields must fail closed.');
alarm_filter_assert(!empty($GLOBALS['syslog_rule_filter_error']), 'Invalid filters must provide a validation reason.');

print "alarm_filter_builder_test passed\n";
