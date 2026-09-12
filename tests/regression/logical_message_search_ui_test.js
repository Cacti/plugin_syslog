const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../js/functions.js'), 'utf8');
let focused;
class Element {
	constructor(tag = 'div') { this.tag = tag; this.children = []; this.handlers = {}; this.jqueryHandlers = {}; this.value = ''; this.dataset = {}; }
	appendChild(child) { this.children.push(child); }
	replaceChildren() { this.children = []; }
	setAttribute(name, value) { this[name] = value; }
	addEventListener(event, handler) { this.handlers[event] = handler; }
	dispatchEvent(event) { if (this.handlers[event.type]) this.handlers[event.type](); }
	focus() { focused = this; }
	reportValidity() { return !this.required || this.value !== ''; }
	querySelectorAll(selector) {
		return this.children.flatMap(child => [
			...(selector[0] === '.' ? (child.className || '').split(' ').includes(selector.slice(1)) : child.tag === selector) ? [child] : [],
			...child.querySelectorAll(selector)
		]);
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0]; }
}
const nodes = {};
for (const id of ['syslog_search_builder', 'search_mode', 'rfilter', 'logical_search_help', 'syslog_form']) {
	nodes[id] = new Element();
}
nodes.search_mode.value = 'logical';
nodes.syslog_search_builder.dataset = {tree: 'null', message: 'Message', contains: 'contains', notContains: 'does not contain', remove: 'Remove condition'};
const context = {
	Event: class { constructor(type) { this.type = type; } },
	$: selector => {
		const node = typeof selector === 'string' ? nodes[selector.slice(1)] : selector;
		return {
			datetimepicker(options) { node.datepickerOptions = options; },
			autocomplete(options) { node.autocompleteOptions = options; return this; },
			val(value) { if (value !== undefined) node.value = value; return node.value; },
			toggle(show) { node.hidden = !show; },
			attr(name, value) { node.setAttribute(name, value); },
			empty() { node.replaceChildren(); },
			on(event, handler) { node.jqueryHandlers[event] = handler; },
			trigger(event) { if (node.jqueryHandlers[event]) node.jqueryHandlers[event](); }
		};
	},
	document: {getElementById: id => nodes[id], createElement: tag => new Element(tag)},
	window: {pageTab: 'alerts'}, Pace: {stop() {}},
	base64_encode: value => Buffer.from(value).toString('base64')
};
const start = source.indexOf('function syslogSearchRows(');
const end = source.indexOf('function applyFilter()', start);
vm.runInNewContext(source.slice(start, end), context);
context.initSyslogSearchBuilder();
const builder = nodes.syslog_search_builder;
function enter(index, value) {
	const input = builder.querySelectorAll('.syslogSearchText')[index];
	input.value = value;
	input.handlers.input();
}
function add(operator) {
	builder.querySelectorAll('.syslogSearchAdd').find(button => button.textContent === operator).handlers.click();
}
assert.equal(builder.querySelectorAll('.syslogSearchText').length, 1);
assert.equal(nodes.rfilter.hidden, true, 'Expression field hidden in builder mode');
assert.equal(context.syncSyslogSearchBuilder(), true, 'Single blank row means no filter');
assert.equal(nodes.rfilter.value, '');
enter(0, 'message A');
add('AND');
assert.equal(builder.querySelectorAll('.syslogSearchText').length, 2);
assert.equal(focused, builder.querySelectorAll('.syslogSearchText')[1]);
assert.equal(context.syncSyslogSearchBuilder(), false, 'Incomplete added row blocks submission');
enter(1, 'message B');
add('OR');
enter(2, 'Message C');
assert.equal(context.syncSyslogSearchBuilder(), true);
assert.equal(nodes.rfilter.value, '"message A" AND "message B" OR "Message C"');
add('NOT');
enter(3, 'AND "quoted" \\ %_');
context.syncSyslogSearchBuilder();
assert.equal(nodes.rfilter.value, '"message A" AND "message B" OR "Message C" AND NOT "AND \\"quoted\\" \\\\ %_"');
builder.querySelectorAll('.syslogSearchRemove')[3].handlers.click();
assert.equal(builder.querySelectorAll('.syslogSearchText').length, 3);
// Rehydration must preserve flat rows and explicit grouping.
const term = value => ['term', value];
for (const [tree, expected] of [
	[['OR', ['AND', term('message A'), term('message B')], term('Message C')], '"message A" AND "message B" OR "Message C"'],
	[['AND', ['OR', term('A'), term('B')], ['NOT', term('C')]], '("A" OR "B") AND NOT "C"'],
	[['NOT', ['OR', term('A'), term('B')]], 'NOT ("A" OR "B")']
]) {
	assert.equal(context.syslogSearchExpression(context.syslogSearchRows(tree)), expected);
}
// Field predicates survive serialization and rehydration, including SQL wildcard text.
const predicate = ['predicate', 'host', 'like', 'web-%'];
assert.equal(context.syslogSearchExpression(context.syslogSearchRows(predicate)), 'host like "web-%"');
assert.equal(context.syslogSearchExpression(context.syslogSearchRows(['NOT', predicate])), 'NOT host like "web-%"');
const first = builder.querySelectorAll('.syslogSearchRow')[0];
const fieldSelect = first.children[1];
fieldSelect.value = 'host';
context.$(fieldSelect).trigger('change');
assert.equal(builder.searchRows[0].field, 'host');
assert.equal(builder.searchRows[0].operator, 'contains');
enter(0, 'message A');
// Export submits the current unsaved builder state in a POST body.
let submitted;
context.postSyslog = data => { submitted = data; };
context.syslogFilterData = () => ({rfilter: nodes.rfilter.value});
const exportStart = source.indexOf('function exportRecords()');
vm.runInNewContext(source.slice(exportStart, source.indexOf('function clearFilter()', exportStart)), context);
context.exportRecords();
assert.equal(submitted.export, 'true');
assert.equal(submitted.rfilter, 'host contains "message A" AND "message B" OR "Message C"');
// Exercise the actual form transport and verify no query is appended to its action.
let postedForm;
Element.prototype.submit = function() { postedForm = this; };
Element.prototype.remove = function() {};
context.document.body = new Element('body');
context.csrfMagicToken = 'test-token';
const postStart = source.indexOf('function postSyslog(');
vm.runInNewContext(source.slice(postStart, source.indexOf('function syslogFilterData()', postStart)), context);
context.postSyslog(submitted);
assert.equal(postedForm.method, 'post');
assert.equal(postedForm.action, 'syslog.php');
const values = Object.fromEntries(postedForm.children.map(input => [input.name, input.value]));
assert.equal(values.tab, 'alerts');
assert.equal(values.__csrf_magic, 'test-token');
assert.equal(values.rfilter, submitted.rfilter);
// Theme-generated jQuery changes must update all available fields, not just Message.
builder.dataset.fields = JSON.stringify({message: 'Message', host: 'Host', program: 'Program', facility: 'Facility', priority: 'Priority', priority_id: 'Priority ID', logtime: 'Date'});
context.initSyslogSearchBuilder();
for (const field of ['host', 'program', 'facility', 'priority', 'priority_id']) {
	const select = builder.querySelectorAll('.syslogSearchRow')[0].children[1];
	assert.ok(select.children.some(option => option.value === field), field + ' available');
	select.value = field;
	context.$(select).trigger('change');
	assert.equal(builder.searchRows[0].field, field, 'Themed field change applied');
	const operator = builder.querySelectorAll('.syslogSearchRow')[0].children[2];
	operator.value = '=';
	context.$(operator).trigger('change');
	enter(0, field === 'priority_id' ? '4' : 'test-value');
	context.syncSyslogSearchBuilder();
	assert.ok(nodes.rfilter.value.startsWith(field + ' = "'), 'Selected field/operator serialized');
}
// Database-backed dropdowns show labels while storing the actual IDs.
builder.dataset.choices = JSON.stringify({facility: [['auth', 'auth']], program_id: [['12', 'sshd (12)']], priority_id: [['4', 'warning (4)']]});
builder.dataset.fields = JSON.stringify({message: 'Message', host: 'Host', facility: 'Facility', program_id: 'Program ID', priority_id: 'Priority ID'});
for (const [field, value] of [['facility', 'auth'], ['program_id', '12'], ['priority_id', '4']]) {
	builder.dataset.tree = JSON.stringify(['predicate', field, '=', value]);
	context.initSyslogSearchBuilder();
	const input = builder.querySelector('.syslogSearchText');
	assert.equal(input.tag, 'input');
	assert.ok(input.autocompleteOptions.source.some(option => option.value === value));
	input.autocompleteOptions.select({}, {item: {value: value}});
	context.syncSyslogSearchBuilder();
	assert.equal(nodes.rfilter.value, field + ' = "' + value + '"');
	const custom = field === 'facility' ? 'future-facility' : '999';
	enter(0, custom);
	context.syncSyslogSearchBuilder();
	assert.equal(nodes.rfilter.value, field + ' = "' + custom + '"', 'Custom value retained without a database match');
	builder.dataset.tree = JSON.stringify(['predicate', field, '=', custom]);
	context.initSyslogSearchBuilder();
	assert.equal(builder.querySelector('.syslogSearchText').value, custom, 'Custom value restored on reload');
}
builder.dataset.tree = JSON.stringify(['predicate', 'host', '=', 'old-host']);
context.initSyslogSearchBuilder();
const hostInput = builder.querySelector('.syslogSearchText');
assert.equal(hostInput.autocompleteOptions.minLength, 0);
hostInput.autocompleteOptions.select({}, {item: {value: 'router-1', label: 'router-1'}});
context.syncSyslogSearchBuilder();
assert.equal(nodes.rfilter.value, 'host = "router-1"');
// Date rows retain the Cacti datetime picker and serialize picker changes.
builder.dataset.fields = JSON.stringify({message: 'Message', logtime: 'Date'});
builder.dataset.tree = JSON.stringify(['AND', ['predicate', 'logtime', '>=', '2020-01-01 00:00:00'], ['predicate', 'logtime', '<=', '2020-01-02 00:00:00']]);
context.initSyslogSearchBuilder();
let dates = builder.querySelectorAll('.syslogSearchDate');
assert.equal(dates.length, 2);
assert.equal(dates[0].datepickerOptions.dateFormat, 'yy-mm-dd');
dates[0].datepickerOptions.onSelect('2020-01-01 12:30:00');
context.syncSyslogSearchBuilder();
assert.equal(nodes.rfilter.value, 'logtime >= "2020-01-01 12:30:00" AND logtime <= "2020-01-02 00:00:00"');
add('AND');
dates = builder.querySelectorAll('.syslogSearchDate');
assert.ok(dates[0].datepickerOptions, 'Picker reattached after adding a condition');
assert.equal(dates[0].value, '2020-01-01 12:30:00');
console.log('logical_message_search_ui_test passed');
