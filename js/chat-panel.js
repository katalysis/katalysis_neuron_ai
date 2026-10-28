/**
 * Neuron AI Chat Panel JavaScript
 */
(function() {
    'use strict';
    
    const NeuronChat = {
        panel: null,
        messages: null,
        input: null,
        sendBtn: null,
        loadBtn: null,
        newBtn: null,
        deleteCurrentBtn: null,
        dashboardToggleBtn: null,
        isListView: false,
        // ID of the saved chat the chat view shows, or null when unknown or none (a new chat
        // has no ID until its first message). See resolveShownChatId().
        shownChatId: null,
        // P3.6-1: the approval cards shown, by call ID: { card, buttons }.
        approvalCards: {},

        // Shows a failed response in the chat. A 403 with code 'invalid_token' means the
        // panel's CSRF token is missing or expired (tokens last 24 hours), so say what
        // to do. That message isn't saved: after the reload it would be stale.
        showFailure(data, prefix) {
            if (data && data.code === 'invalid_token') {
                this.addMessage('Your session has expired \u2014 please reload the page.', 'assistant', false);
                return;
            }
            this.addMessage(prefix + ((data && data.error) || 'Unknown error'), 'assistant');
        },

        // Headers for POST requests: JSON plus the CSRF token the panel element carries.
        postHeaders() {
            return {
                'Content-Type': 'application/json',
                'X-Neuron-Token': (this.panel && this.panel.getAttribute('data-csrf-token')) || ''
            };
        },
        
        init() {
            this.panel = document.getElementById('neuron-chat-panel');
            this.messages = document.getElementById('neuron-chat-messages');
            this.input = document.getElementById('neuron-chat-input');
            this.sendBtn = document.getElementById('neuron-chat-send');
            this.loadBtn = document.getElementById('neuron-chat-load');
            this.newBtn = document.getElementById('neuron-chat-new');
            this.deleteCurrentBtn = document.getElementById('neuron-chat-delete-current');
            
            if (!this.panel) return;

            this.ensurePanelPlacement();
            
            this.bindEvents();
            this.updateDeleteCurrentVisibility();
            this.loadState();
            this.watchDashboardPanel();
            this.injectDashboardButton();
        },

        ensurePanelPlacement() {
            this.panel.classList.add('ccm-ui');

            // Keep the panel outside themed wrappers so Concrete admin styles apply cleanly.
            const wrapper = document.getElementById('wrapper');
            const insideWrapper = wrapper && wrapper.contains(this.panel);
            if (insideWrapper && document.body) {
                document.body.appendChild(this.panel);
            }
        },
        
        bindEvents() {
            // Load saved chats
            if (this.loadBtn) {
                this.loadBtn.addEventListener('click', () => this.loadSavedChats());
            }
            
            // Create new chat
            if (this.newBtn) {
                this.newBtn.addEventListener('click', () => this.createNewChat());
            }

            // Delete current chat
            if (this.deleteCurrentBtn) {
                this.deleteCurrentBtn.addEventListener('click', () => this.deleteCurrentChat());
            }
            
            // Send message
            this.sendBtn.addEventListener('click', () => this.sendMessage());
            
            // Send on Enter (but Shift+Enter for new line)
            this.input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        },
        
        toggle() {
            this.panel.classList.toggle('neuron-chat-collapsed');
            this.panel.classList.toggle('neuron-chat-expanded');
            
            // Save state
            const isExpanded = this.panel.classList.contains('neuron-chat-expanded');
            localStorage.setItem('neuron-chat-expanded', isExpanded);
            
            // Update dashboard button state
            this.updateDashboardButtonState();
        },
        
        loadState() {
            // Check if initial state was already applied by inline script
            const initialStateApplied = this.panel.getAttribute('data-initial-state-applied');
            
            if (!initialStateApplied) {
                // Fallback: apply state from localStorage if inline script didn't run
                const savedState = localStorage.getItem('neuron-chat-expanded');
                const isExpanded = savedState === null ? true : savedState === 'true';
                
                if (isExpanded) {
                    this.panel.classList.remove('neuron-chat-collapsed');
                    this.panel.classList.add('neuron-chat-expanded');
                } else {
                    this.panel.classList.remove('neuron-chat-expanded');
                    this.panel.classList.add('neuron-chat-collapsed');
                }
            }
            
            // Restore the current session chat from the backend.
            // Fallback to localStorage only if backend load fails.
            this.loadCurrentChat();
            
            // Update dashboard button state
            this.updateDashboardButtonState();
            this.updateDeleteCurrentVisibility();
        },

        async loadCurrentChat() {
            try {
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/current', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });

                const data = await response.json();
                if (!data.success) {
                    this.restoreMessages();
                    return;
                }

                this.messages.innerHTML = '';
                this.shownChatId = data.chat ? data.chat.id : null;

                const history = this.extractHistoryArray(data.chat ? data.chat.chatHistory : null);
                if (history.length === 0) {
                    this.showWelcomeMessage();
                    localStorage.removeItem('neuron-chat-history');
                    this.updateDeleteCurrentVisibility();
                    return;
                }

                history.forEach((msg) => {
                    const normalizedContent = this.normalizeMessageContent(msg ? msg.content : '');
                    const role = msg && msg.role === 'user' ? 'user' : 'assistant';
                    if (normalizedContent) {
                        this.addMessage(normalizedContent, role, false, msg ? msg.notice : null, msg ? msg.changes : null);
                    }
                });
                // P3.6-1: calls still waiting for a decision.
                this.showApprovalCards(data.chat ? data.chat.approvals : null);

                // Keep local fallback cache aligned with this session only.
                localStorage.setItem('neuron-chat-history', JSON.stringify(
                    history.map((msg) => ({
                        content: this.normalizeMessageContent(msg ? msg.content : ''),
                        type: msg && msg.role === 'user' ? 'user' : 'assistant',
                        timestamp: Date.now()
                    })).filter((msg) => msg.content)
                ));
                this.updateDeleteCurrentVisibility();
            } catch (error) {
                this.restoreMessages();
            }

            this.afterChangeReload();
        },

        extractHistoryArray(chatHistory) {
            if (!chatHistory) {
                return [];
            }

            if (Array.isArray(chatHistory)) {
                return chatHistory;
            }

            if (typeof chatHistory === 'string') {
                try {
                    const parsed = JSON.parse(chatHistory);
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }

            return [];
        },
        
        // Replaces the messages area with the saved history, if there is any. Returns whether it did.
        restoreMessages() {
            let restored = false;
            try {
                const savedMessages = localStorage.getItem('neuron-chat-history');
                const messages = savedMessages ? JSON.parse(savedMessages) : null;
                if (Array.isArray(messages) && messages.length > 0) {

                    // Clear current messages except welcome message
                    const welcomeMsg = this.messages.querySelector('.neuron-chat-message:first-child');
                    this.messages.innerHTML = '';
                    if (welcomeMsg) {
                        this.messages.appendChild(welcomeMsg.cloneNode(true));
                    }
                    
                    // Restore all saved messages
                    messages.forEach(msg => {
                        this.addMessage(msg.content, msg.type, false); // false = don't save to localStorage again
                    });
                    restored = true;
                }
            } catch (error) {
                console.error('Error restoring chat history:', error);
            }

            this.updateDeleteCurrentVisibility();
            return restored;
        },
        
        saveMessageToStorage(content, type) {
            try {
                const savedMessages = localStorage.getItem('neuron-chat-history');
                const messages = savedMessages ? JSON.parse(savedMessages) : [];
                
                messages.push({
                    content: content,
                    type: type,
                    timestamp: Date.now()
                });
                
                // Keep only last 50 messages to prevent localStorage bloat
                const trimmedMessages = messages.slice(-50);
                localStorage.setItem('neuron-chat-history', JSON.stringify(trimmedMessages));
            } catch (error) {
                console.error('Error saving message to localStorage:', error);
            }
        },
        
        async sendMessage() {
            const message = this.input.value.trim();
            if (!message) return;
            
            // Add user message to UI
            this.addMessage(message, 'user');
            this.input.value = '';
            
            // Show loading indicator
            this.showLoading();
            
            // Disable input
            this.setLoading(true);
            
            try {
                const pageContext = this.getPageContext();

                // Send to backend
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/send_message', {
                    method: 'POST',
                    headers: this.postHeaders(),
                    body: JSON.stringify({ message, pageContext, openBlockIDs: this.getOpenEditorBlockIDs() })
                });

                const contentType = response.headers.get('content-type') || '';
                let data;

                if (contentType.indexOf('application/json') !== -1) {
                    data = await response.json();
                } else {
                    // The status only: the body of an error page can hold a path or a stack trace (P2.6).
                    throw new Error('Server returned ' + response.status + ' ' + response.statusText + '.');
                }
                
                // Remove loading indicator
                this.hideLoading();
                
                if (data.success) {
                    this.showTurn(data);
                } else {
                    this.showFailure(data, 'Error: ');
                }
            } catch (error) {
                this.hideLoading();
                this.addMessage('Error: Could not connect to AI assistant. ' + error.message, 'assistant');
            } finally {
                this.setLoading(false);
            }
        },

        // A turn's or a decision's answer: the cards it settled, the reply with its notice and "Changed" line (wording the
        // server wrote, null when there is none, shown as it comes), and any calls now waiting for a decision (P3.6-1).
        showTurn(data) {
            this.settleApprovalCards(data.settledApprovals);
            if (typeof data.response === 'string') {
                // The "waiting for your approval" reply isn't stored by the server, so it isn't kept here either.
                this.addMessage(data.response, 'assistant', !data.awaitingApproval, data.notice, data.changesLine);
            }
            this.showApprovalCards(data.approvals);
            // Also after a failed turn: a write may have happened before it failed (BF-7).
            this.showChangesToThisPage(data.changes);
        },

        // P3.6-1: one card per call waiting for a decision, each with its own Approve and Deny buttons (chat-format.js
        // builds it as text only). A card already shown is left as it is.
        showApprovalCards(cards) {
            if (!Array.isArray(cards)) {
                return;
            }
            cards.forEach((card) => {
                const already = card && this.approvalCards[card.callId];
                // A card the view dropped (a cleared or reloaded chat) is shown again.
                if (!card || typeof card.callId !== 'string' || (already && already.card.isConnected)) {
                    return;
                }
                const shown = window.NeuronChatFormat.renderApprovalCard(this.messages, card, document);
                this.approvalCards[card.callId] = shown;
                if (shown.buttons) {
                    shown.buttons.approve.addEventListener('click', () => this.decide(card.callId, 'approve'));
                    shown.buttons.deny.addEventListener('click', () => this.decide(card.callId, 'deny'));
                }
            });
            this.scrollToBottom();
        },

        // Replaces a card's buttons with the server's status text.
        settleApprovalCards(cards) {
            if (!Array.isArray(cards)) {
                return;
            }
            cards.forEach((card) => {
                const shown = card && this.approvalCards[card.callId];
                if (shown && shown.buttons && card.status !== 'pending') {
                    this.replaceApprovalButtons(shown, typeof card.statusText === 'string' ? card.statusText : '');
                }
            });
        },

        replaceApprovalButtons(shown, statusText) {
            const actions = shown.buttons.approve.parentNode;
            if (actions) {
                actions.replaceWith(window.NeuronChatFormat.approvalStatus(document, statusText));
            }
            shown.buttons = null;
        },

        async decide(callId, decision) {
            const shown = this.approvalCards[callId];
            if (shown && shown.buttons) {
                shown.buttons.approve.disabled = true;
                shown.buttons.deny.disabled = true;
            }
            this.showLoading();
            this.setLoading(true);

            try {
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/decide', {
                    method: 'POST',
                    headers: this.postHeaders(),
                    body: JSON.stringify({ callId, decision, pageContext: this.getPageContext(), openBlockIDs: this.getOpenEditorBlockIDs() })
                });
                const contentType = response.headers.get('content-type') || '';
                if (contentType.indexOf('application/json') === -1) {
                    throw new Error('Server returned ' + response.status + ' ' + response.statusText + '.');
                }
                const data = await response.json();
                this.hideLoading();

                if (data.success) {
                    // Decided while another call in the batch waits: this card shows its decision now.
                    this.settleApprovalCards(data.approvals);
                    this.showTurn(data);
                } else if (data.code === 'not_waiting' && shown && shown.buttons) {
                    this.replaceApprovalButtons(shown, data.error || '');
                } else {
                    this.showFailure(data, 'Error: ');
                    if (shown && shown.buttons) {
                        shown.buttons.approve.disabled = false;
                        shown.buttons.deny.disabled = false;
                    }
                }
            } catch (error) {
                this.hideLoading();
                this.addMessage('Error: Could not connect to AI assistant. ' + error.message, 'assistant');
                if (shown && shown.buttons) {
                    shown.buttons.approve.disabled = false;
                    shown.buttons.deny.disabled = false;
                }
            } finally {
                this.setLoading(false);
            }
        },

        // BF-7: when the assistant changed the page this panel is on, reload it so the change shows, as a manual edit
        // would (in edit mode too: the page stays in edit mode). Never over an open block editor: say what to do instead.
        showChangesToThisPage(changes) {
            const current = this.getCurrentPageId();
            if (!current || !Array.isArray(changes) || !changes.some((c) => c && Number(c.pageID) === current)) {
                return;
            }

            if (this.isEditorOpen()) {
                this.addPageNotice('The assistant changed this page. Save or cancel your edit, then reload the page to see the change. '
                    + 'If saving fails, cancel, reload and edit again.', true);
                return;
            }

            this.addPageNotice('Updated: reloading to show the change.', false);
            setTimeout(() => {
                // An editor opened while the notice showed: don't reload over it.
                if (this.isEditorOpen()) {
                    this.addPageNotice('Save or cancel your edit, then reload the page to see the change.', true);
                    return;
                }
                this.reloadForChange();
            }, 1500);
        },

        reloadForChange() {
            try {
                sessionStorage.setItem('neuron-chat-reloaded', '1');
                sessionStorage.setItem('neuron-chat-draft', this.input ? this.input.value : '');
            } catch (e) {
                // Private mode or storage off: the reload still happens, without the notice or the draft.
            }
            window.location.reload();
        },

        // After a reload for a change: one line saying why, and the message the user was typing.
        afterChangeReload() {
            let reloaded = null;
            let draft = '';
            try {
                reloaded = sessionStorage.getItem('neuron-chat-reloaded');
                draft = sessionStorage.getItem('neuron-chat-draft') || '';
                sessionStorage.removeItem('neuron-chat-reloaded');
                sessionStorage.removeItem('neuron-chat-draft');
            } catch (e) {
                return;
            }
            if (!reloaded) {
                return;
            }
            this.addPageNotice('Reloaded to show the assistant\'s change.', false);
            if (draft && this.input && !this.input.value) {
                this.input.value = draft;
            }
        },

        // A notice about the page, under the conversation. Not saved: it is about this view of the page only.
        addPageNotice(text, withReloadButton) {
            const notice = document.createElement('div');
            notice.className = 'neuron-chat-notice neuron-chat-page-notice';
            notice.setAttribute('role', 'status');
            notice.textContent = text;
            if (withReloadButton) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-secondary neuron-chat-reload';
                button.textContent = 'Reload now';
                button.addEventListener('click', () => this.reloadForChange());
                notice.appendChild(document.createTextNode(' '));
                notice.appendChild(button);
            }
            this.messages.appendChild(notice);
            this.scrollToBottom();
        },

        isVisible(el) {
            return !!el && window.getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0;
        },

        // Is a Concrete editor open that a reload would lose? Read from the page each time (Concrete 9.5.5, cms.js):
        // - inline editing, or a block being added inline: .ccm-block-edit-inline-active / .ccm-area-edit-inline-active
        // - a dialog (block edit, design, permissions, add block...): a visible jQuery UI .ui-dialog
        // - a detail panel with a form (attributes, SEO, location, the Publish panel's comment): a visible .ccm-panel-detail
        isEditorOpen() {
            if (document.querySelector('.ccm-block-edit-inline-active, .ccm-area-edit-inline-active')) {
                return true;
            }
            const visible = (selector) => Array.from(document.querySelectorAll(selector)).some((el) => this.isVisible(el));

            return visible('.ui-dialog') || visible('.ccm-panel-detail');
        },

        // The blocks an editor is open on (BF-7), so the assistant leaves them alone: the block an inline edit is
        // running on (its data-block-id), and the bID in the action of an open block form (Concrete's block controller
        // adds &bID= to it, for the edit dialog and inline editing alike). The server keeps only positive integers.
        getOpenEditorBlockIDs() {
            const ids = new Set();
            const add = (value) => {
                const id = parseInt(value, 10);
                if (Number.isFinite(id) && id > 0) {
                    ids.add(id);
                }
            };
            document.querySelectorAll('[data-block-id].ccm-block-edit-inline-active').forEach((el) => add(el.getAttribute('data-block-id')));
            document.querySelectorAll('form#ccm-block-form, .ui-dialog form').forEach((form) => {
                if (!form.closest('.ccm-block-edit-inline-active') && !this.isVisible(form)) {
                    return;
                }
                const match = (form.getAttribute('action') || '').match(/[?&]bID=(\d+)/);
                if (match) {
                    add(match[1]);
                }
            });

            return Array.from(ids);
        },

        getPageContext() {
            const path = typeof window.location.pathname === 'string' ? window.location.pathname : null;
            const title = typeof document.title === 'string' ? document.title : null;
            const url = typeof window.location.href === 'string' ? window.location.href : null;
            const pageId = this.getCurrentPageId();

            return {
                id: pageId,
                path,
                title,
                url
            };
        },

        getCurrentPageId() {
            // Concrete prints CCM_CID on the pages it renders (BF-1). It wins over the classes below if they disagree.
            // The server checks the user can view whatever ID is sent.
            const cid = parseInt(window.CCM_CID, 10);
            if (Number.isFinite(cid) && cid > 0) {
                return cid;
            }

            // Themes put ccm-page-id-<id> on the .ccm-page wrapper, not on <body>.
            const wrapper = document.querySelector('.ccm-page');
            const wrapperMatch = wrapper && typeof wrapper.className === 'string'
                ? wrapper.className.match(/(?:^|\s)ccm-page-id-(\d+)(?:\s|$)/i)
                : null;
            if (wrapperMatch && wrapperMatch[1]) {
                const wrapperId = parseInt(wrapperMatch[1], 10);
                if (Number.isFinite(wrapperId) && wrapperId > 0) {
                    return wrapperId;
                }
            }

            if (!document.body || typeof document.body.className !== 'string') {
                return null;
            }

            // Concrete commonly includes either page-id-<id> or ccm-page-id-<id> on body classes.
            const match = document.body.className.match(/(?:^|\s)(?:ccm-)?page-id-(\d+)(?:\s|$)/i);
            if (!match || !match[1]) {
                const attrValue = document.body.getAttribute('data-page-id') || document.documentElement.getAttribute('data-page-id');
                if (!attrValue) {
                    return null;
                }

                const attrId = parseInt(attrValue, 10);
                return Number.isFinite(attrId) && attrId > 0 ? attrId : null;
            }

            const id = parseInt(match[1], 10);
            return Number.isFinite(id) && id > 0 ? id : null;
        },
        
        async createNewChat() {
            if (this.isListView) {
                // If in list view, just switch to chat view
                this.showChatView();
                return;
            }
            
            // Check if there are any messages (beyond the welcome message)
            const messageCount = this.messages.querySelectorAll('.neuron-chat-message').length;
            
            if (messageCount > 1 && !confirm('Start a new chat? Current conversation will be saved.')) {
                return;
            }
            
            try {
                // Call backend to create a new chat session
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/new', {
                    method: 'POST',
                    headers: this.postHeaders()
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Clear the current chat interface
                    this.messages.innerHTML = '';

                    // Ensure only the new conversation is restored on page reload.
                    localStorage.removeItem('neuron-chat-history');
                    this.shownChatId = null;

                    // Show welcome message
                    this.showWelcomeMessage();
                    this.updateDeleteCurrentVisibility();
                    
                    // Clear input
                    this.input.value = '';
                } else {
                    this.showFailure(data, 'Error creating new chat: ');
                }
            } catch (error) {
                this.addMessage('Error creating new chat: ' + error.message, 'assistant');
            }
        },

        async deleteCurrentChat() {
            if (!confirm('Delete the current chat conversation?')) {
                return;
            }

            try {
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/delete_current', {
                    method: 'POST',
                    headers: this.postHeaders()
                });

                const data = await response.json();
                if (!data.success) {
                    this.showFailure(data, 'Error deleting current chat: ');
                    return;
                }

                this.messages.innerHTML = '';
                localStorage.removeItem('neuron-chat-history');
                this.shownChatId = null;
                this.showWelcomeMessage();
                this.updateDeleteCurrentVisibility();
            } catch (error) {
                this.addMessage('Error deleting current chat: ' + error.message, 'assistant');
            }
        },

        showWelcomeMessage() {
            this.addMessage(
                'Hello! I\'m your AI assistant for Concrete CMS. What would you like to do?',
                'assistant',
                false
            );
        },
        
        async loadSavedChats() {
            if (this.isListView) {
                // Switch back to chat view
                this.showChatView();
            } else {
                // Switch to list view
                await this.showChatListView();
            }
        },
        
        showChatView() {
            this.isListView = false;
            
            // Update load button icon and title
            const loadIcon = this.loadBtn.querySelector('i');
            if (loadIcon) {
                loadIcon.className = 'fas fa-folder-open';
            }
            this.loadBtn.title = 'Load saved chats';
            
            // Show chat interface
            this.panel.classList.remove('neuron-chat-list-mode');
            
            // Restore messages from localStorage. With none saved (a new or deleted chat), the
            // list must still go: show the welcome message instead (BF-4).
            if (!this.restoreMessages()) {
                this.messages.innerHTML = '';
                this.showWelcomeMessage();
            }
            this.updateDeleteCurrentVisibility();
        },
        
        async showChatListView() {
            this.isListView = true;
            this.updateDeleteCurrentVisibility();
            
            // Update load button icon and title
            const loadIcon = this.loadBtn.querySelector('i');
            if (loadIcon) {
                loadIcon.className = 'fas fa-comments';
            }
            this.loadBtn.title = 'Back to chat';
            
            // Add list mode class
            this.panel.classList.add('neuron-chat-list-mode');
            
            // Clear current messages area and show loading
            this.messages.innerHTML = '<div class="neuron-chat-list-loading"><i class="fas fa-spinner fa-spin"></i> Loading chats...</div>';
            
            try {
                // Fetch chats list from backend
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/list', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const data = await response.json();
                
                if (data.success && data.chats) {
                    this.renderChatList(data.chats);
                } else {
                    this.messages.innerHTML = '<div class="neuron-chat-list-empty">No saved chats found.</div>';
                }
            } catch (error) {
                // As text (BF-11): a JSON parse error's message quotes the start of the server's reply.
                const errorDiv = document.createElement('div');
                errorDiv.className = 'neuron-chat-list-error';
                errorDiv.textContent = 'Error loading chats: ' + error.message;
                this.messages.innerHTML = '';
                this.messages.appendChild(errorDiv);
            }
        },
        
        renderChatList(chats) {
            if (!chats || chats.length === 0) {
                this.messages.innerHTML = '<div class="neuron-chat-list-empty"><i class="fas fa-inbox"></i><p>No saved chats yet</p></div>';
                return;
            }
            
            // Built as nodes (BF-11): the titles and IDs come from the server.
            const element = (tag, className, text) => {
                const el = document.createElement(tag);
                el.className = className;
                if (text !== undefined) {
                    el.textContent = text;
                }
                return el;
            };
            const button = (className, title, iconClass) => {
                const btn = element('button', className);
                btn.title = title;
                btn.appendChild(element('i', iconClass));
                return btn;
            };

            const list = element('div', 'neuron-chat-list');
            chats.forEach(chat => {
                const item = element('div', 'neuron-chat-list-item');
                item.dataset.chatId = String(chat.id);

                const content = element('div', 'neuron-chat-list-item-content');
                content.appendChild(element('div', 'neuron-chat-list-item-title', String(chat.firstMessage || 'New conversation')));
                content.appendChild(element('div', 'neuron-chat-list-item-time', this.formatChatTime(chat.updatedDate || chat.createdDate)));

                const actions = element('div', 'neuron-chat-list-item-actions');
                actions.appendChild(button('neuron-chat-list-item-load', 'Load chat', 'fas fa-arrow-right'));
                actions.appendChild(button('neuron-chat-list-item-delete', 'Delete chat', 'fas fa-trash'));

                item.appendChild(content);
                item.appendChild(actions);
                list.appendChild(item);
            });

            this.messages.innerHTML = '';
            this.messages.appendChild(list);

            // Bind click handlers
            this.messages.querySelectorAll('.neuron-chat-list-item-load').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const item = e.target.closest('.neuron-chat-list-item');
                    const chatId = item.dataset.chatId;
                    this.loadChatById(chatId);
                });
            });

            this.messages.querySelectorAll('.neuron-chat-list-item-delete').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const item = e.target.closest('.neuron-chat-list-item');
                    if (!item) {
                        return;
                    }

                    const chatId = item.dataset.chatId;
                    await this.deleteChatById(chatId);
                });
            });
        },

        async deleteChatById(chatId) {
            if (!chatId) {
                return;
            }

            if (!confirm('Delete this saved chat?')) {
                return;
            }

            try {
                // Before the delete: afterwards the server can no longer say which chat the session had.
                const shownChatId = await this.resolveShownChatId();

                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/delete', {
                    method: 'POST',
                    headers: this.postHeaders(),
                    body: JSON.stringify({ id: chatId })
                });

                const data = await response.json();
                if (!data.success) {
                    this.showFailure(data, 'Error deleting chat: ');
                    return;
                }

                // The chat view showed the deleted chat: clear it, as "Delete current chat" does.
                // "Back to chat" then shows the welcome message (BF-4).
                if (shownChatId !== null && String(shownChatId) === String(chatId)) {
                    localStorage.removeItem('neuron-chat-history');
                    this.shownChatId = null;
                }

                await this.showChatListView();
            } catch (error) {
                this.addMessage('Error deleting chat: ' + error.message, 'assistant');
            }
        },

        // The ID of the chat the chat view shows. A chat started in the panel gets its ID on the
        // server with its first message, so when the view has messages but no ID, it is the
        // session's chat: ask the server. Null when the view shows no saved chat.
        async resolveShownChatId() {
            if (this.shownChatId !== null) {
                return this.shownChatId;
            }

            let saved = null;
            try {
                saved = JSON.parse(localStorage.getItem('neuron-chat-history') || 'null');
            } catch (error) {
                saved = null;
            }
            if (!Array.isArray(saved) || saved.length === 0) {
                return null;
            }

            try {
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/current', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                const data = await response.json();
                this.shownChatId = data.success && data.chat ? data.chat.id : null;
            } catch (error) {
                return null;
            }

            return this.shownChatId;
        },
        
        async loadChatById(chatId) {
            try {
                // Show loading in chat view
                this.showChatView();
                this.messages.innerHTML = '<div class="neuron-chat-list-loading"><i class="fas fa-spinner fa-spin"></i> Loading chat...</div>';
                
                // Fetch the chat from backend. Loading makes it the session's chat (BF-4), so it
                // sends the token like the POST actions.
                const response = await fetch(`/ccm/system/katalysis_neuron_ai/chat/load?id=${encodeURIComponent(chatId)}`, {
                    method: 'GET',
                    headers: this.postHeaders()
                });
                
                const data = await response.json();
                
                if (data.success && data.chat) {
                    // Clear current messages
                    this.messages.innerHTML = '';
                    
                    // Load the saved chat history
                    if (data.chat.chatHistory) {
                        const history = JSON.parse(data.chat.chatHistory);

                        if (Array.isArray(history)) {
                            history.forEach(msg => {
                                const normalizedContent = this.normalizeMessageContent(msg ? msg.content : '');
                                const role = msg && msg.role === 'user' ? 'user' : 'assistant';
                                if (normalizedContent) {
                                    this.addMessage(normalizedContent, role, false, msg ? msg.notice : null, msg ? msg.changes : null);
                                }
                            });
                            this.showApprovalCards(data.chat.approvals);

                            localStorage.setItem('neuron-chat-history', JSON.stringify(
                                history.map((msg) => ({
                                    content: this.normalizeMessageContent(msg ? msg.content : ''),
                                    type: msg && msg.role === 'user' ? 'user' : 'assistant',
                                    timestamp: Date.now()
                                })).filter((msg) => msg.content)
                            ));
                            this.shownChatId = data.chat.id;
                            this.updateDeleteCurrentVisibility();
                        } else {
                            this.addMessage('Unable to parse saved chat format for this conversation.', 'assistant', false);
                        }
                    }
                } else {
                    this.messages.innerHTML = '';
                    this.showFailure(data, 'Error: Could not load chat. ');
                }
            } catch (error) {
                this.messages.innerHTML = '';
                this.addMessage('Error loading chat: ' + error.message, 'assistant');
            }
        },
        
        formatChatTime(dateStr) {
            if (!dateStr) return '';
            
            const chatDate = new Date(dateStr);
            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            const chatDay = new Date(chatDate.getFullYear(), chatDate.getMonth(), chatDate.getDate());
            
            if (chatDay.getTime() === today.getTime()) {
                // Today - show time
                return chatDate.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            } else if (chatDay.getTime() === yesterday.getTime()) {
                // Yesterday
                return 'Yesterday';
            } else {
                // Older - show date
                const day = chatDate.getDate();
                const month = chatDate.toLocaleString('en-GB', { month: 'short' });
                const year = chatDate.getFullYear();
                const currentYear = now.getFullYear();
                
                if (year === currentYear) {
                    return `${day} ${month}`;
                } else {
                    return `${day} ${month} ${String(year).slice(-2)}`;
                }
            }
        },
        
        normalizeMessageContent(content) {
            if (typeof content === 'string') {
                return content;
            }

            if (Array.isArray(content)) {
                // Neuron content blocks: [{ type: 'text', content: '...' }, ...]
                const parts = content.map((block) => {
                    if (typeof block === 'string') {
                        return block;
                    }

                    if (block && typeof block === 'object') {
                        if (typeof block.content === 'string') {
                            return block.content;
                        }
                        if (typeof block.text === 'string') {
                            return block.text;
                        }
                    }

                    return '';
                }).filter(Boolean);

                return parts.join('\n').trim();
            }

            if (content && typeof content === 'object') {
                if (typeof content.content === 'string') {
                    return content.content;
                }
                if (typeof content.text === 'string') {
                    return content.text;
                }
            }

            return String(content || '');
        },
        
        addMessage(content, type = 'assistant', saveToStorage = true, notice = null, changesLine = null) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `neuron-chat-message neuron-chat-${type}`;

            const contentDiv = document.createElement('div');
            contentDiv.className = 'neuron-chat-content';
            
            const iconSpan = document.createElement('span');
            iconSpan.className = 'neuron-chat-inline-icon';
            const icon = document.createElement('i');
            icon.className = type === 'assistant' ? 'fas fa-robot' : 'fas fa-user';
            iconSpan.appendChild(icon);
            contentDiv.appendChild(iconSpan);

            // Never as HTML (BF-11): a reply is parsed into data and built as nodes (chat-format.js); the person's own
            // message is plain text.
            const text = this.normalizeMessageContent(content);
            if (type === 'assistant') {
                window.NeuronChatFormat.renderInto(contentDiv, window.NeuronChatFormat.parse(text), document);
            } else {
                contentDiv.appendChild(document.createTextNode(text));
            }

            messageDiv.appendChild(contentDiv);

            // The server's fixed notice about text addressed to the assistant. Plain text only (textContent), and only
            // under an assistant reply; this file holds no wording of its own for it.
            if (type === 'assistant' && typeof notice === 'string' && notice !== '') {
                const noticeDiv = document.createElement('div');
                noticeDiv.className = 'neuron-chat-notice';
                noticeDiv.setAttribute('role', 'note');
                noticeDiv.textContent = notice;
                messageDiv.appendChild(noticeDiv);
            }

            // P3.6-1: what the turn changed, as the server wrote it. Text only.
            if (type === 'assistant' && typeof changesLine === 'string' && changesLine !== '') {
                window.NeuronChatFormat.renderChangesLine(messageDiv, changesLine, document);
            }
            
            this.messages.appendChild(messageDiv);
            this.scrollToBottom();
            
            // Save to localStorage for persistence across page navigations
            if (saveToStorage) {
                this.saveMessageToStorage(content, type);
            }

            this.updateDeleteCurrentVisibility();
        },

        updateDeleteCurrentVisibility() {
            if (!this.deleteCurrentBtn) {
                return;
            }

            const hasContent = this.hasDeletableConversationContent();
            const shouldShow = hasContent && !this.isListView;
            this.deleteCurrentBtn.style.display = shouldShow ? '' : 'none';
        },

        hasDeletableConversationContent() {
            try {
                const savedMessages = localStorage.getItem('neuron-chat-history');
                if (savedMessages) {
                    const parsed = JSON.parse(savedMessages);
                    if (Array.isArray(parsed) && parsed.length > 0) {
                        return true;
                    }
                }
            } catch (error) {
                // Ignore parse issues and fall back to DOM detection.
            }

            const visibleMessages = this.messages
                ? this.messages.querySelectorAll('.neuron-chat-message:not(.neuron-chat-loading-msg)')
                : [];

            if (!visibleMessages || visibleMessages.length === 0) {
                return false;
            }

            if (visibleMessages.length > 1) {
                return true;
            }

            const onlyMessage = visibleMessages[0];
            const normalized = onlyMessage.textContent.replace(/\s+/g, ' ').trim();
            const welcome = "Hello! I'm your AI assistant for Concrete CMS. What would you like to do?";

            return normalized !== welcome;
        },
        
        showLoading() {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'neuron-chat-message neuron-chat-assistant neuron-chat-loading-msg';
            loadingDiv.id = 'neuron-chat-loading';

            const contentDiv = document.createElement('div');
            contentDiv.className = 'neuron-chat-content';
            contentDiv.innerHTML = '<span class="neuron-chat-inline-icon"><i class="fas fa-robot"></i></span><div class="neuron-chat-loading"><span></span><span></span><span></span></div>';

            loadingDiv.appendChild(contentDiv);
            
            this.messages.appendChild(loadingDiv);
            this.scrollToBottom();
        },
        
        hideLoading() {
            const loading = document.getElementById('neuron-chat-loading');
            if (loading) {
                loading.remove();
            }
        },
        
        setLoading(isLoading) {
            this.input.disabled = isLoading;
            this.sendBtn.disabled = isLoading;
        },
        
        scrollToBottom() {
            this.messages.scrollTop = this.messages.scrollHeight;
        },
        
        injectDashboardButton() {
            // Wait a bit for the toolbar to render
            setTimeout(() => {
                // Find the toolbar item list
                const toolbar = document.querySelector('.ccm-toolbar-item-list');
                
                if (!toolbar) {
                    // Toolbar not found - might not be on dashboard page
                    return;
                }
                
                // Check if button already exists
                if (document.getElementById('neuron-ai-toolbar-toggle')) {
                    return;
                }
                
                // Create toolbar item (li)
                const toolbarItem = document.createElement('li');
                toolbarItem.className = 'float-end d-none d-sm-none d-md-block';
                toolbarItem.id = 'neuron-ai-toolbar-item';
                
                // Create the link/button
                const button = document.createElement('a');
                button.id = 'neuron-ai-toolbar-toggle';
                button.className = 'launch-tooltip';
                button.href = '#';
                button.setAttribute('data-bs-toggle', 'tooltip');
                button.setAttribute('data-bs-placement', 'bottom');
                button.setAttribute('data-bs-original-title', 'Toggle AI Assistant');
                button.title = 'Toggle AI Assistant';
                
                // Add icon (using Font Awesome since SVG sprite might not have robot icon)
                button.innerHTML = '<i class="fas fa-robot"></i>' +
                    '<span class="ccm-toolbar-accessibility-title">AI Assistant</span>';
                
                // Add click handler
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.toggleFromDashboard();
                });
                
                toolbarItem.appendChild(button);
                
                // Find the search toolbar item to insert before it
                const searchItem = toolbar.querySelector('.ccm-toolbar-search');
                if (searchItem) {
                    toolbar.insertBefore(toolbarItem, searchItem);
                } else {
                    // Fallback: insert before last item (usually dashboard button)
                    const items = toolbar.querySelectorAll('li.float-end');
                    if (items.length > 0) {
                        toolbar.insertBefore(toolbarItem, items[items.length - 1]);
                    } else {
                        toolbar.appendChild(toolbarItem);
                    }
                }
                
                // Save reference
                this.dashboardToggleBtn = button;
                
                // Update button state to reflect current panel state
                this.updateDashboardButtonState();
            }, 200);
        },
        
        toggleFromDashboard() {
            this.toggle();
            this.updateDashboardButtonState();
        },
        
        updateDashboardButtonState() {
            if (!this.dashboardToggleBtn) return;
            
            const isExpanded = this.panel.classList.contains('neuron-chat-expanded');
            
            // Update button appearance based on panel state
            if (isExpanded) {
                this.dashboardToggleBtn.classList.add('active');
                this.dashboardToggleBtn.setAttribute('data-bs-original-title', 'Hide AI Assistant');
                this.dashboardToggleBtn.title = 'Hide AI Assistant';
            } else {
                this.dashboardToggleBtn.classList.remove('active');
                this.dashboardToggleBtn.setAttribute('data-bs-original-title', 'Show AI Assistant');
                this.dashboardToggleBtn.title = 'Show AI Assistant';
            }
        },
        
        watchDashboardPanel() {
            // Check initial state and set position
            this.updatePosition();
            
            // Watch for changes to dashboard panel visibility
            // Concrete CMS typically uses classes on the panel element
            const observer = new MutationObserver(() => {
                this.updatePosition();
            });
            
            // Observe the dashboard panel directly
            const dashboardPanel = document.querySelector('#ccm-panel-dashboard');
            if (dashboardPanel) {
                observer.observe(dashboardPanel, {
                    attributes: true,
                    attributeFilter: ['class', 'style']
                });

                // React during panel open/close animation, not only after class mutations settle.
                ['transitionstart', 'transitionrun', 'transitionend'].forEach((eventName) => {
                    dashboardPanel.addEventListener(eventName, () => this.updatePosition());
                });
            }
            
            // Also observe body for any CMS state changes
            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
            });
            
            // Watch for panel portal changes (where panels are injected)
            const panelPortal = document.querySelector('#ccm-panel-portal');
            if (panelPortal) {
                observer.observe(panelPortal, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
            }
            
            // Update on window resize
            window.addEventListener('resize', () => this.updatePosition());
            
            // Check periodically as fallback with a short interval to avoid lag on close.
            setInterval(() => this.updatePosition(), 250);
        },
        
        updatePosition() {
            // Detect if Concrete CMS dashboard panel is visible
            const isDashboardPanelVisible = this.isDashboardPanelVisible();
            
            // Adjust position based on dashboard panel state
            // Dashboard panel is 320px wide
            if (isDashboardPanelVisible && window.innerWidth >= 1200) {
                // Dashboard panel is open on wide screen - position to its left
                this.panel.style.right = '320px'; // 320px panel width
            } else {
                // Let stylesheet control placement when panel isn't forcing offset.
                this.panel.style.right = '';
            }
        },
        
        isDashboardPanelVisible() {
            const dashboard = document.querySelector('#ccm-panel-dashboard');
            if (!dashboard || !dashboard.classList.contains('ccm-panel-active')) {
                return false;
            }

            const style = window.getComputedStyle(dashboard);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return false;
            }

            // Only treat it as visible if this panel is actually docked on the right side.
            const rect = dashboard.getBoundingClientRect();
            const minRightDockWidth = 240;
            const isRightDocked = rect.right >= (window.innerWidth - 2)
                && rect.width >= minRightDockWidth;

            return isRightDocked;
        }
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => NeuronChat.init());
    } else {
        NeuronChat.init();
    }
    
    // Expose globally for debugging
    window.NeuronChat = NeuronChat;
})();
