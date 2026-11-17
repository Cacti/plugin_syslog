<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for message-details rendering: the log message,
 * device, program, facility, and severity must survive serialization into
 * the button's data-message JSON attribute without ever emitting a raw
 * <script> tag or an unescaped HTML attribute-breakout sequence.
 */

it('escapes hostile log and device text in the message-details button', function () {
	syslog_load_plugin_source('includes/functions.php');

	$message = '<script>alert("log")</script> & "quoted"';
	$html = syslog_message_button($message, 'router" onmouseover="bad', 'kernel', 'kern', 'warning', '2026-09-13 00:00:00');

	expect(strpos($html, '<script>'))->toBeFalse('Log and device strings must be escaped');
	expect(strpos($html, ' onmouseover="'))->toBeFalse('Log and device strings must be escaped');

	preg_match('/data-message="([^"]*)"/', $html, $matches);
	$data = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

	expect($data['message'])->toBe($message, 'Complete log details must survive serialization');
	expect($data['device'])->toBe('router" onmouseover="bad', 'Complete log details must survive serialization');
});

it('preserves long escaped messages in both the preview and details', function ($message) {
	syslog_load_plugin_source('includes/functions.php');

	$html = syslog_message_button($message, 'router', 'kernel', 'kern', 'warning', '2026-11-17 00:00:00');

	preg_match('/<button[^>]*>(.*?)<\/button>/s', $html, $preview);
	preg_match('/data-message="([^"]*)"/', $html, $details);

	expect(html_entity_decode($preview[1], ENT_QUOTES, 'UTF-8'))->toBe($message)
		->and(strpos($preview[1], '<script>'))->toBeFalse()
		->and(json_decode(html_entity_decode($details[1], ENT_QUOTES, 'UTF-8'), true)['message'])->toBe($message);
})->with([
	'ASCII'   => str_repeat('message text ', 30),
	'Unicode' => str_repeat('接口状态正常 ', 30),
	'HTML'    => str_repeat('message text ', 12) . '<script>alert("log")</script> & "quoted"'
]);
