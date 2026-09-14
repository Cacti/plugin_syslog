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
for (const admin of ['0', '1']) {
 for (const global of ['0', '1']) {
  for (const owner of ['tester', 'someone-else']) {
   dropdown.dataset.admin = admin;
   dropdown.selectedOptions = [{dataset: {owner, global}}];
   context.savedSearchButtons();
   const allowed = owner === 'tester' || (global === '1' && admin === '1');
   assert.equal(buttons['#saved_delete'].visible, allowed, JSON.stringify({admin, global, owner}));
   assert.equal(buttons['#saved_delete'].disabled, !allowed);
   assert.equal(buttons['#saved_edit'].disabled, !allowed);
  }
 }
}
dropdown.value = '0';
dropdown.selectedOptions = [];
context.savedSearchButtons();
assert.equal(buttons['#saved_delete'].visible, false);
assert.equal(buttons['#saved_delete'].disabled, true);
console.log('saved_search_permissions_test passed');
