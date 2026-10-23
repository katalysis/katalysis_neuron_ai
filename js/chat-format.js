/**
 * Neuron AI chat panel: the reply formatter (BF-11, plans/BF-11.md §5).
 *
 * Replies come from the model, which reads page content that anyone with edit access can write. Nothing in a reply
 * may become markup: parse() turns the text into data, never HTML, and renderInto() builds the nodes with
 * createElement and textContent only. Links are kept only for http(s), mailto and relative URLs. There are no images.
 *
 * Loaded before chat-panel.js as window.NeuronChatFormat; under Node (tests/js/) it is module.exports.
 */
(function (root, factory) {
    'use strict';
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.NeuronChatFormat = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const ALLOWED_PROTOCOLS = ['http:', 'https:', 'mailto:'];
    // Only to resolve relative URLs for the check; never part of a link.
    const CHECK_BASE = 'https://base.invalid/';

    // One pass over a line, earliest match first: image markdown (kept as text), code, a link, bold, italic.
    const INLINE = /!\[[^\]]*\]\([^)]*\)|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|\*(.+?)\*/g;

    /**
     * The URL as written if it is a link the panel may make, otherwise null. The check uses the WHATWG URL parser,
     * the one browsers use, so leading spaces, control characters, tabs or newlines in the scheme and mixed case are
     * read as the browser would read them.
     */
    function safeHref(url) {
        if (typeof url !== 'string' || url === '') {
            return null;
        }
        let parsed;
        try {
            parsed = new URL(url, CHECK_BASE);
        } catch (e) {
            return null;
        }

        return ALLOWED_PROTOCOLS.includes(parsed.protocol) ? url : null;
    }

    function pushText(pieces, text) {
        if (text === '') {
            return;
        }
        const last = pieces[pieces.length - 1];
        if (last && last.type === 'text') {
            last.text += text;
        } else {
            pieces.push({ type: 'text', text });
        }
    }

    function parseInline(line) {
        const pieces = [];
        let at = 0;
        INLINE.lastIndex = 0;
        let match;
        while ((match = INLINE.exec(line)) !== null) {
            pushText(pieces, line.slice(at, match.index));
            const [written, code, linkText, linkUrl, bold, italic] = match;
            if (code !== undefined) {
                pieces.push({ type: 'code', text: code });
            } else if (linkText !== undefined) {
                const href = safeHref(linkUrl);
                if (href === null) {
                    // A refused link is shown as it was written.
                    pushText(pieces, written);
                } else {
                    pieces.push({ type: 'link', text: linkText, href });
                }
            } else if (bold !== undefined) {
                pieces.push({ type: 'strong', text: bold });
            } else if (italic !== undefined) {
                pieces.push({ type: 'em', text: italic });
            } else {
                // Image markdown: never an image, never fetched. Shown as written.
                pushText(pieces, written);
            }
            at = match.index + written.length;
        }
        pushText(pieces, line.slice(at));

        return pieces;
    }

    /**
     * The text as blocks: {type: 'line', inline} and {type: 'list', items} for consecutive "- " lines. Inline pieces
     * are {type: 'text' | 'strong' | 'em' | 'code', text} and {type: 'link', text, href}. Never HTML.
     */
    function parse(text) {
        if (typeof text !== 'string' || text === '') {
            return [];
        }
        const blocks = [];
        for (const line of text.split(/\r\n|\r|\n/)) {
            if (line.startsWith('- ')) {
                const last = blocks[blocks.length - 1];
                const item = parseInline(line.slice(2));
                if (last && last.type === 'list') {
                    last.items.push(item);
                } else {
                    blocks.push({ type: 'list', items: [item] });
                }
            } else {
                blocks.push({ type: 'line', inline: parseInline(line) });
            }
        }

        return blocks;
    }

    function renderInline(parent, pieces, doc) {
        for (const piece of pieces) {
            if (piece.type === 'link') {
                const href = safeHref(piece.href);
                if (href === null) {
                    parent.appendChild(doc.createTextNode(piece.text));
                    continue;
                }
                const a = doc.createElement('a');
                a.setAttribute('href', href);
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener noreferrer');
                a.textContent = piece.text;
                parent.appendChild(a);
            } else if (piece.type === 'strong' || piece.type === 'em' || piece.type === 'code') {
                const el = doc.createElement(piece.type);
                el.textContent = piece.text;
                parent.appendChild(el);
            } else {
                parent.appendChild(doc.createTextNode(String(piece.text)));
            }
        }
    }

    /**
     * Appends the blocks to the container as nodes. Lines are separated by <br>, as the panel always showed them.
     */
    function renderInto(container, blocks, doc) {
        doc = doc || container.ownerDocument || document;
        let previousWasLine = false;
        for (const block of blocks) {
            if (block.type === 'list') {
                const ul = doc.createElement('ul');
                for (const item of block.items) {
                    const li = doc.createElement('li');
                    renderInline(li, item, doc);
                    ul.appendChild(li);
                }
                container.appendChild(ul);
                previousWasLine = false;
            } else {
                if (previousWasLine) {
                    container.appendChild(doc.createElement('br'));
                }
                renderInline(container, block.inline, doc);
                previousWasLine = true;
            }
        }
    }

    return { parse, safeHref, renderInto };
});
