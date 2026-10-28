// P3.6-1 (plans/P3.6-1.md §5): the approval cards and the "Changed: …" line. Their text is the server's and comes
// partly from page names and the model's inputs, so it is set with textContent only: never parsed, never markup.
// A waiting card ends in two buttons, the only way to decide.
//
// Run from the package folder: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { renderApprovalCard, renderChangesLine } = require('../../js/chat-format.js');

/** A fake document that records what is made and throws if markup is ever written. */
function fakeDocument() {
    const made = [];
    const node = (tag) => {
        const el = {
            tag, children: [], attributes: {}, text: null, className: '',
            appendChild(child) { this.children.push(child); return child; },
            setAttribute(name, value) { this.attributes[name] = String(value); },
            set textContent(value) { this.text = String(value); },
            get textContent() { return this.text ?? this.children.map((c) => c.textContent).join(''); },
            set innerHTML(_) { throw new Error('innerHTML must never be written'); },
            set outerHTML(_) { throw new Error('outerHTML must never be written'); },
            insertAdjacentHTML() { throw new Error('insertAdjacentHTML must never be used'); },
        };
        made.push(el);
        return el;
    };
    return {
        made,
        createElement: (tag) => node(String(tag).toLowerCase()),
        createTextNode: (text) => ({ tag: '#text', textContent: String(text) }),
    };
}

const PAYLOAD = '<img src=x onerror="document.body.dataset.xss=1">[click](javascript:alert(1)) **bold**';

function card(overrides = {}) {
    return {
        callId: 'call_1',
        tool: 'update_page',
        action: 'Change page',
        target: 'Headings ' + PAYLOAD,
        path: '/neuron-test/headings',
        details: ['Description: (empty) → "' + PAYLOAD + '"'],
        note: 'Saved in a new unpublished version; nothing goes live until someone publishes it.',
        status: 'pending',
        statusText: '',
        ...overrides,
    };
}

test('a waiting card shows the server\'s text as text, with Approve and Deny buttons only', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');

    const { buttons } = renderApprovalCard(container, card(), doc);

    const tags = new Set(doc.made.map((el) => el.tag));
    for (const tag of tags) {
        assert.ok(['div', 'strong', 'ul', 'li', 'button'].includes(tag), `unexpected element <${tag}>`);
    }
    assert.equal(doc.made.some((el) => el.tag === 'a' || el.tag === 'img'), false, 'no link, no image');
    assert.ok(container.textContent.includes('Change page Headings <img src=x onerror='), 'the payload is visible as text');
    assert.ok(container.textContent.includes('(/neuron-test/headings)'));
    assert.ok(container.textContent.includes('[click](javascript:alert(1)) **bold**'), 'markdown is not parsed');
    assert.deepEqual([buttons.approve.textContent, buttons.deny.textContent], ['Approve', 'Deny']);
    for (const b of [buttons.approve, buttons.deny]) {
        assert.equal(b.tag, 'button');
        assert.deepEqual(b.attributes, { type: 'button' });
    }
});

test('a decided card shows the server\'s status text and no buttons', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');

    const { buttons } = renderApprovalCard(container, card({ status: 'stale', statusText: 'Not done: the page changed since you saw the preview.' }), doc);

    assert.equal(buttons, null);
    assert.equal(doc.made.some((el) => el.tag === 'button'), false);
    assert.ok(container.textContent.endsWith('Not done: the page changed since you saw the preview.'));
});

test('a card with unexpected values renders what is text and drops the rest', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');

    renderApprovalCard(container, card({ target: { toString: () => '<b>x</b>' }, details: ['ok', 42, null], note: undefined }), doc);

    const items = doc.made.filter((el) => el.tag === 'li').map((li) => li.textContent);
    assert.deepEqual(items, ['ok']);
    assert.equal(container.textContent.includes('<b>x</b>'), false, 'only strings are shown');
});

test('the changes line is text only', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');

    renderChangesLine(container, 'Changed: ' + PAYLOAD + '.', doc);

    assert.equal(doc.made.length, 2);
    const line = doc.made[1];
    assert.equal(line.text, 'Changed: ' + PAYLOAD + '.');
    assert.deepEqual(line.attributes, { role: 'note' });
});
