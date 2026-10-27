<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\Plan;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . Plan::NAME, description: Plan::DESCRIPTION, inputSchema: __DIR__ . '/Schema/plan.json')]
final readonly class PlanTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new Plan($graph);
    }
}
