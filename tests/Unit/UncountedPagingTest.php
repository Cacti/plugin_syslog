<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Skip Total Row Count: the log views fetch one record more than a page and
 * page with Previous and Next instead of counting every matching record.
 */

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
});

afterEach(function () {
	syslog_test_reset_globals();
});

it('follows the Skip Total Row Count setting', function ($value, $expected) {
	test_override('read_config_option', static fn ($name) => $name === 'syslog_skip_row_count' ? $value : '');

	expect(syslog_row_count_disabled())->toBe($expected);
})->with([
	'on'  => ['on', true],
	'off' => ['', false]
]);

it('pages without a total from the one-record look-ahead', function (int $fetched, int $page, int $shown, int $total, bool $count) {
	$records = $fetched ? range(1, $fetched) : [];

	expect(syslog_uncounted_page($records, 10, $page))->toBe(['total' => $total, 'page_count' => $count])
		->and($records)->toBe($shown ? range(1, $shown) : []);
})->with([
	// A first page holding everything still reports its exact count.
	'first page, all records' => [7, 1, 7, 7, true],
	'first page, exactly full' => [10, 1, 10, 10, true],
	// The surplus record means a next page exists; it is never shown.
	'first page, more follow'  => [11, 1, 10, 10, false],
	'middle page'              => [11, 3, 10, 10, false],
	// A later page that is not followed by more never offers Next, even when full.
	'last page, full'          => [10, 4, 10, 9, false],
	'last page, partial'       => [2, 2, 2, 2, false],
	'past the end'             => [0, 5, 0, 0, false]
]);
