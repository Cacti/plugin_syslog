const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const component = fs.readFileSync(path.join(__dirname, '../../js/filter-builder.js'), 'utf8');
let initialized;
const element = {dataset: {}, searchRows: [], querySelectorAll: () => []};
const context = {
	initSyslogSearchBuilder(node, rows) { initialized = {node, rows}; node.searchRows = rows; },
	syslogBuilderSync() { return 'valid'; },
	$: () => ({find: () => ({each() {}})})
};
vm.runInNewContext(component + '\nthis.SyslogFilterBuilder = SyslogFilterBuilder;', context);

const conditions = [{join: 'AND', negative: false, field: 'message', operator: 'contains', value: 'failed'}];
const builder = new context.SyslogFilterBuilder(element, {
	fields: {message: 'Message'},
	operators: {message: ['contains', '=']},
	conditions
});

assert.equal(initialized.node, element);
assert.deepEqual(initialized.rows, conditions);
assert.equal(element.dataset.fields, '{"message":"Message"}');
assert.equal(element.dataset.operators, '{"message":["contains","="]}');
assert.equal(builder.serialize(), '{"version":1,"conditions":[{"join":"AND","negative":false,"field":"message","operator":"contains","value":"failed"}]}');
const input = {value: ''};
assert.equal(builder.syncTo(input), true);
assert.equal(input.value, builder.serialize());

console.log('alarm_filter_builder_ui_test passed');
