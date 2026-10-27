<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\Apply;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . Apply::NAME, description: Apply::DESCRIPTION, inputSchema: __DIR__ . '/Schema/apply.json')]
final readonly class ApplyTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new Apply($graph);
    }
}
