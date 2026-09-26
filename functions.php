<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

$syslog_query_builder = __DIR__ . '/lib/QueryBuilder.php';
if (file_exists($syslog_query_builder)) {
	require_once $syslog_query_builder;
}

/**
 * Allowlisted fields and operators shared by validation and the builder.
 *
 * @return array<string, string> Map of field names to display labels.
 */
function syslog_search_fields(): array {
	return ['message' => 'Message', 'host' => 'Host', 'program' => 'Program',
		'facility' => 'Facility', 'priority' => 'Priority', 'logtime' => 'Date',
		'seq' => 'Sequence', 'host_id' => 'Host ID', 'program_id' => 'Program ID',
		'facility_id' => 'Facility ID', 'priority_id' => 'Priority ID'];
}

/**
 * Database-backed values used by query-builder dropdowns.
 *
 * @return array<string, array<int, array<int, string>>> Map of field names to choice arrays.
 */
function syslog_search_choices(): array {
	global $syslogdb_default;
	$choices = [];
	foreach (['facility' => 'syslog_facilities', 'priority' => 'syslog_priorities', 'program' => 'syslog_programs'] as $field => $table) {
		$choices[$field . '_id'] = [];
		if ($field !== 'program') { $choices[$field] = []; }
		foreach (syslog_db_fetch_assoc("SELECT {$field}_id AS id, $field AS name FROM `$syslogdb_default`.`$table` ORDER BY $field") as $record) {
			$choices[$field . '_id'][] = [(string) $record['id'], $record['name'] . ' (' . $record['id'] . ')'];
			if ($field !== 'program') { $choices[$field][] = [$record['name'], $record['name']]; }
		}
	}
	return $choices;
}

/**
 * Bounded suggestions; message/sequence suggestions sample recent records.
 *
 * @param string $field   The field to get suggestions for.
 * @param string $term    The search term.
 * @param string $tab     The current tab.
 * @param string $removal The removal flag.
 *
 * @return array<int, array{value: string, label: string}> Array of suggestion objects.
 */
function syslog_search_suggestions(string $field, string $term, string $tab, string $removal): array {
	global $syslogdb_default;
	if (!isset(syslog_search_fields()[$field]) || $field === 'logtime' || strlen($term) > 1024) {
		return [];
	}
	$pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
	$base = substr($field, -3) === '_id' ? substr($field, 0, -3) : $field;
	$tables = ['host' => 'syslog_hosts', 'program' => 'syslog_programs', 'facility' => 'syslog_facilities', 'priority' => 'syslog_priorities'];
	if (isset($tables[$base]) && !($base === 'host' && $tab === 'alerts')) {
		$table = $tables[$base];
		$records = syslog_db_fetch_assoc_prepared("SELECT $field AS value, $base AS label
			FROM `$syslogdb_default`.`$table`
			WHERE $base LIKE ? ESCAPE '!' OR CAST($field AS CHAR) LIKE ? ESCAPE '!'
			ORDER BY $base LIMIT 30", [$pattern, $pattern]);
	} else {
		if (!in_array($field, ['message', 'seq', 'host'], true)) { return []; }
		$column = $field === 'message' && $tab === 'alerts' ? 'logmsg' : $field;
		$tables = $tab === 'alerts' ? ['syslog_logs'] : ($removal === '1' ? ['syslog', 'syslog_removed'] : [$removal === '-1' ? 'syslog' : 'syslog_removed']);
		$queries = [];
		foreach ($tables as $table) {
			$queries[] = "SELECT $column AS value FROM (SELECT $column FROM `$syslogdb_default`.`$table` ORDER BY seq DESC LIMIT 1000) AS recent_$table";
		}
		$records = syslog_db_fetch_assoc_prepared('SELECT DISTINCT value, value AS label FROM (' . implode(' UNION ALL ', $queries) . ") AS suggestions WHERE value LIKE ? ESCAPE '!' ORDER BY value LIMIT 30", [$pattern]);
	}
	return array_map(function ($record) {
		return ['value' => (string) $record['value'], 'label' => (string) $record['label']];
	}, $records);
}

/**
 * Get available search operators for a field.
 *
 * @param string $field The field name.
 *
 * @return array<int, string> Array of operator strings.
 */
function syslog_search_operators(string $field): array {
	if ($field === 'logtime') { return ['=', '!=', '>', '>=', '<', '<=', 'last']; }
	return $field === 'seq' || substr($field, -3) === '_id' || $field === 'logtime'
		? ['=', '!=', '>', '>=', '<', '<='] : ['contains', '=', '!=', 'like'];
}

/**
 * Parse literal message searches. Uppercase operators bind NOT, AND, then OR.
 *
 * @param string $input The search input string.
 *
 * @return array<int|string, mixed>|null The parsed search tree, or null if empty.
 *
 * @throws InvalidArgumentException When the search is too long, too complex, or malformed.
 */
function syslog_parse_logical_search(string $input): ?array {
	if (strlen($input) > 8192) {
		throw new InvalidArgumentException('Search is too long (maximum 8192 bytes).');
	}

	$tokens = [];
	$length = strlen($input);
	for ($i = 0; $i < $length;) {
		if (ctype_space($input[$i])) {
			$i++;
			continue;
		}
		if (preg_match('/\G([a-z_]+)\s+(contains|like|last|regex|!=|>=|<=|=|>|<)\s+(?=")/', $input, $match, 0, $i)) {
			if (!isset(syslog_search_fields()[$match[1]]) || !in_array($match[2], syslog_search_operators($match[1]), true)) {
				throw new InvalidArgumentException('Invalid field or operator.');
			}
			$tokens[] = ['field', $match[1], $match[2]];
			$i += strlen($match[0]);
			continue;
		}
		if ($input[$i] == '(' || $input[$i] == ')') {
			$tokens[] = [$input[$i++], ''];
		} elseif ($input[$i] == '"') {
			$value = '';
			$closed = false;
			for ($i++; $i < $length; $i++) {
				if ($input[$i] == '"') {
					$i++;
					$closed = true;
					break;
				}
				if ($input[$i] == '\\' && $i + 1 < $length && ($input[$i + 1] == '"' || $input[$i + 1] == '\\')) {
					$i++;
				}
				$value .= $input[$i];
			}
			if (!$closed || $value === '') {
				throw new InvalidArgumentException('Use a nonempty phrase with a closing double quote.');
			}
			$tokens[] = ['term', $value];
		} elseif (preg_match('/\G(AND|OR|NOT)(?=\s|[()"]|$)/', $input, $match, 0, $i)) {
			$tokens[] = [$match[1], ''];
			$i += strlen($match[1]);
		} else {
			$start = $i++;
			while ($i < $length && strpos('()"', $input[$i]) === false) {
				if (ctype_space($input[$i - 1]) && preg_match('/\G(AND|OR|NOT)(?=\s|[()"]|$)/', $input, $match, 0, $i)) {
					break;
				}
				$i++;
			}
			$tokens[] = ['term', trim(substr($input, $start, $i - $start))];
		}
	}
	if (!$tokens) {
		return null;
	}
	if (count($tokens) > 256) {
		throw new InvalidArgumentException('Search is too complex (maximum 256 tokens).');
	}
	$position = 0;
	$parse = function ($minimum = 0, $depth = 0) use (&$parse, &$position, $tokens) {
		if ($depth > 32) {
			throw new InvalidArgumentException('Search nesting is too deep (maximum 32 levels).');
		}
		/** @var array<int, string> $token A token: ['field', name, operator], ['term', value], ['NOT'|'AND'|'OR'|'('|')', '']. */
		$token = $tokens[$position++] ?? ['', ''];
		if ($token[0] == 'NOT') {
			$node = ['NOT', $parse(3, $depth + 1)];
		} elseif ($token[0] == '(') {
			$node = $parse(0, $depth + 1);
			if (($tokens[$position++][0] ?? '') != ')') {
				throw new InvalidArgumentException('Expected a closing parenthesis.');
			}
		} elseif ($token[0] == 'field') {
			$value = $tokens[$position++] ?? [];
			if (($value[0] ?? '') !== 'term') {
				throw new InvalidArgumentException('Expected a quoted field value.');
			}
			if (($token[1] === 'seq' || substr($token[1], -3) === '_id') && !ctype_digit($value[1])) {
				throw new InvalidArgumentException('IDs must be nonnegative integers.');
			}
			if ($token[2] === 'last' && !in_array($value[1], ['3600', '21600', '86400', '604800', '1209600', '2592000', '3months', '6months'], true)) {
				throw new InvalidArgumentException('Invalid date preset.');
			}
			if ($token[1] === 'logtime' && $token[2] !== 'last' && (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value[1]) || strtotime($value[1]) === false)) {
				throw new InvalidArgumentException('Use a date in YYYY-MM-DD HH:MM:SS format.');
			}
			$node = ['predicate', $token[1], $token[2], $value[1]];
		} elseif ($token[0] == 'term') {
			$node = $token;
		} else {
			throw new InvalidArgumentException('Expected a search term, NOT, or an opening parenthesis.');
		}
		while (isset($tokens[$position])) {
			$operator = $tokens[$position][0];
			$precedence = ['OR' => 1, 'AND' => 2][$operator] ?? 0;
			if (!$precedence || $precedence < $minimum) {
				break;
			}
			$position++;
			$node = [$operator, $node, $parse($precedence + 1, $depth + 1)];
		}
		return $node;
	};
	$tree = $parse();
	if ($position != count($tokens)) {
		throw new InvalidArgumentException('Expected AND or OR between terms, or found an extra closing parenthesis.');
	}
	return $tree;
}

/**
 * LOCATE treats wildcard and regex characters literally and uses column collation.
 *
 * @param array<int|string, mixed>|null $tree   The parsed search tree.
 * @param string                    $column The column name ('message' or 'logmsg').
 *
 * @return string The SQL WHERE clause fragment.
 *
 * @throws InvalidArgumentException When the tree or column is invalid.
 */
function syslog_logical_search_sql(?array $tree, string $column): string {
	if (!in_array($column, ['message', 'logmsg'], true)) {
		throw new InvalidArgumentException('Invalid message column.');
	}
	if ($tree === null) {
		return '';
	}
	if ($tree[0] === 'predicate') {
		global $syslogdb_default;
		[, $field, $operator, $value] = $tree;
		if (!isset(syslog_search_fields()[$field]) || !in_array($operator, syslog_search_operators($field), true)) {
			throw new InvalidArgumentException('Invalid field or operator.');
		}
		if ($field === 'host_id' && $column === 'logmsg') {
			throw new InvalidArgumentException('Host ID is only available for system logs.');
		}
		if ($operator === 'last') {
			if (!in_array($value, ['3600', '21600', '86400', '604800', '1209600', '2592000', '3months', '6months'], true)) {
				throw new InvalidArgumentException('Invalid date preset.');
			}
			$interval = ['3months' => '3 MONTH', '6months' => '6 MONTH'][$value] ?? ((int) $value . ' SECOND');
			return '(syslog.logtime BETWEEN DATE_SUB(NOW(), INTERVAL ' . $interval . ') AND NOW())';
		}
		$target = $field === 'message' ? $column : 'syslog.' . $field;
		if (in_array($field, ['host', 'program', 'facility', 'priority'], true)) {
			if ($field === 'host' && $column === 'logmsg') {
				$target = 'syslog.host';
			} else {
				$table = ['host' => 'syslog_hosts', 'program' => 'syslog_programs', 'facility' => 'syslog_facilities', 'priority' => 'syslog_priorities'][$field];
				$target = "(SELECT search_lookup.$field FROM `$syslogdb_default`.`$table` AS search_lookup WHERE search_lookup.{$field}_id = syslog.{$field}_id)";
			}
		}
		if ($operator === 'contains') {
			return '(LOCATE(' . db_qstr($value) . ', ' . $target . ') > 0)';
		}
		$operator = ['like' => 'LIKE'][$operator] ?? $operator;
		return '(' . $target . ' ' . $operator . ' ' . db_qstr($value) . ')';
	}
	if ($tree[0] == 'term') {
		return '(LOCATE(' . db_qstr($tree[1]) . ', ' . $column . ') > 0)';
	}
	if ($tree[0] == 'NOT') {
		return '(NOT ' . syslog_logical_search_sql($tree[1], $column) . ')';
	}
	return '(' . syslog_logical_search_sql($tree[1], $column) . ' ' . $tree[0] . ' ' . syslog_logical_search_sql($tree[2], $column) . ')';
}

/**
 * Extract positive terms from a parsed search tree.
 *
 * @param array<int|string, mixed>|null $tree     The parsed search tree.
 * @param bool                      $negative Whether to extract negative terms.
 *
 * @return array<int, string> Array of search terms.
 */
function syslog_logical_positive_terms(?array $tree, bool $negative = false): array {
	if ($tree === null) {
		return [];
	}
	if ($tree[0] === 'predicate') {
		return !$negative && $tree[1] === 'message' && in_array($tree[2], ['contains', '='], true) ? [$tree[3]] : [];
	}
	if ($tree[0] == 'term') {
		return $negative ? [] : [$tree[1]];
	}
	if ($tree[0] == 'NOT') {
		return syslog_logical_positive_terms($tree[1], !$negative);
	}
	return array_merge(syslog_logical_positive_terms($tree[1], $negative), syslog_logical_positive_terms($tree[2], $negative));
}

/**
 * Remove the date clause the page entry logic appends to a search, so saved
 * searches stay dynamic (dates are re-derived each time one is applied).
 *
 * @param string $search The search string.
 * @param mixed  $date1  The start date.
 * @param mixed  $date2  The end date.
 *
 * @return string The search string with auto dates removed.
 */
function syslog_strip_auto_dates(string $search, mixed $date1, mixed $date2): string {
	$d1 = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $date1);
	$d2 = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $date2);
	$suffix = 'logtime >= "' . $d1 . '" AND logtime <= "' . $d2 . '"';

	if (substr($search, -strlen($suffix)) === $suffix) {
		$search = substr($search, 0, -strlen($suffix));

		if (substr($search, -5) === ' AND ') {
			$search = substr($search, 0, -5);
		}
	}

	return $search;
}

/**
 * Permission to make saved searches global and to manage other users' global searches.
 *
 * @return bool True if the user has admin permission.
 */
function syslog_saved_search_admin(): bool {
	return api_plugin_user_realm_auth('syslog_saved_searches.php');
}

/**
 * Permission to share saved searches with all syslog users.
 *
 * @return bool True if the user has share permission.
 */
function syslog_saved_search_share(): bool {
	return syslog_saved_search_admin() || api_plugin_user_realm_auth('syslog_saved_searches_share.php');
}

/**
 * Permission to manage all dashboards, including other users' shared dashboards.
 *
 * @return bool True if the user has dashboard admin permission.
 */
function syslog_dashboard_admin(): bool {
	return api_plugin_user_realm_auth('syslog_alerts.php');
}

/**
 * Permission to share dashboards with all syslog users.
 *
 * @return bool True if the user has dashboard share permission.
 */
function syslog_dashboard_share(): bool {
	return syslog_dashboard_admin() || api_plugin_user_realm_auth('syslog_dashboards_share.php');
}

/**
 * The whitelisted share table and item column per shareable item kind.
 *
 * @param string $item The item kind ('dashboard' or 'saved_search').
 *
 * @return array<int, string>|null [table, column], or null for an unknown kind.
 */
function syslog_share_table(string $item): ?array {
	$tables = [
		'dashboard'    => ['syslog_dashboards_perm', 'dashboard_id'],
		'saved_search' => ['syslog_saved_searches_perm', 'search_id']
	];

	return isset($tables[$item]) ? $tables[$item] : null;
}

/**
 * Cacti group ids the session user is a member of, or the given user.
 *
 * @param int $user_id The user ID, or 0 for the current session user.
 *
 * @return array<int, int> Array of group IDs.
 */
function syslog_user_group_ids(int $user_id = 0): array {
	if ($user_id === 0) {
		$user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;
	}

	if ($user_id <= 0) {
		return [];
	}

	$groups = db_fetch_assoc_prepared('SELECT group_id
		FROM user_auth_group_members
		WHERE user_id = ?',
		[$user_id]);

	if (!is_array($groups) || !cacti_sizeof($groups)) {
		return [];
	}

	$ids = [];

	foreach ($groups as $group) {
		$ids[] = (int) $group['group_id'];
	}

	return $ids;
}

/**
 * Ids of one shareable item kind granted to the current user or one of
 * their groups, plus anything granted to everyone through an 'all' row.
 * Returns [] outside a session or when nothing is granted.
 *
 * @param string $item The item kind.
 *
 * @return array<int, int> Array of item IDs granted to the user.
 */
function syslog_shared_item_ids(string $item): array {
	global $syslogdb_default;

	static $cache = [];

	$user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;

	if ($user_id <= 0) {
		return [];
	}

	if (isset($cache[$item])) {
		return $cache[$item];
	}

	$share_table = syslog_share_table($item);

	if ($share_table === null) {
		return [];
	}

	list($table, $column) = $share_table;

	$group_ids = syslog_user_group_ids($user_id);

	$sql = "SELECT $column AS id
		FROM `$syslogdb_default`.`$table`
		WHERE (type = 'user' AND item_id = ?) OR type = 'all'";
	$params = [$user_id];

	if (cacti_sizeof($group_ids)) {
		$sql .= " OR (type = 'group' AND item_id IN (" . implode(',', array_fill(0, cacti_sizeof($group_ids), '?')) . '))';

		foreach ($group_ids as $group_id) {
			$params[] = $group_id;
		}
	}

	$rows = syslog_db_fetch_assoc_prepared($sql, $params);

	$ids = [];

	if (cacti_sizeof($rows)) {
		foreach ($rows as $row) {
			if (isset($row['id']) && (int) $row['id'] > 0) {
				$ids[] = (int) $row['id'];
			}
		}
	}

	$cache[$item] = $ids;

	return $ids;
}

/**
 * Current grants on one item, shaped for the drop_multi form fields.
 *
 * @param string $item   The item kind.
 * @param int    $item_id The item ID.
 *
 * @return array{users: array<int, array{id: string|int}>, groups: array<int, array{id: string|int}>}
 */
function syslog_fetch_item_shares(string $item, int $item_id): array {
	global $syslogdb_default;

	$shares = ['users' => [], 'groups' => []];

	$share_table = syslog_share_table($item);

	if ($share_table === null || (int) $item_id <= 0) {
		return $shares;
	}

	list($table, $column) = $share_table;

	$rows = syslog_db_fetch_assoc_prepared("SELECT type, item_id
		FROM `$syslogdb_default`.`$table`
		WHERE $column = ?",
		[(int) $item_id]);

	if (cacti_sizeof($rows)) {
		foreach ($rows as $row) {
			if ($row['type'] === 'all') {
				// The 'all' grant shows in both selects of the admin forms.
				$shares['users'][]  = ['id' => 'all'];
				$shares['groups'][] = ['id' => 'all'];
			} else {
				$key = $row['type'] === 'group' ? 'groups' : 'users';

				$shares[$key][] = ['id' => (int) $row['item_id']];
			}
		}
	}

	return $shares;
}

/**
 * Normalize a posted multiselect of user or group ids to unique integers, allowing the 'all' sentinel.
 *
 * @param string $name The request variable name.
 *
 * @return array<int, string|int> Array of IDs with 'all' sentinel allowed.
 */
function syslog_parse_share_ids(string $name): array {
	if (!isset_request_var($name)) {
		return [];
	}

	$raw = get_nfilter_request_var($name);

	if (!is_array($raw)) {
		return [];
	}

	$ids = [];

	foreach ($raw as $id) {
		if ($id === 'all') {
			$ids[] = 'all';
		} elseif ((int) $id > 0) {
			$ids[] = (int) $id;
		}
	}

	return array_values(array_unique($ids));
}

/**
 * Replace the user and group share rows of one item. Unknown ids are kept;
 * they simply never match a real user or group. The 'all' sentinel grants
 * the item to every signed-in user with a single row (item_id 0).
 *
 * @param string       $item    The item kind.
 * @param int          $item_id The item ID.
 * @param array<int|string> $users Array of user IDs or 'all'.
 * @param array<int|string> $groups Array of group IDs or 'all'.
 *
 * @return void
 */
function syslog_save_item_shares(string $item, int $item_id, array $users, array $groups): void {
	global $syslogdb_default;

	$share_table = syslog_share_table($item);

	if ($share_table === null || (int) $item_id <= 0) {
		return;
	}

	list($table, $column) = $share_table;

	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`$table`
		WHERE $column = ?",
		[(int) $item_id]);

	// Duplicates would collide with the composite primary key.
	$users  = array_unique(array_map('strval', $users));
	$groups = array_unique(array_map('strval', $groups));

	$grants = [];

	if (in_array('all', $users, true) || in_array('all', $groups, true)) {
		$grants[] = [(int) $item_id, 'all', 0];
	}

	foreach ($users as $user_id) {
		if ((int) $user_id > 0) {
			$grants[] = [(int) $item_id, 'user', (int) $user_id];
		}
	}

	foreach ($groups as $group_id) {
		if ((int) $group_id > 0) {
			$grants[] = [(int) $item_id, 'group', (int) $group_id];
		}
	}

	foreach ($grants as $grant) {
		syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`$table`
			($column, type, item_id)
			VALUES (?, ?, ?)",
			$grant);
	}
}

/**
 * Format a message value with highlighted search terms.
 *
 * @param string $value  The message value.
 * @param string $filter The filter settings.
 * @param string $href   Optional link href.
 *
 * @return string The formatted HTML output.
 */
function syslog_message_filter_value(string $value, string $filter, string $href = ''): string {
	if (get_request_var('search_mode') != 'logical') {
		return filter_value($value, $filter, $href);
	}
	$terms = syslog_logical_positive_terms($GLOBALS['syslog_search_tree'] ?? null);
	usort($terms, function ($a, $b) { return strlen($b) - strlen($a); });
	$pattern = $terms ? '~(' . implode('|', array_map(function ($term) { return preg_quote($term, '~'); }, $terms)) . ')~iu' : '';
	$parts = $pattern ? preg_split($pattern, $value, -1, PREG_SPLIT_DELIM_CAPTURE) : [$value];
	if ($parts === false) {
		$parts = [$value];
	}
	$output = '';
	foreach ($parts as $index => $part) {
		$escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$output .= $index % 2 ? '<span class="filteredValue">' . $escaped . '</span>' : $escaped;
	}
	return $href === '' ? $output : '<a class="linkEditMain" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $output . '</a>';
}

/**
 * Apply a bulk action to selected items.
 *
 * @param array<int, mixed>|false $selected_items Array of selected item IDs, or false.
 * @param string                  $drp_action     The selected action.
 * @param array<int|string, string> $action_map     Map of actions to function names.
 * @param string                  $export_action  Optional export action name.
 * @param string                  $export_items   Optional export items config.
 *
 * @return void
 */
function syslog_apply_selected_items_action($selected_items, string $drp_action, array $action_map, string $export_action = '', string $export_items = ''): void {
	if ($selected_items != false) {
		if (isset($action_map[$drp_action])) {
			$action_function = $action_map[$drp_action];

			if (function_exists($action_function)) {
				foreach ($selected_items as $selected_item) {
					$action_function($selected_item);
				}
			} else {
				cacti_log("SYSLOG ERROR: Bulk action function '$action_function' not found.", false, 'SYSTEM');
			}
		} elseif ($export_action != '' && $drp_action == $export_action) {
			$_SESSION['exporter'] = rawurlencode(serialize($selected_items));
		}
	}
}

/**
 * Download in a separate browsing context so Cacti's page-unload spinner never starts.
 *
 * @param string $url The URL to load in the iframe.
 *
 * @return void
 */
function syslog_download_frame(string $url = ''): void {
	print "<iframe id='syslog_download' name='syslog_download' hidden title='" . __esc('Syslog download', 'syslog') . "' src='" . html_escape($url === '' ? 'about:blank' : $url) . "'></iframe>";
}

/**
 * Close a native bulk confirmation form, targeting exports at the download frame.
 *
 * @param bool $export Whether this is an export form.
 *
 * @return void
 */
function syslog_export_form_end(bool $export): void {
	global $form_id;

	form_end(false);
	if (!$export) {
		return;
	}
	syslog_download_frame();
	?>
	<script type='text/javascript'>
	(function() {
		var form = document.getElementById(<?php print syslog_json_safe($form_id); ?>);
		if (form) form.target = 'syslog_download';
	})();
	</script>
	<?php
}

/**
 * Include syslog plugin JavaScript and CSS assets.
 *
 * @return void
 */
function syslog_include_js(): void {
	global $config;
	?>
	<link rel='stylesheet' href='<?php print $config['url_path']; ?>plugins/syslog/css/search.css?v=<?php print filemtime(__DIR__ . '/css/search.css'); ?>'>
	<link rel='stylesheet' href='<?php print $config['url_path']; ?>plugins/syslog/css/dashboard.css?v=<?php print filemtime(__DIR__ . '/css/dashboard.css'); ?>'>
	<script type='text/javascript' src='<?php print $config['url_path']; ?>plugins/syslog/js/filter-builder.js?v=<?php print filemtime(__DIR__ . '/js/filter-builder.js'); ?>'></script>
	<script type='text/javascript' src='<?php print $config['url_path']; ?>plugins/syslog/js/dashboard.js?v=<?php print filemtime(__DIR__ . '/js/dashboard.js'); ?>'></script>
	<script type='text/javascript' src='<?php print $config['url_path']; ?>plugins/syslog/js/functions.js?v=<?php print filemtime(__DIR__ . '/js/functions.js'); ?>'></script>
	<?php
}

/**
 * __esc() is not enough inside a <script> block, because the browser does
 * not HTML-decode there. The value has to arrive as a JSON literal.
 *
 * @param mixed $value
 *
 * @return string
 */
function syslog_json_safe($value) {
	return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

/**
 * Check if edits are allowed based on remote sync configuration.
 *
 * @return bool True if edits are allowed, false otherwise.
 */
function syslog_allow_edits(): bool {
	global $config;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] > 1) {
			return false;
		}
	}

	return true;
}

/**
 * Whether the current user can change Syslog alarm, removal, or report rules.
 *
 * Rule Administrators are intentionally distinct from Rule Viewers.  Cacti
 * admits viewers to the real page filenames; all writes additionally require
 * this permission-only realm.
 *
 * @return bool True when rule edits are permitted for this user and poller.
 */
function syslog_allow_rule_edits(): bool {
	return syslog_allow_edits() && api_plugin_user_realm_auth('syslog_rule_administrator.php');
}

/**
 * Save data with remote sync support.
 *
 * @param array<string, mixed> $data    The data to save.
 * @param string               $table   The table name.
 * @param string               $primary The primary key column name.
 *
 * @return void
 */
function syslog_sync_save(array $data, string $table, string $primary = ''): void {
	global $config, $syslogdb_default;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] == 1) {
			$id = syslog_sql_save($data, $table, $primary);

			if ($id > 0) {
				raise_message(1);
			} else {
				raise_message(2);
			}

			$pollers = array_rekey(
				db_fetch_assoc('SELECT poller_id
					FROM pollers
					WHERE disabled = ""
					AND id > 1'),
				'id', 'id'
			);

			if (cacti_sizeof($pollers)) {
				foreach ($pollers as $poller_id) {
					$rcnn_id = poller_connect_to_remote($poller_id);

					if ($rcnn_id !== false) {
						$id = sql_save($data, $table, $primary, true, $rcnn_id);
					}
				}
			}
		} else {
			raise_message('syslog_denied', __('Save Failed.  Remote Data Collectors in Sync Mode are not allowed to Save Rules.  Save from the Main Cacti Server instead.', 'syslog'), MESSAGE_LEVEL_ERROR);
		}
	} else {
		$id = syslog_sql_save($data, $table, $primary);

		if ($id > 0) {
			raise_message(1);
		} else {
			raise_message(2);
		}
	}
}

/**
 * Send email alert with optional SMS support.
 *
 * @param string $to         Recipient email address (may include sms@ addresses).
 * @param array<int, string> $from Sender email and name as a list: [email, name].
 * @param string $subject    Email subject.
 * @param string $message    Email message body (HTML).
 * @param string $smsmessage SMS message body.
 *
 * @return void
 */
function syslog_sendemail(string $to, array $from, string $subject, string $message, string $smsmessage = ''): void {
	syslog_debug("Sending Alert email to '" . $to . "'");

	$sms    = '';
	$nonsms = '';

	// if there are SMS emails, process separately
	if (substr_count($to, 'sms@')) {
		$emails = explode(',', $to);

		if (cacti_sizeof($emails)) {
			foreach ($emails as $email) {
				if (substr_count($email, 'sms@')) {
					$sms .= ($sms != '' ? ', ' : '') . str_replace('sms@', '', trim($email));
				} else {
					$nonsms .= ($nonsms != '' ? ', ' : '') . trim($email);
				}
			}
		}
	} else {
		$nonsms = $to;
	}

	if (strlen($sms) && $smsmessage != '') {
		mailer($from, $sms, '', '', '', $subject, '', $smsmessage);
	}

	if (strlen($nonsms)) {
		if (read_config_option('syslog_html') == 'on') {
			mailer($from, $nonsms, '', '', '', $subject, $message, __('Please use an HTML Email Client', 'syslog'));
		} else {
			$message = strip_tags(str_replace('<br>', "\n", $message));
			mailer($from, $nonsms, '', '', '', $subject, '', $message, '', '', false);
		}
	}
}

const SYSLOG_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const SYSLOG_IMPORT_VERSION   = 1;

/**
 * Get import payload from text input or uploaded file.
 *
 * @param string $redirect_url URL to redirect to on error.
 *
 * @return string The import payload.
 *
 * @throws void Exits on error.
 */
function syslog_get_import_xml_payload($redirect_url) {
	$import_text = (string) get_nfilter_request_var('import_text');

	if (strlen($import_text) > SYSLOG_IMPORT_MAX_BYTES) {
		cacti_log('SYSLOG ERROR: Text import payload exceeds the maximum size', false, 'SYSTEM');
		raise_message('syslog_import_size_error', __('Text import payload exceeds the maximum size', 'syslog'), MESSAGE_LEVEL_ERROR);
		header('Location: ' . $redirect_url);
		exit;
	}

	if (trim($import_text) !== '') {
		// textbox input
		return $import_text;
	}

	if (isset($_FILES['import_file']['tmp_name']) &&
		$_FILES['import_file']['tmp_name'] !== 'none' &&
		$_FILES['import_file']['tmp_name'] !== '') {
		// file upload
		$tmp_name = $_FILES['import_file']['tmp_name'];

		if (!isset($_FILES['import_file']['error']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
			raise_message('syslog_import_error', __('Unable to read the uploaded import file. Check the file and upload size limit.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: ' . $redirect_url);
			exit;
		}

		if (!is_uploaded_file($tmp_name)) {
			raise_message('syslog_import_error', __('Unable to read the uploaded import file. Check the file and upload size limit.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: ' . $redirect_url);
			exit;
		}

		$import_data = syslog_read_import_file($tmp_name);

		if ($import_data === false) {
			cacti_log('SYSLOG ERROR: Uploaded import file is empty, unreadable, or exceeds the maximum size', false, 'SYSTEM');
			raise_message('syslog_import_error', __('Unable to read the uploaded import file. Check the file and upload size limit.', 'syslog'), MESSAGE_LEVEL_ERROR);
			header('Location: ' . $redirect_url);
			exit;
		}

		return $import_data;
	}

	raise_message('syslog_import_error', __('Select an import file or paste its contents before importing.', 'syslog'), MESSAGE_LEVEL_ERROR);
	header('Location: ' . $redirect_url);
	exit;
}

/**
 * Read import file contents safely.
 *
 * @param string $filename The file path to read.
 *
 * @return string|false The file contents, or false on failure.
 */
function syslog_read_import_file(string $filename): string|false {
	$size = filesize($filename);

	if ($size === false || $size <= 0 || $size > SYSLOG_IMPORT_MAX_BYTES) {
		return false;
	}

	$handle = fopen($filename, 'rb');

	if ($handle === false) {
		return false;
	}

	try {
		return fread($handle, $size);
	} finally {
		fclose($handle);
	}
}

/**
 * syslog_rules_array2json - encode a list of rule rows as a JSON export document
 *
 * @param string $table                     Source rule table, used for uniqueness/version metadata
 * @param array<int, array<string, mixed>>  $rules Rule rows (the 'id' key is removed before export)
 *
 * @return string JSON document suitable for download
 */
function syslog_rules_array2json(string $table, array $rules): string {
	$templates = [];

	foreach ($rules as $rule) {
		if (!is_array($rule)) {
			continue;
		}

		unset($rule['id']);

		if (!isset($rule['hash']) || $rule['hash'] === '') {
			cacti_log("SYSLOG WARNING: Exported $table rule is missing a hash", false, 'SYSTEM');
		}

		$templates[] = $rule;
	}

	$encoded = json_encode([
		'version'   => SYSLOG_IMPORT_VERSION,
		'generator' => 'syslog',
		'table'     => $table,
		'templates' => $templates,
	], JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

	// json_encode() only fails on malformed data, which cannot occur here;
	// fall back to an empty string rather than returning false to callers.
	return $encoded === false ? '' : $encoded;
}

/**
 * syslog_parse_rule_import - parse a pasted or uploaded rule import payload
 *
 * Accepts JSON (preferred) or the legacy XML format.  Returns an array of
 * rule arrays keyed by an incremental template index, mirroring the shape
 * previously returned by xml2array() for minimal downstream churn.
 *
 * @param string $payload        Raw import payload.
 * @param string $expected_table Destination object table.
 *
 * @return array<string, array<string, mixed>>|false Parsed templates, or false on failure.
 */
function syslog_parse_rule_import(string $payload, string $expected_table): array|false {
	$trimmed = trim($payload);

	if ($trimmed === '') {
		return false;
	}

	if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
		try {
			$decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return false;
		}

		if (!is_array($decoded)) {
			return false;
		}

		if (isset($decoded['table']) && $decoded['table'] !== $expected_table) {
			return false;
		}
		if (isset($decoded['version']) && $decoded['version'] !== SYSLOG_IMPORT_VERSION) {
			return false;
		}

		$templates = [];

		if (isset($decoded['templates']) && is_array($decoded['templates'])) {
			$templates = $decoded['templates'];
		} elseif (isset($decoded[0]) && is_array($decoded[0])) {
			$templates = $decoded;
		}

		$index = 1;
		$out   = [];

		foreach ($templates as $template) {
			if (!is_array($template) || !syslog_import_matches_table($template, $expected_table)) {
				return false;
			}

			$out['template' . $index] = $template;
			$index++;
		}

		return $out;
	}

	// Legacy exports have no table metadata; validate their distinguishing fields.
	if (!in_array($expected_table, ['syslog_alert', 'syslog_remove'], true) ||
		!function_exists('xml2array')) {
		return false;
	}
	$templates = xml2array($payload);
	if (!is_array($templates)) {
		return false;
	}
	foreach ($templates as $template) {
		if (!is_array($template) || !syslog_import_matches_table($template, $expected_table)) {
			return false;
		}
	}
	return $templates;
}

/**
 * Validate object identity even for older exports without table metadata.
 *
 * @param array<string, mixed> $template The template array.
 * @param string               $table    The expected table name.
 *
 * @return bool True if the template matches the table, false otherwise.
 */
function syslog_import_matches_table(array $template, string $table): bool {
	if (!isset($template['name']) || !is_string($template['name']) || trim($template['name']) === '') {
		return false;
	}
	switch ($table) {
		case 'syslog_alert':
			return isset($template['severity'], $template['method'], $template['message']) &&
				in_array((string) $template['method'], ['0', '1'], true) && is_string($template['message']);
		case 'syslog_remove':
			return isset($template['method'], $template['message']) &&
				in_array($template['method'], ['del', 'trans'], true) && is_string($template['message']);
		case 'syslog_saved_searches':
			return isset($template['search']) && is_string($template['search']) && !isset($template['panels']);
		case 'syslog_dashboards':
			return isset($template['panels']) && is_array($template['panels']);
	}
	return false;
}

/**
 * syslog_validate_storage_engine - Normalize a requested storage engine to
 * one of the supported engines.
 *
 * The plugin only supports the InnoDB storage engine and, on MariaDB, the
 * Aria storage engine.  Any other engine, including the MyISAM choice that
 * older installs could make, falls back to InnoDB so table creation can no
 * longer produce tables that the plugin does not support.
 *
 * @param mixed $engine The requested storage engine name.
 *
 * @return string Either 'InnoDB' or 'Aria'.
 */
function syslog_validate_storage_engine($engine) {
	$engine = is_string($engine) ? trim($engine) : '';

	if (stripos($engine, 'aria') !== false) {
		return 'Aria';
	}

	if (stripos($engine, 'innodb') !== false) {
		return 'InnoDB';
	}

	if ($engine !== '') {
		cacti_log("SYSLOG WARNING: Unsupported storage engine '$engine' requested.  Only InnoDB and Aria (MariaDB) are supported; falling back to InnoDB", false, 'SYSLOG');
	}

	return 'InnoDB';
}

/**
 * syslog_notice_traditional_tables - Raise the deprecation notice for
 * traditional (non-partitioned) Syslog tables.
 *
 * Partitioned tables are the only supported architecture for new installs,
 * but existing traditional installs must keep working.  When the main
 * syslog table exists and is not partitioned, this logs a warning and, on
 * UI pages, raises a message advising the administrator to migrate to a
 * partitioned table.  The notice is throttled to once per day through the
 * 'syslog_traditional_notice' setting so neither the poller log nor the UI
 * banner spams the administrator.
 *
 * @param bool $raise Raise a UI message in addition to logging the warning.
 *
 * @return bool true if the table is traditional, false otherwise.
 */
function syslog_notice_traditional_tables($raise = true) {
	// Nothing to warn about when the tables have not been created yet
	if (!syslog_db_table_exists('syslog', false)) {
		return false;
	}

	if (syslog_is_partitioned()) {
		return false;
	}

	$last_notice = read_config_option('syslog_traditional_notice');

	if ($last_notice != '' && (time() - (int) $last_notice) < 86400) {
		return true;
	}

	set_config_option('syslog_traditional_notice', time());

	cacti_log("WARNING: The 'syslog' table is not partitioned.  Traditional (non-partitioned) tables are deprecated and are no longer available for new installs; migrate to a partitioned table", false, 'SYSLOG');

	if ($raise) {
		raise_message('syslog_traditional_deprecated', __('The Syslog tables are not partitioned.  Traditional (non-partitioned) tables are deprecated, and partitioned tables are required for new installs.  Your data will continue to be collected, but you should migrate the syslog table to a partitioned architecture.', 'syslog'), MESSAGE_LEVEL_WARN);
	}

	return true;
}

/**
 * Creates the plugin status table once per request if it does not exist.
 *
 * @return void
 */
function syslog_status_ensure_table(): void {
	global $syslogdb_default;
	static $checked = false;

	if ($checked) {
		return;
	}

	if (!syslog_db_table_exists('syslog_status', false)) {
		syslog_db_execute("CREATE TABLE IF NOT EXISTS `$syslogdb_default`.`syslog_status`
			(`name` varchar(64) NOT NULL default '',
			`value` text NOT NULL,
			`updated` int(16) NOT NULL default '0',
			PRIMARY KEY (`name`))
			ENGINE=InnoDB
			ROW_FORMAT=Dynamic");
	}

	$checked = true;
}

/**
 * Store one status value in the syslog_status table. Invalid names are
 * rejected and logged.
 *
 * @param string                $name  Status field name, matching /^[a-z0-9_]{1,64}$/.
 * @param string|int|float|bool $value Value to store, stringified before insert.
 *
 * @return bool True when the row was written.
 */
function syslog_status_set(string $name, string|int|float|bool $value): bool {
	global $syslogdb_default;

	if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
		cacti_log("SYSLOG ERROR: Invalid status field '$name'", false, 'SYSLOG');

		return false;
	}

	syslog_status_ensure_table();

	return syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_status`
		(`name`, `value`, `updated`)
		VALUES (?, ?, ?)
		ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated` = VALUES(`updated`)",
		[$name, (string) $value, time()]);
}

/**
 * Add an amount to a numeric status counter, creating it at the stored
 * current value plus the amount.
 *
 * @param string           $name   Status field name.
 * @param int|float|string $amount Numeric amount to add.
 *
 * @return bool True when the row was written.
 */
function syslog_status_increment(string $name, int|float|string $amount): bool {
	if (!is_numeric($amount)) {
		return false;
	}

	$status  = syslog_status_get();
	$current = isset($status[$name]) && is_numeric($status[$name]) ? (int) $status[$name] : 0;

	return syslog_status_set($name, $current + (int) $amount);
}

/**
 * Record one polling runtime sample and update the last/min/avg/max/count
 * and sum telemetry fields.
 *
 * @param int|float|string $seconds Elapsed runtime seconds.
 *
 * @return bool True when the samples were written.
 */
function syslog_status_record_runtime(int|float|string $seconds): bool {
	if (!is_numeric($seconds)) {
		return false;
	}

	$seconds = round(max(0, (float) $seconds), 3);
	$status  = syslog_status_get();

	$count = isset($status['polling_runtime_count']) && is_numeric($status['polling_runtime_count']) ? (int) $status['polling_runtime_count'] : 0;
	$sum   = isset($status['polling_runtime_sum']) && is_numeric($status['polling_runtime_sum']) ? (float) $status['polling_runtime_sum'] : 0.0;
	$min   = isset($status['polling_runtime_min']) && is_numeric($status['polling_runtime_min']) ? (float) $status['polling_runtime_min'] : $seconds;
	$max   = isset($status['polling_runtime_max']) && is_numeric($status['polling_runtime_max']) ? (float) $status['polling_runtime_max'] : $seconds;

	$count++;
	$sum += $seconds;
	$min = min($min, $seconds);
	$max = max($max, $seconds);

	syslog_status_set('polling_runtime_last', $seconds);
	syslog_status_set('polling_runtime_min', $min);
	syslog_status_set('polling_runtime_avg', round($sum / $count, 3));
	syslog_status_set('polling_runtime_max', $max);
	syslog_status_set('polling_runtime_count', $count);
	syslog_status_set('polling_runtime_sum', $sum);

	return true;
}

/**
 * Encode the per rule activity of the last poller run as a JSON document.
 *
 * @param array<int, array<string, mixed>> $rules Rules that fired, with 'name' and 'count' keys.
 *
 * @return string JSON document, or an empty string when encoding fails.
 */
function syslog_status_rule_activity_json(array $rules): string {
	$activity = [];

	foreach ($rules as $rule) {
		if (!is_array($rule) || empty($rule['name'])) {
			continue;
		}

		$activity[] = [
			'name'  => (string) $rule['name'],
			'count' => isset($rule['count']) && is_numeric($rule['count']) ? (int) $rule['count'] : 0
		];
	}

	return (string) json_encode($activity);
}

/**
 * The processing phases that carry per phase telemetry.
 *
 * The worker/process path owns the timestamps: each phase records its own
 * start and end wall clock time, duration in seconds, and processed count
 * through syslog_status_record_phase().
 *
 * @return array<int, string> The telemetry phase names.
 */
function syslog_status_phase_names(): array {
	return [
		'partition',
		'references',
		'removal',
		'alerts',
		'transfer',
		'reports'
	];
}

/**
 * Record one processing phase's telemetry in the syslog_status table.
 *
 * The phase entry stores the start timestamp, end timestamp, duration in
 * seconds, and the number of records the phase handled.  Invalid phase
 * names are rejected so the status table stays queryable.
 *
 * @param string     $phase   One of the syslog_status_phase_names() phases.
 * @param int        $start   Unix timestamp the phase began.
 * @param int        $end     Unix timestamp the phase ended.
 * @param float      $seconds Wall clock seconds the phase took.
 * @param int|string $count   Records processed by the phase.
 *
 * @return bool True when the telemetry row was written.
 */
function syslog_status_record_phase(string $phase, int $start, int $end, float $seconds, int|string $count): bool {
	if (!in_array($phase, syslog_status_phase_names(), true)) {
		return false;
	}

	if ($end < $start) {
		return false;
	}

	$telemetry = [
		'start'    => $start,
		'end'      => $end,
		'seconds'  => round(max(0, $seconds), 3),
		'count'    => (int) $count
	];

	return syslog_status_set('phase_' . $phase, (string) json_encode($telemetry));
}

/**
 * Read the recorded per phase telemetry, with empty entries for phases
 * that have never run.
 *
 * @return array<string, array{start:int,end:int,seconds:float,count:int}|null>
 *               Map of phase name to its telemetry document, or null when
 *               the phase has not recorded a run yet.
 */
function syslog_status_phase_telemetry(): array {
	global $syslogdb_default;

	$phases = [];

	foreach (syslog_status_phase_names() as $phase) {
		$phases[$phase] = null;
	}

	$rows = syslog_db_fetch_assoc_prepared("SELECT `name`, `value`
		FROM `$syslogdb_default`.`syslog_status`
		WHERE `name` LIKE 'phase\\_%'",
		[]);

	if (!is_array($rows)) {
		return $phases;
	}

	foreach ($rows as $row) {
		$name = str_replace('phase_', '', (string) $row['name']);

		if (!array_key_exists($name, $phases)) {
			continue;
		}

		$decoded = json_decode((string) $row['value'], true);

		if (!is_array($decoded) || !isset($decoded['start'], $decoded['end'], $decoded['seconds'], $decoded['count'])) {
			continue;
		}

		$phases[$name] = [
			'start'   => (int) $decoded['start'],
			'end'     => (int) $decoded['end'],
			'seconds' => (float) $decoded['seconds'],
			'count'   => (int) $decoded['count']
		];
	}

	return $phases;
}

/**
 * Read the status fields shown on the Syslog Status tab, with defaults for
 * fields that have never been written.
 *
 * @return array<string, string> Map of status field names to values.
 */
function syslog_status_get(): array {
	global $syslogdb_default;

	syslog_status_ensure_table();

	$status = [
		'last_polling_time' => '',
		'last_start_time'   => '',
		'last_end_time'     => '',
		'last_record_count' => '',
		'polling_runtime_last'  => '',
		'polling_runtime_min'   => '',
		'polling_runtime_avg'   => '',
		'polling_runtime_max'   => '',
		'polling_runtime_count' => '',
		'polling_runtime_sum'   => '',
		'last_alert_rules_processed'   => '',
		'total_alert_rules_processed'  => '',
		'last_delete_rules_processed'  => '',
		'total_delete_rules_processed' => '',
		'last_alert_rules_fired'       => '',
		'last_delete_rules_fired'      => '',
		'partition_maintenance_last_attempt' => '',
		'partition_maintenance_last_success' => '',
		'partition_maintenance_outcome'      => '',
		'partition_recovery_progress'        => '',
		'partition_maintenance_history'      => '',
	];

	$rows = syslog_db_fetch_assoc("SELECT `name`, `value`, `updated`
		FROM `$syslogdb_default`.`syslog_status`
		WHERE `name` IN (
			'last_polling_time',
			'last_start_time',
			'last_end_time',
			'last_record_count',
			'polling_runtime_last',
			'polling_runtime_min',
			'polling_runtime_avg',
			'polling_runtime_max',
			'polling_runtime_count',
			'polling_runtime_sum',
			'last_alert_rules_processed',
			'total_alert_rules_processed',
			'last_delete_rules_processed',
			'total_delete_rules_processed',
			'last_alert_rules_fired',
			'last_delete_rules_fired',
			'partition_maintenance_last_attempt',
			'partition_maintenance_last_success',
			'partition_maintenance_outcome',
			'partition_recovery_progress',
			'partition_maintenance_history'
		)");

	foreach ($rows as $row) {
		$status[(string) $row['name']] = (string) $row['value'];
	}

	return $status;
}

/**
 * syslog_worker_stats_get - Collect the parallel worker statistics shown
 * on the Syslog Status tab.
 *
 * Returns the number of worker processes currently registered in Cacti's
 * process table, the configured maximum, and the per child records
 * handled during the last parallel run.  Per child statistics come from
 * the settings table which lives in the main Cacti database, so the core
 * db helper is required here.
 *
 * @return array Array with running workers, configured workers, and per
 *              child stats keyed by child number
 */
function syslog_worker_stats_get() {
	$stats = [
		'running'  => 0,
		'workers'  => max(1, (int) read_config_option('syslog_max_workers')),
		'children' => [],
	];

	if (!db_table_exists('processes')) {
		return $stats;
	}

	$stats['running'] = (int) db_fetch_cell("SELECT COUNT(*)
		FROM processes
		WHERE tasktype = 'syslog'
		AND taskname = 'child'");

	$rows = db_fetch_assoc("SELECT `name`, `value`
		FROM settings
		WHERE `name` LIKE 'stats_syslog_child_%'");

	if (!is_array($rows)) {
		$rows = [];
	}

	foreach ($rows as $row) {
		$child = (int) str_replace('stats_syslog_child_', '', $row['name']);

		$data = json_decode((string) $row['value'], true);

		if (!is_array($data)) {
			continue;
		}

		$stats['children'][$child] = [
			'child'    => isset($data['child']) ? (int) $data['child'] : $child,
			'run_id'   => isset($data['run_id']) ? (string) $data['run_id'] : '',
			'phase'    => isset($data['phase']) ? (string) $data['phase'] : '',
			'moved'    => isset($data['moved']) ? (int) $data['moved'] : 0,
			'resolved' => isset($data['resolved']) ? (int) $data['resolved'] : 0,
			'runtime'  => isset($data['runtime']) ? (float) $data['runtime'] : 0.0,
		];
	}

	ksort($stats['children']);

	return $stats;
}

/**
 * syslog_aggregate_worker_stats - collect and sum the per child
 * statistics recorded in the settings table, then prune them.
 *
 * The rows are read with a dedicated fresh SELECT instead of
 * read_config_option(): that helper caches values per process, and the
 * master has already read these same keys while waiting out the
 * references phase, where the moved count is always zero.  The cached
 * references numbers would therefore make the transfer total and the
 * 'Records processed' figure on the Syslog Status tab stay at zero.
 * The settings table lives in the main Cacti database, so the core
 * db helper is required here.
 *
 * @param int $workers The number of workers that may have run
 *
 * @return array Aggregated moved and resolved totals
 */
function syslog_aggregate_worker_stats($workers) {
	$moved    = 0;
	$resolved = 0;

	$rows = db_fetch_assoc("SELECT `name`, `value`
		FROM settings
		WHERE `name` LIKE 'stats_syslog_child_%'");

	if (!is_array($rows)) {
		$rows = [];
	}

	foreach ($rows as $row) {
		$child = (int) str_replace('stats_syslog_child_', '', $row['name']);

		if ($child < 1 || $child > $workers) {
			continue;
		}

		$data = json_decode((string) $row['value'], true);

		if (!is_array($data)) {
			cacti_log('WARNING: Ignoring malformed Syslog worker statistics.', false, 'SYSLOG');

			continue;
		}

		$moved    += isset($data['moved']) ? (int) $data['moved'] : 0;
		$resolved += isset($data['resolved']) ? (int) $data['resolved'] : 0;
	}

	// The settings table lives in the main Cacti database, not the
	// syslog database, so the core helper is required here.
	db_execute("DELETE FROM settings
		WHERE name LIKE 'stats_syslog_child_%'");

	syslog_status_set('last_worker_moved', $moved);
	syslog_status_set('last_worker_resolved', $resolved);

	return ['moved' => $moved, 'resolved' => $resolved];
}

/**
 * Whether the syslog table uses native partitioning.
 *
 * @return bool True when the table definition contains a PARTITION clause.
 */
function syslog_is_partitioned(): bool {
	global $syslogdb_default;

	// see if the table is partitioned
	$syntax = syslog_db_fetch_row("SHOW CREATE TABLE `$syslogdb_default`.`syslog`");

	if (is_array($syntax) && substr_count((string) $syntax['Create Table'], 'PARTITION')) {
		return true;
	} else {
		return false;
	}
}

/**
 * This function will manage old data for non-partitioned tables
 *
 * Traditional (non-partitioned) tables are deprecated.  Existing installs
 * continue to operate, but the maintenance pass logs a throttled warning so
 * the administrator is reminded to migrate to partitioned tables.
 *
 * @return int Number of rows deleted from the syslog tables.
 */
function syslog_traditional_manage() {
	global $syslogdb_default, $syslog_cnn;

	syslog_notice_traditional_tables(false);

	/*
	 * The retention cutoff is computed in UTC with gmdate() so it agrees with
	 * the UTC epoch partition boundaries used by syslog_partition_create().
	 * 'logtime' is a MySQL TIMESTAMP compared against integer UTC boundaries
	 * everywhere else, so a local-time cutoff here could shift the prune
	 * window by the server timezone offset and DST transitions.
	 */

	// determine the oldest date to retain
	if (read_config_option('syslog_retention') > 0) {
		$retention = gmdate('Y-m-d', time() - (86400 * (int) read_config_option('syslog_retention')));
	} else {
		$retention = gmdate('Y-m-d', time() - (30 * 86400));
		set_config_option('syslog_retention', '30');
	}

	// delete from the main syslog table first
	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog` WHERE logtime < ?", [$retention]);

	$syslog_deleted = db_affected_rows($syslog_cnn);

	// now delete from the syslog removed table
	syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_removed` WHERE logtime < ?", [$retention]);

	$syslog_deleted += db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s, Syslog Message(s) (older than %s)', $syslog_deleted, $retention));

	return $syslog_deleted;
}

/**
 * Whether partition maintenance is currently blocked and why.
 *
 * Reads the telemetry written by syslog_partition_manage(), so the Status
 * page can surface the condition without re-deriving it.
 *
 * @return array{blocked: bool, reason: string} Blocked flag and reason text.
 */
function syslog_partition_blocked_state(): array {
	$status = syslog_status_get();

	$blocked = false;

	if (isset($status['partition_maintenance_blocked']) && is_numeric($status['partition_maintenance_blocked'])) {
		$blocked = (int) $status['partition_maintenance_blocked'] === 1;
	}

	return [
		'blocked' => $blocked,
		'reason'  => isset($status['partition_maintenance_reason']) ? (string) $status['partition_maintenance_reason'] : ''
	];
}

/**
 * Return live partition coverage and dMaxValue occupancy for the Status tab.
 * TABLE_ROWS is an engine estimate, so callers must not present it as an exact
 * count. This is read-only and safe to call while maintenance is blocked.
 *
 * @return array<string, array{coverage_start:string,coverage_end:string,partitions:int,dmax_rows:int|float|null,dmax_bytes:int|float|null}>
 */
function syslog_partition_observability(): array {
	global $syslogdb_default;

	$observability = [];

	foreach (['syslog', 'syslog_removed'] as $table) {
		$observability[$table] = [
			'coverage_start' => '',
			'coverage_end'   => '',
			'partitions'     => 0,
			'dmax_rows'      => null,
			'dmax_bytes'     => null
		];

		$rows = syslog_db_fetch_assoc_prepared('SELECT partition_name, table_rows, data_length, index_length
			FROM information_schema.PARTITIONS
			WHERE table_schema = ? AND table_name = ?
			ORDER BY partition_ordinal_position',
			[$syslogdb_default, $table]);

		if (!is_array($rows)) {
			continue;
		}

		$dates = [];

		foreach ($rows as $row) {
			$name = (string) ($row['partition_name'] ?? $row['PARTITION_NAME'] ?? '');

			if (preg_match('/^d(\d{8})$/', $name, $matches) === 1) {
				$dates[] = $matches[1];
			}

			if ($name !== 'dMaxValue') {
				continue;
			}

			$dmax_rows = $row['table_rows'] ?? $row['TABLE_ROWS'] ?? null;
			$data       = $row['data_length'] ?? $row['DATA_LENGTH'] ?? null;
			$index      = $row['index_length'] ?? $row['INDEX_LENGTH'] ?? null;

			$observability[$table]['dmax_rows']  = is_numeric($dmax_rows) ? $dmax_rows + 0 : null;
			$observability[$table]['dmax_bytes'] = is_numeric($data) && is_numeric($index) ? $data + $index : null;
		}

		sort($dates, SORT_STRING);
		$observability[$table]['partitions']     = count($dates);
		$observability[$table]['coverage_start'] = $dates[0] ?? '';
		$observability[$table]['coverage_end']   = $dates[count($dates) - 1] ?? '';
	}

	return $observability;
}

/**
 * Persist the latest partition-maintenance outcome and a bounded history.
 *
 * @param bool                 $successful True when both tables reached the configured horizon.
 * @param array<string, array> $recovery   Per-table syslog_partition_recover() results.
 * @param int                  $pruned     Partitions pruned in this run.
 * @param string               $reason     Failure or deferral reason.
 *
 * @return void
 */
function syslog_partition_maintenance_record(bool $successful, array $recovery, int $pruned, string $reason = ''): void {
	$status   = syslog_status_get();
	$now      = time();
	$created  = 0;
	$missing  = 0;
	$progress = [];

	foreach (['syslog', 'syslog_removed'] as $table) {
		$state         = is_array($recovery[$table] ?? null) ? $recovery[$table] : [];
		$table_created = isset($state['created']) && is_numeric($state['created']) ? (int) $state['created'] : 0;
		$table_missing = isset($state['missing']) && is_numeric($state['missing']) ? (int) $state['missing'] : 0;

		$created += $table_created;
		$missing += $table_missing;
		$progress[$table] = [
			'created'   => $table_created,
			'missing'   => $table_missing,
			'deferred'  => !empty($state['retention_deferred']),
			'dmax_risk' => !empty($state['dmax_risk'])
		];
	}

	$event = [
		'time'       => $now,
		'successful' => $successful,
		'created'    => $created,
		'missing'    => $missing,
		'pruned'     => $pruned,
		'reason'     => $reason
	];
	$history = json_decode($status['partition_maintenance_history'] ?? '', true);
	$history = is_array($history) ? $history : [];
	$history[] = $event;
	$history = array_slice($history, -10);

	syslog_status_set('partition_maintenance_last_attempt', $now);
	syslog_status_set('partition_maintenance_outcome', $successful ? 'success' : 'deferred');
	syslog_status_set('partition_recovery_progress', (string) json_encode($progress));
	syslog_status_set('partition_maintenance_history', (string) json_encode($history));

	if ($successful) {
		syslog_status_set('partition_maintenance_last_success', $now);
	}
}

/**
 * syslog_partition_manage - Manage the partitions for both syslog tables.
 *
 * The run is fail-safe: when syslog_partition_report_state() reports an
 * invalid or incomplete partition layout for either table, or when the
 * creation of a required partition fails, every further partition action
 * (creation, retention pruning) is skipped.  Writes continue through the
 * dMaxValue safety partition, which is never removed.
 *
 * Missing future partitions are recovered in bounded steps: at most
 * 'syslog_partition_recover_limit' partitions per table per run are
 * created, so a large gap heals over several poller cycles instead of
 * issuing one long-running ALTER TABLE chain.  Retention pruning only
 * runs once the full future horizon exists again.
 *
 * @return int Number of rows removed by partition pruning.
 */
function syslog_partition_manage(): int {
	$syslog_deleted = 0;
	$ahead_days     = syslog_partition_ahead_days();
	syslog_status_set('partition_maintenance_last_attempt', time());

	// Always create partitions ahead of time to avoid midnight races.
	$base_time = time() + 7200;

	// Fail safe: refuse to touch partitions while metadata looks wrong.
	foreach (['syslog', 'syslog_removed'] as $table) {
		if (!syslog_partition_report_state($table)) {
			$reason = sprintf(__('Partition layout for %s is invalid or incomplete; partition maintenance stopped. Verify the partition metadata with SHOW CREATE TABLE and, if required, rebuild the partitions; writes continue into the dMaxValue safety partition.', 'syslog'), $table);

			cacti_log("SYSLOG ERROR: $reason", false, 'SYSLOG');

			syslog_status_set('partition_maintenance_blocked', 1);
			syslog_status_set('partition_maintenance_reason', $reason);
			syslog_partition_maintenance_record(false, [], 0, $reason);

			return 0;
		}
	}

	// Bounded recovery: heal missing future partitions a few at a time.
	$recovery  = syslog_partition_recover('syslog', $base_time, $ahead_days);

	if ($recovery['stop_reason'] === '') {
		$recovery_removed = syslog_partition_remove('syslog');
	} else {
		$recovery_removed = 0;
	}

	$recovery2 = syslog_partition_recover('syslog_removed', $base_time, $ahead_days);

	if ($recovery2['stop_reason'] === '') {
		$recovery2_removed = syslog_partition_remove('syslog_removed');
	} else {
		$recovery2_removed = 0;
	}

	if ($recovery['stop_reason'] === '' && $recovery2['stop_reason'] === '') {
		// All partitions for both tables are healthy; clear any block.
		syslog_status_set('partition_maintenance_blocked', 0);
		syslog_status_set('partition_maintenance_reason', '');
		syslog_partition_maintenance_record(true, ['syslog' => $recovery, 'syslog_removed' => $recovery2], $recovery_removed + $recovery2_removed);

		return $recovery_removed + $recovery2_removed;
	}

	// Report the first blocking recovery for operator visibility.
	$recovery_failed = $recovery['stop_reason'] !== '' ? $recovery : $recovery2;
	$failed_table    = $recovery['stop_reason'] !== '' ? 'syslog' : 'syslog_removed';

	$reason = sprintf(
		'%s: %s; %s; %s',
		$failed_table,
		$recovery_failed['stop_reason'],
		sprintf(__('remaining partition gap: %d day(s)', 'syslog'), $recovery_failed['missing']),
		$recovery_failed['retention_deferred'] ? __('retention pruning deferred until the future horizon is restored', 'syslog') : __('no retention deferral', 'syslog')
	);

	if ($recovery_failed['dmax_risk']) {
		$reason .= '; ' . __('new records are accumulating in the dMaxValue safety partition', 'syslog');
	}

	cacti_log("SYSLOG ERROR: Partition recovery for '$failed_table' stopped early: $reason", false, 'SYSLOG');

	syslog_status_set('partition_maintenance_blocked', 1);
	syslog_status_set('partition_maintenance_reason', $reason);
	syslog_partition_maintenance_record(false, ['syslog' => $recovery, 'syslog_removed' => $recovery2], $recovery_removed + $recovery2_removed, $reason);

	return $recovery_removed + $recovery2_removed;
}

/**
 * syslog_partition_recover - Create missing partitions up to a bounded
 * number per run, stopping safely at the first failure.
 *
 * The future write horizon is what protects retention pruning: only when
 * every partition through the configured ahead-days horizon exists may old
 * partitions be pruned, otherwise data would be dropped without a
 * replacement window.  The dMaxValue partition stays in place as the
 * write-path safety net the whole time.
 *
 * @param string $table      The table to maintain.
 * @param int    $base_time  Base timestamp for the maintenance window.
 * @param int    $ahead_days Number of future days to maintain.
 *
 * @return array{created: int, missing: int, stop_reason: string, retention_deferred: bool, dmax_risk: bool}
 *               Recovery telemetry: partitions created this run, the
 *               remaining gap, why the run stopped, whether retention
 *               was deferred, and whether writes landed in dMaxValue.
 */
function syslog_partition_recover($table, $base_time, $ahead_days) {
	$limit = read_config_option('syslog_partition_recover_limit');

	if (!is_numeric($limit) || (int) $limit < 1) {
		$limit = 3;
	}

	$limit = (int) $limit;

	if ($limit > 31) {
		$limit = 31;
	}

	$created      = 0;
	$missing      = 0;
	$stop         = '';
	$dmax_risk    = false;
	$need_ahead   = 0;

	for ($day = 0; $day <= $ahead_days; $day++) {
		$time = $base_time + ($day * 86400);

		if (syslog_partition_check($table, $time)) {
			$need_ahead++;
		}
	}

	if ($need_ahead === 0) {
		return [
			'created'            => 0,
			'missing'            => 0,
			'stop_reason'        => '',
			'retention_deferred' => false,
			'dmax_risk'          => false
		];
	}

	for ($day = 0; $day <= $ahead_days; $day++) {
		$time = $base_time + ($day * 86400);

		if (!syslog_partition_check($table, $time)) {
			continue;
		}

		if ($created >= $limit) {
			$missing = $need_ahead - $created;
			$stop    = __('per run recovery limit reached', 'syslog');

			break;
		}

		$missing = $need_ahead - $created;

		if (!syslog_partition_create($table, $time)) {
			$stop = __('partition creation failed', 'syslog');
			/*
			 * When the next concrete partition is still missing, every new
			 * write lands in dMaxValue until the gap is healed; the
			 * retention prune stays deferred too.
			 */
			$dmax_risk = true;

			break;
		}

		$created++;
	}

	if ($stop === '') {
		$missing = 0;
	}

	return [
		'created'            => $created,
		'missing'            => $missing,
		'stop_reason'        => $stop,
		'retention_deferred' => $stop !== '',
		'dmax_risk'          => $dmax_risk
	];
}

/**
 * Return the configured number of future daily partitions to maintain.
 *
 * @return int Days ahead to pre-create.
 */
function syslog_partition_ahead_days() {
	$ahead_days = read_config_option('syslog_partition_ahead_days');

	if ($ahead_days === '' || !is_numeric($ahead_days)) {
		$ahead_days = 3;
	}

	$ahead_days = (int) $ahead_days;

	if ($ahead_days < 1 || $ahead_days > 7) {
		$ahead_days = 3;
	}

	return $ahead_days;
}

/**
 * Validate tables that support partition maintenance.
 *
 * Any value added to the allowlist MUST match ^[a-z_]+$ so it is safe
 * for identifier interpolation in DDL statements (MySQL does not support
 * parameter binding for identifiers).
 *
 * The untyped signature is intentional: security tests assert it exactly.
 *
 * @param mixed $table The table name to validate.
 *
 * @return bool True when the table may be used in partition DDL.
 */
function syslog_partition_table_allowed($table) {
	if (!in_array($table, ['syslog', 'syslog_removed'], true)) {
		return false;
	}

	// Defense-in-depth: reject values unsafe for identifier interpolation.
	if (!preg_match('/^[a-z_]+$/', $table)) {
		return false;
	}

	return true;
}

/**
 * syslog_compute_slices - Compute a list of disjoint seq slices covering
 * the given inclusive seq range.  Used to distribute incoming syslog
 * records across parallel worker processes.
 *
 * When $seq_start and $seq_end are both 0, or the range is empty, the
 * function returns an empty array as there is nothing to slice.
 *
 * The worker count is clamped to at least 1.  When the number of workers
 * exceeds the number of records in the range, excess slices are empty
 * arrays which callers tolerate.
 *
 * @param int $seq_start The first seq of the range (inclusive)
 * @param int $seq_end   The last seq of the range (inclusive)
 * @param int $workers   The number of slices to compute
 *
 * @return array An array of ['start' => int, 'end' => int] slices
 */
function syslog_compute_slices($seq_start, $seq_end, $workers) {
	$seq_start = (int) $seq_start;
	$seq_end   = (int) $seq_end;
	$workers   = max(1, (int) $workers);

	if ($seq_end < $seq_start || $seq_start <= 0) {
		return [];
	}

	$total    = $seq_end - $seq_start + 1;
	$per_slice = (int) ceil($total / $workers);

	$slices = [];
	$start  = $seq_start;

	while ($start <= $seq_end) {
		$end = min($start + $per_slice - 1, $seq_end);

		$slices[] = ['start' => $start, 'end' => $end];

		$start = $end + 1;
	}

	return $slices;
}

/**
 * syslog_validate_worker_args - Validate the command line arguments passed
 * to a syslog worker child process before they reach the database layer.
 *
 * All values arrive from the command line, so each is checked against a
 * strict format before use.  Sequence bounds must form a sane inclusive
 * range.
 *
 * @param int    $child     The child process number, must be a positive integer
 * @param string $run_id    A 32 character hex run identifier
 * @param string $phase     Either 'references' or 'transfer'
 * @param int    $seq_start The first seq of the slice (inclusive)
 * @param int    $seq_end   The last seq of the slice (inclusive)
 *
 * @return bool true when all arguments are valid, else false
 */
function syslog_validate_worker_args($child, $run_id, $phase, $seq_start, $seq_end) {
	if (preg_match('/^[1-9][0-9]*$/', (string) $child) !== 1) {
		return false;
	}

	if (preg_match('/^[a-f0-9]{32}$/', (string) $run_id) !== 1) {
		return false;
	}

	if (!in_array($phase, ['references', 'transfer'], true)) {
		return false;
	}

	if (preg_match('/^[0-9]+$/', (string) $seq_start) !== 1 ||
		preg_match('/^[0-9]+$/', (string) $seq_end) !== 1) {
		return false;
	}

	$seq_start = (int) $seq_start;
	$seq_end   = (int) $seq_end;

	// The zero range is the unbounded sentinel reserved for the serial
	// path.  A worker must always be launched with a real slice.
	if ($seq_start <= 0 || $seq_end <= 0) {
		return false;
	}

	return $seq_start <= $seq_end;
}

/**
 * syslog_workers_running - count the parallel worker children registered
 * in Cacti's process table.
 *
 * A child that died without unregistering itself (SIGKILL, OOM kill,
 * database restart) leaves a stale row behind that would otherwise make
 * the master's wait loop block forever.  Stale rows are detected by pid
 * liveness and removed here, with a warning, so the wait always drains.
 *
 * The process table lives in the main Cacti database, not the syslog
 * database, so the core helpers are required here.
 *
 * @return int The number of live, registered workers
 */
function syslog_workers_running() {
	$processes = db_fetch_assoc("SELECT pid
		FROM processes
		WHERE tasktype = 'syslog'
		AND taskname = 'child'");

	if (!is_array($processes) || !cacti_sizeof($processes)) {
		return 0;
	}

	$running = 0;

	foreach ($processes as $process) {
		$pid = (int) $process['pid'];

		if (cacti_process_still_running($pid)) {
			$running++;

			continue;
		}

		cacti_log("WARNING: Syslog worker PID $pid is no longer running, removing its stale process entry", false, 'SYSLOG');

		db_execute("DELETE FROM processes
			WHERE tasktype = 'syslog'
			AND taskname = 'child'
			AND pid = $pid");
	}

	return $running;
}

/**
 * syslog_wait_workers - wait for the launched worker children to finish.
 *
 * The wait is two staged.  First the master waits, up to a short
 * registration grace, for every launched child to appear in the process
 * table; without this a child that has not registered yet would make the
 * running count look like zero and the master would race ahead exactly as
 * if it had never waited at all.  Then the master polls the process
 * table until all children are done.  Polling starts at a tenth of a
 * second and backs off up to one second so a short burst of records is
 * not delayed by a fixed multi second tick while a long transfer does
 * not spam the process table.  Children that disappear without recording
 * their statistics are warned about after the wait completes.
 *
 * @param int $expected The number of children that were launched
 *
 * @return (void)
 */
function syslog_wait_workers($expected) {
	// STAGE 1: give every launched child time to register, bounded so a
	// child that crashed before registering cannot stall the master.
	// The grace window stays at ten seconds; the tenth of a second poll
	// just notices registration as soon as it happens.
	$grace_polls = 100;

	for ($i = 0; $i < $grace_polls && syslog_workers_running() < $expected; $i++) {
		syslog_debug(sprintf('Waiting for %s Worker(s) to register.', $expected));

		usleep(100000);
	}

	// STAGE 2: wait for the registered children to complete, backing off
	// from a tenth of a second to at most one second between polls
	$interval = 100000;

	while (syslog_workers_running() > 0) {
		syslog_debug(sprintf('%s Worker(s) Running.', syslog_workers_running()));

		usleep($interval);

		$interval = min($interval * 2, 1000000);
	}

	// verify that each child recorded its completion
	$missing = [];

	for ($i = 1; $i <= $expected; $i++) {
		$stat = read_config_option('stats_syslog_child_' . $i);

		if ($stat === false || $stat === '' || $stat === null) {
			$missing[] = $i;
		}
	}

	if (cacti_sizeof($missing)) {
		cacti_log('WARNING: Syslog worker(s) exited without recording completion: ' . implode(', ', $missing), false, 'SYSLOG');
	}
}

/**
 * Report suspicious partition metadata after a crash or database restart.
 *
 * This is intentionally read-only. MySQL/MariaDB DDL is atomic, but if the
 * server restarts during maintenance, the next poller run should leave an
 * explicit breadcrumb when metadata is missing or internally inconsistent.
 *
 * @param string $table The table to inspect
 *
 * @return bool true when the visible partition state looks usable.
 */
function syslog_partition_report_state($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		cacti_log("SYSLOG: partition_report_state called with disallowed table '$table'", false, 'SYSTEM');

		return false;
	}

	if (preg_match('/^[a-zA-Z0-9_]+$/', $syslogdb_default) !== 1) {
		cacti_log("SYSLOG ERROR: Invalid database name; partition state check aborted", false, 'SYSLOG');

		return false;
	}

	$partitions = syslog_db_fetch_assoc_prepared('SELECT partition_name, partition_description, partition_ordinal_position
		FROM `information_schema`.`partitions`
		WHERE table_schema = ? AND table_name = ?
		ORDER BY partition_ordinal_position',
		[$syslogdb_default, $table]);

	if (!cacti_sizeof($partitions)) {
		cacti_log("SYSLOG WARNING: No partition metadata found for '$table'; maintenance may be recovering from an interrupted DDL or an unexpected table state", false, 'SYSLOG');

		return false;
	}

	$expected_position = 1;
	$maxvalue_count    = 0;
	$previous_boundary = null;
	$valid             = true;

	foreach ($partitions as $partition) {
		$name        = isset($partition['partition_name']) ? $partition['partition_name'] : (isset($partition['PARTITION_NAME']) ? $partition['PARTITION_NAME'] : '');
		$description = isset($partition['partition_description']) ? $partition['partition_description'] : (isset($partition['PARTITION_DESCRIPTION']) ? $partition['PARTITION_DESCRIPTION'] : '');
		$position    = isset($partition['partition_ordinal_position']) ? $partition['partition_ordinal_position'] : (isset($partition['PARTITION_ORDINAL_POSITION']) ? $partition['PARTITION_ORDINAL_POSITION'] : null);

		if ((int) $position !== $expected_position) {
			cacti_log("SYSLOG WARNING: Partition metadata for '$table' has unexpected ordinal position '$position' at expected position '$expected_position'", false, 'SYSLOG');
			$valid = false;
		}

		if (!is_string($name) || preg_match('/^(d\d{8}|dMaxValue)$/', $name) !== 1) {
			cacti_log("SYSLOG WARNING: Partition metadata for '$table' contains unexpected partition name '$name'", false, 'SYSLOG');
			$valid = false;
		}

		if ($name === 'dMaxValue') {
			$maxvalue_count++;

			if ($expected_position !== cacti_sizeof($partitions)) {
				cacti_log("SYSLOG WARNING: Partition metadata for '$table' has dMaxValue before the last partition; inspect recent ALTER TABLE maintenance", false, 'SYSLOG');
				$valid = false;
			}
		} else {
			if ($description === null || $description === '' || strtoupper((string) $description) === 'MAXVALUE') {
				cacti_log("SYSLOG WARNING: Partition '$name' on '$table' has an unexpected boundary description; inspect recent ALTER TABLE maintenance", false, 'SYSLOG');
				$valid = false;
			} elseif (is_numeric($description)) {
				$boundary = (float) $description;

				if ($previous_boundary !== null && $boundary <= $previous_boundary) {
					cacti_log("SYSLOG WARNING: Partition '$name' on '$table' is not ordered after the previous boundary; inspect recent ALTER TABLE maintenance", false, 'SYSLOG');
					$valid = false;
				}

				$previous_boundary = $boundary;
			}
		}

		$expected_position++;
	}

	if ($maxvalue_count !== 1) {
		cacti_log("SYSLOG WARNING: Partition metadata for '$table' has $maxvalue_count dMaxValue partitions; expected exactly one", false, 'SYSLOG');
		$valid = false;
	}

	return $valid;
}

/**
 * Create a new partition for the specified table.
 *
 * @param mixed $table The table to rotate
 * @param int   $time Assume this time for the partition rotation
 *
 * @return bool true on success, false on lock failure or disallowed table.
 */
function syslog_partition_create($table, $time = null) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	if (preg_match('/^[a-zA-Z0-9_]+$/', $syslogdb_default) !== 1) {
		cacti_log("SYSLOG ERROR: Invalid database name; partition create aborted", false, 'SYSLOG');

		return false;
	}

	if ($time === null) {
		$time = time() + 3600;
	}

	// Reject non-numeric, negative, or far-future timestamps; boundary
	// math assumes a non-negative UTC epoch within 64-bit safe range so
	// extreme inputs cannot underflow or overflow to float.
	if (!is_numeric($time) || (int) $time < 0 || (int) $time > 4102444800) {
		cacti_log("SYSLOG ERROR: syslog_partition_create called with invalid time '$time' for table '$table'", false, 'SYSLOG');

		return false;
	}

	$time = (int) $time;

	// Hash to guarantee the lock name stays within MySQL's 64-byte limit.
	$lock_name = substr(hash('sha256', $syslogdb_default . '.syslog_partition_create.' . $table), 0, 60);

	/*
	 * 10-second timeout is sufficient: partition maintenance runs once per
	 * poller cycle (typically 5 minutes), so sustained contention is not
	 * expected. A failure is logged so monitoring can detect repeated misses.
	 */
	$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', [$lock_name]);

	if ($locked === null) {
		// NULL means the GET_LOCK call itself failed, not just contention.
		cacti_log("SYSLOG: GET_LOCK call failed for partition create on '$table'", false, 'SYSTEM');

		return false;
	}

	if ((int)$locked !== 1) {
		cacti_log("SYSLOG: Unable to acquire partition create lock for '$table'", false, 'SYSTEM');

		return false;
	}

	$success = false;

	try {
		/*
		 * Boundary arithmetic is done in PHP against the UTC epoch so the
		 * result is independent of both the PHP and MySQL session time zones.
		 * $boundary_epoch is the next UTC midnight strictly after $time; it
		 * becomes the VALUES LESS THAN literal for UNIX_TIMESTAMP partitions
		 * and the source for the date string passed to TO_DAYS.
		 */
		$boundary_epoch = (intdiv($time, 86400) + 1) * 86400;

		if ($boundary_epoch <= 0 || $boundary_epoch <= $time) {
			cacti_log("SYSLOG ERROR: Boundary epoch computation failed for '$table' (time=$time); leaving writes in dMaxValue until maintenance recovers", false, 'SYSLOG');

			return false;
		}

		$cformat        = 'd' . gmdate('Ymd', $time);
		$boundary_date  = gmdate('Y-m-d', $boundary_epoch);

		if (preg_match('/^d\d{8}$/', $cformat) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $boundary_date) !== 1) {
			cacti_log("SYSLOG ERROR: Derived partition values failed format validation for '$table'; leaving writes in dMaxValue until maintenance recovers", false, 'SYSLOG');

			return false;
		}

		$exists = syslog_db_fetch_row_prepared('SELECT *
			FROM `information_schema`.`partitions`
			WHERE table_schema = ?
			AND partition_name = ?
			AND table_name = ?
			ORDER BY partition_ordinal_position',
			[$syslogdb_default, $cformat, $table]);

		if (!cacti_sizeof($exists)) {
			cacti_log("SYSLOG: Creating new partition '$cformat'", false, 'SYSTEM');

			syslog_debug("Creating new partition '$cformat'");

			/*
			 * MySQL does not support parameter binding for DDL identifiers
			 * or partition definitions. $table is safe because it passed
			 * syslog_partition_table_allowed() (two-value allowlist plus
			 * regex guard). $cformat, $boundary_epoch, and $boundary_date
			 * derive from integer arithmetic and gmdate(), so they contain
			 * only digits, hyphens, and the letter 'd'.
			 */
			$create_syntax = syslog_db_fetch_row_prepared("SHOW CREATE TABLE `$syslogdb_default`.`$table`");

			if (!cacti_sizeof($create_syntax) || empty($create_syntax['Create Table'])) {
				cacti_log("SYSLOG ERROR: SHOW CREATE TABLE returned no rows for '$table'; leaving writes in dMaxValue until maintenance recovers", false, 'SYSLOG');

				return false;
			}

			$create_sql = $create_syntax['Create Table'];

			if (stripos($create_sql, 'TO_DAYS') !== false) {
				$altered = syslog_db_execute_prepared("ALTER TABLE `$syslogdb_default`.`$table` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION $cformat VALUES LESS THAN (TO_DAYS('$boundary_date')),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)", []);
			} elseif (stripos($create_sql, 'UNIX_TIMESTAMP') !== false) {
				$altered = syslog_db_execute_prepared("ALTER TABLE `$syslogdb_default`.`$table` REORGANIZE PARTITION dMaxValue INTO (
					PARTITION $cformat VALUES LESS THAN ($boundary_epoch),
					PARTITION dMaxValue VALUES LESS THAN MAXVALUE)", []);
			} else {
				cacti_log("SYSLOG ERROR: Unable to determine partition expression (neither TO_DAYS nor UNIX_TIMESTAMP) for '$table'; leaving writes in dMaxValue until maintenance recovers", false, 'SYSLOG');

				return false;
			}

			if ($altered === false) {
				/*
				 * The required partition could not be created.  Callers stop
				 * maintenance and defer retention pruning; writes continue
				 * into the existing dMaxValue safety partition.
				 */
				cacti_log("SYSLOG ERROR: Failed to create partition '$cformat' on '$table'; leaving writes in dMaxValue until maintenance recovers", false, 'SYSLOG');

				return false;
			}
		}

		$success = true;
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock_name]);
	}

	return $success;
}

/**
 * Remove old partitions for the specified table.
 *
 * The untyped signature is intentional: security tests assert it exactly.
 *
 * @param string $table The name of the table
 *
 * @return int Number of partitions removed.
 */
function syslog_partition_remove($table) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		cacti_log("SYSLOG: partition_remove called with disallowed table '$table'", false, 'SYSTEM');

		return 0;
	}

	if (preg_match('/^[a-zA-Z0-9_]+$/', $syslogdb_default) !== 1) {
		cacti_log("SYSLOG ERROR: Invalid database name; partition remove aborted", false, 'SYSLOG');

		return 0;
	}

	$lock_name = substr(hash('sha256', $syslogdb_default . '.syslog_partition_remove.' . $table), 0, 60);

	$locked = syslog_db_fetch_cell_prepared('SELECT GET_LOCK(?, 10)', [$lock_name]);

	if ($locked === null) {
		cacti_log("SYSLOG: GET_LOCK call failed for partition remove on '$table'", false, 'SYSTEM');

		return 0;
	}

	if ((int)$locked !== 1) {
		cacti_log("SYSLOG: Unable to acquire partition remove lock for '$table'", false, 'SYSTEM');

		return 0;
	}

	$syslog_deleted = 0;

	try {
		$number_of_partitions = syslog_db_fetch_assoc_prepared('SELECT *
			FROM `information_schema`.`partitions`
			WHERE table_schema = ? AND table_name = ?
			ORDER BY partition_ordinal_position',
			[$syslogdb_default, $table]);

		$days       = read_config_option('syslog_retention');
		$ahead_days = syslog_partition_ahead_days();

		syslog_debug("There are currently '" . sizeof($number_of_partitions) . "' Syslog Partitions, We will keep '$days' retention partition(s) plus '$ahead_days' future partition(s).");

		if ($days > 0) {
			$user_partitions = sizeof($number_of_partitions) - 1;
			$keep_partitions = (int) $days + $ahead_days;

			if ($user_partitions >= $keep_partitions) {
				$i = 0;

				while ($user_partitions > $keep_partitions) {
					$oldest = $number_of_partitions[$i];

					$part_name = $oldest['PARTITION_NAME'];

					if (preg_match('/^[a-zA-Z0-9_]+$/', $part_name) !== 1) {
						cacti_log("SYSLOG ERROR: Invalid partition name '$part_name' for '$table'; skipping drop", false, 'SYSLOG');
						break;
					}

					if ($part_name === 'dMaxValue') {
						// The dMaxValue partition is the write-path fail
						// safe and must never be dropped.
						cacti_log("SYSLOG ERROR: Refusing to drop the dMaxValue safety partition on '$table'; stopping retention prune", false, 'SYSLOG');
						break;
					}

					cacti_log("SYSLOG: Removing old partition '" . $part_name . "'", false, 'SYSTEM');

					syslog_debug("Removing partition '" . $part_name . "'");

					/* $table passed syslog_partition_table_allowed() at function entry; $part_name is regex-validated above. DDL identifiers cannot be parameterized. */
					$result = syslog_db_execute_prepared("ALTER TABLE `$syslogdb_default`.`$table` DROP PARTITION `$part_name`", []);

					if ($result === false) {
						cacti_log("SYSLOG ERROR: Failed to drop partition '$part_name' from '$table' after $i successful drop(s); aborting further drops", false, 'SYSLOG');
						break;
					}

					$i++;
					$user_partitions--;
					$syslog_deleted++;
				}
			}
		}
	} finally {
		syslog_db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)', [$lock_name]);
	}

	return $syslog_deleted;
}

/**
 * syslog_partition_check is a read-only SELECT against information_schema.
 * It does not execute DDL, so it does not need the named lock that
 * syslog_partition_create and syslog_partition_remove acquire. External
 * serialization is provided by the poller cycle calling
 * syslog_partition_manage().
 *
 * The untyped signature is intentional: security tests assert it exactly.
 *
 * @param string   $table The table to check
 * @param int|null $time  The time to assume for creation verification
 *
 * @return bool If it's time to rotate the partition
 */
function syslog_partition_check($table, $time = null) {
	global $syslogdb_default;

	if (!syslog_partition_table_allowed($table)) {
		return false;
	}

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if ($time === null) {
		$time = time() + 3600;
	}

	// find date of last partition
	$last_part = syslog_db_fetch_cell_prepared('SELECT PARTITION_NAME
		FROM `information_schema`.`partitions`
		WHERE table_schema = ?
		AND table_name = ?
		ORDER BY partition_ordinal_position DESC
		LIMIT 1,1',
		[$syslogdb_default, $table]);

	$lformat   = str_replace('d', '', $last_part);
	$cformat   = gmdate('Ymd', $time);

	if ($cformat > $lformat) {
		return true;
	} else {
		return false;
	}
}

/**
 * Report whether a request variable differs from its remembered session value.
 *
 * @param string $request The request variable name to check.
 * @param string $session The session variable name to compare against.
 *
 * @return int|null 1 when the value changed, or null when unchanged or unset.
 */
function syslog_check_changed(string $request, string $session): ?int {
	if ((isset_request_var($request)) && (isset($_SESSION[$session]))) {
		if (get_request_var($request) != $_SESSION[$session]) {
			return 1;
		}
	}

	return null;
}

/**
 * syslog_get_removal_rule_fields - Trusted field map used to compile
 * structured filter rules for removal processing and editor validation.
 *
 * @param string $table  The syslog table the rule will run against
 * @param string $prefix Optional table alias prefixed to each column
 *
 * @return array The QueryBuilder field definitions
 */
function syslog_get_removal_rule_fields($table = 'syslog_incoming', $prefix = '') {
	global $syslog_incoming_config;

	$column = function ($name) use ($prefix) {
		return $prefix . '`' . $name . '`';
	};

	if ($table === 'syslog_incoming') {
		if (!isset($syslog_incoming_config['programField'])) {
			$syslog_incoming_config['programField'] = 'program';
		}
		foreach (['textField' => 'message', 'hostField' => 'host', 'facilityField' => 'facility_id', 'priorityField' => 'priority_id'] as $setting => $default) {
			if (!isset($syslog_incoming_config[$setting])) {
				$syslog_incoming_config[$setting] = $default;
			}
		}

		return [
			'message' => ['column' => $column($syslog_incoming_config['textField']), 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'host' => ['column' => $column($syslog_incoming_config['hostField']), 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'program' => ['column' => $column($syslog_incoming_config['programField']), 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'facility_id' => ['column' => $column($syslog_incoming_config['facilityField']), 'operators' => ['=', '!='], 'type' => 'integer'],
			'priority_id' => ['column' => $column($syslog_incoming_config['priorityField']), 'operators' => ['=', '!='], 'type' => 'integer']
		];
	}

	// The syslog (and syslog_removed) tables store normalized id columns.
	return [
		'message' => ['column' => $column('message'), 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
		'host_id' => ['column' => $column('host_id'), 'operators' => ['=', '!='], 'type' => 'integer'],
		'program_id' => ['column' => $column('program_id'), 'operators' => ['=', '!='], 'type' => 'integer'],
		'facility_id' => ['column' => $column('facility_id'), 'operators' => ['=', '!='], 'type' => 'integer'],
		'priority_id' => ['column' => $column('priority_id'), 'operators' => ['=', '!='], 'type' => 'integer']
	];
}

/**
 * syslog_removal_filter_normalize - Rewrite string host/program predicates
 * into their normalized id columns so retroactive processing can compile
 * rules against the syslog table, which stores ids rather than names.
 *
 * @param string $json  The versioned filter document
 * @param string $table The syslog table the rule will run against
 *
 * @return array The translated filter document, or the original document
 */
function syslog_removal_filter_normalize($json, $table) {
	if ($table !== 'syslog' || !class_exists('Cacti\\Syslog\\QueryBuilder')) {
		$document = json_decode($json, true);

		return is_array($document) ? $document : [];
	}

	try {
		$document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
	} catch (\JsonException $error) {
		return [];
	}

	if (!is_array($document) || !isset($document['conditions'])) {
		return [];
	}

	$maps = [
		'host' => ['table' => 'syslog_hosts',    'name' => 'host',    'id' => 'host_id'],
		'program' => ['table' => 'syslog_programs', 'name' => 'program', 'id' => 'program_id']
	];

	$resolve = function ($field, $operator, $value) use ($maps) {
		if (!isset($maps[$field])) {
			return null;
		}

		$map   = $maps[$field];
		$value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value);

		switch ($operator) {
			case '=':
				$pattern = $value;
				break;
			case '!=':
				$pattern = $value;
				break;
			case 'contains':
				$pattern = '%' . $value . '%';
				break;
			case 'begins':
				$pattern = $value . '%';
				break;
			case 'ends':
				$pattern = '%' . $value;
				break;
			default:
				return null;
		}

		$ids = [];
		foreach (syslog_db_fetch_assoc_prepared("SELECT {$map['id']} AS id
			FROM `{$map['table']}`
			WHERE `{$map['name']}` LIKE ? ESCAPE '!'",
			[$pattern]) as $record) {
			$ids[] = (string) $record['id'];
		}

		if (!cacti_sizeof($ids)) {
			// Normalized ids are unsigned and start at 1, so 0 matches
			// nothing without tripping the integer value validation.
			$ids[] = '0';
		}

		$rows = [];
		foreach ($ids as $index => $id) {
			$rows[] = [
				'join'     => $index === 0 ? 'AND' : 'OR',
				'negative' => false,
				'field'    => $map['id'],
				'operator' => '=',
				'value'    => $id
			];
		}

		if (cacti_sizeof($rows) === 1) {
			return $rows[0];
		}

		return ['join' => 'AND', 'negative' => false, 'rows' => $rows];
	};

	$walk = function (&$conditions) use (&$walk, $resolve) {
		if (!is_array($conditions)) {
			return;
		}

		foreach ($conditions as $index => $condition) {
			if (!is_array($condition)) {
				continue;
			}

			if (isset($condition['rows'])) {
				$walk($conditions[$index]['rows']);

				continue;
			}

			if (isset($condition['field'], $condition['operator'], $condition['value'])) {
				$replacement = $resolve($condition['field'], $condition['operator'], $condition['value']);

				if (is_array($replacement)) {
					$replacement['join']     = $condition['join'] ?? 'AND';
					$replacement['negative'] = $condition['negative'] ?? false;
					$conditions[$index]      = $replacement;
				}
			}
		}
	};

	$walk($document['conditions']);

	return $document;
}

/**
 * syslog_get_removal_rule_sql - Compile a structured filter removal rule
 * into parameterized SQL for the given table.
 *
 * @param array  $remove The removal rule attributes
 * @param string $table  The syslog table the rule will run against
 * @param string $prefix Optional table alias prefixed to each column
 *
 * @return array The SQL and params, or an empty array on failure
 */
function syslog_get_removal_rule_sql(&$remove, $table = 'syslog_incoming', $prefix = '', $processing_boundary = true) {
	global $syslogdb_default;

	if (!class_exists('Cacti\\Syslog\\QueryBuilder')) {
		$GLOBALS['syslog_rule_filter_error'] = 'The structured query builder is unavailable.';

		return [];
	}

	$document = syslog_removal_filter_normalize($remove['message'], $table);

	if (!isset($document['conditions'])) {
		$GLOBALS['syslog_rule_filter_error'] = 'The filter is not valid JSON.';

		return [];
	}

	$document['version'] = 1;

	$json = json_encode($document);

	if ($json === false) {
		$GLOBALS['syslog_rule_filter_error'] = 'The filter could not be encoded.';

		return [];
	}

	try {
		$filter = \Cacti\Syslog\QueryBuilder::compile(
			$json,
			syslog_get_removal_rule_fields($table, $prefix)
		);
	} catch (InvalidArgumentException $error) {
		$GLOBALS['syslog_rule_filter_error'] = $error->getMessage();

		return [];
	}

	if ($table === 'syslog_incoming' && $prefix === '' && $processing_boundary) {
		$filter['params'][] = 1;
		$filter['params'][] = $remove['max_seq'];

		return [
			'sql' => "WHERE ({$filter['sql']})
				AND `status` = ?
				AND `seq` <= ?",
			'params' => $filter['params']
		];
	}

	if ($table === 'syslog_incoming' && $processing_boundary) {
		// Aliased shape for the joined transferal INSERT.
		$filter['params'][] = 1;
		$filter['params'][] = $remove['max_seq'];

		return [
			'sql' => "WHERE ({$filter['sql']})
				AND si.`status` = ?
				AND si.`seq` <= ?",
			'params' => $filter['params']
		];
	}

	return ['sql' => 'WHERE (' . $filter['sql'] . ')', 'params' => $filter['params']];
}

/**
 * How many preview rows the "Test rule" action may return at most.
 *
 * The per-request preview row count is additionally clamped so a single
 * response cannot stream unbounded data.
 */
define('SYSLOG_RULE_PREVIEW_MAX_ROWS', 10);

/**
 * syslog_rule_preview - Run a read-only preview of a rule against the
 * incoming table.
 *
 * The rule is compiled through the same QueryBuilder/compiler paths the
 * poller uses, so the preview reflects exactly what the rule would match
 * at processing time.  Only SELECT statements are issued: the preview
 * never saves, enables, disables, deletes, moves, alerts, emails,
 * executes commands, or alters any database data.
 *
 * Legacy 'sql' type rules are only executed under the existing
 * trusted-admin model: they carry hand written SQL stored by
 * administrators with the Syslog Administration realm, the same users
 * who can trigger their execution from the poller anyway.
 *
 * @param array  $rule      The rule attributes: 'type', 'message' and, for
 *                          removal rules, 'method'.  The 'filter' and
 *                          legacy match types are compiled safely.
 * @param string $rule_type Either 'alert' or 'removal'.
 * @param int    $rows      Maximum number of matching messages to return.
 *
 * @return array{error: string, count: int, rows: array<int, array<string, string>>}
 *               The bounded preview result, or an error message.
 */
function syslog_rule_preview(array $rule, string $rule_type = 'alert', int $rows = 10): array {
	global $syslogdb_default, $syslog_incoming_config;

	$rows = max(1, min($rows, SYSLOG_RULE_PREVIEW_MAX_ROWS));

	if (!in_array($rule_type, ['alert', 'removal'], true)) {
		return ['error' => __('Unknown rule type.', 'syslog'), 'count' => 0, 'rows' => []];
	}

	$type = isset($rule['type']) ? (string) $rule['type'] : '';
	$rule['message'] = isset($rule['message']) ? (string) $rule['message'] : '';

	$sql    = '';
	$params = [];

	if ($type === 'filter') {
		// Structured filter documents: compile through the shared builder.
		if ($rule_type === 'removal') {
			// The removal compiler reads the processing boundary from the
			// rule; a preview matches the whole incoming table.
			$rule['max_seq'] = PHP_INT_MAX;

			$compiled = syslog_get_removal_rule_sql($rule, 'syslog_incoming', '', false);

			if (cacti_sizeof($compiled)) {
				// The removal compiler returns a bare WHERE clause; the
				// preview always selects from the incoming table.
				$sql    = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` " . $compiled['sql'];
				$params = $compiled['params'];
			}
		} else {
			$sql_data = syslog_get_alert_sql($rule, 0, false);

			$sql    = isset($sql_data['sql']) ? (string) $sql_data['sql'] : '';
			$params = isset($sql_data['params']) ? (array) $sql_data['params'] : [];

			// Preview filters intentionally omit the worker-only status and
			// sequence boundaries, so they show records waiting for the next
			// poller pass as well as records currently being processed.
		}
	} else {
		// Legacy match types and hand written SQL.  Every value is bound
		// through a placeholder; only the static WHERE shape is built here
		// and the configured column names come from the plugin config.
		if (!isset($syslog_incoming_config['programField'])) {
			$syslog_incoming_config['programField'] = 'program';
		}

		foreach (['textField' => 'message', 'hostField' => 'host', 'facilityField' => 'facility_id', 'priorityField' => 'priority_id'] as $setting => $default) {
			if (!isset($syslog_incoming_config[$setting])) {
				$syslog_incoming_config[$setting] = $default;
			}
		}

		$column = match ($type) {
			'facility' => $syslog_incoming_config['facilityField'],
			'host'     => $syslog_incoming_config['hostField'],
			'program'  => $syslog_incoming_config['programField'],
			default    => $syslog_incoming_config['textField']
		};

		if (!is_string($column) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
			return ['error' => __('The configured incoming field mapping is invalid.', 'syslog'), 'count' => 0, 'rows' => []];
		}

		switch ($type) {
			case 'facility':
				// Facility rules store the facility name; resolve the id.
				$facility_id = syslog_db_fetch_cell_prepared("SELECT facility_id
					FROM `$syslogdb_default`.`syslog_facilities`
					WHERE facility = ?",
					[$rule['message']]);

				if (empty($facility_id)) {
					return ['error' => __('The facility of this rule is not in the reference table yet.', 'syslog'), 'count' => 0, 'rows' => []];
				}

				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE `$column` = ?";
				$params  = [$facility_id];

				break;
			case 'host':
			case 'program':
				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE `$column` = ?";
				$params  = [$rule['message']];

				break;
			case 'messageb':
				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE `$column` LIKE ?";
				$params  = [$rule['message'] . '%'];

				break;
			case 'messagec':
				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE `$column` LIKE ?";
				$params  = ['%' . $rule['message'] . '%'];

				break;
			case 'messagee':
				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE `$column` LIKE ?";
				$params  = ['%' . $rule['message']];

				break;
			case 'sql':
				// Trusted-admin legacy rules: hand written WHERE clause.
				$sql     = "SELECT * FROM `$syslogdb_default`.`syslog_incoming` WHERE (" . $rule['message'] . ')';
				$params  = [];

				break;
			default:
				return ['error' => __('Unknown rule match type.', 'syslog'), 'count' => 0, 'rows' => []];
		}
	}

	if ($sql === '') {
		$error = isset($GLOBALS['syslog_rule_filter_error']) && $GLOBALS['syslog_rule_filter_error'] !== ''
			? (string) $GLOBALS['syslog_rule_filter_error']
			: __('The rule did not compile to a query.', 'syslog');

		return ['error' => $error, 'count' => 0, 'rows' => []];
	}

	// Only a bounded SELECT may run: wrap the compiled WHERE in a COUNT
	// subquery and a LIMIT sample ordered by the newest records.
	$count = syslog_db_fetch_cell_prepared("SELECT COUNT(*) FROM ($sql) AS preview", $params);

	if (!is_numeric($count)) {
		return [
			'error' => __('The preview query could not be evaluated.', 'syslog'),
			'count' => 0,
			'rows'  => []
		];
	}

	$sample = syslog_db_fetch_assoc_prepared("$sql ORDER BY seq DESC LIMIT $rows", $params);

	if (!is_array($sample)) {
		return [
			'error' => __('The preview sample could not be read.', 'syslog'),
			'count' => (int) $count,
			'rows'  => []
		];
	}

	$time_field = isset($syslog_incoming_config['timeField']) ? $syslog_incoming_config['timeField'] : 'logtime';
	$text_field = isset($syslog_incoming_config['textField']) ? $syslog_incoming_config['textField'] : 'message';

	$out = [];

	foreach ($sample as $record) {
		$out[] = [
			'seq'     => isset($record['seq']) ? (string) $record['seq'] : '',
			'logtime' => isset($record[$time_field]) ? (string) $record[$time_field] : '',
			'host'    => isset($record['host']) ? (string) $record['host'] : '',
			'program' => isset($record['program']) ? (string) $record['program'] : '',
			'message' => isset($record[$text_field]) ? (string) $record[$text_field] : ''
		];
	}

	return ['error' => '', 'count' => (int) $count, 'rows' => $out];
}

/**
 * Handle the editor's "Test rule" POST: authorize, validate CSRF, compile
 * the rule from the submitted form values, and return a bounded preview.
 *
 * The action is strictly read-only.  It is additionally gated on the
 * editor realms so a user without the rule pages cannot reach the
 * compiler with arbitrary filter documents.
 *
 * @param string $rule_type Either 'alert' or 'removal'.
 *
 * @return string A JSON document with the preview result, or an error.
 */
function syslog_rule_test_action(string $rule_type): string {
	$realm_page = $rule_type === 'removal' ? 'syslog_removal.php' : 'syslog_alerts.php';

	if (!api_plugin_user_realm_auth($realm_page) || !syslog_allow_rule_edits()) {
		cacti_log("WARNING: syslog rule preview blocked -- missing realm for '$realm_page'", false, 'SYSLOG');

		return (string) json_encode(['error' => __('Permission denied.', 'syslog')]);
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'syslog')]);
	}

	if (!function_exists('csrf_check') || !csrf_check(false)) {
		return (string) json_encode(['error' => __('Invalid request. Please try again.', 'syslog')]);
	}

	$type = (string) get_nfilter_request_var('type');
	$name = trim((string) get_nfilter_request_var('name'));

	// The filter builder syncs its conditions into the message textarea
	// before the form posts; SQL expressions arrive in the same field.
	$message = (string) get_nfilter_request_var('message');

	if ($message === '') {
		return (string) json_encode(['error' => __('The rule has no match expression to test.', 'syslog')]);
	}

	if (mb_strlen($message) > 8192) {
		return (string) json_encode(['error' => __('The match expression is too long to test.', 'syslog')]);
	}

	$preview_rows = get_filter_request_var('preview_rows', FILTER_VALIDATE_INT);

	if ($preview_rows === false || $preview_rows === null || $preview_rows < 1) {
		$preview_rows = 10;
	}

	$rule = [
		'type'    => $type,
		'message' => $message,
		'name'    => $name
	];

	$result = syslog_rule_preview($rule, $rule_type, (int) $preview_rows);

	// Escape every returned message cell against XSS before the client
	// renders it; the JSON encoder alone is not an HTML escape.
	$encoded = syslog_json_safe($result);

	return (string) $encoded;
}

/**
 * syslog_filter_rule_summary - Human readable one-line summary of a
 * versioned filter document for list pages.
 *
 * @param string $json The versioned filter document
 *
 * @return string The summary, or the raw string when not a filter document
 */
function syslog_filter_rule_summary($json) {
	$document = json_decode((string) $json, true);

	if (!is_array($document) || !isset($document['conditions']) || !is_array($document['conditions'])) {
		return $json;
	}

	$fields = syslog_search_fields();
	$parts  = [];

	$walk = function ($conditions) use (&$walk, &$parts, $fields) {
		if (!is_array($conditions)) {
			return;
		}

		foreach ($conditions as $condition) {
			if (!is_array($condition)) {
				continue;
			}

			if (isset($condition['rows'])) {
				$walk($condition['rows']);

				continue;
			}

			if (isset($condition['field'], $condition['operator'], $condition['value'])) {
				$label    = $fields[$condition['field']] ?? $condition['field'];
				$negative = !empty($condition['negative']) ? '!' : '';
				$parts[]  = $negative . $label . ' ' . $condition['operator'] . ' ' . $condition['value'];
			}
		}
	};

	$walk($document['conditions']);

	return cacti_sizeof($parts) ? implode('; ', $parts) : $json;
}

/**
 * Apply every enabled removal rule against the given table, either deleting
 * matching records or transferring them to the syslog_removed table.
 *
 * @param string $table   The table to process ('syslog' or 'syslog_incoming').
 * @param int    $max_seq The highest incoming sequence number to consider.
 *
 * @return array{removed: int, xferred: int} Counts of removed and transferred records.
 */
function syslog_remove_items($table, $max_seq) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Removal Rules...');

	if ($table == 'syslog') {
		$rows = syslog_db_fetch_assoc("SELECT *
			FROM `$syslogdb_default`.`syslog_remove`
			WHERE enabled = 'on'");
	} else {
		$rows = syslog_db_fetch_assoc("SELECT *
			FROM `$syslogdb_default`.`syslog_remove`
			WHERE enabled='on'");
	}

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));
	syslog_status_set('last_delete_rules_processed', cacti_sizeof($rows));
	syslog_status_increment('total_delete_rules_processed', cacti_sizeof($rows));

	$removed = 0;
	$xferred = 0;
	$fired   = [];

	if ($table == 'syslog_incoming') {
		$total = syslog_db_fetch_cell_prepared("SELECT COUNT(*)
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?",
			[$max_seq]);
	} else {
		$total = 0;
	}

	if (cacti_sizeof($rows)) {
		foreach ($rows as $remove) {
			$sql_where = '';
			$params    = [];

			// The transferal INSERT joins the incoming table with an
			// alias, so the shared WHERE would be ambiguous there while
			// the plain DELETE needs unqualified columns.  Compile both
			// shapes up front and track them separately.
			$insert_where = '';
			$insert_params = [];

			if ($remove['type'] == 'filter') {
				if ($table == 'syslog_incoming') {
					$remove['max_seq'] = $max_seq;

					$compiled = syslog_get_removal_rule_sql($remove, $table);

					if (cacti_sizeof($compiled)) {
						$sql_where = $compiled['sql'];
						$params    = $compiled['params'];
					} else {
						syslog_debug("Removal Rule '" . $remove['name'] . "' filter is invalid: " . ($GLOBALS['syslog_rule_filter_error'] ?? 'unknown error'));
					}

					if ($remove['method'] != 'del') {
						$insert_compiled = syslog_get_removal_rule_sql($remove, $table, 'si.');

						if (cacti_sizeof($insert_compiled)) {
							$insert_where  = $insert_compiled['sql'];
							$insert_params = $insert_compiled['params'];
						} else {
							$insert_where = '';
							$insert_params = [];
						}
					}
				} else {
					$compiled = syslog_get_removal_rule_sql($remove, $table);

					if (cacti_sizeof($compiled)) {
						$sql_where = $compiled['sql'];
						$params    = $compiled['params'];
					} else {
						syslog_debug("Removal Rule '" . $remove['name'] . "' filter is invalid: " . ($GLOBALS['syslog_rule_filter_error'] ?? 'unknown error'));
					}
				}
			} elseif ($remove['type'] == 'facility') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['facilityField'] . '` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$facility_id = syslog_db_fetch_cell_prepared("SELECT facility_id
						FROM `$syslogdb_default`.`syslog_facilities`
						WHERE facility = ?",
						[$remove['message']]);

					if (!empty($facility_id)) {
						$sql_where = 'WHERE facility_id = ?';
						$params[]  = $facility_id;
					}
				}
			} elseif ($remove['type'] == 'program') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `program` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$program_id = syslog_db_fetch_cell_prepared("SELECT program_id
						FROM `$syslogdb_default`.`syslog_programs`
						WHERE program = ?", [$remove['message']]);

					if (!empty($program_id)) {
						$sql_where = 'WHERE program_id = ?';
						$params[]  = $program_id;
					}
				}
			} elseif ($remove['type'] == 'host') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `host` = ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'];
					$params[] = $max_seq;
				} else {
					$host_id = syslog_db_fetch_cell_prepared("SELECT host_id
						FROM `$syslogdb_default`.`syslog_hosts`
						WHERE host = ?",
						[$remove['message']]);

					if (!empty($host_id)) {
						$sql_where = 'WHERE host_id = ?';
						$params[]  = $host_id;
					}
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $remove['message'] . '%';
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = $remove['message'] . '%';
				}
			} elseif ($remove['type'] == 'messagec') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = '%' . $remove['message'] . '%';
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = '%' . $remove['message'] . '%';
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE `' . $syslog_incoming_config['textField'] . '` LIKE ?
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = '%' . $remove['message'];
					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE message LIKE ?';
					$params[]  = '%' . $remove['message'];
				}
			} elseif ($remove['type'] == 'sql') {
				if ($table == 'syslog_incoming') {
					$sql_where = 'WHERE (' . $remove['message'] . ')
						AND `status` = 1
						AND `seq` <= ?';

					$params[] = $max_seq;
				} else {
					$sql_where = 'WHERE (' . $remove['message'] . ')';
				}
			}

			if ($sql_where != '') {
				$transaction_started = false;
				$move_failed         = false;
				$messages_xferred    = 0;
				$messages_removed    = 0;

				if ($remove['method'] != 'del') {
					if (!syslog_db_execute('START TRANSACTION')) {
						cacti_log("SYSLOG ERROR: Unable to start transaction for removal rule '" . $remove['name'] . "'", false, 'SYSLOG');
						continue;
					}

					$transaction_started = true;

					if ($table == 'syslog_incoming') {
						if ($remove['type'] == 'filter') {
							// Filter rules compile an alias-qualified WHERE
							// for this joined INSERT separately.
							if ($insert_where == '') {
								syslog_db_execute('ROLLBACK');
								continue;
							}

							$move_failed = !syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT si.logtime, si.priority_id, si.facility_id, sp.program_id, sh.host_id, si.message
								FROM `$syslogdb_default`.`syslog_incoming` AS si
								INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
								ON sh.host = si.host
								INNER JOIN `$syslogdb_default`.`syslog_programs` AS sp
								ON sp.program = si.program $insert_where", $insert_params);
						} else {
							$move_failed = !syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT si.logtime, si.priority_id, si.facility_id, sp.program_id, sh.host_id, si.message
								FROM `$syslogdb_default`.`syslog_incoming` AS si
								INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
								ON sh.host = si.host
								INNER JOIN `$syslogdb_default`.`syslog_programs` AS sp
								ON sp.program = si.program $sql_where", $params);
						}
					} else {
						$move_failed = !syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM `$syslogdb_default`.`syslog` $sql_where", $params);
					}

					if ($move_failed) {
						syslog_db_execute('ROLLBACK');
						cacti_log("SYSLOG ERROR: Rolled back removal rule '" . $remove['name'] . "' after archive insert failed", false, 'SYSLOG');
						continue;
					}

					$messages_xferred = db_affected_rows($syslog_cnn);
				}

				if ($table == 'syslog_incoming' && $remove['method'] != 'del') {
					$replication_where  = $remove['type'] == 'filter' ? $insert_where : $sql_where;
					$replication_params = $remove['type'] == 'filter' ? $insert_params : $params;

					if (!syslog_replication_enqueue_incoming($replication_where, $replication_params, 'syslog_removed')) {
						syslog_db_execute('ROLLBACK');
						cacti_log("SYSLOG ERROR: Rolled back removal rule '" . $remove['name'] . "' after replication outbox insert failed", false, 'SYSLOG');
						continue;
					}
				}

				if ($table == 'syslog_incoming') {
					$move_failed = !syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_incoming` $sql_where", $params);
				} else {
					$move_failed = !syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog` $sql_where", $params);
				}

				if ($move_failed) {
					if ($transaction_started) {
						syslog_db_execute('ROLLBACK');
						cacti_log("SYSLOG ERROR: Rolled back removal rule '" . $remove['name'] . "' after delete failed", false, 'SYSLOG');
					}

					continue;
				}

				$messages_removed = db_affected_rows($syslog_cnn);

				if ($transaction_started && !syslog_db_execute('COMMIT')) {
					syslog_db_execute('ROLLBACK');
					cacti_log("SYSLOG ERROR: Unable to commit transaction for removal rule '" . $remove['name'] . "'", false, 'SYSLOG');
					continue;
				}

				$xferred += $messages_xferred;
				$removed += $messages_removed;

				if (($messages_xferred + $messages_removed) > 0) {
					$fired[] = [
						'name'  => $remove['name'],
						'count' => $messages_xferred + $messages_removed
					];
				}
			}
		}
		}

	syslog_status_set('last_delete_rules_fired', syslog_status_rule_activity_json($fired));

	syslog_debug(sprintf('Removed %5s - Record(s) from ' . $table, $removed));
	syslog_debug(sprintf('Xferred %5s - Record(s) to the syslog_removed table', $xferred));

	return ['removed' => $removed, 'xferred' => $xferred];
}

/**
 * Sets the CSS class for each alert log row of the syslog table as it is
 * displayed. The classes reuse the same Kiwi-style pastel tints that the
 * main syslog table applies per priority, so both tables share one look.
 *
 * @param mixed $severity The alert severity of the row.
 * @param mixed $tip_title The row tooltip title (unused, kept for compatibility).
 *
 * @return void
 */
function syslog_log_row_color($severity, $tip_title): void {
	$class = '';

	switch($severity) {
		case '':
		case '0':
			$class = 'logInfo';

			break;
		case '1':
			$class = 'logWarning';

			break;
		case '2':
			$class = 'logAlert';

			break;
	}

	print "<tr class='tableRow selectable syslogRow $class'>\n";
}

/**
 * Returns the CSS class for a syslog priority level as it is displayed.
 *
 * @param mixed $priority The syslog priority level (0-7).
 *
 * @return string The CSS class name, empty for an unknown priority.
 */
function syslog_priority_class($priority): string {
	switch($priority) {
		case '0':
			$class = 'logEmergency';

			break;
		case '1':
			$class = 'logAlert';

			break;
		case '2':
			$class = 'logCritical';

			break;
		case '3':
			$class = 'logError';

			break;
		case '4':
			$class = 'logWarning';

			break;
		case '5':
			$class = 'logNotice';

			break;
		case '6':
			$class = 'logInfo';

			break;
		case '7':
			$class = 'logDebug';

			break;
	}

	return $class ?? '';
}

/**
 * Prints the opening row tag with the priority CSS class and message tooltip
 * for each row of the syslog table as it is displayed. It supports both the
 * legacy as well as the new approach to controlling these colors.
 *
 * @param mixed $priority The syslog priority level of the row.
 * @param mixed $message  The syslog message for the row tooltip.
 *
 * @return string Always an empty string, kept for legacy row composition.
 */
function syslog_row_color($priority, $message): string {
	$priority_class = syslog_priority_class($priority);
	print "<tr title='" . html_escape($message) . "' class='tableRow selectable syslogRow syslog-detail-row " . html_escape($priority_class) . "'>";

	return '';
}

/**
 * Render compact metadata labels without changing the surrounding table theme.
 *
 * @param mixed  $value The facility or priority value to display.
 * @param string $type The label type ('priority' or 'facility').
 *
 * @return string The formatted HTML label.
 */
function syslog_metadata_label($value, string $type): string {
	$value = (string) $value;
	$class = $type === 'priority' ? 'syslogSeverity' : 'syslogFacility';
	$modifier = preg_replace('/[^a-z]/', '', strtolower($value));

	return '<span class="' . $class . ' ' . $class . '-' . html_escape($modifier) . '">' . html_escape($value) . '</span>';
}

/**
 * Render a displayed device or program value as a direct filter action.
 *
 * @param mixed  $value The displayed value to render as a filter button.
 * @param string $field The field the filter applies to.
 *
 * @return string The formatted HTML filter button.
 */
function syslog_value_filter_button($value, string $field): string {
	$value = (string) $value;
	if ($value === '') return html_escape(__('Unknown', 'syslog'));
	$class = 'syslogValueFilter';
	if ($field === 'host') $class .= ' syslogHostLabel';
	if ($field === 'program') $class .= ' syslogProgramLabel';
	if ($field === 'priority') {
		$class .= ' syslogSeverity syslogSeverity-' . html_escape(preg_replace('/[^a-z]/', '', strtolower($value)));
	}
	return '<button type="button" class="' . $class . '" data-filter-field="' . html_escape($field) . '" data-filter-value="' . html_escape($value) . '">' . html_escape($value) . '</button>';
}

/**
 * Build the SQL host filter clauses from the current host request filter.
 * The resulting clauses are returned through the $hostfilter and
 * $hostfilter_log globals.
 *
 * @param mixed $tab The current tab (unused, kept for compatibility).
 *
 * @return void
 */
function sql_hosts_where($tab) {
	global $hostfilter, $hostfilter_log, $syslog_incoming_config;
	global $syslogdb_default;

	$hostfilter     = '';
	$hostfilter_log = '';
	$hosts_array    = [];

	if (!isempty_request_var('host') && get_nfilter_request_var('host') != 'null') {
		$hostarray = explode(',', trim(get_nfilter_request_var('host')));

		if ($hostarray[0] != '0') {
			foreach ($hostarray as $host_id) {
				input_validate_input_number($host_id);

				if ($host_id > 0) {
					$log_host = syslog_db_fetch_cell_prepared("SELECT host
						FROM `$syslogdb_default`.`syslog_hosts`
						WHERE host_id = ?",
						[$host_id]);

					if (!empty($log_host)) {
						$hosts_array[] = db_qstr($log_host);
					}
				}
			}

			if (cacti_sizeof($hosts_array)) {
				$hostfilter_log = ' host IN(' . implode(',', $hosts_array) . ')';
			}

			$hostfilter .= ' host_id IN(' . implode(',', $hostarray) . ')';
		}
	}
}

/**
 * Prefix values that spreadsheet applications could interpret as formulas.
 * Non-string values are returned unchanged for callers that preserve types.
 *
 * Only literal spaces are stripped before the check; a leading tab or CR is
 * itself a formula trigger in some importers and must stay detectable as
 * the first character rather than being treated as skippable whitespace.
 *
 * @param mixed $value Value destined for CSV output.
 * @return mixed Sanitized CSV value.
 */
function syslog_csv_safe(mixed $value): mixed {
	if (!is_string($value) || $value === '') {
		return $value;
	}

	if (str_starts_with($value, "'")) {
		return $value;
	}

	$stripped = ltrim($value, ' ');

	if ($stripped === '') {
		return $value;
	}

	if (preg_match('/^[=+\-@\t\r]/', $stripped) === 1) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Stream the current syslog or alert log view to the browser as a CSV file
 * download.
 *
 * @param mixed $tab The current tab ('syslog' or an alert log tab).
 *
 * @return void
 */
function syslog_export($tab) {
	if (!empty($GLOBALS['syslog_search_error'])) {
		http_response_code(400);
		header('Content-Type: text/plain; charset=UTF-8');
		print $GLOBALS['syslog_search_error'];
		return;
	}

	global $syslog_incoming_config, $severities;
	global $syslogdb_default;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if ($tab == 'syslog') {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=syslog_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		$hosts = array_rekey(
			syslog_db_fetch_assoc("SELECT host_id, host
				FROM `$syslogdb_default`.`syslog_hosts`"),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc("SELECT facility_id, facility
				FROM `$syslogdb_default`.`syslog_facilities`"),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc("SELECT priority_id, priority
				FROM `$syslogdb_default`.`syslog_priorities`"),
			'priority_id', 'priority'
		);

		$programs = array_rekey(
			syslog_db_fetch_assoc("SELECT program_id, program
				FROM `$syslogdb_default`.`syslog_programs`"),
			'program_id', 'program'
		);

		$fp = fopen('php://output', 'w');

		if ($fp === false) {
			return;
		}

		// PHP 8.4 deprecates fputcsv() without an explicit $escape; '' matches the
		// upcoming default and emits RFC 4180 CSV for messages containing backslashes.
		$line = ['host', 'facility', 'priority', 'program', 'date', 'message'];

		fputcsv($fp, $line, ',', '"', '');

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($facilities[$message['facility_id']])) {
					$facility = $facilities[$message['facility_id']];
				} else {
					$facility = 'Unknown';
				}

				if (isset($programs[$message['program_id']])) {
					$program = $programs[$message['program_id']];
				} else {
					$program = 'Unknown';
				}

				if (isset($priorities[$message['priority_id']])) {
					$priority = $priorities[$message['priority_id']];
				} else {
					$priority = 'Unknown';
				}

				if (isset($hosts[$message['host_id']])) {
					$host = $hosts[$message['host_id']];
				} else {
					$host = 'Unknown';
				}

				$logmsg = $message[$syslog_incoming_config['textField']];

				$line = [
					syslog_csv_safe($host),
					syslog_csv_safe(ucfirst($facility)),
					syslog_csv_safe(ucfirst($priority)),
					syslog_csv_safe(ucfirst($program)),
					$message['logtime'],
					syslog_csv_safe($logmsg)
				];

				fputcsv($fp, $line, ',', '"', '');
			}

		}

		fclose($fp);
	} else {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=alert_log_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		$line = ['date', 'device', 'severity', 'alertname', 'message', 'count', 'facility', 'priority'];

		$fp = fopen('php://output', 'w');

		if ($fp === false) {
			return;
		}

		fputcsv($fp, $line, ',', '"', '');

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($severities[$message['severity']])) {
					$severity = $severities[$message['severity']];
				} else {
					$severity = 'Unknown';
				}

				$line = [
					$message['logtime'],
					syslog_csv_safe($message['host']),
					syslog_csv_safe($severity),
					syslog_csv_safe($message['name']),
					syslog_csv_safe($message['logmsg']),
					$message['count'],
					syslog_csv_safe(ucfirst($message['facility'])),
					syslog_csv_safe(ucfirst($message['priority']))
				];

				fputcsv($fp, $line, ',', '"', '');
			}
		}

		fclose($fp);
	}
}

/**
 * Print a debug message to the console when debugging is enabled.
 *
 * @param string $message The debug message to print.
 *
 * @return void
 */
function syslog_debug(string $message): void {
	global $debug;

	if ($debug) {
		print date('H:i:s') . ' SYSLOG DEBUG: ' . trim($message) . PHP_EOL;
	}
}

/**
 * Log a triggered alert to the syslog_logs table and notify the host alarm
 * hook. Both single message alerts and threshold alerts are supported.
 *
 * @param mixed                  $alert_id   The alert rule ID.
 * @param mixed                  $alert_name The alert rule name.
 * @param mixed                  $severity   The alert severity.
 * @param array<string, mixed>   $msg        The matching syslog message record.
 * @param int                    $count      The number of matching messages.
 * @param string                 $html       The HTML body for the alert log entry.
 * @param array<int, mixed>      $hosts      The hosts to associate with the alarm.
 *
 * @return int|false The sequence of the new alert log entry, or false on failure.
 */
function syslog_log_alert($alert_id, $alert_name, $severity, array $msg, int $count = 1, $html = '', $hosts = []) {
	global $config, $severities;
	global $syslogdb_default;

	if ($count <= 1) {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = $msg['seq'];
		$save['logtime']     = $msg['logtime'];
		$save['logmsg']      = $msg['message'];
		$save['host']        = $msg['host'];
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = 1;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, "`$syslogdb_default`.`syslog_logs`", 'seq');

		$save['seq']        = $id;
		$save['alert_name'] = $alert_name;
		api_plugin_hook_function('syslog_update_hostsalarm', $save);

		cacti_log("WARNING: The Syslog Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered on Host '" . $msg['host'] . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	} else {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = 0;
		$save['logtime']     = date('Y-m-d H:i:s');
		$save['logmsg']      = $alert_name;
		$save['host']        = 'N/A';
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = $count;
		$save['html']        = $html;

		$id = 0;
		$id = syslog_sql_save($save, "`$syslogdb_default`.`syslog_logs`", 'seq');

		$save['seq']         = $id;
		$save['alert_name']  = $alert_name;

		if (cacti_sizeof($hosts)) {
			foreach ($hosts as $host) {
				$save['host'] = $host;
				api_plugin_hook_function('syslog_update_hostsalarm', $save);
			}
		}

		cacti_log("WARNING: The Syslog Instance Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered, Count was '" . $count . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	}
}

/**
 * Move or remove syslog messages that match the enabled removal rules. Only
 * allowlisted table names are accepted.
 *
 * @param string $from_table The source table name.
 * @param string $to_table   The destination table name.
 *
 * @return array{removed: int, xferred: int} The number of records removed and transferred.
 */
function syslog_manage_items($from_table, $to_table) {
	global $config, $syslog_cnn, $syslog_incoming_config;
	global $syslogdb_default;

	/*
	 * Table names are interpolated into DDL/DML below because MySQL does
	 * not bind identifiers. Reject anything outside the static allowlist
	 * so a future caller cannot turn this into a SQL injection surface.
	 */
	$allowed_tables = ['syslog', 'syslog_incoming', 'syslog_removed'];

	if (!in_array($from_table, $allowed_tables, true) || !in_array($to_table, $allowed_tables, true)) {
		cacti_log("SYSLOG ERROR: syslog_manage_items called with disallowed tables from='$from_table' to='$to_table'", false, 'SYSLOG');

		return ['removed' => 0, 'xferred' => 0];
	}

	// Select filters to work on
	$rows = syslog_db_fetch_assoc("SELECT * FROM `$syslogdb_default`.`syslog_remove` WHERE enabled = 'on'");

	syslog_debug(sprintf('Found   %5s - Removal Rule(s) to process', cacti_sizeof($rows)));
	syslog_status_set('last_delete_rules_processed', cacti_sizeof($rows));
	syslog_status_increment('total_delete_rules_processed', cacti_sizeof($rows));

	$removed = 0;
	$xferred = 0;
	$total   = 0;
	$fired   = [];

	if (cacti_sizeof($rows)) {
		foreach ($rows as $remove) {
			syslog_debug('Processing Rule  - ' . $remove['message']);

			$sql_sel = '';
			$sql_dlt = '';

			if ($remove['type'] == 'facility') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq
						FROM `$syslogdb_default`.`$from_table`
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `$syslogdb_default`.`syslog_facilities`
							WHERE facility = " . db_qstr($remove['message']) . ')';
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `$syslogdb_default`.`syslog_facilities`
							WHERE facility = " . db_qstr($remove['message']) . ')';
				}
			} elseif ($remove['type'] == 'host') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq
						FROM `$syslogdb_default`.`$from_table`
						WHERE host_id in
							(SELECT distinct host_id FROM `$syslogdb_default`.`syslog_hosts`
							WHERE host = " . db_qstr($remove['message']) . ')';
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE host_id in
							(SELECT distinct host_id FROM `$syslogdb_default`.`syslog_hosts`
							WHERE host = " . db_qstr($remove['message']) . ')';
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr($remove['message'] . '%');
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr($remove['message'] . '%');
				}
			} elseif ($remove['type'] == 'messagec') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr('%' . $remove['message'] . '%');
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr('%' . $remove['message'] . '%');
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr('%' . $remove['message']);
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE message LIKE " . db_qstr('%' . $remove['message']);
				}
			} elseif ($remove['type'] === 'sql') {
				if ($remove['method'] !== 'del') {
					$sql_sel = "SELECT seq FROM `$syslogdb_default`.`$from_table`
						WHERE (" . $remove['message'] . ')';
				} else {
					$sql_dlt = "DELETE FROM `$syslogdb_default`.`$from_table`
						WHERE (" . $remove['message'] . ')';
				}
			}

			if ($sql_sel != '' || $sql_dlt != '') {
				$debugm = '';

				// process the removal rule first
				if ($sql_sel != '') {
					$move_count = 0;
					// first insert, then delete
					$move_records = syslog_db_fetch_assoc($sql_sel);
					syslog_debug(sprintf('Found   %5s - Message(s)', cacti_sizeof($move_records)));

					if (cacti_sizeof($move_records)) {
						$all_seq        = '';
						$messages_moved = 0;

						foreach ($move_records as $move_record) {
							$all_seq = $all_seq . ', ' . $move_record['seq'];
						}

						$all_seq = preg_replace('/^,/i', '', $all_seq);

						if (!syslog_db_execute('START TRANSACTION')) {
							cacti_log("SYSLOG ERROR: Unable to start transaction for removal rule '" . $remove['message'] . "'", false, 'SYSLOG');
							continue;
						}

						if (!syslog_db_execute("INSERT INTO `$syslogdb_default`.`$to_table`
							(facility_id, priority_id, host_id, logtime, message)
							(SELECT facility_id, priority_id, host_id, logtime, message
							FROM `$syslogdb_default`.`$from_table`
							WHERE seq IN (" . $all_seq . '))')) {
							syslog_db_execute('ROLLBACK');
							cacti_log("SYSLOG ERROR: Rolled back removal rule '" . $remove['message'] . "' after move insert failed", false, 'SYSLOG');
							continue;
						}

						$messages_moved = db_affected_rows($syslog_cnn);

						if ($messages_moved > 0) {
							if (!syslog_db_execute("DELETE FROM `$syslogdb_default`.`$from_table`
								WHERE seq IN ($all_seq)")) {
								syslog_db_execute('ROLLBACK');
								cacti_log("SYSLOG ERROR: Rolled back removal rule '" . $remove['message'] . "' after move delete failed", false, 'SYSLOG');
								continue;
							}
						}

						if (!syslog_db_execute('COMMIT')) {
							syslog_db_execute('ROLLBACK');
							cacti_log("SYSLOG ERROR: Unable to commit transaction for removal rule '" . $remove['message'] . "'", false, 'SYSLOG');
							continue;
						}

						$xferred += $messages_moved;
						$move_count = $messages_moved;

						if ($messages_moved > 0) {
							$fired[] = [
								'name'  => $remove['name'],
								'count' => $messages_moved
							];
						}
					}

					$debugm = sprintf('Moved   %5s - Message(s)', $move_count);
				}

				if ($sql_dlt != '') {
					// now delete the remainder that match
					syslog_db_execute($sql_dlt);
					$deleted = db_affected_rows($syslog_cnn);
					$removed += $deleted;
					$debugm   = sprintf('Deleted %5s Message(s)', $removed);

					if ($deleted > 0) {
						$fired[] = [
							'name'  => $remove['name'],
							'count' => $deleted
						];
					}
				}

				syslog_debug($debugm);
			}
		}
	}

	syslog_status_set('last_delete_rules_fired', syslog_status_rule_activity_json($fired));

	return ['removed' => $removed, 'xferred' => $xferred];
}

/**
 * get_hash_syslog - returns the current unique hash for an alert
 *
 * @param mixed $id
 * @param mixed $table
 *
 * @return string 128-bit hexadecimal hash
 */
function get_hash_syslog($id, $table) {
	$hash = syslog_db_fetch_cell_prepared('SELECT hash
		FROM ' . $table . '
		WHERE id = ?',
		[$id]);

	if (empty($hash)) {
		return generate_hash();
	}

	if (preg_match('/[a-fA-F0-9]{32}/', $hash)) {
		return $hash;
	} else {
		return generate_hash();
	}
}

/**
 * Recursively render an array of item values as one indented XML tag per key.
 *
 * @param array<string|int, mixed> $array The array to convert.
 *
 * @return string The XML fragment.
 */
function syslog_ia2xml(array $array): string {
	$xml = '';

	if (cacti_sizeof($array)) {
		foreach ($array as $key=>$value) {
			if (is_array($value)) {
				$xml .= "\t<$key>" . syslog_ia2xml($value) . "</$key>\n";
			} else {
				$xml .= "\t<$key>" . html_escape((string) $value) . "</$key>\n";
			}
		}
	}

	return $xml;
}

/**
 * Wrap an array of item values in a numbered XML tag block.
 *
 * @param array<string|int, mixed> $array The array to convert.
 * @param string                   $tag   The tag name to wrap the data in.
 *
 * @return string The XML fragment.
 */
function syslog_array2xml(array $array, string $tag = 'template'): string {
	static $index = 1;

	$xml = "<$tag$index>\n" . syslog_ia2xml($array) . "</$tag$index>\n";

	$index++;

	return $xml;
}

/**
 * syslog_execute_ticket_command - run the configured ticketing command for an alert
 *
 * @param array  $alert         The alert row from syslog_alert table
 * @param array  $hostlist      Hostnames matched by the alert
 * @param string $error_message sprintf template used if exec() returns non-zero
 *
 * @return void
 */
function syslog_execute_ticket_command($alert, $hostlist, $error_message) {
	$command = read_config_option('syslog_ticket_command');

	if ($command != '') {
		$command = trim($command);
	}

	if ($alert['open_ticket'] == 'on' && $command != '') {
		// trim surrounding quotes so paths like "/usr/bin/cmd" resolve correctly
		$cparts     = preg_split('/\s+/', trim($command)) ?: [''];
		$executable = trim($cparts[0], '"\'');

		if (cacti_sizeof($cparts) && is_executable($executable)) {
			$command = $command .
				' --alert-name=' . cacti_escapeshellarg(clean_up_name($alert['name'])) .
				' --severity=' . cacti_escapeshellarg($alert['severity']) .
				' --hostlist=' . cacti_escapeshellarg(implode(',', $hostlist)) .
				' --message=' . cacti_escapeshellarg($alert['message']);

			$output = [];
			$return = 0;

			exec($command, $output, $return);

			if ($return !== 0) {
				cacti_log(sprintf($error_message, $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
			}
		} else {
			$reason = (strpos($executable, DIRECTORY_SEPARATOR) === false)
				? 'PATH-based lookups are not supported; use an absolute path'
				: 'file not found or not marked executable';
			cacti_log("SYSLOG ERROR: Ticket command is not executable: '$command' -- $reason", false, 'SYSTEM');
		}
	}
}

/**
 * syslog_execute_alert_command - run the per-alert shell command for a matched result
 *
 * @param array  $alert    The alert row from syslog_alert table
 * @param array  $results  The matched syslog result row
 * @param string $hostname Resolved hostname for the source device
 *
 * @return void
 */
function syslog_execute_alert_command($alert, $results, $hostname) {
	/* alert_replace_variables() escapes each substituted token (<ALERTID>,
	 * <HOSTNAME>, <PRIORITY>, <FACILITY>, <MESSAGE>, <SEVERITY>) with
	 * cacti_escapeshellarg(). The command template itself comes from admin
	 * configuration ($alert['command']) and is trusted at that boundary.
	 * Do not introduce additional substitution paths that bypass this escaping. */
	$command = alert_replace_variables($alert, $results, $hostname);

	// trim surrounding quotes so paths like "/usr/bin/cmd" resolve correctly
	$cparts     = preg_split('/\s+/', trim($command)) ?: [''];
	$executable = trim($cparts[0], '"\'');

	$output = [];
	$return = 0;

	if (cacti_sizeof($cparts) && is_executable($executable)) {
		exec($command, $output, $return);

		if ($return !== 0 && !empty($output)) {
			cacti_log('SYSLOG NOTICE: Alert command output: ' . implode(', ', $output), true, 'SYSTEM');
		}

		if ($return !== 0) {
			cacti_log(sprintf('ERROR: Alert command failed.  Alert:%s, Exit:%s, Output:%s', $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
		}
	} else {
		$reason = (strpos($executable, DIRECTORY_SEPARATOR) === false)
			? 'PATH-based lookups are not supported; use an absolute path'
			: 'file not found or not marked executable';
		cacti_log("SYSLOG ERROR: Alert command is not executable: '$command' -- $reason", false, 'SYSTEM');
	}
}

/**
 * syslog_process_alerts - Process each of the Syslog Alerts
 *
 * Syslog Alerts come in essentially 4 types
 *
 * System Wide non-threshold alerts - These alerts are simply alerts that match the pattern defined by the alert
 * System Wide threshold alerts     - These alerts are syslog messages that both match the pattern and have more than the
 *                                    threshold amount that take place every collector cycle (30 seconds, 1 minutes, 5 minutes, etc)
 * Host based non-threshold alerts  - Alerts that happen on a per host basis, so you can alert for each host that the syslog message
 *                                    occurred to.
 * Host based threshold alerts      - Like the system level alert, it's an alert that happens more than x times per host.
 *
 * The advantage and reason for having host based alerts is that it allows you to target ticket generation for a specific host
 * and more importantly, to be able to have a separate re-alert cycles for that very same message as there can be similar messages
 * happening all the time at the system level, so it's hard to target a single host for re-alert rules.
 *
 * @param int  $max_seq The max_seq to process
 *
 * @return array An array of the number of alerts processed and the number of alerts generated
 */
/**
 * Return true when a day expression contains the given ISO weekday (1=Mon).
 */
function syslog_alert_schedule_day_matches(string $expression, int $weekday): bool {
	$days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7];
	$expression = strtolower(trim($expression));

	if ($expression === '*') {
		return true;
	}

	foreach (explode(',', $expression) as $part) {
		$range = array_map('trim', explode('-', $part, 2));
		$start = $days[substr($range[0], 0, 3)] ?? 0;
		$end   = $days[substr($range[1] ?? $range[0], 0, 3)] ?? 0;
		if ($start && $end && (($start <= $end && $weekday >= $start && $weekday <= $end) || ($start > $end && ($weekday >= $start || $weekday <= $end)))) {
			return true;
		}
	}

	return false;
}

/**
 * Determine whether a local-time maintenance schedule is active.
 * Schedules contain one or more "days HH:MM-HH:MM" windows, separated by
 * newlines or semicolons; overnight windows apply to the following morning.
 */
function syslog_alert_schedule_is_active(string $schedule, ?int $timestamp = null): bool {
	if (trim($schedule) === '') {
		return false;
	}

	$timestamp = $timestamp ?? time();
	$weekday   = (int) date('N', $timestamp);
	$minute    = ((int) date('G', $timestamp) * 60) + (int) date('i', $timestamp);

	foreach (preg_split('/[;\r\n]+/', $schedule) ?: [] as $window) {
		if (!preg_match('/^\s*([^\s]+)\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $window, $matches)) {
			continue;
		}
		$start = ((int) $matches[2] * 60) + (int) $matches[3];
		$end   = ((int) $matches[4] * 60) + (int) $matches[5];
		if ($start > 1439 || $end > 1439) {
			continue;
		}
		if ($start <= $end && syslog_alert_schedule_day_matches($matches[1], $weekday) && $minute >= $start && $minute <= $end) {
			return true;
		}
		if ($start > $end && (($minute >= $start && syslog_alert_schedule_day_matches($matches[1], $weekday)) || ($minute <= $end && syslog_alert_schedule_day_matches($matches[1], $weekday === 1 ? 7 : $weekday - 1)))) {
			return true;
		}
	}

	return false;
}

/** Build a single maintenance window from the day and time form controls. */
function syslog_alert_maintenance_window(string $days, string $start, string $end): string {
	if ($days === '0' || !preg_match('/^[1-7](,[1-7])*$/', $days) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $start) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $end) || $start === $end) {
		return '';
	}

	return "$days $start-$end";
}

/** Return true while a valid local one-time maintenance interval is active. */
function syslog_alert_datetime_window_is_active(string $start, string $end, ?int $timestamp = null): bool {
	if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $end)) {
		return false;
	}
	$start_time = strtotime($start);
	$end_time   = strtotime($end);
	$timestamp  = $timestamp ?? time();

	return $start_time !== false && $end_time !== false && $end_time > $start_time && $timestamp >= $start_time && $timestamp <= $end_time;
}

/**
 * Check cooldown and duplicate state for a rule notification.
 *
 * @return array{allowed:bool,scope_key:string,dedup_hash:string}
 */
function syslog_alert_suppression_check(array $alert, string $hostname, array $matches): array {
	$scope_key = $hostname !== '' ? $hostname : 'system';
	$messages  = array_unique(array_map(static fn($match) => (string) ($match['host'] ?? '') . "\n" . (string) ($match['message'] ?? ''), $matches));
	sort($messages, SORT_STRING);
	$dedup_hash = sha1(implode("\n", $messages));
	$cooldown_value = (int) ($alert['cooldown_minutes'] ?? -1);
	$dedup_value    = (int) ($alert['deduplication_minutes'] ?? -1);
	$cooldown = $cooldown_value >= 0 ? $cooldown_value : (int) read_config_option('syslog_alert_cooldown_minutes');
	$dedup    = $dedup_value >= 0 ? $dedup_value : (int) read_config_option('syslog_alert_deduplication_minutes');
	$now      = time();

	if ($cooldown > 0) {
		$last_sent = (int) syslog_db_fetch_cell_prepared('SELECT MAX(last_sent) FROM syslog_alert_suppression WHERE alert_id = ? AND scope_key = ?', [$alert['id'], $scope_key]);
		if ($last_sent > $now - ($cooldown * 60)) {
			return ['allowed' => false, 'scope_key' => $scope_key, 'dedup_hash' => $dedup_hash];
		}
	}

	if ($dedup > 0) {
		$last_sent = (int) syslog_db_fetch_cell_prepared('SELECT last_sent FROM syslog_alert_suppression WHERE alert_id = ? AND scope_key = ? AND dedup_hash = ?', [$alert['id'], $scope_key, $dedup_hash]);
		if ($last_sent > $now - ($dedup * 60)) {
			return ['allowed' => false, 'scope_key' => $scope_key, 'dedup_hash' => $dedup_hash];
		}
	}

	return ['allowed' => true, 'scope_key' => $scope_key, 'dedup_hash' => $dedup_hash];
}

/** Record a notification after it has been sent. */
function syslog_alert_suppression_record(array $alert, array $state): void {
	syslog_db_execute_prepared('INSERT INTO syslog_alert_suppression (alert_id, scope_key, dedup_hash, last_sent) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_sent = VALUES(last_sent)', [$alert['id'], $state['scope_key'], $state['dedup_hash'], time()]);
}

/** Return whether this alert's configured maintenance window is active. */
function syslog_alert_maintenance_is_active(array $alert): bool {
	$mode = $alert['maintenance_mode'] ?? 'inherit';
	if ($mode === 'disabled') {
		return false;
	}
	if ($mode === 'custom') {
		return syslog_alert_datetime_window_is_active((string) ($alert['maintenance_datetime_start'] ?? ''), (string) ($alert['maintenance_datetime_end'] ?? ''))
			|| syslog_alert_schedule_is_active(syslog_alert_maintenance_window((string) ($alert['maintenance_days'] ?? ''), (string) ($alert['maintenance_start'] ?? ''), (string) ($alert['maintenance_end'] ?? '')));
	}

	return syslog_alert_datetime_window_is_active((string) read_config_option('syslog_alert_maintenance_datetime_start'), (string) read_config_option('syslog_alert_maintenance_datetime_end'))
		|| syslog_alert_schedule_is_active(syslog_alert_maintenance_window((string) read_config_option('syslog_alert_maintenance_days'), (string) read_config_option('syslog_alert_maintenance_start'), (string) read_config_option('syslog_alert_maintenance_end')));
}

/**
 * Limit an alert query by active device-wide handling rules.
 *
 * A pass-through priority of Critical (2), for example, permits priorities
 * Emergency through Critical even if the host is muted or in maintenance.
 * A rule applies by hostname to every alert definition, not merely one rule.
 *
 * @return array{sql:string,params:array<int,int>}
 */
function syslog_device_rule_sql(bool $maintenance_active): array {
	global $syslogdb_default, $syslog_incoming_config;
	$host_field = $syslog_incoming_config['hostField'] ?? 'host';
	$priority_field = $syslog_incoming_config['priorityField'] ?? 'priority_id';
	$now = time();
	$active_mute = "(dr.mute_mode = 'indefinite' OR (dr.mute_mode = 'until' AND dr.mute_until > ?))";
	$pass_through = "(dr.pass_through_priority >= 0 AND COALESCE(`$priority_field`, 99) <= dr.pass_through_priority)";
	$mute_filter = "\n\t\t\t\tAND NOT EXISTS (SELECT 1 FROM `$syslogdb_default`.`syslog_device_rule` AS dr WHERE dr.host = `$host_field` AND dr.enabled = 'on' AND NOT $pass_through AND $active_mute)";
	if ($maintenance_active) {
		// Normal maintenance still mutes every device.  Only an explicit device
		// exception may admit a record, so the absence of a device rule is not
		// accidentally treated as an exception.
		$maintenance_filter = "\n\t\t\t\tAND EXISTS (SELECT 1 FROM `$syslogdb_default`.`syslog_device_rule` AS dr WHERE dr.host = `$host_field` AND dr.enabled = 'on' AND (dr.allow_maintenance = 'on' OR $pass_through))";
	} else {
		$maintenance_filter = '';
	}

	return [
		'sql' => $maintenance_filter . $mute_filter,
		'params' => [$now]
	];
}

function syslog_process_alerts($max_seq) {
	global $syslogdb_default;

	$syslog_alarms = 0;
	$syslog_alerts = 0;
	$fired         = [];

	// send out the alerts
	$alerts = syslog_db_fetch_assoc("SELECT *
		FROM `$syslogdb_default`.`syslog_alert`
		WHERE enabled = 'on'");

	if (cacti_sizeof($alerts)) {
		$syslog_alerts = cacti_sizeof($alerts);
	}

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Alerts...');
	syslog_debug('-------------------------------------------------------------------------------------');

	syslog_debug(sprintf('Found   %5s - Alert Rule(s) to process', $syslog_alerts));
	syslog_status_set('last_alert_rules_processed', $syslog_alerts);
	syslog_status_increment('total_alert_rules_processed', $syslog_alerts);

	if (cacti_sizeof($alerts)) {
		foreach ($alerts as $alert) {
			$maintenance_active = syslog_alert_maintenance_is_active($alert);
			$sql      = '';
			$params   = [];

			// we roll up statistics depending on the level
			if ($alert['level'] == 1) {
				$groupBy = ' GROUP BY host';
			} else {
				$groupBy = '';
			}

			$sql_data = syslog_get_alert_sql($alert, $max_seq);

			if (!cacti_sizeof($sql_data)) {
				syslog_debug(sprintf('Error       - Unable to determine SQL for Alert \'%s\'', $alert['name']));

				continue;
			}

			$sql    = $sql_data['sql'];
			$params = $sql_data['params'];
			$device_rule = syslog_device_rule_sql($maintenance_active);
			$sql .= $device_rule['sql'];
			$params = array_merge($params, $device_rule['params']);

			if ($sql != '') {
				if ($alert['level'] == '1') {
					$th_sql  = str_replace('*', 'host, COUNT(*) AS count', $sql);
					$results = syslog_db_fetch_assoc_prepared($th_sql . $groupBy, $params);

					if (cacti_sizeof($results)) {
						foreach ($results as $result) {
							$aparams   = $params;
							$aparams[] = $result['host'];

							$asql = $sql . ' AND host = ?';

							$triggered = syslog_process_alert($alert, $asql, $aparams, $result['count'], $result['host']);
							$syslog_alarms += $triggered;

							if ($triggered > 0) {
								$fired[$alert['name']] = ($fired[$alert['name']] ?? 0) + $triggered;
							}
						}
					}
				} elseif ($alert['method'] == '1') {
					$th_sql = str_replace('*', 'COUNT(*)', $sql);
					$count  = syslog_db_fetch_cell_prepared($th_sql . $groupBy, $params);
					$triggered = syslog_process_alert($alert, $sql, $params, $count);
					$syslog_alarms += $triggered;

					if ($triggered > 0) {
						$fired[$alert['name']] = ($fired[$alert['name']] ?? 0) + $triggered;
					}
				} else {
					$count = 0;
					$triggered = syslog_process_alert($alert, $sql, $params, $count);
					$syslog_alarms += $triggered;

					if ($triggered > 0) {
						$fired[$alert['name']] = ($fired[$alert['name']] ?? 0) + $triggered;
					}
				}
			}
		}
	}

	$activity = [];
	foreach ($fired as $name => $count) {
		$activity[] = ['name' => $name, 'count' => $count];
	}

	syslog_status_set('last_alert_rules_fired', syslog_status_rule_activity_json($activity));

	return ['syslog_alerts' => $syslog_alerts, 'syslog_alarms' => $syslog_alarms];
}

/**
 * syslog_process_alert - Process the Alert and generate notifications, execute commands, etc.
 *
 * @param array  $alert The alert to process
 * @param string $sql The SQL to search for the Alert
 * @param array  $params The SQL parameters to be prepared into the SQL
 * @param int    $count In the case of a threshold alert, the number of occurrents
 *                      of hosts with occurrences that were encountered through
 *                      pre-processing the message
 * @param string $hostname The hostname that this alert rule is for
 *
 * @return int 1 if the alert triggered, else 0
 */
function syslog_process_alert($alert, $sql, $params, $count, $hostname = '') {
	global $config, $severities, $syslog_levels;

	include_once($config['base_path'] . '/lib/reports.php');

	$messese  = '';
	$smsalert = '';

	$alert_count   = 0;
	$syslog_alarms = 0;
	$hostlist      = [];
	$max_alerts    = read_config_option('syslog_maxrecords');
	$report_tag    = false;
	$theme         = false;
	$format_ok     = false;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug(sprintf('Processing    - %s', $alert['name']));

	if (read_config_option('syslog_html') == 'on') {
		$html      = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok' : 'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included' : 'missing'));
	} else {
		$html = false;
	}

	/**
	 * format the from Email address
	 */
	$from_email = read_config_option('settings_from_email');

	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');

	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = [$from_email, $from_name];

	/**
	 * format the destination Email addresses
	 */
	$alert['email'] = trim($alert['email'], ', ');

	if ($alert['notify'] > 0) {
		$additional = db_fetch_cell_prepared('SELECT emails
			FROM plugin_notification_lists
			WHERE id = ?',
			[$alert['notify']]);

		if ($additional != '') {
			$alert['email'] .= ', ' . trim($additional, ' ,');
		}
	}

	/**
	 * process the alert now.
	 */
	if (($alert['method'] == '1' && $count >= $alert['num']) || $alert['method'] == '0') {
		$at = syslog_db_fetch_assoc_prepared($sql, $params);

		/**
		 * get a date for the repeat alert
		 */
		if ($alert['repeat_alert']) {
			$date = date('Y-m-d H:i:s', time() - ($alert['repeat_alert'] * read_config_option('poller_interval')));
		} else {
			$date = '';
		}

		/**
		 * The finalized email or test message.
		 */
		$message  = '';

		/**
		 * A list of all messages from the alert
		 */
		$results = [];

		syslog_debug(sprintf('Found   %5s - Matching Records.', cacti_sizeof($at)));

		if (cacti_sizeof($at)) {
			if ($html) {
				if (!$format_ok) {
					$message .= "<style type='text/css'>";
					$message .= file_get_contents($config['base_path'] . '/plugins/syslog/css/syslog.css');
					$message .= '</style>';
				}

				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message .= '<h1>' . __esc('Cacti Syslog Threshold Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}

					$message .= '<table class="cactiTable">';
					$message .= '<tr class="header_row tableHeader">
						<th>' . __('Alert Name', 'syslog') . '</th>
						<th>' . __('Severity', 'syslog') . '</th>
						<th>' . __('Threshold', 'syslog') . '</th>
						<th>' . __('Count', 'syslog') . '</th>
						<th>' . __('Match String', 'syslog') . '</th>
					</tr>';

					$message .= '<tr><td>' . html_escape($alert['name']) . '</td>';
					$message .= '<td>' . $severities[$alert['severity']] . '</td>';
					$message .= '<td>' . $alert['num'] . '</td>';
					$message .= '<td>' . sizeof($at) . '</td>';
					$message .= '<td>' . html_escape($alert['message']) . '</td></tr></table><br>';
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog') . '</h1>';
						} else {
							$message .= '<h1>' . __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
						}
					} else {
						$message .= '<table class="cactiTable"><tr><td>' . $alert['body'] . '</td></td></table>';
					}
				}

				$message .= '<table class="cactiTable">';
				$message .= '<tr class="header_row tableHeader">
					<th>' . __('Hostname', 'syslog') . '</th>
					<th>' . __('Date', 'syslog') . '</th>
					<th>' . __('Severity', 'syslog') . '</th>
					<th>' . __('Level', 'syslog') . '</th>
					<th>' . __('Message', 'syslog') . '</th>
				</tr>';
			} else {
				if ($alert['method'] == '1') {
					if ($alert['body'] == '') {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;

						if ($hostname != '') {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered for Host \'%s\'', $hostname, 'syslog') . PHP_EOL . PHP_EOL;
						} else {
							$message .= __('WARNING: A Syslog Threshold Alert has Been Triggered', 'syslog') . PHP_EOL . PHP_EOL;
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'] . PHP_EOL;
					}

					$message .= __('Name:', 'syslog') . ' ' . html_escape($alert['name']) . PHP_EOL;
					$message .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']] . PHP_EOL;
					$message .= __('Threshold:', 'syslog') . ' ' . $alert['num'] . PHP_EOL;
					$message .= __('Count:', 'syslog') . ' ' . sizeof($at) . PHP_EOL;
					$message .= __('Message String:', 'syslog') . ' ' . html_escape($alert['message']) . PHP_EOL;
				} else {
					if ($alert['body'] == '') {
						if ($hostname != '') {
							$message .= __esc('Cacti Syslog Alert \'%s\' for Host \'%s\'', $alert['name'], $hostname, 'syslog');
						} else {
							$message .= __esc('Cacti Syslog Alert \'%s\'', $alert['name'], 'syslog');
						}
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= $alert['body'];
					}
				}
			}

			$hmessage = $message;
			$plogged  = false;
			$flogged  = false;

			foreach ($at as $a) {
				$hostlist[]         = $a['host'];
				$results['message'] = (isset($results['message']) ? $results['message'] . ', ' : '') . $a['message'];

				if (isset($results['priority_id']) && $results['priority_id'] != $a['priority_id'] && !$plogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one priority id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$plogged = true;
				}

				if (isset($results['facility_id']) && $results['facility_id'] != $a['facility_id'] && !$flogged) {
					cacti_log(sprintf('Alert \'%s\' has more than one facility id, last one experienced will be leveraged', $alert['name']), false, 'SYSLOG');
					$flogged = true;
				}

				$results['priority_id'] = $a['priority_id'];
				$results['facility_id'] = $a['facility_id'];

				if (($alert['method'] == 1 && $alert_count < $max_alerts) || $alert['method'] == 0) {
					if ($alert['method'] == 0) {
						$message = $hmessage;
					}

					if ($html) {
						$message .= '<tr>
							<td>' . html_escape($a['host']) . '</td>
							<td>' . $a['logtime'] . '</td>
							<td>' . $severities[$alert['severity']] . '</td>
							<td>' . $syslog_levels[$a['priority_id']] . '</td>
							<td>' . html_escape($a['message']) . '</td>
						</tr>';
					} else {
						$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
						$message .= __('Hostname:', 'syslog') . ' ' . html_escape($a['host']) . PHP_EOL;
						$message .= __('Date:', 'syslog') . ' ' . $a['logtime'] . PHP_EOL;
						$message .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']] . PHP_EOL . PHP_EOL;
						$message .= __('Level:', 'syslog') . ' ' . $syslog_levels[$a['priority_id']] . PHP_EOL . PHP_EOL;
						$message .= __('Message:', 'syslog') . ' ' . PHP_EOL . $a['message'] . PHP_EOL;
					}
				}
			}

			$hostlist = array_unique($hostlist);

			$syslog_alarms++;
			$alert_count++;

			$send  = true;
			$found = false;

			/**
			 * If this is a repeat alert type threshold, then check to
			 * see if it's time to re-alert.
			 */
			if ($alert['repeat_alert'] > 0) {
				if ($hostname != '') {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = ?',
						[$alert['id'], $date, $hostname]);
				} else {
					$found = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
						FROM syslog_logs
						WHERE alert_id = ?
						AND logtime > ?
						AND host = "system"',
						[$alert['id'], $date]);
				}
			}

			if ($found) {
				$send = false;
			}

			$suppression_state = syslog_alert_suppression_check($alert, $hostname, $at);
			if ($send && !$suppression_state['allowed']) {
				$send = false;
				syslog_debug("Alert Rule '" . $alert['name'] . "' notification suppressed by cooldown or duplicate detection");
			}

			if ($html) {
				$message .= '</table>';
			} else {
				$message .= '---------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
			}

			if ($html) {
				if ($format_ok) {
					if ($report_tag) {
						$message = str_replace('<REPORT>', $message, $output);
					} else {
						$message = $output . $message . '</body></html>';
					}
				} else {
					$message = '<html><body>' . $message . '</body></html>';
				}
			}

			/**
			 * This is a Traditional syslog alert where all matching messages
			 * will be reported in the notification.
			 */
			if ($alert['method'] == '0') {
				/**
				 * Without matching records the alert body and the matched
				 * message record would be undefined, so do not alert.
				 */
				if ($send && isset($a)) {
					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $a, 1, $message);

					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Host:', 'syslog') . $a['host'] . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					/**
					 * Send the Email notification
					 */
					if ($alert['email'] != '' || $smsalert != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					/**
					 * Open a ticket if this options have been selected.
					 */
					syslog_execute_ticket_command($alert, $hostlist, 'ERROR: Ticket Command Failed.  Alert:%s, Exit:%s, Output:%s');

					if (trim($alert['command']) != '') {
						syslog_execute_alert_command($alert, $results, $hostname);
					}
				}
			} elseif ($alert['method'] == 1) {
				if ($send) {
					/**
					 * Send the Email notification
					 */
					/**
					 * The SMS text is only known after the alert is logged,
					 * so a threshold alert emails out only when a recipient
					 * is configured.
					 */
					if ($alert['email'] != '') {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), $message, $smsalert);
					}

					$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $at[0], sizeof($at), $message, $hostlist);
					$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Count:', 'syslog') . sizeof($at) . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

					alert_setup_environment($alert, $results, $hostlist, $hostname);

					syslog_execute_ticket_command($alert, $hostlist, 'ERROR: Command Failed.  Alert:%s, Exit:%s, Output:%s');

					if (trim($alert['command']) != '') {
						syslog_execute_alert_command($alert, $results, $hostname);
					}
				}
			}

			if ($send) {
				syslog_alert_suppression_record($alert, $suppression_state);
			}

			syslog_debug("Alert Rule '" . $alert['name'] . "' has been triggered");
		}
	}

	return $alert_count;
}

/**
 * syslog_get_alert_sql - Get the SQL and params for the alert to
 * checi.
 *
 * @param array $alert The alert attributes to process
 * @param int   $max_seq The max sequence
 *
 * @return array The SQL and the prepared array for the SQL
 */
function syslog_get_alert_sql(&$alert, $max_seq, $processing_boundary = true) {
	global $syslogdb_default, $syslog_incoming_config;

	if (defined('SYSLOG_CONFIG')) {
		include(SYSLOG_CONFIG);
	}

	if (!isset($syslog_incoming_config['programField'])) {
		$syslog_incoming_config['programField'] = 'program';
	}
	foreach (['textField' => 'message', 'hostField' => 'host', 'facilityField' => 'facility_id', 'priorityField' => 'priority_id'] as $setting => $default) {
		if (!isset($syslog_incoming_config[$setting])) {
			$syslog_incoming_config[$setting] = $default;
		}
	}

	$params = [];
	$sql    = '';

	if ($alert['type'] == 'filter') {
		if (!class_exists('Cacti\\Syslog\\QueryBuilder')) {
			$GLOBALS['syslog_rule_filter_error'] = 'The structured query builder is unavailable.';

			return [];
		}
		$fields = [
			'message' => ['column' => '`' . $syslog_incoming_config['textField'] . '`', 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'host' => ['column' => '`' . $syslog_incoming_config['hostField'] . '`', 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'program' => ['column' => '`' . $syslog_incoming_config['programField'] . '`', 'operators' => ['contains', 'begins', 'ends', '=', '!=']],
			'facility_id' => ['column' => '`' . $syslog_incoming_config['facilityField'] . '`', 'operators' => ['=', '!='], 'type' => 'integer'],
			'priority_id' => ['column' => '`' . $syslog_incoming_config['priorityField'] . '`', 'operators' => ['=', '!='], 'type' => 'integer']
		];

		try {
			$filter = \Cacti\Syslog\QueryBuilder::compile($alert['message'], $fields);
		} catch (InvalidArgumentException $error) {
			$GLOBALS['syslog_rule_filter_error'] = $error->getMessage();

			return [];
		}

		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE ({$filter['sql']})";

		if ($processing_boundary) {
			$filter['params'][] = 1;
			$filter['params'][] = $max_seq;
			$sql .= "\n\t\t\t\tAND `status` = ?\n\t\t\t\tAND `seq` <= ?";
		}

		return ['sql' => $sql, 'params' => $filter['params']];
	}

	if ($alert['type'] == 'facility') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['facilityField']}` = ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messageb') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['textField']}` LIKE ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagec') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['textField']}` LIKE ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = '%' . $alert['message'] . '%';
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'messagee') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['textField']}` LIKE ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = '%' . $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'host') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['hostField']}` = ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'program') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `{$syslog_incoming_config['programField']}` = ?
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = $alert['message'];
		$params[] = $max_seq;
	} elseif ($alert['type'] == 'sql') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE ({$alert['message']})
			AND `status` = 1
			AND `seq` <= ?";

		$params[] = $max_seq;
	}

	return ['sql' => $sql, 'params' => $params];
}

/**
 * syslog_preprocess_incoming_records - Generate a max_seq to allow moving of
 * records to done table and mark incoming records with the max_seq and
 * then if syslog is configured to strip domains, perform that first.
 *
 * @return array{max_seq: int, incoming: int} The maximum sequence id, allowing
 *             syslog messages that come in randomly to be differentiated
 *             between messages to process and messages to be left till the
 *             next polling cycle, and the number of incoming records found.
 */
function syslog_preprocess_incoming_records(): array {
	global $syslogdb_default;

	$max_seq = syslog_db_fetch_cell("SELECT MAX(seq) FROM `$syslogdb_default`.`syslog_incoming` WHERE status = 0");

	if ($max_seq > 0) {
		// flag all records with the status = 1 prior to moving
		syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
			SET `status` = 1
			WHERE `status` = 0
			AND `seq` <= ?",
			[$max_seq]);

		syslog_debug('Max Sequence ID = ' . $max_seq);
		syslog_debug('-------------------------------------------------------------------------------------');

		$syslog_incoming = syslog_db_fetch_cell_prepared("SELECT COUNT(seq)
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?",
			[$max_seq]);

		syslog_debug(sprintf('Found   %5s - New Message(s) to process', $syslog_incoming));

		// strip domains if we have requested to do so
		syslog_strip_incoming_domains($max_seq);

		api_plugin_hook('plugin_syslog_before_processing');

		return ['max_seq' => (int) $max_seq, 'incoming' => (int) $syslog_incoming];
	}

	return ['max_seq' => 0, 'incoming' => 0];
}

/**
 * syslog_strip_incoming_domains - If syslog is setup to strip DNS domain name suffixes do that
 * prior to processing the records.
 *
 * @param int $max_seq The max_seq records to process
 *
 * @return void
 */
function syslog_strip_incoming_domains($max_seq) {
	global $syslogdb_default;

	$syslog_domains = read_config_option('syslog_domains');

	if ($syslog_domains != '') {
		$domains = explode(',', trim($syslog_domains));

		foreach ($domains as $domain) {
			syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
				SET host = SUBSTRING_INDEX(host, '.', 1)
				WHERE host LIKE ?
				AND `status` = 1
				AND `seq` <= ?",
				['%' . $domain, $max_seq]);
		}
	}
}

/**
 * Check if the hostname is in the cacti hosts table
 * Some devices only send IP addresses in syslog messages, and may not be in the DNS
 * however they may be in the cacti hosts table as monitored devices.
 *
 * @param string $host      The hostname to check
 * @param int    $max_seq   The max_seq for syslog_incoming messages to process
 * @param int    $seq_start Optional first seq of a slice (0 for no bound)
 * @param int    $seq_end   Optional last seq of a slice (0 for no bound)
 *
 * @return bool True if the host exists in the Cacti database, false otherwise
 */
function syslog_check_cacti_hosts($host, $max_seq, $seq_start = 0, $seq_end = 0) {
	global $syslogdb_default;

	if (empty($host)) {
		return false;
	}

	// Check if the host exists in cacti by hostname and get the description
	$cacti_host = db_fetch_row_prepared('SELECT DISTINCT description
		FROM host
		WHERE hostname = ?
		LIMIT 1',
		[$host]);

	if (cacti_sizeof($cacti_host) && !empty($cacti_host['description'])) {
		if ($seq_start > 0 && $seq_end > 0) {
			syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
				SET host = ?
				WHERE host = ?
				AND `status` = 1
				AND `seq` BETWEEN ? AND ?",
				[$cacti_host['description'], $host, $seq_start, $seq_end]);
		} else {
			syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
				SET host = ?
				WHERE host = ?
				AND `status` = 1
				AND `seq` <= ?",
				[$cacti_host['description'], $host, $max_seq]);
		}

		return true;
	}

	return false;
}

/**
 * syslog_update_reference_tables - There are many values in the syslog plugin
 * that for the purposes of reducing the size of the syslog table are normalized
 * the columns includes the facility, the priority, and the hostname.
 *
 * This function will add those new hostnames to the various reference tables
 * and assign an id to each of them.  This way the syslog table can be optimized
 * for size as much as possible.
 *
 * @param int $max_seq The max_seq for syslog_incoming messages to process
 *
 * @return void
 */
function syslog_update_reference_tables($max_seq) {
	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Updating Reference Tables from New Syslog Records');

	// Validate and resolve hostnames - check DNS first, then Cacti, then mark invalid
	syslog_resolve_incoming_hosts($max_seq);

	// Upsert the normalized reference values
	syslog_normalize_reference_tables($max_seq);
}

/**
 * syslog_resolve_incoming_hosts - Validate and resolve the hostnames of
 * incoming syslog records.  DNS is attempted first, then the Cacti hosts
 * table, and finally the hostname is prefixed with 'unresolved-'.
 *
 * When seq bounds are supplied, only records inside the inclusive seq
 * slice are updated which allows parallel workers to operate on disjoint
 * slices without touching each other's rows.
 *
 * @param int $max_seq   The max_seq for syslog_incoming messages to process
 * @param int $seq_start Optional first seq of a slice (0 for no bound)
 * @param int $seq_end   Optional last seq of a slice (0 for no bound)
 *
 * @return int The number of distinct hosts resolved
 */
function syslog_resolve_incoming_hosts($max_seq, $seq_start = 0, $seq_end = 0) {
	global $syslogdb_default;

	if (read_config_option('syslog_resolve_hostname') != 'on') {
		return 0;
	}

	if ($seq_start > 0 && $seq_end > 0) {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT host
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` BETWEEN ? AND ?",
			[$seq_start, $seq_end]);
	} else {
		$hosts = syslog_db_fetch_assoc_prepared("SELECT DISTINCT host
			FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?",
			[$max_seq]);
	}

	$resolved_count = 0;

	foreach ($hosts as $host) {
		if (!isset($host['host']) || empty($host['host'])) {
			continue;
		}

		$resolved = false;

		// Check if hostname resolves via DNS (only if DNS is enabled)
		if (read_config_option('syslog_no_dns') != 'on') {
			if ($host['host'] != gethostbyname($host['host'])) {
				// DNS resolved successfully
				$resolved = true;
			}
		}

		// Check if hostname exists in Cacti hosts table (only if not already resolved via DNS)
		if (!$resolved) {
			$resolved = syslog_check_cacti_hosts($host['host'], $max_seq, $seq_start, $seq_end);
		}

		// If not resolved via DNS or found in Cacti, prefix the hostname
		if (!$resolved) {
			$unresolved_host = 'unresolved-' . $host['host'];
			cacti_log("SYSLOG WARNING: Hostname '" . $host['host'] . "' could not be resolved via DNS or found in Cacti hosts table, marking as '" . $unresolved_host . "'", false, 'SYSLOG');

			if ($seq_start > 0 && $seq_end > 0) {
				syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
					SET host = ?
					WHERE host = ?
					AND `status` = 1
					AND `seq` BETWEEN ? AND ?",
					[$unresolved_host, $host['host'], $seq_start, $seq_end]);
			} else {
				syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_incoming`
					SET host = ?
					WHERE host = ?
					AND `status` = 1
					AND `seq` <= ?",
					[$unresolved_host, $host['host'], $max_seq]);
			}
		} else {
			$resolved_count++;
		}
	}

	return $resolved_count;
}

/**
 * syslog_normalize_reference_tables - Upsert the normalized reference
 * values (programs, hosts, host facilities) for all incoming records in
 * scope.  This is a set-based operation and is intentionally performed
 * by the master process only when running in parallel mode to avoid
 * InnoDB concurrent ON DUPLICATE KEY deadlocks.
 *
 * @param int $max_seq The max_seq for syslog_incoming messages to process
 *
 * @return void
 */
function syslog_normalize_reference_tables($max_seq) {
	global $syslogdb_default;

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_programs`
		(program, last_updated)
		SELECT DISTINCT program, NOW()
		FROM `$syslogdb_default`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			program = VALUES(program),
			last_updated = VALUES(last_updated)",
		[$max_seq]);

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_hosts`
		(host, last_updated)
		SELECT DISTINCT host, NOW() AS last_updated
		FROM `$syslogdb_default`.`syslog_incoming`
		WHERE `status` = 1
		AND `seq` <= ?
		ON DUPLICATE KEY UPDATE
			host = VALUES(host),
			last_updated = NOW()",
		[$max_seq]);

	syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_host_facilities`
		(host_id, facility_id)
		SELECT host_id, facility_id
		FROM (
			(
				SELECT DISTINCT host, facility_id
				FROM `$syslogdb_default`.`syslog_incoming`
				WHERE `status` = 1
				AND `seq` <= ?
			) AS s
			INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
			ON s.host = sh.host
		)
		ON DUPLICATE KEY UPDATE
			host_id = VALUES(host_id),
			last_updated = NOW()",
		[$max_seq]);
}

/**
 * syslog_delete_stale_incoming - Delete records left in the incoming table
 * that are older than one hour.  These are records whose owning poller
 * crashed before they were transferred.
 *
 * @return int|false The number of stale records deleted
 */
function syslog_delete_stale_incoming() {
	global $syslogdb_default, $syslog_cnn;

	syslog_db_execute("DELETE FROM `$syslogdb_default`.`syslog_incoming` WHERE logtime < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

	$stale = db_affected_rows($syslog_cnn);

	syslog_debug(sprintf('Deleted %5s - Stale Message(s) from incoming', $stale));

	return $stale;
}

/**
 * Whether this process is a configured Syslog Remote Poller.  Main and
 * ordinary remote collectors never create an outbox backlog.
 *
 * @return bool
 */
function syslog_replication_is_enabled(): bool {
	global $config;

	return isset($config['poller_id']) && (int) $config['poller_id'] > 1
		&& read_config_option('syslog_remote_enabled') === 'on';
}

/**
 * Add a portable incoming event to the plugin-owned outbox.  Callers run
 * this inside the same transaction as the local archival disposition.
 * INSERT IGNORE makes retries idempotent on (source_poller_id, source_event_id).
 *
 * @param string               $where       A WHERE clause scoped to syslog_incoming AS si.
 * @param array<int,mixed>     $params      Prepared-statement values for $where.
 * @param string               $disposition syslog or syslog_removed.
 *
 * @return bool
 */
function syslog_replication_enqueue_incoming(string $where, array $params, string $disposition): bool {
	global $config, $syslogdb_default;

	if (!syslog_replication_is_enabled()) {
		return true;
	}

	if (!in_array($disposition, ['syslog', 'syslog_removed'], true)) {
		return false;
	}

	return syslog_db_execute_prepared("INSERT IGNORE INTO `$syslogdb_default`.`syslog_replication_output`
		(source_poller_id, source_event_id, facility_id, priority_id, program, logtime, host, message, disposition)
		SELECT ?, si.seq, si.facility_id, si.priority_id, si.program, si.logtime, si.host, si.message, ?
		FROM `$syslogdb_default`.`syslog_incoming` AS si $where",
		array_merge([(int) $config['poller_id'], $disposition], $params));
}

/** Conservative online delivery limit. Payloads may contain 2KiB messages. */
if (!defined('SYSLOG_REPLICATION_BATCH_SIZE')) {
	define('SYSLOG_REPLICATION_BATCH_SIZE', 100);
}

/**
 * Return whether Cacti has a usable Main Collector connection. Cacti's
 * recovery mode still has a live central connection; its Boost backlog does
 * not determine the separate Syslog synchronization state.
 *
 * @return bool
 */
function syslog_replication_delivery_is_online(): bool {
	global $config, $remote_db_cnn_id;

	if (!empty($GLOBALS['syslog_replication_connection_failed'])
		|| !syslog_replication_is_enabled() || !isset($remote_db_cnn_id) || !is_object($remote_db_cnn_id)) {
		return false;
	}

	$connection = defined('CACTI_CONNECTION') ? CACTI_CONNECTION : ($config['connection'] ?? 'offline');

	return in_array($connection, ['online', 'recovery'], true);
}

/**
 * Check for pending plugin-owned replication work without counting a possibly
 * large backlog. Main and ordinary remote collectors have no Syslog state.
 *
 * @return bool
 */
function syslog_replication_has_backlog(): bool {
	global $syslogdb_default;

	if (!syslog_replication_is_enabled()) {
		return false;
	}

	return (bool) syslog_db_fetch_cell("SELECT 1 FROM `$syslogdb_default`.`syslog_replication_output` LIMIT 1", '', false);
}

/**
 * Derive the current Syslog synchronization state from Cacti connectivity and
 * the durable outbox. A null state means this collector does not participate.
 *
 * @return string|null online, offline, recovery, or null when not applicable
 */
function syslog_replication_get_state(): ?string {
	if (!syslog_replication_is_enabled()) {
		return null;
	}

	if (!syslog_replication_delivery_is_online()) {
		return 'offline';
	}

	return syslog_replication_has_backlog() ? 'recovery' : 'online';
}

/**
 * Persist only the last observed state to suppress outage log storms. The
 * state itself remains derived and restart-safe from connectivity plus outbox.
 *
 * @return string|null
 */
/** Mark a failed central operation unavailable for the current process only. */
function syslog_replication_mark_connection_failure(): void {
	$GLOBALS['syslog_replication_connection_failed'] = true;
}

function syslog_replication_record_state(): ?string {
	$state = syslog_replication_get_state();
	if ($state === null) {
		return null;
	}

	$status = syslog_status_get();
	$prior  = $status['replication_state'] ?? '';
	if ($prior === $state) {
		return $state;
	}

	syslog_status_set('replication_state', $state);
	if ($state === 'offline') {
		cacti_log('SYSLOG: Central synchronization unavailable; local processing and outbox retention continue', false, 'SYSLOG');
	} elseif ($state === 'recovery') {
		cacti_log('SYSLOG: Central synchronization entering recovery with pending outbox backlog', false, 'SYSLOG');
	} else {
		cacti_log('SYSLOG: Central synchronization recovered; Syslog outbox is empty', false, 'SYSLOG');
	}

	return $state;
}

/**
 * Archive one portable outbox record on the Main Collector. This writes
 * directly to historical tables, so central alerts and removal rules do not
 * run again.
 *
 * @param array<string,mixed> $event
 * @param PDO                 $central
 *
 * @return bool
 */
function syslog_replication_accept_central(array $event, $central): bool {
	global $syslogdb_default;

	if (!in_array($event['disposition'], ['syslog', 'syslog_removed'], true)) {
		cacti_log('SYSLOG ERROR: Replication event has an invalid disposition', false, 'SYSLOG');
		return false;
	}

	$receipt = db_execute_prepared("INSERT IGNORE INTO `$syslogdb_default`.`syslog_replication_receipts`
		(source_poller_id, source_event_id, disposition) VALUES (?, ?, ?)",
		[(int) $event['source_poller_id'], (int) $event['source_event_id'], $event['disposition']], true, $central);
	if (!$receipt) {
		return false;
	}

	// An existing receipt was committed with its archive row in an earlier
	// attempt, including the ambiguous 'commit succeeded, response lost' case.
	if (db_affected_rows($central) === 0) {
		return true;
	}

	if (!db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_programs` (program, last_updated)
		VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_updated = VALUES(last_updated)", [$event['program']], true, $central)
		|| !db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog_hosts` (host, last_updated)
		VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_updated = VALUES(last_updated)", [$event['host']], true, $central)) {
		return false;
	}

	$table = $event['disposition']; // validated fixed identifiers, never caller SQL
	return db_execute_prepared("INSERT INTO `$syslogdb_default`.`$table`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT ?, ?, ?, sp.program_id, sh.host_id, ?
		FROM `$syslogdb_default`.`syslog_programs` AS sp
		INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
		WHERE sp.program = ? AND sh.host = ?",
		[$event['logtime'], $event['priority_id'], $event['facility_id'], $event['message'], $event['program'], $event['host']], true, $central);
}

/**
 * Deliver one bounded oldest-first outbox batch. Central acceptance is one
 * transaction: any failure rolls back the whole batch and retains its local
 * rows. Exact identities are deleted only after a confirmed central commit.
 *
 * @return int
 */
function syslog_replication_deliver_online(): int {
	global $syslogdb_default, $remote_db_cnn_id, $syslog_cnn;

	if (!syslog_replication_delivery_is_online()) {
		return 0;
	}

	$events = syslog_db_fetch_assoc("SELECT source_poller_id, source_event_id, facility_id, priority_id, program, logtime, host, message, disposition
		FROM `$syslogdb_default`.`syslog_replication_output`
		ORDER BY created_at ASC, source_poller_id ASC, source_event_id ASC
		LIMIT " . SYSLOG_REPLICATION_BATCH_SIZE);
	if (empty($events)) {
		return 0;
	}

	cacti_log('SYSLOG: Delivering ' . cacti_sizeof($events) . ' replication records to the Main Collector', false, 'SYSLOG');
	if (!db_execute('START TRANSACTION', true, $remote_db_cnn_id)) {
		syslog_replication_mark_connection_failure();
		cacti_log('SYSLOG ERROR: Unable to begin central replication transaction; retaining local outbox', false, 'SYSLOG');
		return 0;
	}

	foreach ($events as $event) {
		if (!syslog_replication_accept_central($event, $remote_db_cnn_id)) {
			syslog_replication_mark_connection_failure();
			db_execute('ROLLBACK', false, $remote_db_cnn_id);
			cacti_log('SYSLOG ERROR: Main Collector rejected replication batch; retaining local outbox', false, 'SYSLOG');
			return 0;
		}
	}

	if (!db_execute('COMMIT', true, $remote_db_cnn_id)) {
		syslog_replication_mark_connection_failure();
		db_execute('ROLLBACK', false, $remote_db_cnn_id);
		cacti_log('SYSLOG ERROR: Central replication commit was not confirmed; retaining local outbox for idempotent retry', false, 'SYSLOG');
		return 0;
	}

	$clauses = [];
	$params  = [];
	foreach ($events as $event) {
		$clauses[] = '(source_poller_id = ? AND source_event_id = ?)';
		$params[]  = (int) $event['source_poller_id'];
		$params[]  = (int) $event['source_event_id'];
	}

	if (!syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_replication_output` WHERE " . implode(' OR ', $clauses), $params)) {
		cacti_log('SYSLOG ERROR: Main Collector accepted replication batch but local acknowledgement failed; retry is safe', false, 'SYSLOG');
		return 0;
	}

	$acknowledged = db_affected_rows($syslog_cnn);
	cacti_log('SYSLOG: Main Collector accepted and acknowledged ' . $acknowledged . ' replication records', false, 'SYSLOG');
	return $acknowledged;
}

/**
 * syslog_incoming_to_syslog - Move incoming syslog records to the syslog table
 *
 * Once all Alerts have been processed, we need to move entries first to
 * the syslog table, and then after which we can perform various
 * removal rules against them.
 *
 * When seq bounds are supplied, only records within the inclusive seq
 * slice are transferred which allows parallel workers to operate on
 * disjoint slices.  The stale record deletion is intentionally left to
 * the caller in that case.
 *
 * @param int $max_seq   The max_seq for rows in the syslog table
 * @param int $seq_start Optional first seq of a slice (0 for no bound)
 * @param int $seq_end   Optional last seq of a slice (0 for no bound)
 *
 * @return array Array with the number of rows moved and stale rows deleted
 */
function syslog_incoming_to_syslog($max_seq, $seq_start = 0, $seq_end = 0) {
	global $syslogdb_default, $syslog_cnn;

	$slice_where = '';
	$slice_param = [];

	if ($seq_start > 0 && $seq_end > 0) {
		$slice_where = ' AND si.`seq` BETWEEN ? AND ?';
		$slice_param = [$seq_start, $seq_end];
	}

	$replication_transaction = false;

	if (syslog_replication_is_enabled()) {
		if (!syslog_db_execute('START TRANSACTION')) {
			cacti_log('SYSLOG ERROR: Unable to start transaction for replication outbox', false, 'SYSLOG');

			return ['moved' => 0, 'stale' => 0];
		}

		$replication_transaction = true;
		$replication_where = 'WHERE si.`status` = 1 AND si.`seq` <= ?' . $slice_where;

		if (!syslog_replication_enqueue_incoming($replication_where, array_merge([$max_seq], $slice_param), 'syslog')) {
			syslog_db_execute('ROLLBACK');
			cacti_log('SYSLOG ERROR: Rolled back transfer after replication outbox insert failed', false, 'SYSLOG');

			return ['moved' => 0, 'stale' => 0];
		}
	}

	$archived = syslog_db_execute_prepared("INSERT INTO `$syslogdb_default`.`syslog`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT logtime, priority_id, facility_id, program_id, host_id, message
		FROM (
			SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
			FROM syslog_incoming AS si
			INNER JOIN syslog_hosts AS sh
			ON sh.host = si.host
			INNER JOIN syslog_programs AS sp
			ON sp.program = si.program
			WHERE si.`status` = 1
			AND si.`seq` <= ?
			$slice_where
		) AS merge",
		array_merge([$max_seq], $slice_param));

	if (!$archived) {
		if ($replication_transaction) {
			syslog_db_execute('ROLLBACK');
		}

		cacti_log('SYSLOG ERROR: Unable to archive incoming records', false, 'SYSLOG');

		return ['moved' => 0, 'stale' => 0];
	}

	$moved = db_affected_rows($syslog_cnn);

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Moving or Removing Processed Records');

	syslog_debug(sprintf('Moved   %5s - Message(s) to the syslog table', $moved));

	if ($seq_start > 0 && $seq_end > 0) {
		$deleted = syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` BETWEEN ? AND ?",
			[$seq_start, $seq_end]);
	} else {
		$deleted = syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_incoming`
			WHERE `status` = 1
			AND `seq` <= ?",
			[$max_seq]);
	}

	syslog_debug(sprintf('Deleted %5s - Already Processed Message(s) from incoming', db_affected_rows($syslog_cnn)));

	if (!$deleted) {
		if ($replication_transaction) {
			syslog_db_execute('ROLLBACK');
		}

		cacti_log('SYSLOG ERROR: Unable to delete archived incoming records', false, 'SYSLOG');

		return ['moved' => 0, 'stale' => 0];
	}

	if ($replication_transaction && !syslog_db_execute('COMMIT')) {
		syslog_db_execute('ROLLBACK');
		cacti_log('SYSLOG ERROR: Unable to commit transfer and replication outbox transaction', false, 'SYSLOG');

		return ['moved' => 0, 'stale' => 0];
	}

	if ($seq_start > 0 && $seq_end > 0) {
		// The stale record cleanup is owned by the master after all
		// transfer workers complete to avoid interleaved DELETEs.
		$stale = 0;
	} else {
		$stale = syslog_delete_stale_incoming();
	}

	return ['moved' => $moved, 'stale' => $stale];
}

/**
 * syslog_postprocess_tables - Remove stale records and optimize tables after
 * message processing has been completed.
 *
 * @return void
 */
function syslog_postprocess_tables() {
	global $syslogdb_default, $syslog_cnn;

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Post Processing/Maintenance of Syslog Tables');
	syslog_debug('-------------------------------------------------------------------------------------');

	/*
	 * Like syslog_traditional_manage(), reference-table retention is computed
	 * in UTC so it agrees with the UTC partition boundaries and cannot drift
	 * with the server timezone or DST transitions.
	 */
	$delete_date = gmdate('Y-m-d H:i:s', time() - ((int) read_config_option('syslog_retention') * 86400));

	// remove alert log messages
	if (read_config_option('syslog_alert_retention') > 0) {
		api_plugin_hook_function('syslog_delete_hostsalarm', $delete_date);

		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_logs`
			WHERE logtime < ?",
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog alarm log Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_hosts`
			WHERE last_updated < ?",
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog Host Record(s)', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_programs`
			WHERE last_updated < ?",
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Old programs from programs table', db_affected_rows($syslog_cnn)));

		syslog_db_execute_prepared("DELETE FROM `$syslogdb_default`.`syslog_host_facilities`
			WHERE last_updated < ?",
			[$delete_date]);

		syslog_debug(sprintf('Deleted %5s - Syslog Host/Facility Record(s)', db_affected_rows($syslog_cnn)));
	}

	// OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP
	if (date('G') == 0 && date('i') < 5) {
		syslog_debug('Optimizing Tables');

		if (!syslog_is_partitioned()) {
			syslog_db_execute("OPTIMIZE TABLE
				`$syslogdb_default`.`syslog_incoming`,
				`$syslogdb_default`.`syslog`,
				`$syslogdb_default`.`syslog_remove`,
				`$syslogdb_default`.`syslog_removed`,
				`$syslogdb_default`.`syslog_alert`");
		} else {
			syslog_db_execute("OPTIMIZE TABLE
				`$syslogdb_default`.`syslog_incoming`,
				`$syslogdb_default`.`syslog_remove`,
				`$syslogdb_default`.`syslog_alert`");
		}
	}
}

/**
 * syslog_process_reports - Processes all syslog reports scheduled to run
 *
 * @return array An array of total and sent reports
 */
function syslog_process_reports() {
	global $config, $syslogdb_default, $syslog_cnn, $forcer;

	include_once($config['base_path'] . '/lib/reports.php');

	syslog_debug('-------------------------------------------------------------------------------------');
	syslog_debug('Processing Reports...');
	syslog_debug('-------------------------------------------------------------------------------------');

	$report_tag = false;
	$theme      = false;
	$format_ok  = false;
	$from_email = read_config_option('settings_from_email');

	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');

	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = [$from_email, $from_name];

	if (read_config_option('syslog_html') == 'on') {
		$html      = true;
		$format_ok = reports_load_format_file(read_config_option('syslog_format_file'), $output, $report_tag, $theme);

		syslog_debug('Format/CSS ' . ($format_ok ? 'Ok' : 'Not Ok') . ' - Report Tag ' . ($report_tag ? 'included' : 'missing'));
	} else {
		$html = false;
	}

	// Lets run the reports
	$reports = syslog_db_fetch_assoc("SELECT *
		FROM `$syslogdb_default`.`syslog_reports`
		WHERE enabled = 'on'");

	$total_reports = cacti_sizeof($reports);
	$sent_reports  = 0;

	syslog_debug('We have ' . $total_reports . ' Reports in the database to check');

	if (cacti_sizeof($reports)) {
		$total_reports = cacti_sizeof($reports);

		foreach ($reports as $report) {
			syslog_debug('-------------------------------------------------------------------------------------');
			syslog_debug(sprintf('Processing    - %s', $report['name']));

			$base_start_time = $report['timepart'];
			$last_run_time   = $report['lastsent'];
			$time_span       = $report['timespan'];
			$seconds_offset  = (int) read_config_option('cron_interval');

			$current_time = time();

			if (empty($last_run_time)) {
				$start = strtotime(date('Y-m-d 00:00', $current_time)) + $base_start_time;

				if ($current_time > $start) {
					// if timer expired within a polling interval, then poll
					if (($current_time - $seconds_offset) < $start) {
						$next_run_time = $start;
					} else {
						$next_run_time = $start + 3600 * 24;
					}
				} else {
					$next_run_time = $start;
				}
			} else {
				$next_run_time = strtotime(date('Y-m-d 00:00', $last_run_time)) + $base_start_time + $time_span;
			}

			$time_till_next_run = $next_run_time - $current_time;

			if ($time_till_next_run < 0 || $forcer) {
				syslog_db_execute_prepared("UPDATE `$syslogdb_default`.`syslog_reports`
					SET lastsent = ?
					WHERE id = ?",
					[time(), $report['id']]);

				syslog_debug('Next Send     - Now');
				syslog_debug('Creating Report...');

				$reptext = '';

				$sql = syslog_get_report_sql($report);

				if ($sql != '') {
					$date2 = date('Y-m-d H:i:s', $current_time);
					$date1 = date('Y-m-d H:i:s', $current_time - $time_span);
					$sql .= ' AND logtime BETWEEN ? AND ?';
					$sql .= ' ORDER BY logtime DESC';
					$items = syslog_db_fetch_assoc_prepared($sql, [$date1, $date2]);

					syslog_debug('We have ' . cacti_sizeof($items) . ' items for the Report');

					$classes = ['even', 'odd'];

					if (cacti_sizeof($items)) {
						$i = 0;

						foreach ($items as $item) {
							$class = $classes[$i % 2];

							$reptext .= '<tr class="' . $class . '">
								<td class="host">' . html_escape($item['host']) . '</td>
								<td class="date">' . $item['logtime'] . '</td>
								<td class="message">' . html_escape($item['message']) . '</td>
							</tr>';

							$i++;
						}
					}

					if ($reptext != '') {
						$message = '';

						if (!$format_ok) {
							$message  = '<style type="text/css">';
							$message .= file_get_contents($config['base_path'] . '/plugins/syslog/css/syslog.css');
							$message .= '</style>';
						}

						$message .= '<h1>Cacti Syslog Report - ' . html_escape($report['name']) . '</h1>';
						$message .= '<hr>';
						$message .= '<p>' . $report['body'] . '</p>';
						$message .= '<hr>';

						$message .= '<table class="cactiTable">';

						$message .= '<tr class="header_row tableHeader">
							<th>' . __('Host', 'syslog') . '</th>
							<th>' . __('Date', 'syslog') . '</th>
							<th>' . __('Message', 'syslog') . '</th>
						</tr>';

						$message .= $reptext;

						$message .= '</table>';

						$smsalert  = '';

						$sent_reports++;

						if ($html) {
							if ($format_ok) {
								if ($report_tag) {
									$message = str_replace('<REPORT>', $message, $output);
								} else {
									$message = $output . $message . '</body></html>';
								}
							} else {
								$message = '<html><body>' . $message . '</body></html>';
							}
						}

						syslog_sendemail($report['email'], $from, __esc('Event Report - %s', $report['name'], 'syslog'), $message, $smsalert);
					}
				}
			} else {
				syslog_debug('Next Send     - ' . date('Y-m-d H:i:s', $next_run_time));
			}
		}
	}

	return ['total_reports' => $total_reports, 'sent_reports' => $sent_reports];
}

/**
 * syslog_get_report_sql - Return the SQL syntax for the report query
 *
 * @param array<string, mixed> $report The report to process, passed by reference
 *
 * @return string The unprepared SQL, empty for an unknown report type
 */
function syslog_get_report_sql(&$report) {
	global $syslogdb_default;

	$sql = '';

	if ($report['type'] == 'messageb') {
		$sql = "SELECT sl.*, sh.host
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE " . db_qstr($report['message'] . '%');
	}

	if ($report['type'] == 'messagec') {
		$sql = "SELECT sl.*, sh.host
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE " . db_qstr('%' . $report['message'] . '%');
	}

	if ($report['type'] == 'messagee') {
		$sql = "SELECT sl.*, sh.host
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE message LIKE " . db_qstr('%' . $report['message']);
	}

	if ($report['type'] == 'host') {
		$sql = "SELECT sl.*, sh.host
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_hosts` AS sh
			ON sl.host_id = sh.host_id
			WHERE sh.host = " . db_qstr($report['message']);
	}

	if ($report['type'] == 'facility') {
		$sql = "SELECT sl.*, sf.facility
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_facilities` AS sf
			ON sl.facility_id = sf.facility_id
			WHERE sf.facility = " . db_qstr($report['message']);
	}

	if ($report['type'] == 'program') {
		$sql = "SELECT sl.*, sp.program
			FROM `$syslogdb_default`.`syslog` AS sl
			INNER JOIN `$syslogdb_default`.`syslog_programs` AS sp
			ON sl.program_id = sp.program_id
			WHERE sp.program = " . db_qstr($report['message']);
	}

	if ($report['type'] == 'sql') {
		$sql = "SELECT *
			FROM `$syslogdb_default`.`syslog`
			WHERE (" . $report['message'] . ')';
	}

	return $sql;
}

/**
 * generate a Cacti log message and save settings in the settings table for use
 * by various graph templates
 *
 * @param float  $start_time The start time of the polling process
 * @param int    $deleted The number of syslog messages deleted
 * @param int    $incoming The number of syslog incoming messages
 * @param int    $removed The number of syslog messages removed
 * @param int    $xferred The number of syslog messages transferred
 * @param int    $alerts The number of alerts processed
 * @param int    $alarms The number of alerts triggered
 * @param int    $reports The number of reports sent
 *
 * @return void
 */
function syslog_process_log(float $start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports): void {
	global $database_default, $debug;

	// record the end time
	$end_time = microtime(true);

	$stats =
		' Time:' . round($end_time - $start_time,2) .
		' Deletes:' . $deleted .
		' Incoming:' . $incoming .
		' Removes:' . $removed .
		' XFers:' . $xferred .
		' Alerts:' . $alerts .
		' Alarms:' . $alarms .
		' Reports:' . $reports;

	cacti_log('SYSLOG STATS:' . $stats, false, 'SYSTEM');

	syslog_debug('-------------------------------------------------------------------------------------');

	if ($debug) {
		syslog_debug($stats);
	} else {
		print date('H:m:s') . ' SYSLOG NOTE: ' . $stats . PHP_EOL;
	}

	set_config_option('syslog_stats',
		'time:' . round($end_time - $start_time,2) .
		' deletes:' . $deleted .
		' incoming:' . $incoming .
		' removes:' . $removed .
		' xfers:' . $xferred .
		' alerts:' . $alerts .
		' alarms:' . $alarms .
		' reports:' . $reports
	);
}

/**
 * syslog_log_child_statistics - log the statistics of a completed worker
 * child process following the boost poller's per child stats format.
 *
 * The master's aggregate 'SYSLOG STATS' line only shows the cycle total,
 * so under parallel processing this per process line attributes the work
 * to each worker, mirroring 'BOOST STATS: Time:... ProcessNumber:N ...'.
 *
 * @param float $start    The child process start time from microtime()
 * @param int   $child    The child process number
 * @param int   $moved    The records the child transferred
 * @param int   $resolved The hostnames the child resolved
 *
 * @return void
 */
function syslog_log_child_statistics($start, $child, $moved, $resolved) {
	$end = microtime(true);

	$cacti_stats = sprintf(
		'Time:%01.2f ' .
		'ProcessNumber:%s ' .
		'Records:%s ' .
		'Resolved:%s',
		round($end - $start, 2),
		$child,
		$moved,
		$resolved
	);

	cacti_log('SYSLOG STATS: ' . $cacti_stats, false, 'SYSTEM');
}

/**
 * syslog_init_variables - initialize key variables on first pass of a run
 * of the syslog plugin.  This function should not have to run more than
 * once during the syslog plugins lifecycle.
 *
 * @return void
 */
function syslog_init_variables() {
	$syslog_retention = read_config_option('syslog_retention');
	$alert_retention  = read_config_option('syslog_alert_retention');
	$ahead_days       = read_config_option('syslog_partition_ahead_days');

	if ($syslog_retention == '' || $syslog_retention < 0 || $syslog_retention > 365) {
		set_config_option('syslog_retention', '30');
	}

	if ($alert_retention == '' || $alert_retention < 0 || $alert_retention > 365) {
		set_config_option('syslog_alert_retention', '30');
	}

	if ($ahead_days == '' || !is_numeric($ahead_days) || $ahead_days < 1 || $ahead_days > 7) {
		set_config_option('syslog_partition_ahead_days', '3');
	}

	if (substr((string) read_config_option('base_url'), 0, 4) != 'http') {
		if (read_config_option('force_https') == 'on') {
			$prefix = 'https://';
		} else {
			$prefix = 'http://';
		}

		set_config_option('base_url', $prefix . read_config_option('base_url'));
	}
}

/**
 * alert_setup_environment - set's up the environment for a syslog alert
 *
 * @param array<string, mixed> $alert    The alert definition
 * @param array<string, mixed> $results  The matched syslog result row
 * @param array<int, string>   $hostlist The list of hosts that match for the alert
 * @param string               $hostname The hostname in the case of a host level alert
 *
 * @return void
 */
function alert_setup_environment(&$alert, $results, $hostlist = [], $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	putenv('ALERT_ALERTID=' . cacti_escapeshellarg($alert['id']));
	putenv('ALERT_NAME=' . cacti_escapeshellarg(clean_up_name($alert['name'])));
	putenv('ALERT_MESSAGE=' . cacti_escapeshellarg($alert['message']));

	putenv('ALERT_SEVERITY=' . cacti_escapeshellarg($alert['severity']));
	putenv('ALERT_SEVERITY_TEXT=' . cacti_escapeshellarg($severities[$alert['severity']]));

	putenv('ALERT_PRIORITY=' . cacti_escapeshellarg($syslog_levels[$results['priority_id']]));
	putenv('ALERT_FACILITY=' . cacti_escapeshellarg($syslog_facilities[$results['facility_id']]));

	putenv('ALERT_HOSTLIST=' . cacti_escapeshellarg(implode(',', $hostlist)));
	putenv('ALERT_HOSTNAME=' . cacti_escapeshellarg($hostname));

	putenv('ALERT_MESSAGES=' . cacti_escapeshellarg(trim(str_replace("\0", ' ', $results['message']))));
}

/**
 * alert_replace_variables - add command line parameter to the syslog command
 *   or ticket opening script
 *
 * @param array<string, mixed> $alert    The alert definition
 * @param array<string, mixed> $results  The matched syslog result row
 * @param string               $hostname The hostname in the case of a host level alert
 *
 * @return string The command and it'a arguments escaped
 */
function alert_replace_variables($alert, $results, $hostname = '') {
	global $severities, $syslog_levels, $syslog_facilities;

	$command = (string) $alert['command'];

	$command = str_replace('<ALERTID>',  cacti_escapeshellarg($alert['id']), $command);
	$command = str_replace('<HOSTNAME>', cacti_escapeshellarg($hostname), $command);
	$command = str_replace('<PRIORITY>', cacti_escapeshellarg($syslog_levels[$results['priority_id']]), $command);
	$command = str_replace('<FACILITY>', cacti_escapeshellarg($syslog_facilities[$results['facility_id']]), $command);
	$command = str_replace('<MESSAGE>',  cacti_escapeshellarg($results['message']), $command);
	$command = str_replace('<SEVERITY>', cacti_escapeshellarg($severities[$alert['severity']]), $command);

	return $command;
}

/**
 * Render untrusted log text as an accessible details trigger.
 *
 * @param string     $message  The log message text.
 * @param string     $device   The device (host) name.
 * @param string     $program  The program name.
 * @param string     $facility The facility name.
 * @param string     $severity The severity (priority) text.
 * @param string     $received The logtime of the record.
 * @param int|string $id       The sequence id of the record, 0 when unknown.
 * @param string     $source   The table the record came from.
 *
 * @return string The details trigger button HTML.
 */
function syslog_message_button($message, $device, $program, $facility, $severity, $received, $id = 0, $source = ''): string {
	$details = compact('device', 'program', 'facility', 'severity', 'received');
	$details['message'] = (string) $message;
	$details['rules'] = syslog_message_rule_links($id, $source, $received, $device);
	$text = title_trim((string) $message, 100);
	return '<button type="button" class="syslogMessageOpen" aria-controls="syslog_message_details" aria-expanded="false" data-message="' .
		html_escape(json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE)) . '">' . html_escape($text) . '</button>';
}

/**
 * Only main-table records can seed the existing rule editors.
 *
 * @param int|string $id       The sequence id of the record.
 * @param string     $source   The table the record came from.
 * @param string     $received The logtime of the record.
 *
 * @return array<string, string> Map of action name to rule editor URL.
 */
function syslog_message_rule_links($id, $source, $received, $host = ''): array {
	$links = [];
	if ($source !== 'main' || !ctype_digit((string) $id) || (int) $id < 1) {
		return $links;
	}

	$query = http_build_query(['id' => $id, 'action' => 'newedit', 'type' => '0', 'date' => $received]);
	if (syslog_allow_rule_edits()) {
		foreach (['alarm' => 'syslog_alerts.php', 'removal' => 'syslog_removal.php'] as $action => $page) {
			if (api_plugin_user_realm_auth($page)) {
				$links[$action] = $page . '?' . $query;
			}
		}
	}
	if ($host !== '' && api_plugin_user_realm_auth('syslog_device_rules.php')) {
		$links['device'] = 'syslog_device_rules.php?' . http_build_query(['action' => 'edit', 'host' => $host]);
	}
	return $links;
}

/**
 * Dates nested in authored groups must not gain a second implicit time range.
 *
 * @param array<int|string, mixed>|null $tree The parsed search tree.
 *
 * @return bool True when the tree contains a logtime predicate.
 */
function syslog_search_has_time($tree): bool {
	if (!is_array($tree) || !isset($tree[0])) {
		return false;
	}

	if ($tree[0] === 'predicate') {
		return ($tree[1] ?? null) === 'logtime';
	}

	if ($tree[0] === 'NOT') {
		return syslog_search_has_time(is_array($tree[1] ?? null) ? $tree[1] : null);
	}

	if ($tree[0] === 'AND' || $tree[0] === 'OR') {
		return syslog_search_has_time(is_array($tree[1] ?? null) ? $tree[1] : null)
			|| syslog_search_has_time(is_array($tree[2] ?? null) ? $tree[2] : null);
	}

	return false;
}
