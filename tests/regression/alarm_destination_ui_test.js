const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'syslog_alerts.php'), 'utf8');
const script = source.match(/function changeMethod\(\) \{[\s\S]*?\n\t\}/)[0];
let method, destination;
const visibility = {};
const context = {notifyExists: true, $: selector => ({
    val: () => selector === '#report_method' ? method : destination,
    toggle: visible => selector.split(', ').forEach(row => { visibility[row] = visible; })
})};
vm.runInNewContext(script, context);
for (method of ['0', '1']) {
    for (destination of ['syslog', 'alarmmanager']) {
        context.changeMethod();
        const syslog = destination === 'syslog';
        assert.equal(visibility['#row_spacer1'], syslog);
        assert.equal(visibility['#row_open_ticket'], syslog);
        assert.equal(visibility['#row_command'], syslog);
        assert.equal(visibility['#row_recovery_command'], syslog && method === '1');
        for (const row of ['#row_header_email', '#row_email', '#row_body', '#row_notify']) {
            assert.equal(visibility[row], syslog);
        }
        assert.equal(visibility['#row_num'], method === '1');
    }
}
context.notifyExists = false;
destination = 'syslog';
context.changeMethod();
assert.equal(visibility['#row_notify'], false);
const fixture = path.join(root, 'tests/Fixtures/threshold_rule_commands.php');
const cacti = process.env.CACTI_PATH || path.resolve(root, '../cacti');
const installed = execFileSync('php', [fixture, cacti, 'installed'], {encoding: 'utf8'});
const missing = execFileSync('php', [fixture, cacti, 'missing'], {encoding: 'utf8'});
assert.match(installed, /<select id='alarm_destination'/);
assert.match(installed, /value="syslog" selected>Syslog Alarming/);
assert.match(installed, /value="alarmmanager">Alarm Manager/);
assert.doesNotMatch(missing, /<select id='alarm_destination'/);
assert.match(missing, /type='hidden' id='alarm_destination'/);
assert.match(source, /alarm_destination'\)\.on\('change.syslogDestination', changeMethod\)/);
console.log('Alarm destination UI checks passed (native form fixture and actual JavaScript).');
