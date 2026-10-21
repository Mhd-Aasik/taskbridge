const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { Script } = require('node:vm');
const assert = require('node:assert/strict');
const { test } = require('node:test');

// Execute the actual Alpine handler object with simulated geometry, not a browser.
const source = readFileSync(resolve(__dirname, '../../resources/views/livewire/messages/show.blade.php'), 'utf8');
const expression = source.match(/x-data="([\s\S]*?)"\s*\n\s*x-on:conversation-refreshed/)[1]
    .replaceAll('&lt;', '<').replaceAll('&amp;', '&');
const handler = new Script(`(${expression})`);

function fixture({ hidden = false, older = false, bottom = true, end = 600 } = {}) {
    const calls = [];
    const component = handler.runInNewContext({ document: { visibilityState: hidden ? 'hidden' : 'visible' }, window: { innerHeight: 800 } });
    Object.assign(component, {
        atBottom: bottom,
        $el: { dataset: { presentation: 'server-issued-window' } },
        $refs: { end: { getBoundingClientRect: () => ({ top: end - 1, bottom: end }) }, thread: { scrollHeight: 1200, scrollTop: 500, clientHeight: 400 } },
        $wire: { viewingOlder: older, markDisplayedRead: async token => { calls.push(token); } },
        $nextTick: async callback => callback?.(),
    });
    return { component, calls };
}

test('visible latest window acknowledges only its server-issued receipt', async () => {
    const { component, calls } = fixture();
    await component.acknowledge();
    assert.deepEqual(calls, ['server-issued-window']);
    assert.equal(component.reading, false);
});

for (const [label, options] of Object.entries({ hidden: { hidden: true }, older: { older: true }, scrolled: { bottom: false }, belowViewport: { end: 900 }, aboveViewport: { end: -10 } })) {
    test(`${label} conversation does not acknowledge messages`, async () => {
        const { component, calls } = fixture(options);
        await component.acknowledge();
        assert.deepEqual(calls, []);
    });
}

test('concurrent acknowledgment and older-history loading do not issue another receipt', async () => {
    const { component, calls } = fixture();
    component.reading = true;
    await component.acknowledge();
    component.reading = false;
    component.olderBusy = true;
    await component.acknowledge();
    assert.deepEqual(calls, []);
});

test('older loading preserves retained message offset and scroll position', async () => {
    const { component } = fixture();
    let loaded = false;
    const thread = component.$refs.thread;
    thread.querySelector = () => ({ dataset: { messageId: '50' }, getBoundingClientRect: () => ({ top: loaded ? 260 : 100 }) });
    component.$wire.loadOlder = async () => { loaded = true; };
    component.track = () => {};
    await component.older();
    assert.equal(thread.scrollTop, 660);
    assert.equal(component.olderBusy, false);
});

for (const [mode, bottom, expected] of [['following', false, 500], ['none', false, 500], ['following', true, 1200], ['sent', false, 1200], ['requested', false, 1200]]) {
    test(`scroll ${mode} atBottom=${bottom} follows only appropriate windows`, async () => {
        const { component } = fixture({ bottom });
        component.acknowledge = () => {};
        component.refreshed(mode);
        await Promise.resolve();
        assert.equal(component.$refs.thread.scrollTop, expected);
    });
}
