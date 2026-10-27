<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Contracts\McpToolInterface;
use Altair\Mcp\Exception\GuardrailException;
use Altair\Mcp\Guard\ServerMode;
use Orion\Tools\Tool;
use Orion\Wiring\Graph;
use Override;

use function sprintf;

/**
 * A core tool (v1 §15) as the framework's MCP server sees it: the name, description and input schema are on each
 * subclass's `#[McpTool]` attribute, taken from the core tool's constants and the schema file beside it, and the
 * call is the core tool's. A mutating tool keeps core's gate (nothing without `confirm`) and adds the server's:
 * confirming needs `mcp:serve --allow-writes`, the same switch that gates the framework's own migrations.
 */
abstract readonly class OrionTool implements McpToolInterface
{
    public const string PREFIX = 'orion__';

    public function __construct(private Graph $graph, private ServerMode $mode = new ServerMode())
    {
    }

    abstract protected function tool(Graph $graph): Tool;

    #[Override]
    public function call(array $input): array
    {
        $tool = $this->tool($this->graph);
        if ($tool->mutates() && ($input['confirm'] ?? false) === true && !$this->mode->allowsDbWrites()) {
            throw new GuardrailException(sprintf(
                '%s%s changes the index: start the server with --allow-writes (and not --readonly), or omit confirm to preview.',
                self::PREFIX,
                $tool->name(),
            ));
        }

        return $tool->call($input);
    }
}
