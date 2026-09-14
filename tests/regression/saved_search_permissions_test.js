const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../js/functions.js'), 'utf8');
const dropdown = {value: '7', dataset: {user: 'tester', admin: '0'}, selectedOptions: []};
const buttons = {};
const context = {
 document: {getElementById: () => dropdown},
 $: selector => {
  if (selector === '#saved_search') return {val: () => dropdown.value};
  const state = buttons[selector] ||= {};
  return {length: 1, prop(key, value) {state[key] = value; return this;}, toggle(value) {state.visible = value; return this;}};
 }
};
vm.runInNewContext(source.slice(source.indexOf('function savedSearchActive('), source.indexOf('/** Save all authored conditions')), context);
// Only an explicit server stamp of data-manage='0' withdraws Delete; any other
// state (missing flag, '1', legacy markup) defers to server-side enforcement.
for (const manage of ['0', '1', undefined]) {
 dropdown.selectedOptions = [{dataset: {owner: 'someone-else', global: '0', ...(manage !== undefined ? {manage} : {})}}];
 context.savedSearchButtons();
 assert.equal(buttons['#saved_delete'].visible, manage !== '0');
 assert.equal(buttons['#saved_delete'].disabled, manage === '0');
 assert.equal(buttons['#saved_edit'].disabled, manage === '0');
}
// The dropdown-level attributes no longer influence the decision.
dropdown.dataset.admin = '1';
dropdown.dataset.user = 'someone-else';
dropdown.selectedOptions = [{dataset: {owner: 'someone-else', global: '1', manage: '0'}}];
context.savedSearchButtons();
assert.equal(buttons['#saved_delete'].visible, false);
assert.equal(buttons['#saved_delete'].disabled, true);
dropdown.selectedOptions = [{dataset: {owner: 'tester', global: '0', manage: '1'}}];
context.savedSearchButtons();
assert.equal(buttons['#saved_delete'].visible, true);
assert.equal(buttons['#saved_delete'].disabled, false);
dropdown.value = '0';
dropdown.selectedOptions = [];
context.savedSearchButtons();
assert.equal(buttons['#saved_delete'].visible, false);
assert.equal(buttons['#saved_delete'].disabled, true);
console.log('saved_search_permissions_test passed');
