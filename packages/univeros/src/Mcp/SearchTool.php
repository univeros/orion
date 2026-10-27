<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\Search;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . Search::NAME, description: Search::DESCRIPTION, inputSchema: __DIR__ . '/Schema/search.json')]
final readonly class SearchTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new Search($graph);
    }
}
