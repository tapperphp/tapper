# The console component framework

Everything under `src/Console/` (minus `State/`, `EventBus.php`, `main.php`, `Application.php`) is built around one base class, `Component`, plus five attributes and one pub/sub bus. This doc is the detailed reference; `AGENTS.md` has the condensed must-know version.

This layer is **not backend-agnostic today** — `Component::view()` returns a concrete `php-tui` `Widget`, and layout is done with `php-tui`'s `Area`/`Constraint`/`Direction`/`Layout`. Treat everything below as "how the TUI is built," not yet as "the framework" — see [`known-issues.md`](known-issues.md#framework-extraction-readiness).

## `Component` (`src/Console/Component.php`)

Abstract base class. Every concrete component (`Header`, `LogList`, `LogItem`, `Details`, `Navigation`, `Splash`, and the two `Windows/*`) extends it and implements one method:

```php
abstract protected function view(Area $area): Widget;
```

### Construction-time wiring

`Component::__construct()` does five things, in order, using PHP reflection over the concrete class's methods:

1. Calls a `beforeInit()` method if one exists (via the DI container, so it's autowired).
2. Scans methods for `#[KeyPressed]`, `#[Mouse]`, `#[OnEvent]` attributes and registers a closure with `EventBus::listen()` for each. The closure captures the attribute instance and does the matching described below before calling the method.
3. Scans methods for `#[Periodic]` and registers a ReactPHP periodic timer calling the method directly.
4. Scans methods for `#[FirstRender]` and stashes them to be called once, the first time `render()` is invoked (not from the constructor — `$area` isn't known yet at construction time).
5. Instantiates every class listed in `protected array $components = [...]` via the container (`registerComponents()`), then calls `afterInit()` if it exists.

Because this all happens in the base constructor via reflection, **every component pays this cost on every instantiation** — including the `LogItem` instances created per visible row in `LogList::ensureVisible()`. This is fine at current scale (a few dozen components) but is worth knowing if you're ever tempted to construct components in a hot loop.

### Event matching rules

These are the actual runtime rules baked into the closure registered in step 2 above — read this before adding a new `#[KeyPressed]`/`#[Mouse]` handler, the logic is not obvious from the attribute alone:

- **`#[KeyPressed($key, $keyModifiers = null, $global = false)]`** — if `$keyModifiers` is set, the event's modifiers must match *exactly* (not "at least contain"). If the component `isActive() === false` and `$global` is `false`, the handler is skipped entirely.
- **`#[Mouse($key, $button = MouseButton::Left, $global = false)]`** — for scroll events (`ScrollUp/Down/Left/Right`) the button is ignored. For click events, the event's button must match `$button` exactly. Same `global`/`isActive()` gating as above.
- **`#[OnEvent($key, $global = false)]`** — matches on `EventBus::emit()`'s custom string keys (e.g. `'resize'`, `'input'`). Same `global`/`isActive()` gating.
- **`#[Periodic($interval, $global = false)]`** — note the `$global` parameter is declared but **not read anywhere** in `Component::__construct()`; periodic timers always fire regardless of `isActive()`. Don't rely on `global: false` doing anything for `#[Periodic]`.
- **`#[FirstRender]`** — fires once, on the first `render()` call, regardless of `isActive()`.

`isActive()`/`activate()`/`deactivate()` is a per-component boolean the component tree uses to route input only to the "focused" component — see `Windows/Main.php::afterInit()` for the pattern: it observes `AppState`'s `previewLog` and toggles `Details` vs `LogList` active state via `Loop::futureTick()` (deferred by one tick so the activation change doesn't happen mid-event-dispatch).

### Rendering

`render(Area $area)` is called top-down starting from `Application::draw()` on the root `Main` component. First call captures `$area` and fires any `#[FirstRender]` methods; every call (first or not) then delegates to the abstract `view($area)`, which returns the actual `Widget` tree for that subtree. Parent components call `renderComponent($childClass, $area)` / `getComponent($childClass)->render($area)` on their registered children — there is no automatic recursive render; each parent's `view()` explicitly renders the children it wants, in the layout it wants (see `Windows/Main.php::view()` for the canonical 3-row `GridWidget` split: header / body / nav).

Components do **not** receive props from parents. A component that needs external data (e.g. `LogItem` needs to know which log entry to draw) exposes an explicit setter (`LogItem::setData()`) that the parent (`LogList::fill()`) calls before rendering. There's no generalized prop-passing mechanism — each case is bespoke.

### Commands (currently unused)

`protected function execute(Command $command): mixed` delegates to `CommandInvoker::invoke()`, which just calls `Invoker\InvokerInterface::call($command)` (php-di's auto-wiring invoker). `Commands/Command.php` is an empty abstract class. **No component anywhere calls `execute()` and no concrete `Command` subclass exists in the codebase.** This looks like the start of a command-object pattern (useful for e.g. undo/redo or macro recording) that was never followed through. Don't assume it's an established convention to build on without checking with the project owner first.

## `EventBus` (`src/Console/EventBus.php`)

A flat `string|int => callable[]` registry. `listen($event, $func)` normalizes `KeyCode`/`MouseEventKind` enum keys to strings (`$event->name`, or `"Mouse{$event->name}"` for mouse kinds) before storing. `emit($event, $data = [])` does the same normalization on the way out, so a `KeyPressed(KeyCode::Enter)` attribute and a raw `EventBus::listen(KeyCode::Enter, ...)` call in `Server.php` end up on the exact same registry key (`Server.php` relies on this — see `architecture.md`'s note on `wait`).

There is no unsubscribe. Every `listen()` call is permanent for the lifetime of the process. Combined with `Server.php`'s `wait` handler registering a fresh `KeyCode::Enter` listener per `wait()` call (see `known-issues.md`), this bus only ever grows.

`emit()` silently no-ops if nothing is registered for a key — there's no way to detect "nobody handled this event."

## `AppState` (`src/Console/State/AppState.php`)

A single, app-wide, mutable object. It is the *only* channel by which the socket-facing `Server.php` communicates with the rendering tree — there is no separate "props" or "context" system.

- **Magic properties**: `__get`/`__set` proxy to real private/constructor-promoted properties. The `@property` PHPDoc block at the top of the class is what makes these visible to IDEs/static analysis at all — it is not generated, it must be hand-kept in sync with the constructor's promoted properties. If you add a field to one and forget the other, you get either a silent dynamic property (constructor not updated) or an IDE that can't see a real property (docblock not updated).
- **Change notification**: every `__set` (outside a batch) calls `notifyChange()` (the single `$change` callback registered once, by `Application::startRendering()`, to flip `shouldDraw = true`) and `callObservers($name)` (per-field subscribers registered via `observe($name, $callable)`).
- **Field-specific observers**: `observe(string $name, callable $callable)` validates `$name` exists via `get_class_vars($this::class)` at call time (a `RuntimeException` if you typo a field name — this is your only safety net, and it's runtime-only). Multiple components observe overlapping fields today, e.g. `LogList::beforeInit()` observes `logs`, `cursor`, and `live` to keep unread counts and live-follow behavior in sync — read that method as the canonical example of cross-field reactive logic.
- **Batching**: `deffer()` (sic — note the actual method name has this typo, not "defer") sets a `batching` flag; subsequent `__set` calls accumulate field names into `$changed` instead of notifying immediately. `commit()` flips batching off, replays observers for every accumulated field, then calls `notifyChange()` once. **There is a documented, unresolved bug here** — see the `@TODO` comment directly in `AppState.php`: repeatedly setting the same field while batched (the example given is pressing Enter repeatedly while `tp()->wait()` is paused) causes `$changed` to "overflow" in some way that isn't fully diagnosed. Don't build new batched-write logic on top of `deffer()`/`commit()` until this is understood; prefer direct `__set` (unbatched) if you're unsure.

## `Support/Scroll.php`

The one piece of business logic in `Console/` that's pure enough to unit test in isolation, and the only class in the repo with a real test suite (`tests/Unit/ScrollTest.php`). It operates purely on `(cursor, offset)` pairs read/written through `AppState`, given `count`/`visible` as parameters — no widget, no rendering, no reflection. `LogList` and `Details` both have their own scroll-adjacent logic; `LogList` delegates to a `Scroll` instance, `Details` reimplements similar (but not identical, and untested) up/down/page logic inline (`Components/Details.php::up()/down()/pageUp()/pageDown()`) instead of reusing `Scroll`. If you're touching scrolling behavior in `Details`, consider whether it should be unified with `Support/Scroll` rather than diverging further.

## Practical checklist for adding a new component

1. Extend `Component`, implement `view(Area $area): Widget`.
2. If it needs to be constructed by a parent, add its class name to the parent's `protected array $components = [...]` and register it in the container graph implicitly via autowiring (no explicit binding needed for a plain class).
3. If it needs external data from its parent, add an explicit setter — don't reach for a generic prop-passing mechanism, there isn't one.
4. If it needs input, use `#[KeyPressed]`/`#[Mouse]`/`#[OnEvent]` — remember `global: true` if it should react while not the "active" component (e.g. a `q`-to-quit handler).
5. If it needs one-time setup once its `Area` is known, use `#[FirstRender]`, not the constructor.
6. If it reads `AppState`, read directly (`$this->appState->foo`) inside `view()` or an event handler — don't invent a new state channel.