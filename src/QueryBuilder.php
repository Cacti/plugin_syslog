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
 */

namespace Cacti\Syslog;

use InvalidArgumentException;

/** Compile versioned visual-filter documents into parameterized SQL. */
class QueryBuilder {
	private const MAX_DEPTH = 16;
	private const MAX_CONDITIONS = 128;
	private const MAX_JSON_BYTES = 8192;

	/**
	 * @param string $json Versioned filter document.
	 * @param array  $fields Map of logical field names to trusted SQL columns.
	 *
	 * @return array{sql:string,params:array}
	 */
	public static function compile(string $json, array $fields): array {
		if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
			throw new InvalidArgumentException('The filter is empty or exceeds 8192 bytes.');
		}

		try {
			$document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new InvalidArgumentException('The filter is not valid JSON.', 0, $error);
		}

		if (!is_array($document) || ($document['version'] ?? null) !== 1 || !isset($document['conditions'])) {
			throw new InvalidArgumentException('The filter schema or version is not supported.');
		}

		self::assertKeys($document, ['version', 'conditions']);
		$count  = 0;
		$params = [];
		$sql    = self::compileConditions($document['conditions'], $fields, $params, $count, 0);

		return ['sql' => $sql, 'params' => $params];
	}

	private static function compileConditions($conditions, array $fields, array &$params, int &$count, int $depth): string {
		if (!is_array($conditions) || !$conditions || $depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException($depth > self::MAX_DEPTH ? 'The filter nesting is too deep.' : 'The filter must contain a condition.');
		}

		$parts = [];
		foreach (array_values($conditions) as $index => $condition) {
			if (!is_array($condition)) {
				throw new InvalidArgumentException('Each filter condition must be an object.');
			}
			if (++$count > self::MAX_CONDITIONS) {
				throw new InvalidArgumentException('The filter contains too many conditions.');
			}

			$join = $index === 0 ? '' : strtoupper((string) ($condition['join'] ?? ''));
			if ($index > 0 && !in_array($join, ['AND', 'OR'], true)) {
				throw new InvalidArgumentException('A filter connector must be AND or OR.');
			}

			if (isset($condition['rows'])) {
				self::assertKeys($condition, ['join', 'negative', 'rows']);
				$clause = '(' . self::compileConditions($condition['rows'], $fields, $params, $count, $depth + 1) . ')';
			} else {
				self::assertKeys($condition, ['join', 'negative', 'field', 'operator', 'value']);
				$clause = self::compilePredicate($condition, $fields, $params);
			}

			if (($condition['negative'] ?? false) === true) {
				$clause = '(NOT ' . $clause . ')';
			} elseif (($condition['negative'] ?? false) !== false) {
				throw new InvalidArgumentException('The filter negative flag must be boolean.');
			}

			$parts[] = ($join !== '' ? $join . ' ' : '') . $clause;
		}

		return implode(' ', $parts);
	}

	private static function compilePredicate(array $condition, array $fields, array &$params): string {
		$field    = $condition['field'] ?? '';
		$operator = $condition['operator'] ?? '';
		$value    = $condition['value'] ?? null;

		if (!is_string($field) || !isset($fields[$field]) || !is_string($operator) || !is_scalar($value)) {
			throw new InvalidArgumentException('The filter contains an invalid field, operator, or value.');
		}

		$definition = $fields[$field];
		$allowed    = $definition['operators'] ?? [];
		if (!in_array($operator, $allowed, true)) {
			throw new InvalidArgumentException('The selected operator is not allowed for this field.');
		}

		$value = (string) $value;
		if ($value === '' || strlen($value) > 2048) {
			throw new InvalidArgumentException('Filter values must contain between 1 and 2048 bytes.');
		}
		if (($definition['type'] ?? 'string') === 'integer' && !ctype_digit($value)) {
			throw new InvalidArgumentException('The selected field requires a nonnegative integer.');
		}

		$column = $definition['column'];
		switch ($operator) {
			case 'contains':
				$params[] = '%' . self::escapeLike($value) . '%';
				return "($column LIKE ? ESCAPE '!')";
			case 'begins':
				$params[] = self::escapeLike($value) . '%';
				return "($column LIKE ? ESCAPE '!')";
			case 'ends':
				$params[] = '%' . self::escapeLike($value);
				return "($column LIKE ? ESCAPE '!')";
			case '=':
			case '!=':
			case '>':
			case '>=':
			case '<':
			case '<=':
				$params[] = $value;
				return "($column $operator ?)";
		}

		throw new InvalidArgumentException('The selected operator is not supported.');
	}

	private static function escapeLike(string $value): string {
		return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
	}

	private static function assertKeys(array $value, array $allowed): void {
		if (array_diff(array_keys($value), $allowed)) {
			throw new InvalidArgumentException('The filter contains unsupported properties.');
		}
	}
}
