<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\Capabilities;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . Capabilities::NAME, description: Capabilities::DESCRIPTION, inputSchema: __DIR__ . '/Schema/capabilities.json')]
final readonly class CapabilitiesTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new Capabilities($graph);
    }
}
