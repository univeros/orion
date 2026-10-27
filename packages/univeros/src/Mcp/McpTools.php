<?php

declare(strict_types=1);

namespace Univeros\Orion\Mcp;

use Altair\Mcp\Attribute\McpTool;
use Altair\Mcp\Tool\ToolDescriptor;
use Altair\Mcp\Tool\ToolRegistry;
use JsonException;
use LogicException;
use ReflectionClass;

use function file_get_contents;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The module's MCP tools, registered on the framework's {@see ToolRegistry} by {@see \Univeros\Orion\Module} so
 * `bin/altair mcp:serve` and `mcp:tools` see them. The framework discovers user tools by scanning directories its
 * serve command does not expose, so the module registers its own descriptors, read from the same `#[McpTool]`
 * attributes the scanner would read.
 */
final class McpTools
{
    /** @var list<class-string<OrionTool>> */
    public const array CLASSES = [
        ApplyTool::class,
        CapabilitiesTool::class,
        CompileQueryTool::class,
        DescribeIndexTool::class,
        ListIndicesTool::class,
        PlanTool::class,
        ReindexTool::class,
        SearchTool::class,
    ];

    public static function register(ToolRegistry $registry): void
    {
        foreach (self::descriptors() as $descriptor) {
            if (!$registry->has($descriptor->name)) {
                $registry->register($descriptor);
            }
        }
    }

    /**
     * @return list<ToolDescriptor>
     */
    public static function descriptors(): array
    {
        $descriptors = [];
        foreach (self::CLASSES as $class) {
            $attributes = (new ReflectionClass($class))->getAttributes(McpTool::class);
            $attribute = ($attributes[0] ?? throw new LogicException(sprintf('%s carries no #[McpTool].', $class)))->newInstance();
            $descriptors[] = new ToolDescriptor($attribute->name, $attribute->description, $class, self::schema($attribute->inputSchema));
        }

        return $descriptors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function schema(?string $file): ?array
    {
        if ($file === null) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new LogicException(sprintf('The tool schema %s is not JSON: %s', $file, $invalid->getMessage()), 0, $invalid);
        }
        if (!is_array($decoded)) {
            throw new LogicException(sprintf('The tool schema %s is not a JSON object.', $file));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
