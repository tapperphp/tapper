# Known issues, gaps, and the path toward a framework

This doc consolidates everything found during a full-codebase review (2026-08-15) that isn't just "how it works" (that's `architecture.md`/`console-framework.md`/`rpc-protocol.md`) but "this is broken, incomplete, or a decision point." Re-read the relevant section before touching an area listed here.

## Bugs (fix these regardless of the framework question)

### ~~Blocking: hardcoded socket path breaks the package for everyone but one machine~~ — fixed 2026-08-15

`src/Rpc/JsonRpcClient.php` used to connect to a literal absolute path (`unix:///Users/mateuszcholewka/Projects/tapperphp/tapper.sock`) instead of computing it. Fixed by extracting the shared computation into `Tapper\SocketPath::resolve()` (`src/SocketPath.php`), used by both `Server.php` and `Rpc/JsonRpcClient.php`, so the two sides can no longer diverge. `tapper.sock` is now also in `.gitignore` (it's a runtime artifact, not something to commit).

Verified with an isolated smoke test (no terminal required): booted `Server` directly against a real `AppState`/`EventBus`, confirmed the socket file appears at `SocketPath::resolve()`, and confirmed `tp()` from a separate process round-trips successfully (`result: ok`) instead of throwing `[Tapper] server not responding.`.

~~Note: `SocketPath::resolve()` still resolves to "the installed package's own directory"~~ — fixed 2026-08-16: `SocketPath::resolve()` and `LogPath::resolve()` now resolve to `sys_get_temp_dir().'/tapper.sock'` / `'/tapper.log'` instead of `realpath(__DIR__.'/..')`. This was flagged as a deliberate follow-up above, and became a real requirement once native packaging entered the picture — a static-php-cli/phpmicro build (`docs/architecture.md` roadmap) embeds `src/` inside a read-only Phar, so a path computed from `__DIR__` would point at a virtual filesystem that can't hold a socket file or an appended-to log file. `sys_get_temp_dir()` is always a real, writable directory regardless of how the app is packaged. Trade-off: this drops the previous per-install isolation (two different projects each requiring `tapperphp/tapper` from their own `vendor/` used to get distinct socket paths for free, since `__DIR__` differed per install) — now every local Tapper session shares `<tmp>/tapper.sock`, so only one debuggee/TUI pair should run at a time per machine. Revisit if that turns out to matter in practice (e.g. namespace the filename by project directory hash).

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

Note: this intentionally does *not* route the "check the logs" notice through `Windows/Popup.php` (a full-screen modal isn't the right shape for a transient 5-second notice); the banner lives in `Header` via two new `AppState` fields (`errorNotice`, `errorNoticeExpiresAt`) instead.

### ~~Blocking: typed class constants (PHP 8.3+ syntax) broke every PHP 8.2 install despite README/CI claiming 8.2 support~~ — fixed 2026-08-16

`private const string FOO = '...';`-style *typed* class constants are a PHP 8.3 feature — on PHP 8.2 the parser throws `ParseError: syntax error, unexpected identifier "FOO", expecting "="` on the file, at include time, regardless of whether the constant is ever read. This pattern was scattered across most of `Console/*` (`Application`, `Component`, `ErrorHandler`, `Palette`, `MessageFormatter`, `PhpHighlighter`, `Components/{Splash,Details,LogItem}`, `Windows/Main`) — i.e. it would have broken `bin/tapper` for any PHP 8.2 user the moment a log rendered (`MessageFormatter`/`Palette` are on the hot render path for every list row).

It went undetected by CI (which does run PHP 8.2 in the matrix) purely because the old, minimal test suite (`Scroll`, `ScrollbarRenderer`, `SpanTruncator`) never autoloaded any of the affected classes — PHP never parsed the offending files under 8.2, so there was nothing to fail on. It surfaced the moment new tests started exercising `MessageFormatter`/`PhpHighlighter` directly. Fixed by dropping the type annotation from every class constant repo-wide (behavior is identical, `const FOO = '...'` works on 8.2–8.4); `composer.json` also had no `"php"` constraint at all, so `composer require`d installs wouldn't have been warned either — added `"php": "^8.2"` to `require` to match what the README already promises.

This is a strong argument for the still-open Console/Components/Server/Application test-coverage gap noted elsewhere in this doc: coverage that's too thin doesn't just miss logic bugs, it can hide the codebase not even parsing on a supported PHP version.

## Incomplete abstractions (finish or remove, don't extend as-is)

- **`Rpc\JsonRpc` interface / `JsonRpcResult` / `JsonRpcError`** — `JsonRpcResult.php` and `JsonRpcError.php` are empty files; `JsonRpc`'s encode/parse methods exist only as commented-out code; `Server.php` builds raw arrays instead of using these types. See `rpc-protocol.md`.
- **`Commands/Command.php` + `CommandInvoker`** — an empty abstract class and an invoker with zero concrete `Command` subclasses anywhere in the codebase. Either commit to modeling user actions as `Command` objects (useful for undo/redo, macro recording, remapping keys later) or remove the layer until there's a real consumer.
- **`docs/openrpc.json`** — describes a method (`appendLog`, nested `details` param) that doesn't match the implemented protocol (`log`/`wait`, flat params). Regenerate from `Server.php` or delete.
- ~~**`Windows/Popup.php`**~~ — corrected 2026-08-16: this entry was stale. `Popup` is a working shortcuts-help modal (toggled with `?`, closed with `Esc`, real `view()` content) — not dead scaffolding. `Application::draw()` renders it based on `AppState::popupOpen`, not `isActive()`; `Popup`'s own bindings are `global: true` so its `isActive` is never touched at all, by design.
- ~~**`AppState::typingMode` is only ever set to `false`**~~ — resolved 2026-08-16: it's now the basis for log filtering. `LogList::startFilter()` (bound to `/`) sets it `true`; `Console\Components\Filter` (a standalone overlay owned by `Application`, mirroring how `Popup` is owned rather than being a `Main` child) uses `Application::handleEventInTypingMode()`'s `'input'` event to build `AppState::filter`, live-filtered via `AppState::filteredLogs()` (case-insensitive substring match on `LogItem::$message`). Enter confirms and exits typing mode keeping the filter; Esc cancels and clears it. See `Filter.php` for why its key bindings are deliberately non-global. One related fix required to make this safe: `handleEventInTypingMode()` used to pass the *raw stdin chunk* as the `'input'` payload for every drained event — since one stdin read can drain several events (fast typing/paste), they'd all have shared and duplicated the same bytes. It now emits the event's own `CharKeyEvent::$char` instead. Also: `Application::startInputHandling()`'s `'q'`-quits-the-app check now only fires outside typing mode, since `q` is a normal character you'd type into the filter box.
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