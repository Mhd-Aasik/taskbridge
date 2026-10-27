import test from 'node:test';
import assert from 'node:assert/strict';
import { accountLink, initializeAccountLink } from '../../resources/js/account-link.js';

test('accepts only complete tokens and decodes fragment email', () => {
    const token = 'a'.repeat(64);
    assert.deepEqual(accountLink(`#token=${token}&email=owner%40example.test`), { token, email: 'owner@example.test' });
    for (const value of ['', '#token=short', `#token=${'a'.repeat(63)}!`, `#token=${'a'.repeat(65)}`]) assert.equal(accountLink(value), null);
});

test('removes token from browser history, initializes form without consuming it', () => {
    const token = 'b'.repeat(64), field = { value: '' }, error = { hidden: true }, submit = { disabled: false };
    const form = { dataset: { accountLink: 'reset' }, elements: { email: { value: '' } }, querySelector: selector => ({ '[data-link-token]': field, '[data-link-error]': error, '[type="submit"]': submit })[selector] };
    const calls = [];
    initializeAccountLink(form, { hash: `#token=${token}&email=owner%40example.test`, pathname: '/reset-password', search: '' }, { replaceState: (...args) => calls.push(args) });
    assert.equal(field.value, token);
    assert.equal(form.elements.email.value, 'owner@example.test');
    assert.deepEqual(calls, [[null, '', '/reset-password']]);
    assert.equal(submit.disabled, false);
});

test('invalid links disable completion; POST validation retains the in-memory response field', () => {
    const field = { value: '' }, error = { hidden: true }, submit = { disabled: false };
    const form = { dataset: { accountLink: 'setup' }, querySelector: selector => ({ '[data-link-token]': field, '[data-link-error]': error, '[type="submit"]': submit })[selector] };
    initializeAccountLink(form, { hash: '', pathname: '/register/setup/id', search: '' }, {});
    assert.equal(submit.disabled, true); assert.equal(error.hidden, false);
    field.value = 'c'.repeat(64); submit.disabled = false;
    initializeAccountLink(form, { hash: '', pathname: '/register/setup/id', search: '' }, {});
    assert.equal(submit.disabled, false);
});
