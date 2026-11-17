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

it('rejects records that are not usable recommendations', function (array $input) {
	expect(syslog_recommendation_normalize($input))->toBeNull();
})->with([
	'no identifier'         => [['explanation' => 'text']],
	'nothing to show'       => [['identifier' => 'X-1-Y']],
	'identifier too long'   => [['identifier' => str_repeat('a', 191), 'explanation' => 'text']],
	'multi-line identifier' => [['identifier' => "a\nb", 'explanation' => 'text']]
]);

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

it('parses the export envelope and a bare list, counting rejected records', function () {
	$record = recommendation_sample();
	$wrapped = syslog_recommendations_parse(json_encode([
		'format' => 'syslog-recommendations', 'version' => 1, 'recommendations' => [$record, ['explanation' => 'no id']]
	]));
	$bare = syslog_recommendations_parse(json_encode([$record]));

	expect($wrapped['records'])->toHaveCount(1)
		->and($wrapped['invalid'])->toBe(1)
		->and($bare['records'][0]['identifier'])->toBe('%LINK-3-UPDOWN');
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

it('extracts message identifiers with and without the percent prefix', function () {
	$candidates = syslog_recommendation_candidates('%LINEPROTO-5-UPDOWN: Line protocol on Interface Gi0/1, changed state to up');

	expect($candidates)->toContain('%LINEPROTO-5-UPDOWN')->toContain('LINEPROTO-5-UPDOWN')
		->and(syslog_recommendation_candidates('RPD_OSPF_NBRDOWN: neighbor down'))->toContain('%RPD_OSPF_NBRDOWN');
});

it('looks messages up by their identifiers and returns the localized JSON', function () {
	$bound = [];
	test_override('syslog_db_fetch_assoc_prepared', function ($sql, $params) use (&$bound) {
		$bound = $params;

		return [recommendation_sample()];
	});

	$response = json_decode(syslog_recommendations_lookup_json('%LINK-3-UPDOWN: Interface Gi0/1, changed state to down'), true);

	expect($bound)->toContain('%LINK-3-UPDOWN')
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

	$response = json_decode(syslog_recommendations_lookup_json('12 34 56'), true);

	expect($response['found'])->toBeFalse()
		->and($response['recommendations'])->toBe([])
		->and($queried)->toBeFalse();
});

it('seeds from single-line INSERT statements only, and only once', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, "-- comment\nCREATE TABLE IF NOT EXISTS `syslog_recommendations` (\n  `id` int\n);\n"
		. "INSERT IGNORE INTO `syslog_recommendations` (vendor) VALUES ('a');\n"
		. "INSERT IGNORE INTO `syslog_recommendations` (vendor) VALUES ('b');\n"
		. "DROP TABLE users;\n");

	$run = [];
	test_override('syslog_db_execute', function ($sql) use (&$run) {
		$run[] = $sql;

		return true;
	});

	expect(syslog_recommendations_seed($file))->toBe(2)
		->and($run)->toHaveCount(2)
		->and(implode('', $run))->not->toContain('DROP');

	unlink($file);

	$flag = [];
	test_override('read_config_option', static fn ($name) => $name === SYSLOG_RECOMMENDATIONS_SEED_FLAG ? '1' : '');
	test_override('set_config_option', function ($name) use (&$flag) {
		$flag[] = $name;
	});

	expect(syslog_recommendations_seed())->toBe(-1)->and($flag)->toBe([]);
});

it('seeds from a gzip compressed file', function () {
	$file = tempnam(sys_get_temp_dir(), 'rec');
	file_put_contents($file, gzencode("-- c\nINSERT IGNORE INTO `syslog_recommendations` (vendor) VALUES ('a');\n", 9));

	$run = [];
	test_override('syslog_db_execute', function ($sql) use (&$run) {
		$run[] = $sql;

		return true;
	});

	expect(syslog_recommendations_seed($file))->toBe(1)->and($run[0])->toEndWith("VALUES ('a');");
	unlink($file);
});

it('reports an unreadable seed file without marking it applied', function () {
	$logged = [];
	test_override('cacti_log', function ($message) use (&$logged) {
		$logged[] = $message;
	});

	expect(syslog_recommendations_seed(sys_get_temp_dir() . '/no-such-seed.sql.gz'))->toBe(-1)
		->and($logged[0])->toContain('not readable');
});

it('does not remember a bundled seed that had failing statements', function () {
	$flag = [];
	test_override('syslog_db_execute', static fn () => false);
	test_override('set_config_option', function ($name) use (&$flag) {
		$flag[] = $name;
	});

	expect(syslog_recommendations_seed())->toBe(0)->and($flag)->toBe([]);
});

it('counts statements that fail to store as failures', function () {
	test_override('syslog_db_execute_prepared', static fn () => false);

	expect(syslog_recommendations_store([recommendation_sample()], false))->toBe(['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1]);
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

it('limits how many candidate identifiers one message can produce', function () {
	$message = implode(' ', array_map(static fn ($n) => 'Word' . $n, range(1, 100)));

	expect(syslog_recommendation_candidates($message))->toHaveCount(80);
});

it('remembers the bundled seed was applied', function () {
	$flag = [];
	test_override('syslog_db_execute', static fn () => true);
	test_override('set_config_option', function ($name, $value) use (&$flag) {
		$flag[$name] = $value;
	});

	expect(syslog_recommendations_seed())->toBeGreaterThan(0)
		->and($flag)->toBe([SYSLOG_RECOMMENDATIONS_SEED_FLAG => '1']);
});

it('ships a gzip seed file whose statements all target the normalized table', function () {
	$handle = gzopen(dirname(__DIR__, 2) . '/' . SYSLOG_RECOMMENDATIONS_SEED_FILE, 'rb');
	$prefix = 'INSERT IGNORE INTO `syslog_recommendations` (vendor, identifier, component, severity, message, explanation, recommended_action) VALUES (';
	$rows   = 0;

	while (($line = gzgets($handle)) !== false) {
		if (stripos($line, 'INSERT') === 0) {
			expect(str_starts_with($line, $prefix))->toBeTrue();
			$rows++;
		}
	}
	gzclose($handle);

	expect($rows)->toBeGreaterThan(100);
});

it('marks syslog rows for hover lookups only when recommendations exist', function () {
	syslog_load_plugin_source('includes/functions.php');

	test_override('syslog_db_fetch_cell_prepared', static fn () => '1');

	ob_start();
	syslog_row_color('3', 'message');
	$html = ob_get_clean();

	expect($html)->toContain("data-recommendations='1'")->toContain("title='message'");
});
