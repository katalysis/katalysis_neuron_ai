// P3.12 part B (plans/P3.12-pasted-text-guard.md): "Approve all" never approves a delete_page card; each needs its own
// click. "Deny all" still covers every card.
//
// Run from the package folder: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { approveAllCards, approveAllLabel } = require('../../js/chat-format.js');

const cards = [
    { callId: 'a', tool: 'update_page', status: 'pending' },
    { callId: 'b', tool: 'delete_page', status: 'pending' },
    { callId: 'c', tool: 'add_block_to_page_area', status: 'pending' },
];

test('Approve all leaves out delete_page cards', () => {
    assert.deepEqual(approveAllCards(cards).map((c) => c.callId), ['a', 'c']);
});

test('the button says so when a delete card is waiting', () => {
    assert.equal(approveAllLabel(cards), 'Approve all (except deletions)');
    assert.equal(approveAllLabel(cards.filter((c) => c.tool !== 'delete_page')), 'Approve all');
});
