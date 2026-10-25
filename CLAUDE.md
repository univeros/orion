# CLAUDE.md

> The canonical guide is [AGENT.md](AGENT.md). Read it first; this file adds Claude-Code-specific notes.

- Run `composer qa` before proposing a commit; CI runs the same four steps.
- New code: write the failing test first (`tdd-guide`), then the smallest implementation; review with
  `code-reviewer`; anything touching tenancy, receipts or signing also goes through `security-reviewer`.
- `bin/check-imports` is the isolation gate. If it fails, the fix is moving code to the right package, not
  loosening the script.
- Decisions go to `docs/decisions.md` (append only), never into chat alone.
- The reference implementation of this repository's shape is `~/Projects/polaris-core` (core, ports, harness,
  split) and `~/Projects/polaris` (what a Univeros module binds). Copy their patterns; do not import them.
