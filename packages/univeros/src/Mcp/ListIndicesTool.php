<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\ListIndices;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . ListIndices::NAME, description: ListIndices::DESCRIPTION, inputSchema: __DIR__ . '/Schema/list_indices.json')]
final readonly class ListIndicesTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new ListIndices($graph);
    }
}
