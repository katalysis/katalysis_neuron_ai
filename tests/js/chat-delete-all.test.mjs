// P3.9-2 (B-PII-5): "Delete all my chats" asks in the panel, with the count, before anything is deleted.
//
// Run from the package folder: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { deleteAllConfirmText } = require('../../js/chat-format.js');

test('the confirmation says how many chats and messages, that it cannot be undone, and a waiting change', () => {
    const text = deleteAllConfirmText({ chats: 3, messages: 12, waiting: 1 });

    assert.match(text, /3 chats/);
    assert.match(text, /12 messages/);
    assert.match(text, /can.t be undone/);
    assert.match(text, /waiting for approval/);
});

test('one chat is singular, and nothing to delete says so', () => {
    assert.match(deleteAllConfirmText({ chats: 1, messages: 2, waiting: 0 }), /1 chat \(/);
    assert.doesNotMatch(deleteAllConfirmText({ chats: 1, messages: 2, waiting: 0 }), /waiting/);
    assert.equal(deleteAllConfirmText({ chats: 0, messages: 0, waiting: 0 }), 'You have no saved chats to delete.');
});
