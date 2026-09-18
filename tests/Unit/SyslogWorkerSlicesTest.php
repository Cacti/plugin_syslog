<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Coverage for parallel worker slice computation: slices must be disjoint,
 * contiguous, and cover the requested inclusive seq range exactly so that
 * parallel workers never skip or double-process incoming records.  Edge
 * cases (empty ranges, inverted ranges, more workers than records) must
 * degrade to safe, non-throwing results.
 */

function syslog_slice_coverage(array $slices): array {
	$covered = [];

	foreach ($slices as $slice) {
		for ($seq = $slice['start']; $seq <= $slice['end']; $seq++) {
			$covered[$seq] = true;
		}
	}

	return array_keys($covered);
}

syslog_load_plugin_source('functions.php');

it('splits a seq range into disjoint contiguous slices', function (int $start, int $end, int $workers) {
	$slices = syslog_compute_slices($start, $end, $workers);

	$covered = syslog_slice_coverage($slices);

	sort($covered);

	expect($covered)->toBe(range($start, $end), 'Slice union covers the range exactly once');
})->with([
	'even split'    => [1, 100, 4],
	'odd remainder' => [1, 103, 4],
	'single record' => [1, 1, 4],
	'one worker'    => [5, 900, 1],
	'seq offset'    => [77, 1234, 3],
	'many records'  => [1000, 5000, 8],
]);

it('produces at most one slice per worker', function (int $start, int $end, int $workers) {
	$slices = syslog_compute_slices($start, $end, $workers);

	expect(count($slices))->toBeLessThanOrEqual($workers);
})->with([
	[1, 100, 4],
	[1, 1, 8],
	[10, 20, 16],
]);

it('clamps worker counts below one to a single slice', function () {
	$slices = syslog_compute_slices(1, 100, 0);

	expect(count($slices))->toBe(1);
	expect($slices[0]['start'])->toBe(1);
	expect($slices[0]['end'])->toBe(100);
});

it('returns an empty set for empty or inverted ranges', function () {
	expect(syslog_compute_slices(0, 0, 4))->toBe([]);
	expect(syslog_compute_slices(10, 5, 4))->toBe([]);
	expect(syslog_compute_slices(-1, 10, 4))->toBe([]);
	expect(syslog_compute_slices(5, 5, 4))->toBe([['start' => 5, 'end' => 5]], 'A single record range is one valid slice');
});

it('tolerates more workers than records without duplicating rows', function () {
	$slices = syslog_compute_slices(1, 2, 16);

	$covered = syslog_slice_coverage($slices);

	sort($covered);

	expect($covered)->toBe([1, 2], 'Every record still covered exactly once');
	expect(count($slices))->toBe(2, 'No empty trailing slices for a tiny range');
});