<?php

if (!function_exists('syslog_messages')) {
	function syslog_messages($tab = 'syslog', $offset = null): void {
		$GLOBALS['syslog_datatable_filtered'] = 2;
		print '<div id="syslog_workspace"><table><tr class="syslogRow logWarning"><td>2026-01-01</td><td>&lt;script&gt;</td></tr>' .
			'<tr class="syslog-detail-row" data-parent="1"><td>detail</td></tr></table></div>';
	}
}

it('bounds DataTables pages and allowlists sorting for both log tabs', function () {
	$source = plugin_test_read_source('syslog.php');
	$start = strpos($source, 'function syslog_datatable_request(');
	$end = strpos($source, '/** Return one bounded', $start);
	eval(substr($source, $start, $end - $start));

	test_override('get_request_var', function ($name) {
		return $GLOBALS['datatable_request'][$name] ?? '';
	});
	$GLOBALS['datatable_request'] = ['grouping' => '0'];

	expect(syslog_datatable_request(['start' => '1500', 'length' => '750', 'order' => [['column' => '3', 'dir' => 'asc']]], 'syslog'))
		->toBe([1500, 750, 'message', 'ASC']);
	expect(syslog_datatable_request(['start' => '0', 'length' => '999999', 'order' => [['column' => '7', 'dir' => 'desc']]], 'alerts'))
		->toBe([0, 750, 'priority_id', 'DESC']);

	foreach ([
		['start' => '-1'],
		['length' => '-1'],
		['order' => [['column' => '8', 'dir' => 'asc']]],
		['order' => [['column' => '0; DROP TABLE syslog', 'dir' => 'asc']]],
		['order' => [['column' => '0', 'dir' => 'desc; DROP TABLE syslog']]]
	] as $invalid) {
		expect(fn() => syslog_datatable_request(array_replace_recursive(['start' => '0', 'length' => '25', 'order' => [['column' => '0', 'dir' => 'desc']]], $invalid), 'syslog'))
			->toThrow(InvalidArgumentException::class);
	}

	$start = strpos($source, 'function syslog_datatable_response(');
	$end = strrpos(substr($source, 0, strpos($source, 'function syslog_messages(', $start)), '/**');
	eval(substr($source, $start, $end - $start));
	test_override('set_request_var', function ($name, $value) { $GLOBALS['datatable_request'][$name] = $value; });
	$_POST = ['draw' => '4', 'start' => '0', 'length' => '750', 'order' => [['column' => '0', 'dir' => 'desc']]];
	$response = syslog_datatable_response('syslog');
	expect($response['draw'])->toBe(4)
		->and($response['recordsTotal'])->toBe(2)
		->and($response['recordsFiltered'])->toBe(2)
		->and($response['data'][0]['cells'])->toBe(['2026-01-01', '&lt;script&gt;'])
		->and(count($response['data'][0]['details']))->toBe(1);
});
