// P3.9-4 (found in P1.11): a chat that can't be loaded says so. The panel used to restore its local copy of the
// messages without a word, so the person saw an out-of-date chat as if it had loaded.
//
// Run from the package folder: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { loadFailureText } = require('../../js/chat-format.js');

test('a failed load names the server\'s reason and says what to do', () => {
    const text = loadFailureText({ success: false, error: 'Unable to load the current chat.' });

    assert.match(text, /couldn.t be loaded/);
    assert.match(text, /Unable to load the current chat\./);
    assert.match(text, /reload the page/i);
});

test('with the local copy shown, it says the messages may be out of date', () => {
    assert.match(loadFailureText({ success: false }, true), /may be out of date/);
    assert.doesNotMatch(loadFailureText({ success: false }, false), /out of date/);
});

test('an expired session gets the session message, and a network error has a reason too', () => {
    assert.match(loadFailureText({ code: 'invalid_token' }), /session has expired/i);
    assert.match(loadFailureText(null), /couldn.t be loaded/);
    assert.match(loadFailureText(new Error('Failed to fetch')), /Failed to fetch/);
});
