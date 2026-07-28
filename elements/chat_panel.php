<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>

<div id="neuron-chat-panel" class="neuron-chat-expanded ccm-ui">
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
