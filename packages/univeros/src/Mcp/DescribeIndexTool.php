<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\DescribeIndex;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . DescribeIndex::NAME, description: DescribeIndex::DESCRIPTION, inputSchema: __DIR__ . '/Schema/describe_index.json')]
final readonly class DescribeIndexTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new DescribeIndex($graph);
    }
}
