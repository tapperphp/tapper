# Known issues, gaps, and the path toward a framework

This doc consolidates everything found during a full-codebase review (2026-08-15) that isn't just "how it works" (that's `architecture.md`/`console-framework.md`/`rpc-protocol.md`) but "this is broken, incomplete, or a decision point." Re-read the relevant section before touching an area listed here.

## Bugs (fix these regardless of the framework question)

### ~~Blocking: hardcoded socket path breaks the package for everyone but one machine~~ — fixed 2026-08-15

`src/Rpc/JsonRpcClient.php` used to connect to a literal absolute path (`unix:///Users/mateuszcholewka/Projects/tapperphp/tapper.sock`) instead of computing it. Fixed by extracting the shared computation into `Tapper\SocketPath::resolve()` (`src/SocketPath.php`), used by both `Server.php` and `Rpc/JsonRpcClient.php`, so the two sides can no longer diverge. `tapper.sock` is now also in `.gitignore` (it's a runtime artifact, not something to commit).

Verified with an isolated smoke test (no terminal required): booted `Server` directly against a real `AppState`/`EventBus`, confirmed the socket file appears at `SocketPath::resolve()`, and confirmed `tp()` from a separate process round-trips successfully (`result: ok`) instead of throwing `[Tapper] server not responding.`.

Note: `SocketPath::resolve()` still resolves to "the installed package's own directory" (`realpath(__DIR__.'/..')`), same semantics as the original `Server.php` code — when Tapper is installed as a dependency, that's `vendor/tapperphp/tapper/tapper.sock`, not the consuming project's root. That's fine functionally (both processes resolve the same install, so they agree), but if this ever needs to be more conventional (e.g. `sys_get_temp_dir()`-based, to avoid touching `vendor/`), that's a deliberate follow-up, not part of this fix.

### ~~`wait()` listeners on `EventBus` are never cleaned up~~ — fixed 2026-08-15

Previously every `tp(...)->wait()` call added a permanent `KeyCode::Enter` listener to `EventBus`, never removed, so overlapping `wait()` calls all resolved on the same keypress instead of one at a time. Fixed in `Server.php`: wait resolvers are now pushed onto a FIFO queue (`$waitResolvers`), and a single `KeyCode::Enter` listener is registered once (`registerWaitListener()`, guarded by `$waitListenerRegistered`) that pops and resolves the oldest pending wait per keypress. No per-call listener is added anymore, so there's nothing to leak, and N overlapping waits now require N separate Enter presses, in order. `EventBus` itself still has no general unsubscribe mechanism — see below, this remains a real gap for any future feature with dynamic listener lifecycles.

### ~~`Application::run()` registers signal handlers after the blocking call that would need them~~ — fixed 2026-08-15

`addSignal(SIGINT, ...)`/`addSignal(SIGTERM, ...)` are now registered before `$this->loop->run()`, so they're actually reachable, and their bodies call `$this->close()` (restores raw mode, mouse capture, cursor, alternate screen) instead of `echo 'kill'`. **This alone does not make Ctrl+C work**, though — see below.

### Ctrl+C did nothing even after the signal-handler fix above — fixed 2026-08-15

Root cause: `Terminal::enableRawMode()` shells out to `stty raw` (`vendor/php-tui/term/src/RawMode/SttyRawMode.php`), which disables `ISIG` at the tty driver level. With `ISIG` off, Ctrl+C is never translated into a `SIGINT` signal in the first place — it arrives as a normal byte, `\x03` (ETX), on stdin, same as any other keypress. So `$this->loop->addSignal(SIGINT, ...)` was structurally unreachable for the Ctrl+C case specifically, regardless of registration order (it still matters for external `kill -INT`/`SIGTERM`, which aren't tty-mediated). Fixed in `Application::startInputHandling()` by treating `"\x03"` the same as the existing `'q'` raw-byte check — both now call `$this->close()`.

### ~~`AppState` batching bug (documented in-code, unresolved)~~ — fixed 2026-08-15

`$changed` was a list, so `__set`-ing the same field repeatedly while batched (e.g. pressing Enter many times while `tp()->wait()` is paused) appended a duplicate entry per write, growing unbounded and replaying that field's observers once per duplicate on `commit()`. Fixed by keying `$changed` on field name (`$this->changed[$name] = true`) so it behaves as a set; `commit()` iterates `array_keys($this->changed)`. Same fix applied to `appendLog()`'s batched branch (`'logs'` key).

### ~~`JsonRpcRequest::payload()` references an undefined `$id`~~ — fixed 2026-08-15

Added a real `private ?string $id = null` constructor parameter; `payload()` now reads `$this->id ?? uniqid('rpc_', true)` instead of an undefined `$id` that only worked by accident via `??`'s notice suppression.

### ~~`Server::$id` is `static` inside a container-managed singleton~~ — fixed 2026-08-15

Changed to `private int $id = 0` (instance property), consistent with the rest of the class's DI-managed design.

### ~~PHP warnings/notices printed straight onto the live TUI, corrupting the render~~ — fixed 2026-08-16

Reported case: pressing Space in `LogList` with zero log entries hit `LogList::select()` reading `$this->appState->logs()[$this->appState->cursor]` with an empty `logs` array — an undefined-array-key warning, repeated once per render tick, each one printed raw over the alternate-screen buffer. Two fixes:

1. Root cause: `LogList::select()` now reads via `?? null` and no-ops when there's no log at the cursor, instead of indexing blind.
2. General case: `Console\ErrorHandler::install()` (called first thing in `Application::run()`, before raw mode/alt-screen are enabled) installs a `set_error_handler` that logs any warning/notice/deprecation to `tapper.log` (`LogPath::resolve()`, same directory as `tapper.sock`) and sets a 5-second `AppState::errorNotice` banner (rendered by `Header`) instead of letting PHP print to stdout. Uncaught `Throwable`s still propagate normally — `bin/tapper`'s `catch (\Throwable $e)` (widened from `\Exception`) calls `Application::close()` to restore the terminal, logs via `ErrorHandler::logThrowable()`, then rethrows so the trace prints on a normal, restored terminal rather than corrupting the TUI.

Note: this intentionally does *not* revive `Windows/Popup.php` for the "check the logs" notice — see the dead-scaffolding entry below; the banner lives in `Header` via two new `AppState` fields (`errorNotice`, `errorNoticeExpiresAt`) instead.

## Incomplete abstractions (finish or remove, don't extend as-is)

- **`Rpc\JsonRpc` interface / `JsonRpcResult` / `JsonRpcError`** — `JsonRpcResult.php` and `JsonRpcError.php` are empty files; `JsonRpc`'s encode/parse methods exist only as commented-out code; `Server.php` builds raw arrays instead of using these types. See `rpc-protocol.md`.
- **`Commands/Command.php` + `CommandInvoker`** — an empty abstract class and an invoker with zero concrete `Command` subclasses anywhere in the codebase. Either commit to modeling user actions as `Command` objects (useful for undo/redo, macro recording, remapping keys later) or remove the layer until there's a real consumer.
- **`docs/openrpc.json`** — describes a method (`appendLog`, nested `details` param) that doesn't match the implemented protocol (`log`/`wait`, flat params). Regenerate from `Server.php` or delete.
- **`Windows/Popup.php`** — exists, is instantiated and checked (`Application::draw()` renders it when `isActive()`), but its `view()` just returns an empty `BlockWidget::default()` and nothing in the codebase ever calls `activate()` on it (confirmed by grep — zero hits). Dead scaffolding for a not-yet-built feature (likely a modal/dialog system) — fine to leave, but don't assume it's a working popup system if you go looking for one.
- **`AppState::typingMode` is only ever set to `false`**, never `true`, anywhere in the codebase (confirmed by grep). `Application::handleEventInTypingMode()` therefore always returns early — its entire body (redirecting character input, exiting on Esc) is currently unreachable dead code, presumably scaffolding for a not-yet-built text-input feature.
- **`EventBus` has no unsubscribe** — every `listen()` is permanent. This is fine for the fixed component tree Tapper has today (components are never torn down mid-run), but is a real gap the moment anything needs dynamic component lifecycles. (The `wait()` leak that used to be a direct symptom of this gap is fixed above by having `Server` register one permanent listener instead of one per call — that sidesteps the gap for this one case, it doesn't close it.)

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