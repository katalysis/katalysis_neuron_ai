<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Zep;

use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * https://help.getzep.com/sdk-reference/graph/search
 *
 * @method static static make(string $key, string $user_id)
 */
class ZepSearchGraphTool extends Tool
{
    use HandleZepClient;

    public function __construct(
        protected string $key,
        protected string $user_id
    ) {
        parent::__construct(
            'search_knowledge_graph',
            'Searches the knowledge graph for relevant facts or nodes.
Use this tool if you need to retrieve user information that can help you provide more accurate answers.'
        );

        $this->createUser();
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The search term to find relevant facts or nodes',
                true
            ),
            new ToolProperty(
                'search_scope',
                PropertyType::STRING,
                'The scope of the search to perform. Can be "facts" or "nodes"',
                false,
                ['facts', 'nodes']
            )
        ];
    }

    public function __invoke(string $query, string $search_scope = 'facts', int $limit = 5): array
    {
        $response = $this->getClient()->post('graph/search', [
            RequestOptions::JSON => [
                'user_id' => $this->user_id,
                'query' => $query,
                'scope' => $search_scope === 'facts' ? 'edges' : 'nodes',
                'limit' => $limit,
            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        return match ($search_scope) {
            'nodes' => $this->mapNodes($response['nodes']),
            default => $this->mapEdges($response['edges']),
        };
    }

    protected function mapEdges(array $edges): array
    {
        return \array_map(fn (array $edge): array => [
            'fact' => $edge['fact'],
            'created_at' => $edge['created_at'],
        ], $edges);
    }

    protected function mapNodes(array $nodes): array
    {
        return \array_map(fn (array $node): array => [
            'name' => $node['name'],
            'summary' => $node['summary'],
        ], $nodes);
    }
}
