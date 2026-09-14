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
	$this->loadPluginSource('functions.php');

	$message = '<script>alert("log")</script> & "quoted"';
	$html = syslog_message_button($message, 'router" onmouseover="bad', 'kernel', 'kern', 'warning', '2026-09-13 00:00:00');

	expect(strpos($html, '<script>'))->toBeFalse('Log and device strings must be escaped');
	expect(strpos($html, ' onmouseover="'))->toBeFalse('Log and device strings must be escaped');

	preg_match('/data-message="([^"]*)"/', $html, $matches);
	$data = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

	expect($data['message'])->toBe($message, 'Complete log details must survive serialization');
	expect($data['device'])->toBe('router" onmouseover="bad', 'Complete log details must survive serialization');
});
