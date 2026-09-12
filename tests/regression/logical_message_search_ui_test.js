const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../js/functions.js'), 'utf8');
let focused;
class Element {
	constructor(tag = 'div') { this.tag = tag; this.children = []; this.handlers = {}; this.value = ''; this.dataset = {}; }
	appendChild(child) { this.children.push(child); }
	replaceChildren() { this.children = []; }
	setAttribute(name, value) { this[name] = value; }
	addEventListener(event, handler) { this.handlers[event] = handler; }
	focus() { focused = this; }
	reportValidity() { return !this.required || this.value !== ''; }
	querySelectorAll(selector) {
		return this.children.flatMap(child => [
			...(selector[0] === '.' ? child.className === selector.slice(1) : child.tag === selector) ? [child] : [],
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
	$: selector => {
		const node = nodes[selector.slice(1)];
		return {
			val(value) { if (value !== undefined) node.value = value; return node.value; },
			toggle(show) { node.hidden = !show; },
			attr(name, value) { node.setAttribute(name, value); },
			on(event, handler) { node.addEventListener(event, handler); }
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
fieldSelect.handlers.change();
assert.equal(builder.searchRows[0].field, 'host');
assert.equal(builder.searchRows[0].operator, 'contains');
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
console.log('logical_message_search_ui_test passed');
