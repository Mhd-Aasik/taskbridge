const { Script, createContext } = require('node:vm');

// Test-only: evaluate bindings extracted from real rendered HTML, without a browser.
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; });
process.stdin.on('end', () => {
    try {
        const fixture = JSON.parse(input);
        const state = new Script(`(${fixture.data})`).runInNewContext({}, { timeout: 1000 });
        const wire = {};
        const context = createContext({ ...state, $wire: wire });
        const bindings = Object.fromEntries(Object.entries(fixture.bindings)
            .map(([name, expression]) => [name, new Script(`(${expression})`)]));
        const events = Object.fromEntries(Object.entries(fixture.events || {})
            .map(([name, expression]) => [name, new Script(expression)]));
        const results = fixture.states.map(update => {
            Object.assign(wire, update.wire || {});
            for (const event of update.events || []) events[event].runInContext(context, { timeout: 1000 });
            return {
                ...Object.fromEntries(Object.entries(bindings).map(([name, script]) => [name, script.runInContext(context, { timeout: 1000 })])),
                uploading: context.uploading,
            };
        });
        process.stdout.write(JSON.stringify({ results }));
    } catch (error) {
        // Never echo HTML, tokens, account data or private paths on failure.
        process.stdout.write(JSON.stringify({ error: error.name }));
    }
});
