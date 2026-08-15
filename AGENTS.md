# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository. Read this before making changes. Deeper topics are split into `docs/` — see the index at [`docs/README.md`](docs/README.md).

## What this project is

Tapper is a PHP debugging tool in the shape of `Ray`/`Clockwork`: you call `tp($value)` anywhere in a PHP application, and the payload shows up live in a terminal UI (TUI) running as a separate process.

**Two things are true about this repo at once, and both should shape how you work in it:**

1. It is a real, shippable debugger with its own README and CI.
2. It is deliberately being used as a **research vehicle**. The longer-term goal (not yet started) is a general PHP desktop-app framework with a swappable rendering backend — this TUI today, a GLFW-based GUI later. Tapper exists to discover, under real pressure, what that framework's abstractions should be, before anything is extracted into a separate package.

Decision from 2026-08-15: **do not extract a framework yet.** Keep building Tapper as an application first. Extracting now, from a single example, would mean guessing at the TUI/GLFW boundary rather than discovering it — see [`docs/known-issues.md`](docs/known-issues.md#framework-extraction-readiness) for what "ready to extract" will look like and why it isn't yet (the widget/layout layer is currently *not* abstracted — components build `php-tui` widgets directly).

## Repository layout

```
bin/tapper                 CLI entrypoint — boots the DI container and runs the TUI Application
src/
  helpers.php               Global tp() function (the public API surface for debuggee apps)
  Runtime/Tapper.php         Client side: collects debug info, sends it over the wire, blocks for the reply
  Rpc/                       Minimal JSON-RPC-ish request/response types + blocking socket client
  Server.php                 TUI-process side: unix socket server, decodes requests, mutates AppState
  Console/
    main.php                 DI container wiring (php-di) for the TUI process
    Application.php           Owns the ReactPHP event loop: render timer, resize timer, input handling
    Component.php             Base class for every UI component (see docs/console-framework.md)
    EventBus.php               Pub/sub used for key/mouse/custom events
    CommandInvoker.php          Thin wrapper around php-di's Invoker (see Commands note below)
    MessageFormatter.php        JSON syntax highlighting for log payloads
    CommandAttributes/          #[KeyPressed] #[Mouse] #[OnEvent] #[Periodic] #[FirstRender]
    Commands/Command.php        Abstract marker class — currently has zero implementations (see known-issues.md)
    Components/                 Header, LogList, LogItem, Details, Navigation, Splash
    Windows/                    Main (root layout), Popup (stub, unused)
    State/AppState.php           Central observable state store (magic __get/__set)
    State/LogItem.php            Value object for a single log entry
    Support/Scroll.php           Cursor/offset scrolling math — the best-tested module in the repo
docs/                         Deeper documentation — see docs/README.md
examples/BasicExample.php     Runnable demo of the tp() API
tests/Unit/ScrollTest.php     Only test suite that currently exists
```

## Running things

```bash
composer install

# Start the TUI (in one terminal)
php bin/tapper

# In another terminal/process, run something that calls tp()
php examples/BasicExample.php
```

There is no `composer.json` `bin` alias beyond `bin/tapper`; it is run directly with `php`.

### Tests & lint

```bash
composer test:unit   # pest --compact
composer test:lint   # pint --test (check only, does not auto-fix)
vendor/bin/pint       # auto-fix style
```

CI (`.github/workflows/tests.yml`, `static.yml`) runs `test:unit` on PHP 8.2/8.3/8.4 × ubuntu/macos, and `test:lint` on PHP 8.3. There is no static analysis tool (phpstan/psalm) configured despite the workflow being named "Static Analysis" — it currently only runs Pint (code style), not type checking.

## Key architectural facts an agent should know before editing

- **Two processes, two concurrency models.** The debuggee (anything calling `tp()`) runs normal synchronous PHP and blocks on a socket round-trip in `Runtime/Tapper.php::send()`. The TUI process (`bin/tapper`) is fully async on a ReactPHP event loop. Never assume `tp()` callers can be non-blocking, and never introduce blocking I/O inside `Console/*` — it runs on the loop.
- **State flows one way into the TUI:** `Server.php` decodes a socket message → builds a `State\LogItem` → calls `AppState::appendLog()` → `AppState` notifies its `onChange` callback → `Application` sets `shouldDraw = true` → next 1/60s tick redraws. Components read `AppState` directly in `view()`; they do not receive props from parents in a React sense — see `docs/console-framework.md`.
- **`AppState` uses magic `__get`/`__set`.** Every field must exist as a constructor-promoted property *and* be documented in the `@property` docblock at the top of the class, or IDE/static tooling won't see it. `observe($name, $cb)` validates `$name` against `get_class_vars()` at runtime, not compile time.
- **There is a known, unresolved bug in `AppState` batching** (`deffer()`/`commit()`) — see the `@TODO` comment in `src/Console/State/AppState.php`. Don't build new features on top of `deffer()`/`commit()` without checking whether it still needs fixing.
- **Components declare behavior via PHP 8 attributes**, wired up by reflection in `Component::__construct` (`#[KeyPressed]`, `#[Mouse]`, `#[OnEvent]`, `#[Periodic]`, `#[FirstRender]`). See `docs/console-framework.md` for exact semantics (especially `global: true`, which is easy to get wrong).
- **Rendering is not backend-agnostic.** `Component::view()` returns a concrete `PhpTui\Tui\Widget\Widget`, and every component uses `php-tui` layout primitives (`Area`, `Constraint`, `Direction`, `Layout`) directly. If you are working toward the GLFW goal, do not scatter more `php-tui`-specific calls into component logic without first reading `docs/known-issues.md#framework-extraction-readiness` — the abstraction boundary that would make backends swappable does not exist yet, and it's the single biggest gap.
- **The RPC/transport layer is otherwise unfinished** (see below), though the socket-path bug is fixed: both `Server.php` and `Rpc/JsonRpcClient.php` now resolve the unix-socket path through the shared `Tapper\SocketPath::resolve()` (`src/SocketPath.php`) instead of computing it independently. See `docs/known-issues.md` before touching `Rpc/*`.
- **`docs/openrpc.json` is stale.** It documents an `appendLog` method with a nested `details` param; the actual server (`Server.php`) implements `log` and `wait` methods with flat params. Don't treat `openrpc.json` as ground truth for the wire format — see `docs/rpc-protocol.md` for what's actually implemented.
- **`Commands/Command.php` and `CommandInvoker` have no concrete usages anywhere in the codebase.** Treat this as unfinished scaffolding, not an established pattern to extend — confirm with the project owner before building on it.

## Conventions to follow when editing

- PSR-4 autoload root: `Tapper\` → `src/`. Namespaces mirror directory structure exactly.
- `declare(strict_types=1);` at the top of every PHP file — keep this on new files.
- Style is enforced by Laravel Pint (`vendor/bin/pint`) — run it before finishing a change; don't hand-format.
- No PHPDoc/comment blocks explaining *what* code does — only ones explaining non-obvious *why* (this mirrors the project owner's general preference, not something specific to this file).
- Prefer extending the existing attribute/`EventBus` pattern over inventing a new event mechanism for TUI interactions.
- New `AppState` fields: add to the constructor-promoted property list **and** the `@property` docblock in the same change — they're both authoritative and must stay in sync.

## Where to look next

- [`docs/README.md`](docs/README.md) — documentation index.
- [`docs/architecture.md`](docs/architecture.md) — full data-flow walkthrough, process boundaries.
- [`docs/console-framework.md`](docs/console-framework.md) — Component/EventBus/AppState mechanics in detail.
- [`docs/rpc-protocol.md`](docs/rpc-protocol.md) — the actual wire protocol between debuggee and TUI.
- [`docs/known-issues.md`](docs/known-issues.md) — bugs, gaps, and the framework-extraction roadmap.