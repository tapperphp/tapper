# Tapper documentation index

This is the documentation set for the Tapper codebase, written for both humans and AI agents working in this repo. Start with [`../AGENTS.md`](../AGENTS.md) for the condensed orientation (commands, layout, must-know gotchas); come here for depth on a specific subsystem.

| Doc | Covers |
|---|---|
| [`architecture.md`](architecture.md) | The two-process design, full request/render data flow, event loop structure |
| [`console-framework.md`](console-framework.md) | The TUI's component model: `Component`, attribute-based event binding, `EventBus`, `AppState` reactivity |
| [`rpc-protocol.md`](rpc-protocol.md) | The actual wire protocol between a debuggee process and the TUI process (and where `openrpc.json` disagrees with it) |
| [`known-issues.md`](known-issues.md) | Known bugs, incomplete abstractions, and what "ready to extract a framework" would require |

## Reading order

- **New to the codebase, want the big picture:** `architecture.md`, then `console-framework.md`.
- **About to touch anything under `Console/`:** `console-framework.md` first — the attribute/event/state wiring has non-obvious rules.
- **About to touch `Rpc/`, `Server.php`, or `Runtime/Tapper.php`:** `rpc-protocol.md` first — there's a live bug in the client's socket path.
- **Planning ahead toward the GLFW/desktop-framework goal:** `known-issues.md#framework-extraction-readiness`.

## Source of truth

Code is the source of truth; these docs describe the state of the repo as of 2026-08-15 and should be updated in the same change that changes the behavior they describe. If a doc and the code disagree, trust the code and fix the doc.