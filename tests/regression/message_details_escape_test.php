<?php
require dirname(__DIR__, 2) . '/functions.php';
function html_escape($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function get_request_var_request($name) { return 0; }
$message = '<script>alert("log")</script> & "quoted"';
$html = syslog_message_button($message, 'router" onmouseover="bad', 'kernel', 'kern', 'warning', '2026-09-13 00:00:00');
if (strpos($html, '<script>') !== false || strpos($html, ' onmouseover="') !== false) {
 throw new RuntimeException('Log and device strings must be escaped');
}
preg_match('/data-message="([^"]*)"/', $html, $matches);
$data = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
if ($data['message'] !== $message || $data['device'] !== 'router" onmouseover="bad') {
 throw new RuntimeException('Complete log details must survive serialization');
}
echo "message_details_escape_test passed\n";
