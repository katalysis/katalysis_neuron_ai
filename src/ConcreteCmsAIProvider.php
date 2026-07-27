<?php

namespace Katalysis\NeuronAi;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use Katalysis\NeuronAi\Toolkits\PagesToolkit;
use Katalysis\NeuronAi\Toolkits\FilesToolkit;
use Katalysis\NeuronAi\Toolkits\UsersToolkit;

/**
 * All-in-One Concrete CMS AI Provider
 * 
 * Makes it trivial to create AI agents with comprehensive Concrete CMS capabilities.
 * 
 * Usage:
 * ```php
 * $agent = ConcreteCmsAIProvider::create();
 * $response = $agent->handle("How many pages are in this site?");
 * ```
 * 
 * This provides:
 * - 6 page tools (list, get, create, update, delete, children)
 * - 4 file tools (list, get, delete, list_folders)
 * - 4 user tools (list, get, get_current, search)
 * 
 * Total of 14 tools ready to use!
 */
class ConcreteCmsAIProvider
{
    /**
     * Create a new Concrete CMS AI agent with all toolkits enabled
     * 
     * @param AIProviderInterface|null $provider Optional custom AI provider (defaults to OpenAI from config)
     * @param string|null $instructions Optional custom instructions
     * @return Agent The configured agent ready to use
     */
    public static function create(
        ?AIProviderInterface $provider = null,
        ?string $instructions = null
    ): Agent {
        // Create anonymous agent class
        return new class($provider, $instructions) extends Agent {
            private $customProvider;
            private $customInstructions;
            
            public function __construct($provider, $instructions)
            {
                $this->customProvider = $provider;
                $this->customInstructions = $instructions;
                parent::__construct();
            }
            
            protected function provider(): AIProviderInterface
            {
                if ($this->customProvider) {
                    return $this->customProvider;
                }
                
                // Use default OpenAI provider from config
                return new \NeuronAI\Providers\OpenAI\OpenAI(
                    key: \Concrete\Core\Support\Facade\Config::get('katalysis.ai.open_ai_key'),
                    model: \Concrete\Core\Support\Facade\Config::get('katalysis.ai.open_ai_model', 'gpt-4o')
                );
            }
            
            protected function instructions(): string
            {
                if ($this->customInstructions) {
                    return $this->customInstructions;
                }
                
                $context = $this->getCurrentContext();
                
                return <<<INST
You are an AI assistant for Concrete CMS with comprehensive site management capabilities.

CURRENT CONTEXT:
{$context}

CAPABILITIES:
You have access to 14 tools across 3 categories:

**Pages (6 tools):**
- List pages in the site with filtering
- Get detailed page information
- Create new pages with specific types
- Update existing pages
- Delete pages
- Navigate the sitemap (get children)

**Files (4 tools):**
- List files in the file manager
- Get detailed file information
- Delete files
- List file folders/structure

**Users (4 tools):**
- List all users
- Get detailed user information
- Get current user details
- Search for users by name/email

GUIDELINES:
- Execute tools immediately when user intent is clear
- Use appropriate tools based on the question
- Chain multiple tools when needed (e.g., list pages then get details)
- Provide specific details in responses (IDs, paths, counts)
- Be helpful and action-oriented
- Use markdown formatting for clarity

RESPONSE STYLE:
- Concise but informative
- Include specific data (IDs, paths, counts)
- Use the formatted output from tools
- Confirm actions when creating/updating/deleting
INST;
            }
            
            protected function tools(): array
            {
                return [];  // Toolkits are registered differently
            }
            
            protected function toolkits(): array
            {
                return [
                    new PagesToolkit(),
                    new FilesToolkit(),
                    new UsersToolkit(),
                ];
            }
            
            private function getCurrentContext(): string
            {
                $context = [];
                
                // Get current page if available
                $c = \Concrete\Core\Page\Page::getCurrentPage();
                if ($c && !$c->isError()) {
                    $context[] = "Current Page: " . $c->getCollectionPath();
                    $context[] = "Page Type: " . ($c->getPageTypeHandle() ?: 'N/A');
                }
                
                // Get user context
                $u = new \Concrete\Core\User\User();
                if ($u->isRegistered()) {
                    $context[] = "User: " . $u->getUserName();
                    $context[] = "User ID: " . $u->getUserID();
                } else {
                    $context[] = "User: Guest (not logged in)";
                }
                
                // Get site stats
                try {
                    $pl = new \Concrete\Core\Page\PageList();
                    $pl->ignorePermissions();
                    $totalPages = $pl->getTotalResults();
                    $context[] = "Total Pages in Site: {$totalPages}";
                } catch (\Exception $e) {
                    // Ignore
                }
                
                return !empty($context) ? implode("\n", $context) : "No context available";
            }
        };
    }
    
    /**
     * Quick helper: Create agent and execute a single message
     * 
     * @param string $message The user message
     * @return string The AI response
     */
    public static function ask(string $message): string
    {
        $agent = self::create();
        return $agent->chat(new \NeuronAI\Chat\Messages\UserMessage($message))
                    ->getMessage()
                    ->getContent();
    }
}
