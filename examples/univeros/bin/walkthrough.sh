#!/usr/bin/env bash
# The Orion walkthrough, executable: write products through the application (each write and its outbox entry in one
# transaction), search them, delete one, change the schema in a way the engine cannot apply in place, see the drift,
# reindex by replay while the application keeps writing and searching, verify the receipts, and run the doctor.
# Run from this directory after bin/setup. Starts its own PHP server unless ORION_URL points at a running one.
# Exits non-zero on the first failure.
set -euo pipefail

SERVER_LOG="${ORION_SERVER_LOG:-var/server.log}"
URL="${ORION_URL:-}"
SERVER=""
MANIFEST_BACKUP=""
cleanup() {
    [ -n "$SERVER" ] && kill "$SERVER" 2>/dev/null || true
    [ -n "$MANIFEST_BACKUP" ] && mv "$MANIFEST_BACKUP" api/products/search.yaml || true
}
trap cleanup EXIT
if [ -z "$URL" ]; then
    php -S 127.0.0.1:8080 -t public > "$SERVER_LOG" 2>&1 &
    SERVER=$!
    URL=http://127.0.0.1:8080
    for _ in $(seq 1 50); do curl -s -o /dev/null "$URL/products/search" && break; sleep 0.1; done
fi

json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $d = $d[$k] ?? null; } echo is_scalar($d) ? $d : json_encode($d);' -- "$1"; }
call() { # call METHOD PATH [JSON]
    local args=(-s -w '\n%{http_code}' -X "$1" "$URL$2" -H 'Content-Type: application/json')
    [ -n "${3:-}" ] && args+=(-d "$3")
    local out; out=$(curl "${args[@]}")
    STATUS=${out##*$'\n'}; BODY=${out%$'\n'*}
}
altair() { set +e; OUT=$(php bin/altair "$@"); STATUS=$?; set -e; BODY=$OUT; }
step() { printf '%-60s %s\n' "$1" "$STATUS"; }
expect() { if [ "$STATUS" != "$1" ]; then echo "expected $1, got $STATUS: $BODY" >&2; exit 1; fi; }
expect_ids() { local ids; ids=$(printf '%s' "$BODY" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $ids = array_map(fn($h) => $h["id"], $d["hits"] ?? []); sort($ids); echo json_encode($ids);'); if [ "$ids" != "$1" ]; then echo "expected ids $1, got $ids" >&2; exit 1; fi; }
# The demo reads its own writes at once; a real application lets the engine refresh on its own schedule.
search() { php bin/altair orion:flush products --json > /dev/null; call GET "$1"; }

call POST /products '{"id":"p1","title":"Trail running shoe","sku":"TR-1"}'; step "1. POST /products (product + outbox entry, one transaction)"; expect 201
call POST /products '{"id":"p2","title":"Road running shoe","sku":"RD-1"}'; step "2. POST /products"; expect 201
call POST /products '{"id":"p3","title":"Hiking boot","sku":"HK-1"}'; step "3. POST /products"; expect 201
search '/products/search?q=running'; step "4. GET /products/search?q=running (the manifest's endpoint)"; expect 200; expect_ids '["p1","p2"]'
call DELETE /products/p2; step "5. DELETE /products/p2 (a tombstone, versioned)"; expect 200
search '/products/search?q=running'; step "6. GET /products/search?q=running"; expect 200; expect_ids '["p1"]'

# A breaking change: the title's analysis. The engine cannot apply it to a live index.
MANIFEST_BACKUP=$(mktemp); cp api/products/search.yaml "$MANIFEST_BACKUP"
sed -i.bak 's/title: { type: text, language: en }/title: { type: text, language: en, folding: true }/' api/products/search.yaml && rm -f api/products/search.yaml.bak
altair orion:drift --check --json; step "7. bin/altair orion:drift --check (breaking drift)"; expect 3
altair orion:apply products --json; step "8. bin/altair orion:apply products (refused: needs a reindex)"; expect 3
call POST /products '{"id":"p4","title":"Trail running jacket","sku":"TR-2"}'; step "9. POST /products while the old version serves"; expect 201
altair orion:reindex products --batch=2 --json; step "10. bin/altair orion:reindex products (replay, swap, receipt)"; expect 0
[ "$(printf '%s' "$BODY" | json to_version)" = "2" ] || { echo "expected version 2: $BODY" >&2; exit 1; }
search '/products/search?q=running'; step "11. GET /products/search?q=running (version 2, nothing lost)"; expect 200; expect_ids '["p1","p4"]'
altair orion:status products --json; expect 0
[ "$(printf '%s' "$BODY" | json current.version)" = "2" ] || { echo "expected version 2: $BODY" >&2; exit 1; }
altair orion:drift --check --json; step "12. bin/altair orion:drift --check (in sync)"; expect 0
altair orion:prune products --grace=0 --json; step "13. bin/altair orion:prune products (drops version 1)"; expect 0
altair orion:verify --json; step "14. bin/altair orion:verify (hashes, links, signatures, attested)"; expect 0
printf '    receipt head %s:%s\n' "$(printf '%s' "$BODY" | json head.sequence)" "$(printf '%s' "$BODY" | json head.hash)"
altair doctor --only=orion_engine_reachable,orion_registry_current,orion_schema_drift,orion_alias_resolves,orion_outbox_lag,orion_dead_letters,orion_receipts_verify --format=json
step "15. bin/altair doctor --only=orion_* (seven checks, all ok)"; expect 0
OK=$(printf '%s' "$BODY" | php -r '$d = json_decode(stream_get_contents(STDIN), true); $ok = array_filter($d["checks"] ?? [], fn($c) => $c["status"] === "ok"); echo count($ok), "/", count($d["checks"] ?? []);')
[ "$OK" = "7/7" ] || { echo "expected 7/7 Orion checks ok, got $OK: $BODY" >&2; exit 1; }

# The agent surface: the orion__* tools on the framework's MCP server, and compile_query answering over stdio with the
# request Elasticsearch would receive, without running it.
altair mcp:tools --format=json; step "16. bin/altair mcp:tools (the orion__* tools are listed)"; expect 0
printf '%s' "$BODY" | grep -q '"orion__compile_query"' || { echo "orion__compile_query is not listed: $BODY" >&2; exit 1; }
MCP=$(printf '%s\n%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"walkthrough","version":"0"}}}' \
    '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"orion__compile_query","arguments":{"index":"products","query":[{"type":"match","field":"title","value":"running"}]}}}' \
    | php bin/altair mcp:serve); STATUS=$?; BODY=$MCP
step "17. mcp:serve tools/call orion__compile_query (native DSL, not executed)"; expect 0
printf '%s' "$BODY" | grep -q '"isError":false' || { echo "expected a tool result, got an error: $BODY" >&2; exit 1; }
printf '%s' "$BODY" | grep -q '"body":{"from":0,"size":20,"query":{"bool":{"must":\[{"match":{"title":{"query":"running"}}}\]}}}' || { echo "expected the compiled Elasticsearch body: $BODY" >&2; exit 1; }
# A confirmed mutation is refused unless the server started with --allow-writes; with it, the in-sync index is unchanged.
mcp_apply() { printf '%s\n%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"walkthrough","version":"0"}}}' \
    '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"orion__apply","arguments":{"index":"products","confirm":true}}}' \
    | php bin/altair mcp:serve "$@"; }
BODY=$(mcp_apply); STATUS=$?; step "18. tools/call orion__apply confirm:true without --allow-writes (refused)"; expect 0
printf '%s' "$BODY" | grep -q '"isError":true' && printf '%s' "$BODY" | grep -q -- '--allow-writes' || { echo "expected the guardrail: $BODY" >&2; exit 1; }
BODY=$(mcp_apply --allow-writes); STATUS=$?; step "19. tools/call orion__apply confirm:true with --allow-writes (unchanged)"; expect 0
printf '%s' "$BODY" | grep -q '"operation":"unchanged"' || { echo "expected an unchanged apply: $BODY" >&2; exit 1; }
echo "Walkthrough complete."
