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

    // One pass over a line, earliest match first: image markdown (made a link), code, a link, bold, italic.
    const INLINE = /!\[([^\]]*)\]\(([^)\s]*)\)|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|\*(.+?)\*/g;

    // Bare http(s) URLs and email addresses in plain text, only at the start or after a space or "(", as GitHub's
    // markdown does: never inside raw HTML such as href="…". A URL stops at a space, a quote, < or >.
    const AUTOLINK = /(^|[\s(])(?:(https?:\/\/[^\s<>"'`]+)|([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+))/gi;
    // Not part of a URL when it ends one: "see https://x.example/a." links to https://x.example/a.
    const TRAILING = /[.,;:!?)\]]+$/;

    // List lines: "- item", "1. item" (or "1) item"), and one level of sub-items indented by a tab or 2+ spaces.
    const BULLET = /^- (.*)$/;
    const NUMBERED = /^(\d{1,9})[.)] (.*)$/;
    const SUB_ITEM = /^(?:\t+| {2,})[-*] (.*)$/;

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

    // A markdown link, or a refused one shown as it was written.
    function pushLink(pieces, text, url, written) {
        const href = safeHref(url);
        if (href === null) {
            pushText(pieces, written);
        } else {
            pieces.push({ type: 'link', text, href });
        }
    }

    // Plain text, with its bare URLs and email addresses made links.
    function pushAutolinked(pieces, text) {
        let at = 0;
        AUTOLINK.lastIndex = 0;
        let match;
        while ((match = AUTOLINK.exec(text)) !== null) {
            const [, before, url, email] = match;
            const start = match.index + before.length;
            let found = url !== undefined ? url : email;
            if (url !== undefined) {
                found = found.replace(TRAILING, '');
            }
            const href = safeHref(url !== undefined ? found : 'mailto:' + found);
            if (href === null || found === '') {
                continue;
            }
            pushText(pieces, text.slice(at, start));
            pieces.push({ type: 'link', text: found, href });
            at = start + found.length;
            AUTOLINK.lastIndex = at;
        }
        pushText(pieces, text.slice(at));
    }

    function parseInline(line) {
        const pieces = [];
        let at = 0;
        INLINE.lastIndex = 0;
        let match;
        while ((match = INLINE.exec(line)) !== null) {
            pushAutolinked(pieces, line.slice(at, match.index));
            const [written, imageAlt, imageUrl, code, linkText, linkUrl, bold, italic] = match;
            if (imageUrl !== undefined) {
                // Image markdown: a link labelled with the alt text, never an image, never fetched.
                pushLink(pieces, imageAlt !== '' ? imageAlt : imageUrl, imageUrl, written);
            } else if (code !== undefined) {
                pieces.push({ type: 'code', text: code });
            } else if (linkText !== undefined) {
                pushLink(pieces, linkText, linkUrl, written);
            } else if (bold !== undefined) {
                pieces.push({ type: 'strong', text: bold });
            } else {
                pieces.push({ type: 'em', text: italic });
            }
            at = match.index + written.length;
        }
        pushAutolinked(pieces, line.slice(at));

        return pieces;
    }

    function listLine(line) {
        let m = BULLET.exec(line);
        if (m) {
            return { kind: 'item', ordered: false, text: m[1] };
        }
        m = NUMBERED.exec(line);
        if (m) {
            return { kind: 'item', ordered: true, start: parseInt(m[1], 10), text: m[2] };
        }
        m = SUB_ITEM.exec(line);

        return m ? { kind: 'sub', text: m[1] } : null;
    }

    /**
     * The text as blocks: {type: 'line', inline}, and {type: 'list', ordered, start, items} where each item is
     * {inline, items} and its items are the sub-items' inline pieces (one level). Inline pieces are
     * {type: 'text' | 'strong' | 'em' | 'code', text} and {type: 'link', text, href}. Never HTML.
     */
    function parse(text) {
        if (typeof text !== 'string' || text === '') {
            return [];
        }
        const lines = text.split(/\r\n|\r|\n/);
        const blocks = [];
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const last = blocks[blocks.length - 1];
            const list = last && last.type === 'list' ? last : null;
            const found = listLine(line);

            if (found && found.kind === 'item') {
                const item = { inline: parseInline(found.text), items: [] };
                if (list && list.ordered === found.ordered) {
                    list.items.push(item);
                } else {
                    blocks.push({ type: 'list', ordered: found.ordered, start: found.ordered ? found.start : 1, items: [item] });
                }
            } else if (found) {
                const sub = parseInline(found.text);
                if (list) {
                    list.items[list.items.length - 1].items.push(sub);
                } else {
                    // A sub-item with no list before it starts a bullet list.
                    blocks.push({ type: 'list', ordered: false, start: 1, items: [{ inline: sub, items: [] }] });
                }
            } else if (list && line.trim() === '' && continuesList(lines, i + 1, list)) {
                // A blank line between items (a "loose" list) doesn't end the list or restart its numbering.
            } else {
                blocks.push({ type: 'line', inline: parseInline(line) });
            }
        }

        return blocks;
    }

    // Whether the next line that isn't blank is another item of the same list, or a sub-item.
    function continuesList(lines, from, list) {
        for (let j = from; j < lines.length; j++) {
            if (lines[j].trim() === '') {
                continue;
            }
            const found = listLine(lines[j]);

            return found !== null && (found.kind === 'sub' || found.ordered === list.ordered);
        }

        return false;
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
                const list = doc.createElement(block.ordered ? 'ol' : 'ul');
                const start = Number(block.start);
                if (block.ordered && Number.isInteger(start) && start !== 1) {
                    list.setAttribute('start', String(start));
                }
                for (const item of block.items) {
                    const li = doc.createElement('li');
                    renderInline(li, item.inline, doc);
                    if (item.items.length > 0) {
                        const sub = doc.createElement('ul');
                        for (const subItem of item.items) {
                            const subLi = doc.createElement('li');
                            renderInline(subLi, subItem, doc);
                            sub.appendChild(subLi);
                        }
                        li.appendChild(sub);
                    }
                    list.appendChild(li);
                }
                container.appendChild(list);
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

    /**
     * P3.6-1: one call waiting for approval, as a card. Every text in it is the server's (ApprovalService::card()):
     * set with textContent only, never parsed. While the call waits the card ends in Approve and Deny buttons, the
     * only way to decide (typed text never approves); afterwards in the server's status text. Returns the card and
     * its buttons (null once decided), for the panel to wire.
     */
    function renderApprovalCard(container, card, doc) {
        doc = doc || container.ownerDocument || document;
        const text = (value) => (typeof value === 'string' ? value : '');
        const el = doc.createElement('div');
        el.className = 'neuron-approval-card';
        el.setAttribute('role', 'group');

        const title = doc.createElement('div');
        title.className = 'neuron-approval-title';
        const action = doc.createElement('strong');
        action.textContent = text(card.action);
        title.appendChild(action);
        const target = text(card.target) + (text(card.path) !== '' ? ' (' + text(card.path) + ')' : '');
        if (target !== '') {
            title.appendChild(doc.createTextNode(' ' + target));
        }
        el.appendChild(title);

        const details = Array.isArray(card.details) ? card.details.filter((d) => typeof d === 'string') : [];
        if (details.length > 0) {
            const list = doc.createElement('ul');
            for (const detail of details) {
                const li = doc.createElement('li');
                li.textContent = detail;
                list.appendChild(li);
            }
            el.appendChild(list);
        }
        if (text(card.note) !== '') {
            const note = doc.createElement('div');
            note.className = 'neuron-approval-note';
            note.textContent = text(card.note);
            el.appendChild(note);
        }

        let buttons = null;
        if (card.status === 'pending') {
            const actions = doc.createElement('div');
            actions.className = 'neuron-approval-actions';
            const button = (label, decision) => {
                const b = doc.createElement('button');
                b.setAttribute('type', 'button');
                b.className = 'neuron-approval-' + decision;
                b.textContent = label;
                actions.appendChild(b);
                return b;
            };
            buttons = { approve: button('Approve', 'approve'), deny: button('Deny', 'deny') };
            el.appendChild(actions);
        } else {
            el.appendChild(approvalStatus(doc, text(card.statusText)));
        }

        container.appendChild(el);

        return { card: el, buttons };
    }

    /** The decided state at the foot of a card: the server's status text. */
    function approvalStatus(doc, statusText) {
        const status = doc.createElement('div');
        status.className = 'neuron-approval-status';
        status.setAttribute('role', 'status');
        status.textContent = statusText;
        return status;
    }

    /**
     * P3.6-1: the turn's "Changed: …" line under a reply, as the server wrote it. Text only.
     */
    function renderChangesLine(container, line, doc) {
        doc = doc || container.ownerDocument || document;
        const el = doc.createElement('div');
        el.className = 'neuron-chat-changes';
        el.setAttribute('role', 'note');
        el.textContent = typeof line === 'string' ? line : '';
        container.appendChild(el);
        return el;
    }

    return { parse, safeHref, renderInto, renderApprovalCard, approvalStatus, renderChangesLine };
});
