<?php

namespace Katalysis\NeuronAi;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Katalysis\NeuronAi\DatabaseChatHistory;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Page\Page;

class ConcreteCmsAgent extends Agent
{
    private ?int $chatId = null;
    private ?DatabaseChatHistory $chatHistoryInstance = null;
    
    public function __construct(?int $chatId = null)
    {
        $this->chatId = $chatId;
        
        parent::__construct();
        
        // Increase tool max runs to prevent premature failures
        // Default is 10, but complex queries may require more iterations
        $this->toolMaxRuns(20);
        
        // Set error handler for better error messages to the LLM
        $this->toolErrorHandler(function(\Throwable $e, $tool) {
            $errorMsg = "Tool '{$tool->getName()}' failed: " . $e->getMessage();
            \Concrete\Core\Support\Facade\Log::error('Neuron AI Tool Error: ' . $errorMsg);
            
            // Return a clear error message that the LLM can understand
            return "Error executing tool: " . $e->getMessage() . ". Please try a different approach or ask for clarification.";
        });
    }
    
    /**
     * Set the chat ID for the current conversation
     * This allows the agent to persist chat history to the database
     */
    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;
        
        // IMPORTANT: Always call chatHistory() to ensure instance exists
        // This matches the pattern from katalysis_pro_ai RagAgent
        $chatHistory = $this->chatHistory();
        
        if ($chatHistory instanceof DatabaseChatHistory && $chatId) {
            $chatHistory->setChatId($chatId);
        }
        
        return $this;
    }
    
    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: Config::get('katalysis.ai.open_ai_key'),
            model: Config::get('katalysis.ai.open_ai_model', 'gpt-4o')
        );
    }

    /**
     * Use database-backed chat history for persistent storage
     * Uses singleton pattern to ensure chat ID persistence
     * 
     * @return \NeuronAI\Chat\History\AbstractChatHistory
     */
    protected function chatHistory(): \NeuronAI\Chat\History\AbstractChatHistory
    {
        // Use singleton pattern to ensure chat ID persistence
        if ($this->chatHistoryInstance === null) {
            $this->chatHistoryInstance = new DatabaseChatHistory(50000);
            
            // Set chat ID directly on the instance if available (don't call setChatId to avoid recursion)
            if ($this->chatId) {
                $this->chatHistoryInstance->setChatId($this->chatId);
            }
        }
        
        return $this->chatHistoryInstance;
    }

    protected function instructions(): string
    {
        $context = $this->getCurrentContext();
        
        return <<<INST
You are an AI assistant for Concrete CMS with comprehensive site management capabilities.

CURRENT CONTEXT:
{$context}

CAPABILITIES:
You have access to 19 tools across 3 categories:

**Pages (11 tools):**
- List pages with filtering options
- Get detailed page information
- Create new pages with specific types and locations
- Update existing page properties
- Move pages to new parent locations
- Delete pages
- Navigate the sitemap and get child pages
- Add blocks to page areas
- Update blocks in page areas
- Delete blocks from page areas

**Files (4 tools):**
- List files in the file manager
- Get detailed file information
- Delete files
- Browse file folder structure

**Users (4 tools):**
- List all users in the system
- Get detailed user information
- Get current user details
- Search for users by name or email

GUIDELINES:
- ALWAYS ask for missing required information before executing tools
- When creating pages: if parent location not specified, ask where to create it
- When moving pages: if destination not specified, ask where to move it
- Use appropriate tools based on the question
- Chain multiple tools when needed (e.g., list pages then get specific page details)
- **NEVER call the same tool repeatedly with the same arguments** - if a tool doesn't give you the answer you need, try a different approach or ask the user for clarification
- If a tool fails or returns unexpected results, explain what happened and ask the user for help
- Provide specific, actionable information
- Include relevant IDs, paths, and counts in responses
- When asked about "how many" or "list all", use the list tools and count/summarize the results
- For questions about page types, use list_pages with filtering rather than checking individual pages
- Treat short follow-up questions (e.g., "which is used most often", "and are there others?") as referring to the immediately previous answer and tool results
- For Concrete CMS Page Types questions, prefer list_page_types first, then report exact counts and handles
- Do not ask the user to confirm scope when the prior message already defines it

RESPONSE STYLE:
- Concise and helpful
- Include specific data from tool results
- Use markdown formatting
- Confirm before destructive actions (delete)
INST;
    }
    
    protected function tools(): array
    {
        $toolkits = [
            new PagesToolkit(),
            new FilesToolkit(),
            new UsersToolkit(),
        ];

        return array_merge($toolkits, ExternalToolkitRegistry::getExternalToolkits());
    }
    
    protected function getCurrentContext(): string
    {
        $context = [];
        
        // Get current page if in dashboard (skip in CLI)
        if (php_sapi_name() !== 'cli') {
            try {
                $c = Page::getCurrentPage();
                if ($c && !$c->isError()) {
                    $context[] = "Current Page: " . $c->getCollectionPath();
                    $context[] = "Page Type: " . ($c->getPageTypeHandle() ?: 'N/A');
                }
            } catch (\Exception $e) {
                // Ignore - might be CLI or bootstrap not complete
            }
        }
        
        // Get user context
        try {
            $u = new \Concrete\Core\User\User();
            if ($u->isRegistered()) {
                $context[] = "User: " . $u->getUserName();
                $context[] = "User ID: " . $u->getUserID();
            }
        } catch (\Exception $e) {
            // Ignore - might be CLI or bootstrap not complete
        }
        
        return implode("\n", $context);
    }
    
    public function handle(string $message): string
    {
        try {
            // Send message and get response using Neuron AI's built-in chat method
            $userMessage = new UserMessage($message);
            $response = $this->chat($userMessage)->getMessage();
            
            return $response->getContent();
        } catch (\Exception $e) {
            \Concrete\Core\Support\Facade\Log::error('Neuron AI Error: ' . $e->getMessage());

            return "I ran into an error processing that request. Please try again.";
        }
    }

}
