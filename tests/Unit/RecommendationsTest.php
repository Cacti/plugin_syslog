<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Syslog recommendations: import/export normalization, message lookup and the
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
	$message = implode(' ', array_map(static fn ($n) => 'Word' . $n, range(1, 121)));

	$candidates = syslog_recommendation_candidates($message);
	expect($candidates)->toHaveCount(120)
		->and($candidates[119])->toBe('Word120')
		->and($candidates)->not->toContain('Word121');
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
	'Cisco'              => ['cisco.json.gz', 'Cisco', 27318],
	'Juniper'            => ['juniper.json.gz', 'Juniper', 14],
	'Arista'             => ['arista.json.gz', 'Arista', 1047],
	'Dell'               => ['dell.json.gz', 'Dell', 7142],
	'HPE'                => ['hpe.json.gz', 'HPE', 4883],
	'Huawei'             => ['huawei.json.gz', 'Huawei', 3686],
	'NVIDIA'             => ['nvidia.json.gz', 'NVIDIA', 251],
	'Nokia'              => ['nokia.json.gz', 'Nokia', 2016],
	'Fortinet'           => ['fortinet.json.gz', 'Fortinet', 1462],
	'Palo Alto Networks' => ['paloalto.json.gz', 'Palo Alto Networks', 398],
	'MikroTik'           => ['mikrotik.json.gz', 'MikroTik', 11]
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

it('reports unavailable or failing zlib operations without returning a successful document', function ($mode, $disabled, $expected) {
	$process = new \Symfony\Component\Process\Process([
		PHP_BINARY, '-d', 'disable_functions=' . $disabled,
		dirname(__DIR__) . '/Fixtures/recommendations_zlib.php', $mode
	]);
	$process->mustRun();

	expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
	'export initialization' => ['export-init', 'deflate_init', 'Unable to initialize recommendation export compression'],
	'export compression' => ['export-write', 'deflate_add', 'Unable to compress recommendation export'],
	'import initialization' => ['import-init', 'inflate_init', false],
	'zlib unavailable' => ['import-unavailable', 'inflate_init', false]
]);

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

it('renders message rows without hover titles or recommendation lookups', function () {
	syslog_load_plugin_source('includes/functions.php');

	$queries = [];
	test_override('syslog_db_fetch_cell_prepared', function ($sql) use (&$queries) {
		$queries[] = $sql;

		return '1';
	});

	ob_start();
	syslog_row_color('3', '<script>alert("message")</script>');
	$html = ob_get_clean();

	expect($html)->not->toContain('title=')
		->not->toContain('data-recommendations')
		->and($queries)->toBe([]);
});

it('batch-checks page messages with the same matching rules as the flyout', function (bool $lowercase_matches) {
	$db = new PDO('sqlite::memory:');
	$schema = $lowercase_matches
		? 'CREATE TABLE syslog_recommendations (identifier TEXT COLLATE NOCASE, vendor TEXT, component TEXT,
			severity INTEGER, message TEXT, explanation TEXT, recommended_action TEXT, reference TEXT)'
		: 'CREATE TABLE syslog_recommendations (identifier TEXT COLLATE BINARY, vendor TEXT, component TEXT,
			severity INTEGER, message TEXT, explanation TEXT, recommended_action TEXT, reference TEXT)';
	$db->exec($schema);
	$db->exec("INSERT INTO syslog_recommendations (identifier) VALUES ('%LINK-3-UPDOWN'), ('%LINK-3-UPDOWN'), ('4625'), ('B')");
	$queries = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use ($db, &$queries) {
		$queries[] = $params;
		// Emulate MySQL's case-insensitive connection collation on bound candidates.
		$sql = str_replace('SELECT ? AS identifier', 'SELECT ? COLLATE NOCASE AS identifier', $sql);
		$statement = $db->prepare(str_replace('CHAR_LENGTH(', 'LENGTH(', $sql));
		$statement->execute($params);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	});

	$messages = [
		'stored'  => '%link-3-updown: Interface Gi0/1 down',
		'removed' => '%LINK-3-UPDOWN: Interface Gi0/2 down',
		'alert'   => 'EventID 4625: Login failed',
		'short'   => 'B',
		'plain'   => 'LINK-3-UPDOWN',
		'suffix'  => '%LINK-3-UPDOWN-EXT',
		'empty'   => ''
	];
	$expected = ['stored' => $lowercase_matches, 'removed' => true, 'alert' => true, 'short' => true, 'plain' => false, 'suffix' => false, 'empty' => false];

	expect(syslog_recommendations_for_messages($messages))->toBe($expected)
		->and($queries)->toHaveCount(1)
		->and($queries[0])->toContain('4625')
		->and(array_keys($queries[0], '%link-3-updown', true))->toHaveCount(1);

	foreach ($messages as $key => $message) {
		expect(syslog_recommendations_lookup($message) !== [])->toBe($expected[$key]);
	}
})->with([
	'case-insensitive catalogue' => [true],
	'case-sensitive catalogue'   => [false]
]);

it('names the catalogue vendor from a device sysDescr or its template', function (string $descr, string $template, string $vendor) {
	expect(syslog_recommendation_vendor_from_device($descr, $template))->toBe($vendor);
})->with([
	'Arista'           => ['Arista Networks EOS version 4.30.1F running on an Arista Networks DCS-7050SX3-48YC8', '', 'Arista'],
	'HPE Comware'      => ['HPE Comware Platform Software, Software Version 7.1.070', '', 'HPE'],
	'Aruba AOS-CX'     => ['Aruba JL659A 6300M 48SR5 CL6 PoE 4SFP56 Swch FL.10.13.1000', '', 'HPE'],
	'Huawei VRP'       => ['S5735-L48T4S-A1 Huawei Versatile Routing Platform Software VRP (R) software', '', 'Huawei'],
	'Cumulus on Dell'  => ['Cumulus Linux version 5.9.1 running on Dell EMC S5248F-ON', '', 'NVIDIA'],
	'Dell OS10'        => ['Dell EMC Networking OS10 Enterprise. System Description: OS10 Enterprise.', '', 'Dell'],
	'Cisco'            => ['Cisco IOS Software [Cupertino], Catalyst L3 Switch Software', '', 'Cisco'],
	'Juniper'          => ['Juniper Networks, Inc. ex4300-48p Ethernet Switch, kernel JUNOS 21.4R3', '', 'Juniper'],
	'Nokia SR OS'      => ['TiMOS-C-23.10.R1 cpm/hops64 Nokia 7750 SR Copyright (c) 2000-2023 Nokia.', '', 'Nokia'],
	'FortiGate'        => ['FortiGate-60F v7.4.3,build2573,240201 (GA.F)', '', 'Fortinet'],
	'Palo Alto'        => ['Palo Alto Networks PA-3200 series firewall', '', 'Palo Alto Networks'],
	'MikroTik'         => ['RouterOS RB4011iGS+', '', 'MikroTik'],
	'template only'    => ['', 'Arista Switch', 'Arista'],
	'unknown'          => ['Linux web01 6.1.0 x86_64', 'Generic SNMP Device', '']
]);

it('scopes recommendations to the vendor of the sending Cacti device', function () {
	$db = new PDO('sqlite::memory:');
	$db->exec('CREATE TABLE syslog_recommendations (identifier TEXT, vendor TEXT, component TEXT,
		severity INTEGER, message TEXT, explanation TEXT, recommended_action TEXT, reference TEXT)');
	$db->exec("INSERT INTO syslog_recommendations (identifier, vendor, explanation) VALUES
		('4701', 'HPE', 'An HPE event.'), ('%LINK-3-UPDOWN', 'Cisco', 'A link changed state.'), ('GENERIC_CODE', '', 'Any vendor.')");
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use ($db) {
		$statement = $db->prepare(str_replace('CHAR_LENGTH(', 'LENGTH(', $sql));
		$statement->execute($params);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	});
	$devices = [
		'description' => ['vendor-scope-hpe' => 'HPE Comware Platform Software'],
		'hostname'    => ['vendor-scope-cisco.example.com' => 'Cisco IOS Software']
	];
	test_override('db_fetch_assoc_prepared', function ($sql, $params) use ($devices) {
		$column = str_contains($sql, 'WHERE h.description') ? 'description' : 'hostname';
		$rows   = [];

		foreach ($params as $name) {
			if (isset($devices[$column][$name])) {
				$rows[] = ['name' => $name, 'snmp_sysDescr' => $devices[$column][$name], 'template' => ''];
			}
		}

		return $rows;
	});

	$messages = [
		'hpe'      => 'Event|4701|LOG_INFO|AMM|1/1|Port 1/1/1 is up',
		'cisco'    => 'Port 4701 changed state',
		'generic'  => 'GENERIC_CODE raised',
		'unknown'  => 'Port 4701 changed state',
		'cisco_id' => '%LINK-3-UPDOWN: Interface Gi0/1, changed state to down'
	];
	$hosts = [
		'hpe'      => 'vendor-scope-hpe',
		'cisco'    => 'vendor-scope-cisco.example.com',
		'generic'  => 'vendor-scope-cisco.example.com',
		'unknown'  => 'vendor-scope-not-in-cacti',
		'cisco_id' => 'vendor-scope-hpe'
	];

	expect(syslog_recommendations_for_messages($messages, $hosts))
		->toBe(['hpe' => true, 'cisco' => false, 'generic' => true, 'unknown' => true, 'cisco_id' => false]);

	foreach ($messages as $key => $message) {
		expect(syslog_recommendations_lookup($message, 3, $hosts[$key]) !== [])
			->toBe(in_array($key, ['hpe', 'generic', 'unknown'], true));
	}

	expect(array_column(syslog_recommendations_lookup('Port 4701 changed state', 3, 'vendor-scope-hpe'), 'vendor'))->toBe(['HPE']);
});

it('does not query recommendations for an empty page or messages without candidates', function () {
	test_override('syslog_db_fetch_assoc_prepared', static function () {
		throw new RuntimeException('Unexpected recommendation query');
	});

	expect(syslog_recommendations_for_messages([]))->toBe([])
		->and(syslog_recommendations_for_messages(['', ' - : , ']))->toBe([false, false]);
});

it('bounds shared lookup batches instead of querying once per row', function () {
	$queries = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$queries) {
		$queries[] = $params;

		return array_map(static fn ($identifier) => ['identifier' => $identifier], $params);
	});
	$messages = array_map(static fn ($id) => 'EVENT_' . $id, range(1, 501));

	expect(syslog_recommendations_for_messages($messages))->toBe(array_fill(0, 501, true))
		->and($queries)->toHaveCount(2)
		->and($queries[0])->toHaveCount(500)
		->and($queries[1])->toBe(['EVENT_501']);
});

it('surfaces a failed availability query instead of presenting partial results', function () {
	$errors = [];
	$queries = 0;
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$queries) {
		$queries++;

		return $queries === 1 ? array_map(static fn ($identifier) => ['identifier' => $identifier], $params) : false;
	});
	test_override('raise_message', function ($id, $message, $level) use (&$errors) {
		$errors[] = [$message, $level];
	});

	$messages = array_map(static fn ($id) => 'EVENT_' . $id, range(1, 1001));

	expect(syslog_recommendations_for_messages($messages))->toBe(array_fill(0, 1001, false))
		->and($queries)->toBe(2)
		->and($errors)->toBe([['Recommendations could not be loaded.', MESSAGE_LEVEL_ERROR]]);
});

it('renders an accessible static lightbulb only for matching messages', function () {
	$html = syslog_recommendation_indicator(true);

	expect(syslog_recommendation_indicator(false))->toBe('')
		->and($html)->toContain('fa-lightbulb', 'role="img"', 'aria-label="Recommendation available - click the message for details"')
		->not->toContain('title=')
		->not->toContain('onclick')
		->not->toContain('data-action');
});
