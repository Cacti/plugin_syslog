<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for issue #277: the shared XML import payload loader
 * must validate uploads before reading them, and must fail closed on a
 * zero-byte file rather than calling fread() with a zero length.
 */

it('validates uploads before reading and rejects zero-byte imports', function () {
	$functions = plugin_test_read_source('functions.php');

	if (substr_count($functions, 'function syslog_get_import_xml_payload(') !== 1 ||
		preg_match('/^function syslog_get_import_xml_payload\([^)]*\)\s*\{.*?^\}/ms', $functions, $matches) !== 1) {
		throw new RuntimeException('Unable to isolate one shared import payload loader helper.');
	}

	$helperBody = $matches[0];

	if (strpos($helperBody, '$import_text = (string) get_nfilter_request_var(\'import_text\')') === false ||
		strpos($helperBody, "trim(\$import_text) !== ''") === false) {
		throw new RuntimeException('Shared import payload loader is missing trimmed text handling.');
	}

	if (strpos($helperBody, 'return $import_text;') === false) {
		throw new RuntimeException('Shared import payload loader must return non-empty textbox input without trimming it.');
	}

	$uploadGuards = [
		"\$_FILES['import_file']['tmp_name']",
		"\$_FILES['import_file']['error'] !== UPLOAD_ERR_OK",
		'is_uploaded_file($tmp_name)',
		'syslog_read_import_file($tmp_name)',
	];

	foreach ($uploadGuards as $guard) {
		if (strpos($helperBody, $guard) === false) {
			throw new RuntimeException("Shared import payload loader is missing upload guard: $guard");
		}
	}

	$validationPosition = strpos($helperBody, 'is_uploaded_file($tmp_name)');
	$readPosition       = strpos($helperBody, 'syslog_read_import_file($tmp_name)');

	if ($validationPosition === false || $readPosition === false || $validationPosition > $readPosition) {
		throw new RuntimeException('Shared import payload loader must validate an upload before opening it.');
	}

	$this->loadPluginSource('functions.php');

	$emptyFixture   = tempnam(sys_get_temp_dir(), 'syslog-empty-import-');
	$payloadFixture = tempnam(sys_get_temp_dir(), 'syslog-import-');

	if ($emptyFixture === false || $payloadFixture === false ||
		file_put_contents($payloadFixture, '<xml>fixture</xml>') === false) {
		throw new RuntimeException('Unable to create import payload fixtures.');
	}

	try {
		expect(syslog_read_import_file($emptyFixture))->toBeFalse('A zero-byte import must fail without calling fread() with a zero length.');
		expect(syslog_read_import_file($payloadFixture))->toBe('<xml>fixture</xml>', 'A non-empty import payload must round trip without data loss.');
	} finally {
		unlink($emptyFixture);
		unlink($payloadFixture);
	}
});
