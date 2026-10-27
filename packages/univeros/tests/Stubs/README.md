# Dev-only stubs of `univeros/mcp`

The seven files under `Altair/Mcp/` are verbatim copies (MIT, header kept) of the contracts in `univeros/mcp`
v2.6.0 that `Univeros\Orion\Mcp` adapts the core tools to: the `#[McpTool]` attribute, `McpToolInterface`,
`ServerMode`, the two exceptions, `ToolRegistry` and `ToolDescriptor`.

They exist because `univeros/mcp` cannot be installed in this repository's root: it requires `univeros/introspection`
→ `univeros/http` → `neomerx/cors-psr7`, which pins `psr/http-message` 1, while the Elasticsearch 9 client needs 2.
The root's `autoload-dev` maps `Altair\Mcp\` here, so the module's adapters load, PHPStan sees them, and
`McpToolsTest` runs them against the stubs. Wherever the real package is installed (a host, the demo in
`examples/univeros`), its autoloader wins and these files are never read. The demo walkthrough proves the real
one: `bin/altair mcp:tools` lists the `orion__*` tools and `mcp:serve` answers `orion__compile_query`.

Do not edit these files; update them from the framework version the module targets.
