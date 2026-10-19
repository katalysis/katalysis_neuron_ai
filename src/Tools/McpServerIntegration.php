<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use NeuronAI\MCP\McpConnector;
use Concrete\Core\Support\Facade\Log;
use Concrete\Core\Support\Facade\Config;

/**
 * Integration layer for external MCP servers
 * 
 * This class provides a bridge between external MCP servers (like the Macareux 
 * Concrete CMS MCP server) and the Neuron AI agent system.
 * 
 * Usage:
 * 1. Install the MCP server (e.g., npm install -g @macareux-digital/concretecms-mcp-server)
 * 2. Configure the server URL or command in config
 * 3. Register it in your package controller's on_start():
 * 
 * Example:
 * ```php
 * McpServerIntegration::registerMacareuxServer();
 * ```
 * 
 * Or register a custom MCP server:
 * ```php
 * McpServerIntegration::registerServer('my-server', [
 *     'url' => 'http://localhost:3000',
 *     // or 'command' => 'npx my-mcp-server'
 * ]);
 * ```
 */
class McpServerIntegration
{
    /**
     * Register the Macareux Concrete CMS MCP server
     * https://github.com/MacareuxDigital/concretecms-mcp-server
     * 
     * Provides tools like:
     * - get_page_blocks: Get all blocks from a page
     * - move_block: Move a block to a different area
     * - create_page_version: Create a new page version
     * - And more...
     * 
     * @param array<string, mixed> $config Optional configuration override
     * @return void
     */
    public static function registerMacareuxServer(array $config = []): void
    {
        // Check if user has configured a custom URL or command
        $configuredUrl = Config::get('katalysis.ai.mcp.macareux.url');
        $configuredCommand = Config::get('katalysis.ai.mcp.macareux.command');
        
        if ($configuredUrl) {
            $config['url'] = $configuredUrl;
        } elseif ($configuredCommand) {
            $config['command'] = $configuredCommand;
        } elseif (empty($config['url']) && empty($config['command'])) {
            // Default: assume it's installed globally via npx
            $config['command'] = 'npx @macareux-digital/concretecms-mcp-server';
        }
        
        self::registerServer('macareux-concretecms', $config);
    }
    
    /**
     * Register a custom MCP server
     * 
     * @param string $key Unique identifier for this server
     * @param array<string, mixed> $config MCP configuration
     *        - 'url' => 'http://localhost:3000' for HTTP transport
     *        - 'command' => 'npx mcp-server' for stdio transport
     *        - 'exclude' => ['tool1', 'tool2'] to exclude specific tools
     *        - 'only' => ['tool1', 'tool2'] to include only specific tools
     * @return void
     */
    public static function registerServer(string $key, array $config): void
    {
        ExternalToolkitRegistry::registerToolkitFactory(
            "mcp-{$key}",
            function () use ($key, $config) {
                try {
                    $exclude = $config['exclude'] ?? [];
                    $only = $config['only'] ?? [];
                    
                    // Remove exclude/only from config before passing to McpConnector
                    unset($config['exclude'], $config['only']);
                    
                    $connector = McpConnector::make($config);
                    
                    if (!empty($exclude)) {
                        $connector->exclude($exclude);
                    }
                    
                    if (!empty($only)) {
                        $connector->only($only);
                    }
                    
                    // Return tools as array for the registry
                    return [$connector];
                    
                } catch (\Throwable $e) {
                    Log::warning(sprintf(
                        'Katalysis Neuron AI: Failed to connect to MCP server "%s": %s',
                        $key,
                        $e->getMessage()
                    ));
                    
                    // Return empty array on failure
                    return [];
                }
            }
        );
    }
    
    /**
     * Check if an MCP server is available for testing
     * 
     * @param array<string, mixed> $config
     * @return bool
     */
    public static function testConnection(array $config): bool
    {
        try {
            $connector = McpConnector::make($config);
            $tools = $connector->tools();
            return !empty($tools);
        } catch (\Throwable $e) {
            Log::debug(sprintf(
                'Katalysis Neuron AI: MCP connection test failed: %s',
                $e->getMessage()
            ));
            return false;
        }
    }
}
