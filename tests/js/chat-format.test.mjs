// BF-11 (plans/BF-11.md §5, §8): the panel's reply formatter. Text from the model, the server or saved history must
// never become markup: parse() returns data, safeHref() allows only http(s), mailto and relative links, and
// renderInto() builds the nodes with createElement and textContent only.
//
// Run from the package folder: node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { parse, safeHref, renderInto } = require('../../js/chat-format.js');

const INLINE_TYPES = new Set(['text', 'strong', 'em', 'code', 'link']);

/** Every inline piece of every block, in order. */
function pieces(blocks) {
    return blocks.flatMap((block) => (block.type === 'list' ? block.items.flat() : block.inline));
}

/** The text the reader sees, joined. */
function visibleText(blocks) {
    return pieces(blocks).map((p) => p.text).join('');
}

/** Only the known piece types, and every link's href passed safeHref(). */
function assertOnlySafePieces(blocks, what) {
    for (const block of blocks) {
        assert.ok(block.type === 'line' || block.type === 'list', `${what}: block type ${block.type}`);
    }
    for (const p of pieces(blocks)) {
        assert.ok(INLINE_TYPES.has(p.type), `${what}: piece type ${p.type}`);
        assert.equal(typeof p.text, 'string', `${what}: piece text`);
        if (p.type === 'link') {
            assert.equal(safeHref(p.href), p.href, `${what}: a link's href must pass safeHref()`);
        } else {
            assert.equal(p.href, undefined, `${what}: only links have an href`);
        }
    }
}

// ---- The payloads from the investigation (plans/BF-11.md §2) -------------------------------------------------

const PROOF_PAYLOADS = {
    'img onerror': "Heading is <img src=x onerror=\"document.body.dataset.xss='fired'; console.log('xss img')\">",
    'javascript: link': "[click me](javascript:console.log('xss link'))",
    'quote break-out': "[hover me](https://example.invalid/\" id=\"breakout\" onmouseover=\"document.body.dataset.xss2='fired')",
    'form and iframe': '<form id="f" action="https://example.invalid/"><input name="pw" type="password"></form><iframe id="fr" srcdoc="<b>framed</b>"></iframe>',
    'h2 heading': 'The first heading on the page is "<h2>updated Seed Heading</h2>".',
    'script tag': "<script>document.body.dataset.xss3='fired'</script>",
};

for (const [name, payload] of Object.entries(PROOF_PAYLOADS)) {
    test(`proof payload stays text: ${name}`, () => {
        const blocks = parse(payload);
        assertOnlySafePieces(blocks, name);
        for (const p of pieces(blocks)) {
            if (p.type === 'link') {
                assert.doesNotMatch(p.href, /^\s*javascript:/i, `${name}: no javascript: link`);
            }
        }
    });
}

test('raw HTML is shown exactly as written', () => {
    for (const payload of [PROOF_PAYLOADS['img onerror'], PROOF_PAYLOADS['form and iframe'], PROOF_PAYLOADS['h2 heading'], PROOF_PAYLOADS['script tag']]) {
        const blocks = parse(payload);
        assert.deepEqual(pieces(blocks).map((p) => p.type), ['text']);
        assert.equal(visibleText(blocks), payload);
    }
});

test('a javascript: link is shown as the text that was written, not as a link', () => {
    const payload = PROOF_PAYLOADS['javascript: link'];
    const blocks = parse(payload);
    assert.ok(!pieces(blocks).some((p) => p.type === 'link'));
    assert.equal(visibleText(blocks), payload);
});

test('a quote in a link URL cannot add attributes', () => {
    // With spaces the markdown is not a link at all.
    let blocks = parse(PROOF_PAYLOADS['quote break-out']);
    assert.ok(!pieces(blocks).some((p) => p.type === 'link'));
    assert.equal(visibleText(blocks), PROOF_PAYLOADS['quote break-out']);

    // Without spaces it is a link, and the quotes are part of the href value, never markup.
    for (const quote of ['"', "'"]) {
        blocks = parse(`[t](https://a.example/${quote}onmouseover=${quote}x)`);
        const links = pieces(blocks).filter((p) => p.type === 'link');
        assert.equal(links.length, 1);
        assert.equal(links[0].href, `https://a.example/${quote}onmouseover=${quote}x`);
        assert.equal(links[0].text, 't');
    }

    blocks = parse('[t](https://a.example/"><img/src=x/onerror=alert(1)>)');
    assertOnlySafePieces(blocks, 'break-out with >');
    const link = pieces(blocks).find((p) => p.type === 'link');
    assert.ok(link.href.startsWith('https://a.example/'));
    assert.equal(visibleText(blocks).includes('<img'), false, 'the <img part is inside the href value, not shown or parsed');
});

// ---- Schemes -------------------------------------------------------------------------------------------------

const REFUSED = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    ' javascript:alert(1)',
    'java\tscript:alert(1)',
    'java\nscript:alert(1)',
    '\x01javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'DATA:text/html;base64,PHNjcmlwdD4=',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'ftp://example.com/x',
];

const ALLOWED = [
    'https://example.com/page',
    'HTTP://EXAMPLE.COM/',
    'http://katalysis-pro.test/neuron-test',
    'mailto:a@b.c',
    'MAILTO:a@b.c',
    '/dashboard/sitemap',
    'page',
    '../up',
    '#section',
    '?q=1',
    '//example.com/x',
];

for (const url of REFUSED) {
    test(`safeHref refuses ${JSON.stringify(url)}`, () => {
        assert.equal(safeHref(url), null);
    });
}

for (const url of ALLOWED) {
    test(`safeHref allows ${JSON.stringify(url)}`, () => {
        assert.equal(safeHref(url), url);
    });
}

test('safeHref refuses anything that is not a string', () => {
    for (const value of [null, undefined, 42, {}, []]) {
        assert.equal(safeHref(value), null);
    }
});

test('a markdown link with a refused scheme is plain text', () => {
    for (const url of ['javascript:alert`1`', 'JaVaScRiPt:alert`1`', 'data:text/html,x', 'DATA:text/html,x', 'vbscript:x']) {
        const written = `[go](${url})`;
        const blocks = parse(written);
        assert.ok(!pieces(blocks).some((p) => p.type === 'link'), written);
        assert.equal(visibleText(blocks), written);
    }
});

// ---- No images, ever ------------------------------------------------------------------------------------------

test('image markdown is plain text: no image, no link', () => {
    const written = '![x](https://elsewhere.example/?d=1)';
    const blocks = parse(written);
    assert.deepEqual(pieces(blocks).map((p) => p.type), ['text']);
    assert.equal(visibleText(blocks), written);
});

// ---- Entities stay literal ------------------------------------------------------------------------------------

test('HTML entities are shown as written, never decoded', () => {
    for (const written of ['&lt;img src=x onerror=alert(1)&gt;', '&#106;avascript:alert(1)', '&amp;']) {
        assert.equal(visibleText(parse(written)), written);
    }
    const blocks = parse('[t](&#106;avascript:alert(1))');
    for (const p of pieces(blocks)) {
        if (p.type === 'link') {
            // A relative URL that happens to contain "&#106;": harmless, and never decoded.
            assert.equal(p.href, '&#106;avascript:alert(1');
        }
    }
});

// ---- The formatting that is kept ------------------------------------------------------------------------------

test('bold, italic, code and a link', () => {
    const blocks = parse('A **bold** and *italic* word, `code`, and [a page](/neuron-test).');
    assert.deepEqual(blocks, [{
        type: 'line',
        inline: [
            { type: 'text', text: 'A ' },
            { type: 'strong', text: 'bold' },
            { type: 'text', text: ' and ' },
            { type: 'em', text: 'italic' },
            { type: 'text', text: ' word, ' },
            { type: 'code', text: 'code' },
            { type: 'text', text: ', and ' },
            { type: 'link', text: 'a page', href: '/neuron-test' },
            { type: 'text', text: '.' },
        ],
    }]);
});

test('code is literal: no bold inside it', () => {
    assert.deepEqual(parse('`**not bold**`'), [{ type: 'line', inline: [{ type: 'code', text: '**not bold**' }] }]);
});

test('lines and a list', () => {
    assert.deepEqual(parse('First line\nSecond line\n- one\n- **two**\nAfter'), [
        { type: 'line', inline: [{ type: 'text', text: 'First line' }] },
        { type: 'line', inline: [{ type: 'text', text: 'Second line' }] },
        { type: 'list', items: [[{ type: 'text', text: 'one' }], [{ type: 'strong', text: 'two' }]] },
        { type: 'line', inline: [{ type: 'text', text: 'After' }] },
    ]);
});

test('one list line makes a list of that line only (BF-11 decision 5)', () => {
    assert.deepEqual(parse('Intro\n- only item\nOutro').map((b) => b.type), ['line', 'list', 'line']);
});

test('empty and non-string input', () => {
    assert.deepEqual(parse(''), []);
    assert.deepEqual(parse(null), []);
    assert.deepEqual(parse(undefined), []);
});

// ---- After BF-11's browser check 4: auto-links, numbered lists, sub-items, image markdown as a link -----------

/** The inline pieces of one list item, its sub-items' pieces included. */
function itemPieces(item) {
    return [...item.inline, ...item.items.flat()];
}

test('a bare email address becomes a mailto link', () => {
    assert.deepEqual(parse('Email: r.ashworth@example.com, or call.')[0].inline, [
        { type: 'text', text: 'Email: ' },
        { type: 'link', text: 'r.ashworth@example.com', href: 'mailto:r.ashworth@example.com' },
        { type: 'text', text: ', or call.' },
    ]);
});

test('a bare http(s) URL becomes a link, without trailing punctuation', () => {
    assert.deepEqual(parse('See https://example.com/a?b=1. Or (http://katalysis-pro.test/neuron-test).')[0].inline, [
        { type: 'text', text: 'See ' },
        { type: 'link', text: 'https://example.com/a?b=1', href: 'https://example.com/a?b=1' },
        { type: 'text', text: '. Or (' },
        { type: 'link', text: 'http://katalysis-pro.test/neuron-test', href: 'http://katalysis-pro.test/neuron-test' },
        { type: 'text', text: ').' },
    ]);
});

test('only http(s) URLs and email addresses are auto-linked', () => {
    for (const written of ['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com/x', 'file:///etc/passwd', 'www.example.com']) {
        assert.deepEqual(pieces(parse(`go ${written} now`)).map((p) => p.type), ['text'], written);
    }
});

test('a URL or address inside raw HTML is not auto-linked: it stays text as written', () => {
    for (const written of ['<a href="https://example.com/">x</a>', "<img src='https://example.com/i.jpg'>", '<form action=https://example.com/>', 'mail=a@b.example']) {
        const blocks = parse(written);
        assert.deepEqual(pieces(blocks).map((p) => p.type), ['text'], written);
        assert.equal(visibleText(blocks), written);
    }
});

test('an auto-link cannot carry markup: it stops at a quote, < or >', () => {
    const blocks = parse('https://example.com/"onmouseover="x <b>');
    const link = pieces(blocks).find((p) => p.type === 'link');
    assert.equal(link.href, 'https://example.com/');
    assert.equal(visibleText(blocks), 'https://example.com/"onmouseover="x <b>');
});

test('no auto-link inside code or inside a markdown link', () => {
    assert.deepEqual(parse('`https://example.com/`'), [{ type: 'line', inline: [{ type: 'code', text: 'https://example.com/' }] }]);
    assert.deepEqual(parse('[a@b.example](https://example.com/)')[0].inline, [{ type: 'link', text: 'a@b.example', href: 'https://example.com/' }]);
});

test('a numbered list with indented sub-items, loose (blank lines between items)', () => {
    const blocks = parse('People:\n\n1. **Robert Ashworth**\n   - **Job Title:** Senior Partner\n   - **Email:** r.ashworth@example.com\n\n2. **David Pemberton**\n   - Phone: +44 20 7123 4569\n\nThat is all.');
    assert.deepEqual(blocks.map((b) => b.type), ['line', 'line', 'list', 'line', 'line']);
    const list = blocks[2];
    assert.equal(list.ordered, true);
    assert.equal(list.start, 1);
    assert.equal(list.items.length, 2, 'the blank line between items does not end the list');
    assert.deepEqual(list.items[0].inline, [{ type: 'strong', text: 'Robert Ashworth' }]);
    assert.deepEqual(list.items[0].items, [
        [{ type: 'strong', text: 'Job Title:' }, { type: 'text', text: ' Senior Partner' }],
        [{ type: 'strong', text: 'Email:' }, { type: 'text', text: ' ' }, { type: 'link', text: 'r.ashworth@example.com', href: 'mailto:r.ashworth@example.com' }],
    ]);
    assert.deepEqual(list.items[1].items, [[{ type: 'text', text: 'Phone: +44 20 7123 4569' }]]);
});

test('a numbered list keeps its first number', () => {
    const [list] = parse('3. third\n4. fourth');
    assert.equal(list.ordered, true);
    assert.equal(list.start, 3);
    assert.equal(list.items.length, 2);
});

test('sub-items by tab or two or more spaces, with - or *', () => {
    const [list] = parse('- top\n\t- by tab\n  * by spaces');
    assert.equal(list.ordered, false);
    assert.deepEqual(list.items[0].items, [[{ type: 'text', text: 'by tab' }], [{ type: 'text', text: 'by spaces' }]]);
});

test('a bullet list and a numbered list next to each other are two lists', () => {
    assert.deepEqual(parse('- a\n1. b').map((b) => [b.type, b.ordered]), [['list', false], ['list', true]]);
});

test('an indented item with no list before it starts a bullet list', () => {
    const [list] = parse('   - alone');
    assert.equal(list.type, 'list');
    assert.equal(list.ordered, false);
    assert.deepEqual(list.items[0].inline, [{ type: 'text', text: 'alone' }]);
});

test('image markdown is a safe link labelled with the alt text, never an image', () => {
    assert.deepEqual(parse('![Robert Ashworth](https://example.com/person-1.jpg)')[0].inline, [
        { type: 'link', text: 'Robert Ashworth', href: 'https://example.com/person-1.jpg' },
    ]);
    assert.deepEqual(parse('![](https://example.com/p.jpg)')[0].inline, [
        { type: 'link', text: 'https://example.com/p.jpg', href: 'https://example.com/p.jpg' },
    ], 'no alt text: the URL is the label');
    for (const url of ['javascript:alert(1)', 'data:image/png;base64,AAAA']) {
        const written = `![x](${url})`;
        const blocks = parse(written);
        assert.ok(!pieces(blocks).some((p) => p.type === 'link'), written);
        assert.equal(visibleText(blocks), written);
    }
});

test('the people reply from BF-11 check 4 renders as a numbered list of people', () => {
    const reply = 'Here is a list of active people:\n\n1. **Robert Ashworth**\n   - **Job Title:** Senior Partner\n   - **Email:** r.ashworth@example.com\n   - ![Image](https://example.com/application/files/7817/8231/1835/person-1.jpg)\n\n2. **David Pemberton**\n   - **Job Title:** Managing Director\n   - **Email:** d.pemberton@example.com\n\nLet me know if you need more details!';
    const blocks = parse(reply);
    assert.deepEqual(blocks.map((b) => b.type), ['line', 'line', 'list', 'line', 'line']);
    const people = blocks[2];
    assert.equal(people.ordered, true);
    assert.deepEqual(people.items.map((item) => item.inline[0].text), ['Robert Ashworth', 'David Pemberton']);
    const links = people.items.flatMap(itemPieces).filter((p) => p.type === 'link');
    assert.deepEqual(links.map((l) => [l.text, l.href]), [
        ['r.ashworth@example.com', 'mailto:r.ashworth@example.com'],
        ['Image', 'https://example.com/application/files/7817/8231/1835/person-1.jpg'],
        ['d.pemberton@example.com', 'mailto:d.pemberton@example.com'],
    ]);
});

test('renderInto builds a numbered list with a nested list, and the first number when it is not 1', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');
    renderInto(container, parse('3. **Robert**\n   - r@example.com\n4. David'), doc);

    const ol = doc.made.find((el) => el.tag === 'ol');
    assert.ok(ol, 'an <ol>');
    assert.deepEqual(ol.attributes, { start: '3' });
    assert.equal(ol.children.length, 2);
    const nested = ol.children[0].children.find((c) => c.tag === 'ul');
    assert.ok(nested, 'the sub-items are a <ul> inside the first <li>');
    const a = doc.made.find((el) => el.tag === 'a');
    assert.deepEqual(a.attributes, { href: 'mailto:r@example.com', target: '_blank', rel: 'noopener noreferrer' });
    assert.equal(doc.made.some((el) => el.tag === 'img'), false);
});

// ---- renderInto(): nodes only, never markup -------------------------------------------------------------------

/** A fake document that records what is made and throws if markup is ever written. */
function fakeDocument() {
    const made = [];
    const node = (tag) => {
        const el = {
            tag, children: [], attributes: {}, text: null,
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

test('renderInto builds only the allowed elements, with safe link attributes, and never writes markup', () => {
    const doc = fakeDocument();
    const container = doc.createElement('div');
    const all = Object.values(PROOF_PAYLOADS).join('\n') + '\n**b** *i* `c` [ok](https://example.com/) [bad](javascript:x)\n- item';

    renderInto(container, parse(all), doc);

    const tags = new Set(doc.made.map((el) => el.tag));
    for (const tag of tags) {
        assert.ok(['div', 'strong', 'em', 'code', 'a', 'br', 'ul', 'li'].includes(tag), `unexpected element <${tag}>`);
    }
    const links = doc.made.filter((el) => el.tag === 'a');
    assert.equal(links.length, 1, 'only the safe link becomes an <a>');
    assert.deepEqual(links[0].attributes, { href: 'https://example.com/', target: '_blank', rel: 'noopener noreferrer' });
    for (const el of doc.made) {
        if (el.tag !== 'a') {
            assert.deepEqual(el.attributes, {}, `<${el.tag}> gets no attributes`);
        }
    }
    assert.ok(container.textContent.includes('<img src=x onerror='), 'the payload is visible as text');
});
