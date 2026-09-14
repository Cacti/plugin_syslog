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
/** Retroactive translation runs against reference tables; stub them here. */
$GLOBALS['syslog_reference_hosts']    = ['router01' => 12, 'core-sw' => 15];
$GLOBALS['syslog_reference_programs'] = ['snmpd' => 3, 'sshd' => 9];

function syslog_db_fetch_assoc_prepared($sql, $params = []) {
	if (str_contains($sql, 'syslog_hosts')) {
		$name = str_replace(['%', '_'], '', end($params));

		return isset($GLOBALS['syslog_reference_hosts'][$name]) ? [['id' => $GLOBALS['syslog_reference_hosts'][$name]]] : [];
	}
	if (str_contains($sql, 'syslog_programs')) {
		$name = str_replace(['%', '_'], '', end($params));

		return isset($GLOBALS['syslog_reference_programs'][$name]) ? [['id' => $GLOBALS['syslog_reference_programs'][$name]]] : [];
	}

	return [];
}

function cacti_sizeof($array) {
	return is_countable($array) ? count($array) : 0;
}

function removal_filter_assert($condition, $message) {
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

// Plain DELETE shape against syslog_incoming.
$remove = ['type' => 'filter', 'message' => $document, 'max_seq' => 55];
$query  = syslog_get_removal_rule_sql($remove, 'syslog_incoming');

removal_filter_assert(str_contains($query['sql'], '`host` = ?'), 'Host predicate must be parameterized.');
removal_filter_assert(str_contains($query['sql'], "LIKE ? ESCAPE '!'"), 'Contains must escape LIKE wildcards.');
removal_filter_assert(str_contains($query['sql'], 'NOT (`priority_id` = ?)'), 'Nested negation must compile.');
removal_filter_assert(!str_contains($query['sql'], 'OR 1=1'), 'Filter values must never be interpolated into SQL.');
removal_filter_assert($query['params'] === ['router01', "%failed!%!_' OR 1=1%", '7', 1, 55], 'Parameters must retain expression order and processing bounds.');
removal_filter_assert(str_contains($query['sql'], '`status` = ?'), 'Incoming processing must bound status.');
removal_filter_assert(str_contains($query['sql'], '`seq` <= ?'), 'Incoming processing must bound the sequence.');

// Aliased shape for the transferal INSERT JOIN.
$aliased = syslog_get_removal_rule_sql($remove, 'syslog_incoming', 'si.');
removal_filter_assert(str_contains($aliased['sql'], 'si.`host` = ?'), 'Aliased predicates must qualify columns.');
removal_filter_assert(str_contains($aliased['sql'], 'si.`seq` <= ?'), 'Aliased bounds must qualify the incoming alias.');
removal_filter_assert($aliased['params'] === ['router01', "%failed!%!_' OR 1=1%", '7', 1, 55], 'Aliased parameters must retain expression order.');

// Retroactive shape against the syslog table translates host names to ids.
$retro = syslog_get_removal_rule_sql($remove, 'syslog');
removal_filter_assert(str_contains($retro['sql'], '`host_id` = ?'), 'Retroactive processing must translate host names to normalized id columns.');
removal_filter_assert(in_array('12', $retro['params'], true), 'Translated host ids must bind as parameters.');
removal_filter_assert(!str_contains($retro['sql'], '`status`'), 'The syslog table has no status column.');

// A host with no reference entry must compile to an unmatchable id.
$unknown = ['type' => 'filter', 'message' => json_encode([
	'version' => 1,
	'conditions' => [['join' => 'AND', 'negative' => false, 'field' => 'host', 'operator' => '=', 'value' => 'no-such-host']]
]), 'max_seq' => 55];
$unmatchable = syslog_get_removal_rule_sql($unknown, 'syslog');
removal_filter_assert(str_contains($unmatchable['sql'], '`host_id` = ?') && end($unmatchable['params']) === '0', 'Unresolvable hosts must match nothing, not everything.');

$invalid = ['type' => 'filter', 'message' => '{"version":1,"conditions":[{"join":"AND","negative":false,"field":"unknown","operator":"=","value":"x"}]}', 'max_seq' => 55];
removal_filter_assert(syslog_get_removal_rule_sql($invalid, 'syslog_incoming') === [], 'Unknown fields must fail closed.');
removal_filter_assert(!empty($GLOBALS['syslog_rule_filter_error']), 'Invalid filters must provide a validation reason.');

$summary = syslog_filter_rule_summary($document);
removal_filter_assert($summary === 'Host = router01; Message contains failed%_\' OR 1=1; !Priority ID = 7', 'Summaries must render nested conditions in order, got: ' . $summary);
removal_filter_assert(syslog_filter_rule_summary('plain match') === 'plain match', 'Non-filter values must pass through unchanged.');

print "removal_filter_builder_test passed\n";