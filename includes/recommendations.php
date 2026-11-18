<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Syslog recommendations: a vendor-neutral knowledge base that maps a message
 * identifier (a Cisco %FACILITY-SEV-MNEMONIC, a Juniper RPD_OSPF_NBRDOWN, ...)
 * to an explanation and a recommended action. The syslog viewer looks a row up
 * when it is hovered; the console page imports, exports and edits the records.
 *
 * Interchange format (JSON). The field names follow the vocabulary of existing
 * rule and event catalogues so data converts easily: Sigma (title/description/
 * level/references), the Elastic Common Schema (event.code/event.severity) and
 * the Cisco/Juniper message references (explanation/recommended action).
 *
 *   {
 *     "format": "syslog-recommendations",
 *     "version": 1,
 *     "recommendations": [
 *       {
 *         "vendor": "Cisco", "identifier": "%LINK-3-UPDOWN", "component": "LINK",
 *         "severity": "error", "message": "Interface [chars], changed state to [chars]",
 *         "explanation": "...", "recommended_action": "...", "reference": "https://..."
 *       }
 *     ]
 *   }
 */

const SYSLOG_RECOMMENDATIONS_FORMAT    = 'syslog-recommendations';
const SYSLOG_RECOMMENDATIONS_VERSION   = 1;
const SYSLOG_RECOMMENDATIONS_SEED_FILE = 'docs/recommendations.sql.gz';
const SYSLOG_RECOMMENDATIONS_MAX_BYTES = 64 * 1024 * 1024;
const SYSLOG_RECOMMENDATIONS_MAX_TEXT  = 20000;
const SYSLOG_RECOMMENDATIONS_SEED_FLAG = 'syslog_recommendations_seeded';

// The shape of a "word" in a syslog message that the hover lookup compares with
// identifiers: an optional literal leading %, then letters, digits and _ . - /
// starting and ending with a letter, digit or underscore. The percent sign is
// retained when matching. Numeric event IDs and short codes qualify. Identifiers
// outside this shape can be stored and exported (the bundled data has some, such
// as %ASA|PIX-3-331001 or %FCDD-SLOT#-3-LOG_FCDD_ERR), but can never match a
// message until they are edited.
const SYSLOG_RECOMMENDATIONS_IDENTIFIER = '%?[A-Za-z0-9_](?:[A-Za-z0-9_.\/-]*[A-Za-z0-9_])?';

// The same shape as a POSIX expression for SQL REGEXP, which MySQL 5.7, MySQL 8
// and MariaDB all accept (no non-capturing groups or escapes).
const SYSLOG_RECOMMENDATIONS_IDENTIFIER_SQL = '^%?[A-Za-z0-9_]([A-Za-z0-9_./-]*[A-Za-z0-9_])?$';

/**
 * Syslog severity names indexed by their RFC 5424 number.
 *
 * @return array<int, string> The severity names.
 */
function syslog_recommendation_severity_names(): array {
	return [
		0 => __('Emergency', 'syslog'),
		1 => __('Alert', 'syslog'),
		2 => __('Critical', 'syslog'),
		3 => __('Error', 'syslog'),
		4 => __('Warning', 'syslog'),
		5 => __('Notice', 'syslog'),
		6 => __('Informational', 'syslog'),
		7 => __('Debug', 'syslog')
	];
}

/**
 * Convert a severity from an import (number, syslog keyword or Sigma level) to 0-7.
 *
 * @param mixed $value The raw severity.
 *
 * @return int|null The syslog severity, or null when it is empty or unknown.
 */
function syslog_recommendation_severity($value): ?int {
	if (is_int($value) || (is_string($value) && preg_match('/^[0-7]$/', trim($value)))) {
		$value = (int) $value;

		return $value >= 0 && $value <= 7 ? $value : null;
	}

	if (!is_string($value)) {
		return null;
	}

	$map = [
		'emergency' => 0, 'emerg' => 0, 'panic' => 0,
		'alert'     => 1,
		'critical'  => 2, 'crit' => 2,
		'error'     => 3, 'err' => 3, 'high' => 3,
		'warning'   => 4, 'warn' => 4, 'medium' => 4,
		'notice'    => 5, 'low' => 5,
		'informational' => 6, 'information' => 6, 'info' => 6,
		'debug'     => 7
	];

	return $map[strtolower(trim($value))] ?? null;
}

/**
 * Remove control characters and cap the length of an imported text value.
 *
 * @param mixed $value The raw value.
 * @param int   $max   The maximum number of characters to keep.
 *
 * @return string The cleaned text.
 */
function syslog_recommendation_text($value, int $max = SYSLOG_RECOMMENDATIONS_MAX_TEXT): string {
	if (is_array($value)) {
		$value = implode("\n", array_filter($value, 'is_scalar'));
	}

	if (!is_scalar($value)) {
		return '';
	}

	$value = (string) $value;
	$value = preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $value)) ?? '';
	$value = trim($value);

	return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

/**
 * Normalize one imported or edited recommendation to the table columns.
 *
 * Besides the native field names, the common names used by other catalogues
 * are accepted (name/code/event_code, description/cause, action/resolution,
 * level, references).
 *
 * @param array<string, mixed> $input  The raw record.
 * @param string|null          $reason Set to why the record was rejected.
 *
 * @return array<string, mixed>|null The normalized record, or null when it is unusable.
 */
function syslog_recommendation_normalize(array $input, ?string &$reason = null): ?array {
	$reason = null;
	$pick   = static function (array $keys) use ($input) {
		foreach ($keys as $key) {
			if (isset($input[$key]) && $input[$key] !== '' && $input[$key] !== []) {
				return $input[$key];
			}
		}

		return '';
	};

	$identifier = rtrim(syslog_recommendation_text($pick(['identifier', 'name', 'code', 'event_code', 'mnemonic']), 191), ": \t");

	if ($identifier === '') {
		$reason = __('the identifier is empty', 'syslog');
	} elseif (strlen($identifier) > 190) {
		$reason = __('the identifier is longer than 190 characters', 'syslog');
	} elseif (preg_match('/\s/u', $identifier)) {
		$reason = __('the identifier contains spaces or line breaks', 'syslog');
	}

	if ($reason !== null) {
		return null;
	}

	$record = [
		'vendor'             => syslog_recommendation_text($pick(['vendor', 'supplier', 'manufacturer']), 64),
		'identifier'         => $identifier,
		'component'          => syslog_recommendation_text($pick(['component', 'facility']), 64),
		'severity'           => syslog_recommendation_severity($pick(['severity', 'level', 'priority'])),
		'message'            => syslog_recommendation_text($pick(['message', 'template', 'pattern'])),
		'explanation'        => syslog_recommendation_text($pick(['explanation', 'description', 'cause'])),
		'recommended_action' => syslog_recommendation_text($pick(['recommended_action', 'action', 'resolution', 'remediation'])),
		'reference'          => syslog_recommendation_text($pick(['reference', 'references', 'url', 'link']))
	];

	// A record with nothing to show a user is not a recommendation.
	if ($record['explanation'] === '' && $record['recommended_action'] === '') {
		$reason = __('it has neither an explanation nor a recommended action', 'syslog');

		return null;
	}

	return $record;
}

/**
 * Whether an identifier can ever match a word of a syslog message.
 *
 * @param string $identifier The stored identifier.
 *
 * @return bool True when the hover lookup can find it.
 */
function syslog_recommendation_matchable(string $identifier): bool {
	return strlen($identifier) <= 190 && preg_match('/^' . SYSLOG_RECOMMENDATIONS_IDENTIFIER . '$/D', $identifier) === 1;
}

/**
 * Write one Cacti log line for every record an import rejected.
 *
 * @param list<array{index: int, identifier: string, reason: string}> $rejected The rejected records.
 *
 * @return void
 */
function syslog_recommendations_log_rejected(array $rejected): void {
	foreach ($rejected as $r) {
		cacti_log(sprintf("WARNING: Recommendation import rejected record %d (identifier '%s'): %s",
			$r['index'], $r['identifier'], $r['reason']), false, 'SYSLOG');
	}
}

/**
 * Parse an uploaded or pasted recommendations document.
 *
 * Accepts the envelope shown at the top of this file or a bare JSON list of
 * records.
 *
 * @param string $payload The raw JSON text.
 *
 * @return array{records: list<array<string, mixed>>, invalid: int, rejected: list<array{index: int, identifier: string, reason: string}>}|false
 *         The usable records, the number of rejected ones with the position, identifier and reason of each,
 *         or false when the document is not a recommendations file.
 */
function syslog_recommendations_parse(string $payload) {
	$payload = trim(preg_replace('/^\xEF\xBB\xBF/', '', $payload) ?? '');

	if ($payload === '' || ($payload[0] !== '{' && $payload[0] !== '[')) {
		return false;
	}

	try {
		$decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		return false;
	}

	if (isset($decoded['recommendations'])) {
		if (isset($decoded['format']) && $decoded['format'] !== SYSLOG_RECOMMENDATIONS_FORMAT) {
			return false;
		}

		if (isset($decoded['version']) && $decoded['version'] !== SYSLOG_RECOMMENDATIONS_VERSION) {
			return false;
		}
		$items = $decoded['recommendations'];
	} elseif ($decoded === array_values($decoded)) {
		$items = $decoded;
	} else {
		return false;
	}

	if (!is_array($items)) {
		return false;
	}

	$records  = [];
	$rejected = [];

	foreach (array_values($items) as $position => $item) {
		$reason = null;
		$record = is_array($item) ? syslog_recommendation_normalize($item, $reason) : null;

		if ($record === null) {
			$id = is_array($item) ? ($item['identifier'] ?? $item['name'] ?? $item['code'] ?? $item['event_code'] ?? '') : '';

			$rejected[] = [
				'index'      => $position + 1,
				'identifier' => is_scalar($id) ? substr((string) $id, 0, 200) : '',
				'reason'     => $reason ?? __('it is not a JSON object', 'syslog')
			];
		} else {
			$records[] = $record;
		}
	}

	return ['records' => $records, 'invalid' => count($rejected), 'rejected' => $rejected];
}

/**
 * Store records, either keeping or replacing those that already exist.
 *
 * A record is the same as an existing one when vendor and identifier match.
 *
 * @param list<array<string, mixed>> $records   Normalized records.
 * @param bool                       $overwrite Replace existing records instead of skipping them.
 *
 * @return array{imported: int, updated: int, skipped: int, failed: int} The outcome counts.
 */
function syslog_recommendations_store(array $records, bool $overwrite): array {
	$result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

	$columns = 'vendor, identifier, component, severity, message, explanation, recommended_action, reference';
	$sql     = "INSERT INTO syslog_recommendations ($columns) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

	if ($overwrite) {
		$sql .= ' ON DUPLICATE KEY UPDATE component = VALUES(component), severity = VALUES(severity),
			message = VALUES(message), explanation = VALUES(explanation),
			recommended_action = VALUES(recommended_action), reference = VALUES(reference)';
	} else {
		$sql = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $sql);
	}

	foreach ($records as $r) {
		$params = [$r['vendor'], $r['identifier'], $r['component'], $r['severity'], $r['message'],
			$r['explanation'], $r['recommended_action'], $r['reference']];

		if (!syslog_db_execute_prepared($sql, $params)) {
			$result['failed']++;
			cacti_log(sprintf("WARNING: Recommendation import could not store '%s' (vendor '%s'); see the database error above",
				$r['identifier'], $r['vendor']), false, 'SYSLOG');

			continue;
		}

		// MySQL reports 1 for an insert, 2 for a changed duplicate and 0 for no change.
		$affected = (int) syslog_db_affected_rows();

		if ($affected === 1) {
			$result['imported']++;
		} elseif ($affected === 2) {
			$result['updated']++;
		} else {
			$result['skipped']++;
		}
	}

	return $result;
}

/**
 * Convert a table row to its export form.
 *
 * @param array<string, mixed> $row The table row.
 *
 * @return array<string, mixed> The exported record.
 */
function syslog_recommendation_export_record(array $row): array {
	return [
		'vendor'             => (string) $row['vendor'],
		'identifier'         => (string) $row['identifier'],
		'component'          => (string) $row['component'],
		'severity'           => $row['severity'] === null ? null : (int) $row['severity'],
		'message'            => (string) $row['message'],
		'explanation'        => (string) $row['explanation'],
		'recommended_action' => (string) $row['recommended_action'],
		'reference'          => (string) $row['reference']
	];
}

/**
 * Stream recommendations as a JSON document, never holding the full set in memory.
 *
 * @param string            $where  An SQL WHERE clause (with leading WHERE) over syslog_recommendations, or ''.
 * @param array<int, mixed> $params The bind values for $where.
 *
 * @return void
 */
function syslog_recommendations_export(string $where = '', array $params = []): void {
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE |
		JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT;

	print '{' . "\n";
	print '    "format": ' . json_encode(SYSLOG_RECOMMENDATIONS_FORMAT) . ",\n";
	print '    "version": ' . SYSLOG_RECOMMENDATIONS_VERSION . ",\n";
	print '    "recommendations": [';

	$last  = 0;
	$first = true;
	$glue  = $where === '' ? 'WHERE' : $where . ' AND';

	do {
		$rows = syslog_db_fetch_assoc_prepared(
			"SELECT * FROM syslog_recommendations $glue id > ? ORDER BY id LIMIT 500",
			array_merge($params, [$last])
		);
		$rows = is_array($rows) ? $rows : [];

		foreach ($rows as $row) {
			$last  = (int) $row['id'];
			$json  = (string) json_encode(syslog_recommendation_export_record($row), $flags);
			$json  = preg_replace('/^/m', '        ', $json) ?? $json;
			print ($first ? "\n" : ",\n") . $json;
			$first = false;
		}
	} while (count($rows) === 500);

	print ($first ? '' : "\n    ") . "]\n}\n";
}

/**
 * Read an import file, refusing empty or oversized ones.
 *
 * @param string $path The file to read.
 *
 * @return string|false The contents, or false when the file is unusable.
 */
function syslog_recommendations_read_file(string $path) {
	clearstatcache(true, $path);
	$size = is_file($path) ? filesize($path) : false;

	return $size === false || $size <= 0 || $size > SYSLOG_RECOMMENDATIONS_MAX_BYTES ? false : file_get_contents($path);
}

/**
 * Read an import from the pasted text or the uploaded file field.
 *
 * @return string|false The payload, or false when nothing usable was provided.
 */
function syslog_recommendations_read_payload() {
	$text = (string) get_nfilter_request_var('import_text');

	if (trim($text) !== '') {
		return strlen($text) <= SYSLOG_RECOMMENDATIONS_MAX_BYTES ? $text : false;
	}

	$file = $_FILES['import_file'] ?? null;
	$path = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) ($file['tmp_name'] ?? ''))
		? (string) $file['tmp_name'] : '';

	return $path === '' ? false : syslog_recommendations_read_file($path);
}

/**
 * Pull the candidate message identifiers out of a syslog message.
 *
 * A candidate is a word that looks like a message code: it starts with a
 * letter (optionally after a leading %) and may contain digits, underscores,
 * dashes and dots. A leading % is retained as part of the candidate and must
 * match the identifier as stored.
 *
 * @param string $message The syslog message text.
 *
 * @return list<string> The distinct candidate identifiers.
 */
function syslog_recommendation_candidates(string $message): array {
	if (!preg_match_all('/' . SYSLOG_RECOMMENDATIONS_IDENTIFIER . '/', substr($message, 0, 2048), $matches)) {
		return [];
	}

	$out = [];

	foreach ($matches[0] as $token) {
		if (strlen($token) > 190) {
			continue;
		}
		$out[$token] = true;

		if (count($out) >= 120) {
			break;
		}
	}

	return array_map('strval', array_keys($out));
}

/**
 * Find the recommendations that apply to a syslog message.
 *
 * @param string $message The syslog message text.
 * @param int    $limit   The maximum number of recommendations to return.
 *
 * @return list<array<string, mixed>> The matching rows, longest identifier first.
 */
function syslog_recommendations_lookup(string $message, int $limit = 3): array {
	$candidates = syslog_recommendation_candidates($message);

	if (!$candidates) {
		return [];
	}

	$rows = syslog_db_fetch_assoc_prepared(
		'SELECT vendor, identifier, component, severity, message, explanation, recommended_action, reference
		FROM syslog_recommendations
		WHERE identifier IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')
		ORDER BY CHAR_LENGTH(identifier) DESC, vendor
		LIMIT ' . max(1, $limit),
		$candidates,
		false
	);

	return is_array($rows) ? $rows : [];
}

/**
 * Shape recommendation rows for the browser: severity names resolved and
 * every column a string, so the client inserts them as plain text.
 *
 * @param list<array<string, mixed>> $rows Rows from syslog_recommendations_lookup().
 *
 * @return list<array<string, mixed>> The rows ready for JSON encoding.
 */
function syslog_recommendations_format(array $rows): array {
	$severities = syslog_recommendation_severity_names();
	$matches    = [];

	foreach ($rows as $row) {
		$severity  = $row['severity'] === null ? null : (int) $row['severity'];
		$matches[] = [
			'vendor'             => (string) $row['vendor'],
			'identifier'         => (string) $row['identifier'],
			'component'          => (string) $row['component'],
			'severity'           => $severity,
			'severity_name'      => $severity === null ? '' : ($severities[$severity] ?? ''),
			'message'            => (string) $row['message'],
			'explanation'        => (string) $row['explanation'],
			'recommended_action' => (string) $row['recommended_action'],
			'reference'          => (string) $row['reference']
		];
	}

	return $matches;
}

/**
 * The localized section labels shown with recommendations, so the browser
 * needs no translation catalogue of its own.
 *
 * @return array<string, string> The labels keyed by section.
 */
function syslog_recommendations_labels(): array {
	return [
		'message'     => __('Message', 'syslog'),
		'explanation' => __('Explanation', 'syslog'),
		'action'      => __('Recommended Action', 'syslog'),
		'reference'   => __('Reference', 'syslog')
	];
}

/**
 * Build the JSON response the hover tooltip requests for a message.
 *
 * @param string $message The syslog message text.
 *
 * @return string The JSON document. It always carries the localized labels so
 *                the browser needs no translation catalogue of its own.
 */
function syslog_recommendations_lookup_json(string $message): string {
	$matches = syslog_recommendations_format(syslog_recommendations_lookup($message));

	return (string) json_encode([
		'found'           => $matches !== [],
		'recommendations' => $matches,
		'labels'          => syslog_recommendations_labels()
	], JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Whether any recommendations exist, so the viewer can skip hover lookups.
 *
 * @return bool True when the table holds at least one recommendation.
 */
function syslog_recommendations_available(): bool {
	static $available = null;

	if ($available === null) {
		$available = (bool) syslog_db_fetch_cell_prepared('SELECT id FROM syslog_recommendations LIMIT 1', [], '', false);
	}

	return $available;
}

/**
 * Load the bundled recommendations the first time the plugin is installed or upgraded.
 *
 * The seed file is only ever applied once (INSERT IGNORE, so records a user
 * added or changed are never overwritten), and a user who removes the bundled
 * records is not given them back by a later upgrade.
 *
 * @param string|null $file An alternative seed file, mainly for tests.
 *
 * @return int The number of statements executed, or -1 when the seed was skipped.
 */
function syslog_recommendations_seed(?string $file = null): int {
	$custom = $file !== null;

	if (!$custom && read_config_option(SYSLOG_RECOMMENDATIONS_SEED_FLAG) === '1') {
		return -1;
	}

	$file = $file ?? dirname(__DIR__) . '/' . SYSLOG_RECOMMENDATIONS_SEED_FILE;

	// gzopen() reads a plain file just as well as a gzip one, one line at a time.
	$fh = function_exists('gzopen') && is_readable($file) ? gzopen($file, 'rb') : false;

	if ($fh === false) {
		cacti_log('SYSLOG WARNING: Recommendations seed file ' . $file . ' is not readable or the PHP zlib extension is missing', false, 'SYSTEM');

		return -1;
	}

	if (function_exists('set_time_limit')) {
		@set_time_limit(0);
	}

	$executed = 0;
	$failed   = false;

	// One INSERT per line; comments and the CREATE TABLE statement are documentation.
	while (($line = gzgets($fh)) !== false) {
		if (!str_starts_with($line, 'INSERT IGNORE INTO `syslog_recommendations`')) {
			continue;
		}

		if (syslog_db_execute(rtrim($line))) {
			$executed++;
		} else {
			$failed = true;
		}
	}
	gzclose($fh);

	if (!$failed && !$custom) {
		set_config_option(SYSLOG_RECOMMENDATIONS_SEED_FLAG, '1');
	}

	return $executed;
}
