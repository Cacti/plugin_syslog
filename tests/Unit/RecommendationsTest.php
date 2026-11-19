<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Syslog recommendations: import/export normalization, hover lookup and the
 * one-time seed that the 5.0 upgrade performs.
 */

beforeEach(function () {
	syslog_load_plugin_source('includes/recommendations.php');
});

afterEach(function () {
	syslog_test_reset_globals();
});

function recommendation_sample(array $override = []): array {
	return array_merge([
		'vendor'             => 'Cisco',
		'identifier'         => '%LINK-3-UPDOWN',
		'component'          => 'LINK',
		'severity'           => 3,
		'message'            => 'Interface [chars], changed state to [chars]',
		'explanation'        => 'The interface changed state.',
		'recommended_action' => 'Check the cable.',
		'reference'          => ''
	], $override);
}

it('normalizes the field names used by other catalogues', function () {
	$record = syslog_recommendation_normalize([
		'name' => 'RPD_OSPF_NBRDOWN:', 'supplier' => 'Juniper', 'level' => 'high',
		'description' => 'OSPF neighbor went down.', 'action' => 'Check the link.', 'references' => ['https://a', 'https://b']
	]);

	expect($record)->toMatchArray([
		'vendor'             => 'Juniper',
		'identifier'         => 'RPD_OSPF_NBRDOWN',
		'severity'           => 3,
		'explanation'        => 'OSPF neighbor went down.',
		'recommended_action' => 'Check the link.',
		'reference'          => "https://a\nhttps://b"
	]);
});

it('rejects records that are not usable recommendations, saying why', function (array $input, string $why) {
	$reason = null;

	expect(syslog_recommendation_normalize($input, $reason))->toBeNull()
		->and($reason)->toBe($why);
})->with([
	'no identifier'         => [['explanation' => 'text'], 'the identifier is empty'],
	'nothing to show'       => [['identifier' => 'X-1-Y'], 'it has neither an explanation nor a recommended action'],
	'identifier too long'   => [['identifier' => str_repeat('a', 191), 'explanation' => 'text'], 'the identifier is longer than 190 characters'],
	'multi-line identifier' => [['identifier' => "a\nb", 'explanation' => 'text'], 'the identifier contains spaces or line breaks'],
	'identifier with space' => [['identifier' => 'LINK UPDOWN', 'explanation' => 'text'], 'the identifier contains spaces or line breaks']
]);

it('accepts every identifier shape found in the bundled data, flagging the ones that cannot match', function (string $identifier, bool $matchable) {
	$record = syslog_recommendation_normalize(['identifier' => $identifier, 'explanation' => 'x']);

	expect($record)->not->toBeNull()
		->and($record['identifier'])->toBe($identifier)
		->and(syslog_recommendation_matchable($identifier))->toBe($matchable)
		// The SQL form behind the list's Can Match filter must agree with the PHP check.
		->and(preg_match('~' . SYSLOG_RECOMMENDATIONS_IDENTIFIER_SQL . '~', $identifier) === 1)->toBe($matchable);
})->with([
	'cisco mnemonic'     => ['%LINK-3-UPDOWN', true],
	'path-like code'     => ['%AUTH/84', true],
	'alternatives'       => ['%ASA|PIX-3-331001', false],
	'slot placeholder'   => ['%FCDD-SLOT#-3-LOG_FCDD_ERR', false],
	'documented value'   => ['%DHCPD-2-(-1)', false],
	'quoted placeholder' => ['%DSXPNM-3-"NULL"', false],
	'inner colon'        => ['%AUTH/84:LAN-to-LAN', false]
]);

it('accepts numeric event IDs and short codes, and finds them in a message', function () {
	expect(syslog_recommendation_normalize(['identifier' => '4625', 'explanation' => 'x']))->toMatchArray(['identifier' => '4625'])
		->and(syslog_recommendation_normalize(['identifier' => 'B', 'explanation' => 'x']))->toMatchArray(['identifier' => 'B'])
		->and(syslog_recommendation_candidates('EventID 4625: An account failed to log on'))->toContain('4625')->not->toContain('%4625')
		->and(syslog_recommendation_candidates('code B reported'))->toContain('B');
});

it('maps severity numbers, syslog keywords and Sigma levels', function () {
	expect(syslog_recommendation_severity(0))->toBe(0)
		->and(syslog_recommendation_severity('7'))->toBe(7)
		->and(syslog_recommendation_severity('Warning'))->toBe(4)
		->and(syslog_recommendation_severity('critical'))->toBe(2)
		->and(syslog_recommendation_severity('informational'))->toBe(6)
		->and(syslog_recommendation_severity(8))->toBeNull()
		->and(syslog_recommendation_severity('loud'))->toBeNull()
		->and(syslog_recommendation_severity(''))->toBeNull();
});

it('parses the export envelope and a bare list, recording why each rejected record was refused', function () {
	$record = recommendation_sample();
	$wrapped = syslog_recommendations_parse(json_encode([
		'format' => 'syslog-recommendations', 'version' => 1,
		'recommendations' => [$record, ['explanation' => 'no id'], 'not an object', ['identifier' => 'NO_TEXT']]
	]));
	$bare = syslog_recommendations_parse(json_encode([$record]));

	expect($wrapped['records'])->toHaveCount(1)
		->and($wrapped['invalid'])->toBe(3)
		->and($wrapped['rejected'])->toBe([
			['index' => 2, 'identifier' => '', 'reason' => 'the identifier is empty'],
			['index' => 3, 'identifier' => '', 'reason' => 'it is not a JSON object'],
			['index' => 4, 'identifier' => 'NO_TEXT', 'reason' => 'it has neither an explanation nor a recommended action']
		])
		->and($bare['records'][0]['identifier'])->toBe('%LINK-3-UPDOWN')
		->and($bare['rejected'])->toBe([]);
});

it('logs every rejected record with its position, identifier and reason', function () {
	$logged = [];
	test_override('cacti_log', function ($message) use (&$logged) {
		$logged[] = $message;
	});

	syslog_recommendations_log_rejected([
		['index' => 2, 'identifier' => 'A B', 'reason' => 'the identifier contains spaces or line breaks'],
		['index' => 7, 'identifier' => '', 'reason' => 'the identifier is empty']
	]);

	expect($logged)->toBe([
		"WARNING: Recommendation import rejected record 2 (identifier 'A B'): the identifier contains spaces or line breaks",
		"WARNING: Recommendation import rejected record 7 (identifier ''): the identifier is empty"
	]);
});

it('refuses documents that are not recommendations', function (string $payload) {
	expect(syslog_recommendations_parse($payload))->toBeFalse();
})->with([
	'empty'          => [''],
	'not json'       => ['<xml/>'],
	'broken json'    => ['{"recommendations": ['],
	'other format'   => ['{"format": "other", "recommendations": []}'],
	'future version' => ['{"version": 2, "recommendations": []}'],
	'plain object'   => ['{"vendor": "Cisco"}']
]);

it('exports a document that imports back unchanged', function () {
	$rows = [array_merge(recommendation_sample(), ['id' => 5]), array_merge(recommendation_sample(['identifier' => 'B', 'severity' => null]), ['id' => 9])];

	test_override('syslog_db_fetch_assoc_prepared', static fn ($sql, $params) => $params[count($params) - 1] === 0 ? $rows : []);

	ob_start();
	syslog_recommendations_export();
	$json = ob_get_clean();

	$decoded = json_decode($json, true);
	$parsed  = syslog_recommendations_parse($json);

	expect($decoded['format'])->toBe('syslog-recommendations')
		->and($parsed['invalid'])->toBe(0)
		->and(array_column($parsed['records'], 'identifier'))->toBe(['%LINK-3-UPDOWN', 'B'])
		->and($parsed['records'][1]['severity'])->toBeNull();
});

it('exports an empty set as a valid document', function () {
	ob_start();
	syslog_recommendations_export();

	expect(json_decode(ob_get_clean(), true)['recommendations'])->toBe([]);
});

it('keeps existing records unless asked to overwrite them', function () {
	$sql = [];
	test_override('syslog_db_execute_prepared', function ($statement) use (&$sql) {
		$sql[] = $statement;

		return true;
	});
	test_override('syslog_db_affected_rows', static fn () => 0);

	$result = syslog_recommendations_store([recommendation_sample()], false);

	expect($result['skipped'])->toBe(1)
		->and($sql[0])->toContain('INSERT IGNORE INTO')
		->and($sql[0])->not->toContain('ON DUPLICATE KEY');
});

it('counts imported and updated records when overwriting', function () {
	$affected = [1, 2];
	test_override('syslog_db_execute_prepared', static fn () => true);
	test_override('syslog_db_affected_rows', function () use (&$affected) {
		return array_shift($affected);
	});

	$result = syslog_recommendations_store([recommendation_sample(), recommendation_sample(['identifier' => 'B'])], true);

	expect($result)->toBe(['imported' => 1, 'updated' => 1, 'skipped' => 0, 'failed' => 0]);
});

it('preserves a literal percent prefix in message identifiers', function () {
	$candidates = syslog_recommendation_candidates('%LINEPROTO-5-UPDOWN: Line protocol on Interface Gi0/1, changed state to up');

	expect($candidates)->toContain('%LINEPROTO-5-UPDOWN')->not->toContain('LINEPROTO-5-UPDOWN')
		->and(syslog_recommendation_candidates('RPD_OSPF_NBRDOWN: neighbor down'))->toContain('RPD_OSPF_NBRDOWN')->not->toContain('%RPD_OSPF_NBRDOWN');
});

it('looks messages up by their identifiers and returns the localized JSON', function () {
	$bound = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$bound) {
		$bound = $params;

		return [recommendation_sample()];
	});

	$response = json_decode(syslog_recommendations_lookup_json('%LINK-3-UPDOWN: Interface Gi0/1, changed state to down'), true);

	expect($bound)->toContain('%LINK-3-UPDOWN')->not->toContain('LINK-3-UPDOWN')
		->and($response['found'])->toBeTrue()
		->and($response['recommendations'][0]['severity_name'])->toBe('Error')
		->and($response['recommendations'][0]['recommended_action'])->toBe('Check the cable.')
		->and($response['labels'])->toHaveKeys(['message', 'explanation', 'action', 'reference']);
});

it('reports no recommendation without touching the database for messages with no candidates', function () {
	$queried = false;
	test_override('syslog_db_fetch_assoc_prepared', function () use (&$queried) {
		$queried = true;

		return [];
	});

	$response = json_decode(syslog_recommendations_lookup_json(' - : , '), true);

	expect($response['found'])->toBeFalse()
		->and($response['recommendations'])->toBe([])
		->and($queried)->toBeFalse();
});

it('seeds JSON records with prepared inserts without overwriting existing data', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	$records = [recommendation_sample(), recommendation_sample(['identifier' => 'B'])];
	file_put_contents($file, json_encode($records));

	$run = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$run) {
		$run[] = [$sql, $params];

		return true;
	});
	test_override('syslog_db_affected_rows', static fn () => 1);

	try {
		expect(syslog_recommendations_seed($file))->toBe(2)
			->and($run)->toHaveCount(2)
			->and($run[0][0])->toStartWith('INSERT IGNORE INTO syslog_recommendations')
			->and($run[0][1])->toBe(array_values($records[0]));
	} finally {
		unlink($file);
	}

	$flag = [];
	test_override('read_config_option', static fn ($name) => $name === SYSLOG_RECOMMENDATIONS_SEED_FLAG ? '1' : '');
	test_override('set_config_option', function ($name) use (&$flag) {
		$flag[] = $name;
	});

	expect(syslog_recommendations_seed())->toBe(-1)->and($flag)->toBe([]);
});

it('seeds from a gzip compressed file', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, gzencode(json_encode([recommendation_sample()]), 9));

	$run = [];
	test_override('syslog_db_execute_prepared', function ($sql, $params) use (&$run) {
		$run[] = $params;

		return true;
	});
	test_override('syslog_db_affected_rows', static fn () => 1);

	try {
		expect(syslog_recommendations_seed($file))->toBe(1)
			->and($run[0])->toBe(array_values(recommendation_sample()));
	} finally {
		unlink($file);
	}
});

it('reports an unreadable seed file without marking it applied', function () {
	$logged = [];
	test_override('cacti_log', function ($message) use (&$logged) {
		$logged[] = $message;
	});

	expect(syslog_recommendations_seed(sys_get_temp_dir() . '/no-such-seed.json.gz'))->toBe(-1)
		->and($logged[0])->toContain('not readable');
});

it('does not remember a bundled seed that had failing inserts', function () {
	$flag = [];
	test_override('syslog_db_execute_prepared', static fn () => false);
	test_override('set_config_option', function ($name) use (&$flag) {
		$flag[] = $name;
	});

	expect(syslog_recommendations_seed())->toBe(0)->and($flag)->toBe([]);
});

it('counts and logs statements that fail to store', function () {
	$logged = [];
	test_override('syslog_db_execute_prepared', static fn () => false);
	test_override('cacti_log', function ($message) use (&$logged) {
		$logged[] = $message;
	});

	expect(syslog_recommendations_store([recommendation_sample()], false))->toBe(['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1])
		->and($logged[0])->toContain("could not store '%LINK-3-UPDOWN' (vendor 'Cisco')");
});

it('treats non-scalar and non-list input as unusable', function () {
	expect(syslog_recommendation_severity(true))->toBeNull()
		->and(syslog_recommendation_text(null))->toBe('')
		->and(syslog_recommendations_parse('{"recommendations": "text"}'))->toBeFalse();
});

it('reads imports from pasted text before uploaded files', function () {
	$GLOBALS['request']['import_text'] = '  [{"a":1}] ';
	expect(syslog_recommendations_read_payload())->toBe('  [{"a":1}] ');

	$GLOBALS['request']['import_text'] = '';
	$_FILES = ['import_file' => ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']];
	expect(syslog_recommendations_read_payload())->toBeFalse();

	// A path that was not uploaded through HTTP is never trusted.
	$_FILES = ['import_file' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__]];
	expect(syslog_recommendations_read_payload())->toBeFalse();
});

it('reads import files but refuses missing or empty ones', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');

	expect(syslog_recommendations_read_file($file))->toBeFalse();
	file_put_contents($file, '[]');
	expect(syslog_recommendations_read_file($file))->toBe('[]')
		->and(syslog_recommendations_read_file($file . '.missing'))->toBeFalse();
	unlink($file);
});

it('ignores message tokens longer than any identifier', function () {
	$candidates = syslog_recommendation_candidates(str_repeat('a', 200) . ' ok_token');

	expect($candidates)->toContain('ok_token')->and($candidates)->not->toContain(str_repeat('a', 200));
});

it('limits how many candidate identifiers one message can produce', function () {
	$message = implode(' ', array_map(static fn ($n) => 'Word' . $n, range(1, 150)));

	expect(syslog_recommendation_candidates($message))->toHaveCount(120);
});

it('remembers the bundled seed was applied', function () {
	$flag = [];
	test_override('syslog_db_execute_prepared', static fn () => true);
	test_override('syslog_db_affected_rows', static fn () => 1);
	test_override('set_config_option', function ($name, $value) use (&$flag) {
		$flag[$name] = $value;
	});

	expect(syslog_recommendations_seed())->toBeGreaterThan(0)
		->and($flag)->toBe([SYSLOG_RECOMMENDATIONS_SEED_FLAG => '1']);
});

it('ships compressed catalogues that import without losing records or fields', function (string $file, string $vendor, int $count) {
	$payload = syslog_recommendations_read_file(dirname(__DIR__, 2) . '/docs/' . $file);
	$decoded = json_decode($payload, true);
	$digest  = hash('sha256', json_encode($decoded['recommendations']));
	unset($decoded);
	$parsed  = syslog_recommendations_parse($payload);

	expect($parsed['invalid'])->toBe(0)
		->and($parsed['records'])->toHaveCount($count)
		->and(hash('sha256', json_encode($parsed['records'])))->toBe($digest)
		->and(array_unique(array_column($parsed['records'], 'vendor')))->toBe([$vendor]);
})->with([
	'Cisco'   => ['cisco.json.gz', 'Cisco', 27318],
	'Juniper' => ['juniper.json.gz', 'Juniper', 14]
]);

it('streams compressed exports across database pages without changing the filters or records', function () {
	$first = array_map(static fn ($id) => array_merge(recommendation_sample(['identifier' => 'CODE_' . $id]), ['id' => $id]), range(1, 500));
	$last  = array_merge(recommendation_sample(['identifier' => 'LAST']), ['id' => 501]);
	$calls = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use ($first, $last, &$calls) {
		$calls[] = [$sql, $params];

		return end($params) === 0 ? $first : [$last];
	});

	ob_start();
	syslog_recommendations_export('WHERE vendor = ?', ['Cisco'], true);
	$gzip = ob_get_clean();
	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, $gzip);

	try {
		$parsed = syslog_recommendations_parse(syslog_recommendations_read_file($file));
		expect(substr($gzip, 0, 2))->toBe("\x1f\x8b")
			->and($parsed['invalid'])->toBe(0)
			->and($parsed['records'])->toHaveCount(501)
			->and($parsed['records'][500])->toBe(recommendation_sample(['identifier' => 'LAST']))
			->and($calls[0][0])->toContain('WHERE vendor = ? AND id > ?')
			->and($calls[0][1])->toBe(['Cisco', 0])
			->and($calls[1][1])->toBe(['Cisco', 500]);
	} finally {
		unlink($file);
	}
});

it('exports an empty compressed document that imports successfully', function () {
	ob_start();
	syslog_recommendations_export('', [], true);
	$parsed = syslog_recommendations_parse(gzdecode(ob_get_clean()));

	expect($parsed['invalid'])->toBe(0)->and($parsed['records'])->toBe([]);
});

it('offers both plain and compressed JSON in the import file picker', function () {
	syslog_load_plugin_source('includes/functions.php');
	$form = syslog_test_extract_function('syslog_recommendations.php', 'recommendation_import_form');
	$fields = [];
	test_override('draw_edit_form', function ($definition) use (&$fields) {
		$fields = $definition['fields'];
	});

	ob_start();
	$form();
	ob_end_clean();

	expect($fields['import_file']['accept'])->toBe('.json,.json.gz')
		->and($fields['import_file']['description'])->toContain('.json.gz')
		->and($fields['import_text']['method'])->toBe('textarea');
});

it('rejects damaged gzip imports instead of accepting a partial document', function (string $damage) {
	$payload = gzencode(json_encode([recommendation_sample()]));

	if ($damage === 'checksum') {
		$payload[strlen($payload) - 8] = chr(ord($payload[strlen($payload) - 8]) ^ 1);
	} elseif ($damage === 'truncated') {
		$payload = substr($payload, 0, -1);
	} else {
		$payload .= 'trailing data';
	}

	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, $payload);
	set_error_handler(static fn () => true, E_WARNING);

	try {
		expect(syslog_recommendations_read_file($file))->toBeFalse();
	} finally {
		restore_error_handler();
		unlink($file);
	}
})->with(['checksum', 'truncated', 'trailing']);

it('enforces the expanded import limit and accepts content exactly at that limit', function () {
	$memory_limit = ini_set('memory_limit', '256M');
	$file = tempnam(sys_get_temp_dir(), 'rec');
	$write = static function (int $bytes) use ($file): void {
		$handle = gzopen($file, 'wb');

		while ($bytes > 0) {
			$length = min($bytes, 8192);
			gzwrite($handle, str_repeat(' ', $length));
			$bytes -= $length;
		}

		gzclose($handle);
	};
	$write(SYSLOG_RECOMMENDATIONS_MAX_BYTES);

	try {
		$expanded = syslog_recommendations_read_file($file);
		expect(strlen($expanded))->toBe(SYSLOG_RECOMMENDATIONS_MAX_BYTES);
		unset($expanded);
		$write(SYSLOG_RECOMMENDATIONS_MAX_BYTES + 1);
		expect(syslog_recommendations_read_file($file))->toBeFalse();
	} finally {
		unlink($file);
		unset($expanded);
		ini_set('memory_limit', $memory_limit);
	}
});

it('rejects oversized stored files before reading their contents', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	$handle = fopen($file, 'wb');
	ftruncate($handle, SYSLOG_RECOMMENDATIONS_MAX_BYTES + 1);
	fclose($handle);

	try {
		expect(syslog_recommendations_read_file($file))->toBeFalse();
	} finally {
		unlink($file);
	}
});

it('does not seed a malformed catalogue or mark it applied', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, json_encode([recommendation_sample(), ['explanation' => 'no identifier']]));
	$inserts = [];
	test_override('syslog_db_execute_prepared', function ($sql) use (&$inserts) {
		$inserts[] = $sql;
	});

	try {
		expect(syslog_recommendations_seed($file))->toBe(-1)->and($inserts)->toBe([]);
	} finally {
		unlink($file);
	}
});

it('marks a seed applied even when all of its records already exist', function () {
	$flag = [];
	test_override('syslog_db_execute_prepared', static fn () => true);
	test_override('syslog_db_affected_rows', static fn () => 0);
	test_override('set_config_option', function ($name, $value) use (&$flag) {
		$flag[$name] = $value;
	});

	expect(syslog_recommendations_seed())->toBe(0)
		->and($flag)->toBe([SYSLOG_RECOMMENDATIONS_SEED_FLAG => '1']);
});

it('marks syslog rows for hover lookups only when recommendations exist', function () {
	syslog_load_plugin_source('includes/functions.php');

	test_override('syslog_db_fetch_cell_prepared', static fn () => '1');

	ob_start();
	syslog_row_color('3', 'message');
	$html = ob_get_clean();

	expect($html)->toContain("data-recommendations='1'")->toContain("title='message'");
});
