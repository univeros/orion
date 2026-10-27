<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\Reindex;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . Reindex::NAME, description: Reindex::DESCRIPTION, inputSchema: __DIR__ . '/Schema/reindex.json')]
final readonly class ReindexTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new Reindex($graph);
    }
}
