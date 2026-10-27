<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Orion\Tools\CompileQuery;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

#[McpTool(name: OrionTool::PREFIX . CompileQuery::NAME, description: CompileQuery::DESCRIPTION, inputSchema: __DIR__ . '/Schema/compile_query.json')]
final readonly class CompileQueryTool extends OrionTool
{
    #[Override]
    protected function tool(Graph $graph): Tool
    {
        return new CompileQuery($graph);
    }
}
