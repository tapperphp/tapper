# Known issues, gaps, and the path toward a framework

This doc consolidates everything found during a full-codebase review (2026-08-15) that isn't just "how it works" (that's `architecture.md`/`console-framework.md`/`rpc-protocol.md`) but "this is broken, incomplete, or a decision point." Re-read the relevant section before touching an area listed here.

## Bugs (fix these regardless of the framework question)

### ~~Blocking: hardcoded socket path breaks the package for everyone but one machine~~ — fixed 2026-08-15

`src/Rpc/JsonRpcClient.php` used to connect to a literal absolute path (`unix:///Users/mateuszcholewka/Projects/tapperphp/tapper.sock`) instead of computing it. Fixed by extracting the shared computation into `Tapper\SocketPath::resolve()` (`src/SocketPath.php`), used by both `Server.php` and `Rpc/JsonRpcClient.php`, so the two sides can no longer diverge. `tapper.sock` is now also in `.gitignore` (it's a runtime artifact, not something to commit).

Verified with an isolated smoke test (no terminal required): booted `Server` directly against a real `AppState`/`EventBus`, confirmed the socket file appears at `SocketPath::resolve()`, and confirmed `tp()` from a separate process round-trips successfully (`result: ok`) instead of throwing `[Tapper] server not responding.`.

Note: `SocketPath::resolve()` still resolves to "the installed package's own directory" (`realpath(__DIR__.'/..')`), same semantics as the original `Server.php` code — when Tapper is installed as a dependency, that's `vendor/tapperphp/tapper/tapper.sock`, not the consuming project's root. That's fine functionally (both processes resolve the same install, so they agree), but if this ever needs to be more conventional (e.g. `sys_get_temp_dir()`-based, to avoid touching `vendor/`), that's a deliberate follow-up, not part of this fix.

### `wait()` listeners on `EventBus` are never cleaned up

See `rpc-protocol.md`'s `wait` section — every `tp(...)->wait()` call adds a permanent `KeyCode::Enter` listener to `EventBus` that's never removed, even after it fires. Two overlapping `wait()` calls will both resolve on the same keypress instead of one at a time. `EventBus` has no unsubscribe mechanism at all today (see below).

### `Application::run()` registers signal handlers after the blocking call that would need them

```php
$this->loop->run();                                   // blocks until loop stops

$this->loop->addSignal(SIGINT, function () { echo 'kill'; });
$this->loop->addSignal(SIGTERM, function () { echo 'kill'; });
```

Both `addSignal` calls are unreachable until `loop->run()` returns, at which point registering them is moot. Move them before `$this->loop->run()`. Also worth deciding what SIGINT/SIGTERM *should* do — right now the handler bodies just `echo 'kill'` rather than calling `$this->close()` to restore the terminal, so a Ctrl+C could leave the terminal in raw/alternate-screen mode.

### `AppState` batching bug (documented in-code, unresolved)

`src/Console/State/AppState.php`, directly above `__set`:

```php
/*
 * @TODO investigate why `changed` overflows
 * when setting something multiple times,
 * like pressing enter many times
 * when waiting is set on tp
 */
```

Don't build new logic on `deffer()`/`commit()` batching until this is diagnosed — prefer unbatched (direct) `__set` calls if a new feature doesn't clearly need batching.

### `JsonRpcRequest::payload()` references an undefined `$id`

```php
'id' => $id ?? uniqid('rpc_', true),
```

`$id` is never assigned in scope, so this always evaluates to `uniqid('rpc_', true)` — it works, but only because of PHP's `??` suppressing the undefined-variable notice, not because there's an actual optional-id feature. Either add a real `?string $id = null` constructor parameter to `JsonRpcRequest` if per-request ids are wanted, or simplify to a direct `uniqid('rpc_', true)` call and drop the `??`.

### `Server::$id` is `static` inside a container-managed singleton

`Server` is constructor-injected with `AppState`/`EventBus` (i.e., it's a normal DI-managed instance), but its log-id counter is `private static $id = 0`. Harmless today (one `Server` instance ever exists), but inconsistent with the rest of the class's design and would break if `Server` were ever instantiated more than once (e.g. in a test). Make it an instance property.

## Incomplete abstractions (finish or remove, don't extend as-is)

- **`Rpc\JsonRpc` interface / `JsonRpcResult` / `JsonRpcError`** — `JsonRpcResult.php` and `JsonRpcError.php` are empty files; `JsonRpc`'s encode/parse methods exist only as commented-out code; `Server.php` builds raw arrays instead of using these types. See `rpc-protocol.md`.
- **`Commands/Command.php` + `CommandInvoker`** — an empty abstract class and an invoker with zero concrete `Command` subclasses anywhere in the codebase. Either commit to modeling user actions as `Command` objects (useful for undo/redo, macro recording, remapping keys later) or remove the layer until there's a real consumer.
- **`docs/openrpc.json`** — describes a method (`appendLog`, nested `details` param) that doesn't match the implemented protocol (`log`/`wait`, flat params). Regenerate from `Server.php` or delete.
- **`Windows/Popup.php`** — exists, is instantiated and checked (`Application::draw()` renders it when `isActive()`), but its `view()` just returns an empty `BlockWidget::default()` and nothing in the codebase ever calls `activate()` on it (confirmed by grep — zero hits). Dead scaffolding for a not-yet-built feature (likely a modal/dialog system) — fine to leave, but don't assume it's a working popup system if you go looking for one.
- **`AppState::typingMode` is only ever set to `false`**, never `true`, anywhere in the codebase (confirmed by grep). `Application::handleEventInTypingMode()` therefore always returns early — its entire body (redirecting character input, exiting on Esc) is currently unreachable dead code, presumably scaffolding for a not-yet-built text-input feature.
- **`EventBus` has no unsubscribe** — every `listen()` is permanent. This is fine for the fixed component tree Tapper has today (components are never torn down mid-run), but is a real gap the moment anything needs dynamic component lifecycles (the `wait()` bug above is a direct symptom of this gap).

## Framework-extraction readiness

Context: the long-term goal is a general PHP desktop-app framework with a swappable rendering backend (this TUI, later a GLFW-based GUI). Decision as of 2026-08-15: **keep building Tapper as an application; do not extract a framework package yet.** The reasoning, and what would change this:

**Why not yet:** the framework/backend boundary doesn't exist as a real interface today — it only exists as intent. Concretely:

1. **No renderer abstraction.** `Component::view()` returns a concrete `PhpTui\Tui\Widget\Widget` directly (see `console-framework.md`). Every component builds `php-tui` widgets (`BlockWidget`, `GridWidget`, `ParagraphWidget`, ...) inline. A GLFW backend can't consume this — there is no intermediate, backend-neutral description of "what to draw" that a `TuiRenderer` and a future `GlfwRenderer` could both adapt.
2. **No layout abstraction.** Components use `php-tui`'s `Area`/`Constraint`/`Direction`/`Layout` directly, which model discrete terminal cells (rows/columns), not the continuous pixel/DPI space a GUI layout needs. This isn't a find-and-replace; it's a real design task (something flex-like that both a cell-grid and a pixel-canvas renderer can implement).
3. **No input abstraction.** `EventBus`/`#[KeyPressed]`/`#[Mouse]` are typed against `php-tui/term`'s `KeyCode`/`MouseEventKind`. A GLFW backend has entirely different key/mouse event types that would need to be normalized to something backend-neutral before components could react to them uniformly.
4. **Only one real example so far.** Extracting general abstractions from a single application (Tapper) means guessing at what's actually shared vs. TUI-specific — the layout/rendering boundary in particular is easy to get wrong without a second concrete case pushing on it.

**What would make extraction well-timed** (revisit this list before starting a framework package):

- Tapper has grown enough real UI surface (a second distinct view/screen, a working `Popup`, resizable/responsive layout, something beyond lists+details) that the *TUI-specific* parts of `Component`/`AppState`/`EventBus` have had to flex more than once — giving more confidence about what's actually backend-agnostic.
- Either a second real consumer of the component model exists (even a small non-TUI proof of concept), or there's a concrete, non-hypothetical GLFW spike that stress-tests the rendering/layout/input boundary directly, rather than reasoning about it in the abstract.
- The bugs in this document that touch the framework-adjacent layers (`AppState` batching, `EventBus` unsubscribe) are resolved — extracting a framework on top of an unresolved reactivity bug just relocates the bug into the framework's foundation.

**If/when extraction starts**, the natural package boundary based on the current code is:
- `core` (backend-agnostic): `Component` lifecycle/attribute-binding mechanics (minus the `php-tui`-typed bits), `EventBus` (generalized past `KeyCode`/`MouseEventKind`), `AppState`-style reactive state container, `CommandInvoker`.
- `adapter-tui`: everything that's `php-tui`-specific today — the widget-building code inside every `view()`, `Application`'s render/resize loop, the `php-tui/term` event types.
- `tapper` (the app): `Server.php`, `Runtime/`, `Rpc/`, and the concrete components (`Header`, `LogList`, `Details`, ...), as a consumer of `core` + `adapter-tui`.