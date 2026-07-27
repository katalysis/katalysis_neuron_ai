<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>

<div id="neuron-chat-panel" class="neuron-chat-expanded">
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
        </div>
    </div>
    
    <div class="neuron-chat-body">
        <div class="neuron-chat-messages" id="neuron-chat-messages">
            <div class="neuron-chat-message neuron-chat-assistant">
                <div class="neuron-chat-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="neuron-chat-content">
                    <p>Hello! I'm your AI assistant for Concrete CMS. I can help you:</p>
                    <ul>
                        <li>Create new pages</li>
                        <li>Get information about pages</li>
                        <li>Navigate your site structure</li>
                        <li>And more...</li>
                    </ul>
                    <p>What would you like to do?</p>
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
