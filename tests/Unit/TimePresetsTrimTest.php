<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Log view date presets, aligned with Cacti's timespan selector ("In the
 * last" rolling spans and "In the range" calendar spans), and the message
 * Trim choices.
 */

beforeEach(function () {
	syslog_load_plugin_source('includes/functions.php');
});

afterEach(function () {
	syslog_test_reset_globals();
});

it('compiles every rolling and calendar preset through the search parser', function () {
	foreach (syslog_search_time_presets() as $operator => $values) {
		foreach (array_keys($values) as $value) {
			$sql = syslog_logical_search_sql(syslog_parse_logical_search('logtime ' . $operator . ' "' . $value . '"'), 'message');

			expect($sql)->toStartWith('(syslog.logtime ');
		}
	}
});

it('turns rolling presets into database-relative intervals', function ($value, $interval) {
	expect(syslog_search_time_sql('last', $value))->toBe('(syslog.logtime BETWEEN DATE_SUB(NOW(), INTERVAL ' . $interval . ') AND NOW())');
})->with([
	'minutes' => ['1800', '1800 SECOND'],
	'days'    => ['172800', '172800 SECOND'],
	'month'   => ['1months', '1 MONTH'],
	'months'  => ['4months', '4 MONTH'],
	'years'   => ['2years', '2 YEAR']
]);

it('bounds calendar presets by whole periods', function ($value, $start, $end) {
	test_override('read_user_setting', static fn ($name, $default = '') => $default);

	expect(syslog_search_time_sql('during', $value))->toBe('(syslog.logtime >= ' . $start . ' AND syslog.logtime < ' . $end . ')');
})->with([
	'this day'       => ['this_day', 'CURDATE()', 'CURDATE() + INTERVAL 1 DAY'],
	'previous day'   => ['previous_day', 'CURDATE() - INTERVAL 1 DAY', 'CURDATE()'],
	'this month'     => ['this_month', "CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE)", "CAST(DATE_FORMAT(CURDATE(), '%Y-%m-01') AS DATE) + INTERVAL 1 MONTH"],
	'previous year'  => ['previous_year', 'MAKEDATE(YEAR(CURDATE()), 1) - INTERVAL 1 YEAR', 'MAKEDATE(YEAR(CURDATE()), 1)'],
	'default shift'  => ['day_shift', 'CURDATE() + INTERVAL 420 MINUTE', 'CURDATE() + INTERVAL 1080 MINUTE']
]);

it('starts weeks on the configured first day of the week', function ($first, $offset) {
	test_override('read_user_setting', static fn ($name, $default = '') => $name === 'first_weekdayid' ? $first : $default);

	expect(syslog_search_time_sql('during', 'this_week'))->toContain('INTERVAL (WEEKDAY(CURDATE()) + ' . $offset . ') % 7 DAY');
})->with([
	'Sunday' => [0, 8],
	'Monday' => [1, 7],
	'out of range is clamped' => [9, 2]
]);

it('uses the configured day shift and falls back on unreadable times', function () {
	$settings = ['day_shift_start' => '08:30', 'day_shift_end' => 'late'];
	test_override('read_user_setting', static fn ($name, $default = '') => $settings[$name] ?? $default);

	expect(syslog_search_time_sql('during', 'day_shift'))
		->toBe('(syslog.logtime >= CURDATE() + INTERVAL 510 MINUTE AND syslog.logtime < CURDATE() + INTERVAL 1080 MINUTE)');
});

it('rejects unknown presets and presets on other fields', function ($search) {
	expect(fn () => syslog_parse_logical_search($search))->toThrow(InvalidArgumentException::class);
})->with([
	'unknown range'   => ['logtime during "tomorrow"'],
	'unknown span'    => ['logtime last "3years"'],
	'injection'       => ['logtime during "this_day); DROP TABLE syslog"'],
	'non-date field'  => ['host during "this_day"']
]);

it('refuses an unknown preset when building SQL directly', function () {
	expect(fn () => syslog_search_time_sql('during', 'next_week'))->toThrow(InvalidArgumentException::class)
		->and(fn () => syslog_search_time_sql('last', '1'))->toThrow(InvalidArgumentException::class);
});

it('offers Fit Column, All Text and the character cuts as Trim choices', function () {
	expect(array_keys(syslog_trim_options()))->toBe([0, -1, 30, 50, 75, 100, 150, 300]);
});

it('cuts messages after the chosen number of characters', function ($message, $trim, $expected) {
	expect(syslog_trim_message($message, $trim))->toBe($expected);
})->with([
	'fit column keeps all'  => [str_repeat('a', 40), 0, str_repeat('a', 40)],
	'all text keeps all'    => [str_repeat('a', 40), -1, str_repeat('a', 40)],
	'short enough'          => ['link up', 30, 'link up'],
	'exactly the limit'     => [str_repeat('b', 30), 30, str_repeat('b', 30)],
	'cut with ellipsis'     => ['interface Gi1/0/1 changed state to down', 30, 'interface Gi1/0/1 changed stat…'],
	'trailing space dropped' => ['abc def', 4, 'abc…'],
	'multibyte safe'        => [str_repeat('接口', 20), 30, str_repeat('接口', 15) . '…']
]);

it('shows the trimmed text while the details keep the whole message', function () {
	$message = 'interface Gi1/0/1 changed state to down';
	$html    = syslog_message_button($message, 'router', 'kernel', 'kern', 'warning', '2026-11-18 10:00:00', 0, '', 30);

	preg_match('/<button[^>]*>(.*?)<\/button>/s', $html, $shown);
	preg_match('/data-message="([^"]*)"/', $html, $details);

	expect(html_entity_decode($shown[1], ENT_QUOTES, 'UTF-8'))->toBe('interface Gi1/0/1 changed stat…')
		->and(json_decode(html_entity_decode($details[1], ENT_QUOTES, 'UTF-8'), true)['message'])->toBe($message)
		->and($html)->not->toContain('syslogMessageWrap')
		->and(syslog_message_button($message, 'r', 'p', 'f', 's', 't', 0, '', -1))->toContain('class="syslogMessageOpen syslogMessageWrap"');
});
