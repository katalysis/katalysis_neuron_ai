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
        dashboardToggleBtn: null,
        isListView: false,
        
        init() {
            this.panel = document.getElementById('neuron-chat-panel');
            this.messages = document.getElementById('neuron-chat-messages');
            this.input = document.getElementById('neuron-chat-input');
            this.sendBtn = document.getElementById('neuron-chat-send');
            this.loadBtn = document.getElementById('neuron-chat-load');
            this.newBtn = document.getElementById('neuron-chat-new');
            
            if (!this.panel) return;
            
            this.bindEvents();
            this.loadState();
            this.watchDashboardPanel();
            this.injectDashboardButton();
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
            // Restore expanded/collapsed state (default to expanded on first visit)
            const savedState = localStorage.getItem('neuron-chat-expanded');
            const isExpanded = savedState === null ? true : savedState === 'true';
            
            if (isExpanded) {
                this.panel.classList.remove('neuron-chat-collapsed');
                this.panel.classList.add('neuron-chat-expanded');
            }
            
            // Restore the current session chat from the backend.
            // Fallback to localStorage only if backend load fails.
            this.loadCurrentChat();
            
            // Update dashboard button state
            this.updateDashboardButtonState();
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

                const history = this.extractHistoryArray(data.chat ? data.chat.chatHistory : null);
                if (history.length === 0) {
                    this.addMessage(
                        'Hello! I\'m your AI assistant for Concrete CMS. I can help you:\n\n' +
                        '• Create new pages\n' +
                        '• Get information about pages\n' +
                        '• Navigate your site structure\n' +
                        '• And more...\n\n' +
                        'What would you like to do?',
                        'assistant',
                        false
                    );
                    localStorage.removeItem('neuron-chat-history');
                    return;
                }

                history.forEach((msg) => {
                    const normalizedContent = this.normalizeMessageContent(msg ? msg.content : '');
                    const role = msg && msg.role === 'user' ? 'user' : 'assistant';
                    if (normalizedContent) {
                        this.addMessage(normalizedContent, role, false);
                    }
                });

                // Keep local fallback cache aligned with this session only.
                localStorage.setItem('neuron-chat-history', JSON.stringify(
                    history.map((msg) => ({
                        content: this.normalizeMessageContent(msg ? msg.content : ''),
                        type: msg && msg.role === 'user' ? 'user' : 'assistant',
                        timestamp: Date.now()
                    })).filter((msg) => msg.content)
                ));
            } catch (error) {
                this.restoreMessages();
            }
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
        
        restoreMessages() {
            try {
                const savedMessages = localStorage.getItem('neuron-chat-history');
                if (savedMessages) {
                    const messages = JSON.parse(savedMessages);
                    
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
                }
            } catch (error) {
                console.error('Error restoring chat history:', error);
            }
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
                // Send to backend
                const response = await fetch('/ccm/system/katalysis_neuron_ai/chat/send_message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ message })
                });
                
                const data = await response.json();
                
                // Remove loading indicator
                this.hideLoading();
                
                if (data.success) {
                    this.addMessage(data.response, 'assistant');
                } else {
                    this.addMessage('Error: ' + (data.error || 'Unknown error'), 'assistant');
                }
            } catch (error) {
                this.hideLoading();
                this.addMessage('Error: Could not connect to AI assistant. ' + error.message, 'assistant');
            } finally {
                this.setLoading(false);
            }
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
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Clear the current chat interface
                    this.messages.innerHTML = '';

                    // Ensure only the new conversation is restored on page reload.
                    localStorage.removeItem('neuron-chat-history');
                    
                    // Show welcome message
                    this.addMessage(
                        'Hello! I\'m your AI assistant for Concrete CMS. I can help you:\n\n' +
                        '• Create new pages\n' +
                        '• Get information about pages\n' +
                        '• Navigate your site structure\n' +
                        '• And more...\n\n' +
                        'What would you like to do?',
                        'assistant',
                        false
                    );
                    
                    // Clear input
                    this.input.value = '';
                } else {
                    this.addMessage('Error creating new chat: ' + data.error, 'assistant');
                }
            } catch (error) {
                this.addMessage('Error creating new chat: ' + error.message, 'assistant');
            }
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
            
            // Restore messages from localStorage
            this.restoreMessages();
        },
        
        async showChatListView() {
            this.isListView = true;
            
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
                this.messages.innerHTML = `<div class="neuron-chat-list-error">Error loading chats: ${error.message}</div>`;
            }
        },
        
        renderChatList(chats) {
            if (!chats || chats.length === 0) {
                this.messages.innerHTML = '<div class="neuron-chat-list-empty"><i class="fas fa-inbox"></i><p>No saved chats yet</p></div>';
                return;
            }
            
            let html = '<div class="neuron-chat-list">';
            
            chats.forEach(chat => {
                const timeStr = this.formatChatTime(chat.updatedDate || chat.createdDate);
                const firstMsg = chat.firstMessage || 'New conversation';
                
                html += `
                    <div class="neuron-chat-list-item" data-chat-id="${chat.id}">
                        <div class="neuron-chat-list-item-content">
                            <div class="neuron-chat-list-item-title">${this.escapeHtml(firstMsg)}</div>
                            <div class="neuron-chat-list-item-time">${timeStr}</div>
                        </div>
                        <button class="neuron-chat-list-item-load" title="Load chat">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                `;
            });
            
            html += '</div>';
            this.messages.innerHTML = html;
            
            // Bind click handlers
            this.messages.querySelectorAll('.neuron-chat-list-item-load').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const item = e.target.closest('.neuron-chat-list-item');
                    const chatId = item.dataset.chatId;
                    this.loadChatById(chatId);
                });
            });
        },
        
        async loadChatById(chatId) {
            try {
                // Show loading in chat view
                this.showChatView();
                this.messages.innerHTML = '<div class="neuron-chat-list-loading"><i class="fas fa-spinner fa-spin"></i> Loading chat...</div>';
                
                // Fetch the chat from backend
                const response = await fetch(`/ccm/system/katalysis_neuron_ai/chat/load?id=${chatId}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
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
                                    this.addMessage(normalizedContent, role, false);
                                }
                            });

                            localStorage.setItem('neuron-chat-history', JSON.stringify(
                                history.map((msg) => ({
                                    content: this.normalizeMessageContent(msg ? msg.content : ''),
                                    type: msg && msg.role === 'user' ? 'user' : 'assistant',
                                    timestamp: Date.now()
                                })).filter((msg) => msg.content)
                            ));
                        } else {
                            this.addMessage('Unable to parse saved chat format for this conversation.', 'assistant', false);
                        }
                    }
                } else {
                    this.messages.innerHTML = '';
                    this.addMessage('Error: Could not load chat. ' + (data.error || 'Chat not found'), 'assistant');
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
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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
        
        addMessage(content, type = 'assistant', saveToStorage = true) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `neuron-chat-message neuron-chat-${type}`;
            
            const avatar = document.createElement('div');
            avatar.className = 'neuron-chat-avatar';
            avatar.innerHTML = type === 'assistant' ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-user"></i>';
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'neuron-chat-content';
            
            // Simple markdown-like rendering
            const formattedContent = this.formatContent(content);
            contentDiv.innerHTML = formattedContent;
            
            messageDiv.appendChild(avatar);
            messageDiv.appendChild(contentDiv);
            
            this.messages.appendChild(messageDiv);
            this.scrollToBottom();
            
            // Save to localStorage for persistence across page navigations
            if (saveToStorage) {
                this.saveMessageToStorage(content, type);
            }
        },
        
        formatContent(content) {
            // Simple formatting
            let formatted = this.normalizeMessageContent(content);

            // Hard guard: never allow non-string values into replace() calls.
            if (typeof formatted !== 'string') {
                formatted = String(formatted ?? '');
            }
            
            // Bold: **text**
            formatted = formatted.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            
            // Italic: *text*
            formatted = formatted.replace(/\*(.+?)\*/g, '<em>$1</em>');
            
            // Code: `code`
            formatted = formatted.replace(/`(.+?)`/g, '<code>$1</code>');
            
            // Links: [text](url)
            formatted = formatted.replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank">$1</a>');
            
            // Line breaks
            formatted = formatted.replace(/\n/g, '<br>');
            
            // Lists (basic)
            formatted = formatted.replace(/^- (.+)$/gm, '<li>$1</li>');
            if (formatted.includes('<li>')) {
                formatted = '<ul>' + formatted.replace(/(<li>.+<\/li>)/g, '$1') + '</ul>';
            }
            
            return formatted;
        },
        
        showLoading() {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'neuron-chat-message neuron-chat-assistant neuron-chat-loading-msg';
            loadingDiv.id = 'neuron-chat-loading';
            
            const avatar = document.createElement('div');
            avatar.className = 'neuron-chat-avatar';
            avatar.innerHTML = '<i class="fas fa-robot"></i>';
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'neuron-chat-content';
            contentDiv.innerHTML = '<div class="neuron-chat-loading"><span></span><span></span><span></span></div>';
            
            loadingDiv.appendChild(avatar);
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
            
            // Check periodically as fallback (every 2 seconds instead of 1)
            setInterval(() => this.updatePosition(), 2000);
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
                // Dashboard panel closed or narrow screen - position near edge
                this.panel.style.right = '20px';
            }
        },
        
        isDashboardPanelVisible() {
            // Check for Concrete CMS dashboard panel specifically
            const dashboard = document.querySelector('#ccm-panel-dashboard');
            if (dashboard && dashboard.classList.contains('ccm-panel-active')) {
                return true;
            }
            
            // Check for any active panel
            const panelSelectors = [
                '.ccm-panel.ccm-panel-active',
                '.ccm-panel.ccm-panel-visible',
                '.ccm-panel-detail.active',
                '.ccm-sidebar-open'
            ];
            
            for (const selector of panelSelectors) {
                const panel = document.querySelector(selector);
                if (panel) {
                    const style = window.getComputedStyle(panel);
                    if (style.display !== 'none' && style.visibility !== 'hidden') {
                        return true;
                    }
                }
            }
            
            return false;
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
