<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>

<script>
// Disable transitions during initial load
(function() {
    var style = document.createElement('style');
    style.id = 'neuron-no-transition';
    style.textContent = '#neuron-chat-panel { transition: none !important; }';
    document.head.appendChild(style);
})();
</script>

<div id="neuron-chat-panel" class="neuron-chat-expanded ccm-ui" data-csrf-token="<?= h(\Katalysis\NeuronAi\Access\ChatAccess::generateToken()) ?>">
    <div class="neuron-chat-header">
        <h3>
            <i class="fas fa-robot"></i>
            AI Assistant
        </h3>
        <div>
            <button id="neuron-chat-load" class="btn btn-sm" title="Load saved chats">
                <i class="fas fa-folder-open"></i>
            </button>
            <button id="neuron-chat-new" class="btn btn-sm" title="New chat">
                <i class="fas fa-plus"></i>
            </button>
            <button id="neuron-chat-delete-current" class="btn btn-sm" title="Delete current chat">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    
    <div class="neuron-chat-body">
        <div class="neuron-chat-messages" id="neuron-chat-messages">
            <div class="neuron-chat-message neuron-chat-assistant">
                <div class="neuron-chat-content">
                    <p><i class="fas fa-robot neuron-chat-inline-icon"></i> Hello! I'm your AI assistant for Concrete CMS. What would you like to do?</p>
                </div>
            </div>
        </div>
        
        <div class="neuron-chat-input-area">
            <textarea 
                id="neuron-chat-input" 
                placeholder="Ask me anything..." 
                rows="3"
            ></textarea>
            <button id="neuron-chat-send" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i>
                Send
            </button>
        </div>
    </div>
</div>

<script>
// Apply saved state immediately
(function() {
    var savedState = localStorage.getItem('neuron-chat-expanded');
    var panel = document.getElementById('neuron-chat-panel');
    
    if (panel) {
        // Apply the correct class based on saved state
        if (savedState === 'false') {
            panel.classList.add('neuron-chat-collapsed');
            panel.classList.remove('neuron-chat-expanded');
        }
        
        // Mark that initial state has been applied
        panel.setAttribute('data-initial-state-applied', 'true');
        
        // Re-enable transitions after state is set
        setTimeout(function() {
            var style = document.getElementById('neuron-no-transition');
            if (style && style.parentNode) {
                style.parentNode.removeChild(style);
            }
        }, 50);
    }
})();
</script>
