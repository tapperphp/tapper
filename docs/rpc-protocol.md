# Wire protocol

This documents what is **actually implemented** between a debuggee process (`Runtime\Tapper` / `Rpc\JsonRpcClient`) and the TUI process (`Server.php`). `docs/openrpc.json` exists in this repo but describes a different, unimplemented shape — see [Divergence from `openrpc.json`](#divergence-from-openrpcjson) below. Treat this document, not the OpenRPC file, as ground truth until someone reconciles them.

## Transport

Two transports, selected by whether a port is configured — there is no separate mode flag:

- **Unix domain socket (default)**, path `sys_get_temp_dir().'/tapper.sock'`, resolved by `Tapper\SocketPath::resolve()` (`src/SocketPath.php`). Used whenever no port is given: `bin/tapper` without `--port`, and `Runtime\Tapper` when the `TAPPER_PORT` env var is unset/empty.
- **TCP, `127.0.0.1:<port>`** — `bin/tapper --port=2138` binds `Server` there instead of the socket; `TAPPER_PORT=2138` makes `Runtime\Tapper` connect there instead. Both sides must agree on the same port. `AppState::$port` is `null` in socket mode (Header hides the "port: N" segment) and holds the configured port in TCP mode (Header shows it).
- One line of JSON per message, `\n`-terminated, decoded with `clue/ndjson-react` on the server side.
- The client (`Rpc/JsonRpcClient.php`) is a **blocking** `stream_socket_client()` — connect timeout 0.5s by default, then `stream_set_timeout()` + blocking `fgets()` for the reply, using a separate `pauseTimeout` (default 3600s) so a `wait()` call can block for up to an hour waiting for the user to press Enter in the TUI.

## Request shape (client → server)

Built by `Rpc\JsonRpcRequest::payload()`:

```json
{
  "jsonrpc": "2.0",
  "method": "log",
  "params": { "...": "..." },
  "id": "rpc_..."
}
```

- `id` defaults to `uniqid('rpc_', true)` when the caller doesn't supply one — `JsonRpcRequest`'s constructor takes an optional `?string $id`, and `payload()` reads `$this->id ?? uniqid('rpc_', true)`.
- Two methods are implemented server-side: `log` and `wait`.

### `log` params (sent by every plain `tp($value)`)

| field | type | notes |
|---|---|---|
| `message` | any (JSON-encodable) | the raw value passed to `tp()` |
| `microtime` | float | `microtime(true)` at call time |
| `caller` | string | `"basename(file):line"` of the immediate caller |
| `trace` | array of `{file, line}` | backtrace, `DEBUG_BACKTRACE_IGNORE_ARGS`, first 2 frames skipped |
| `rootDir` | string\|null | resolved via Composer's `ClassLoader` file location |
| `code` | array of `{number, line, active}` | ±3 lines of source around the caller line |
| `kind` | string\|null | `null`/absent for a normal value; `"error"` when `tp()` was called with a `Throwable` — in that case `message` is a preformatted string (not JSON-encoded) and `caller`/`trace`/`code` describe where the exception was thrown, not the `tp()` call site |

Server response: `{"jsonrpc":"2.0","result":"ok","id":<id>}`.

Consecutive `log`/`wait` calls with identical `kind`+`message`+`caller` are coalesced server-side (`AppState::appendLog()`) into one entry with an incrementing `LogItem::$repeatCount`, instead of appending a new row per call — this is what a UI-facing "×N" counter renders from.

### `wait` params

Same shape as `log`. Server behavior differs: it still calls `AppState::appendLog()` immediately (so the "paused" message shows up right away), but **does not reply immediately**. Instead it registers a listener on `EventBus` for `KeyCode::Enter` that writes the deferred reply the next time Enter is pressed anywhere in the TUI:

```json
{"jsonrpc":"2.0","result":"continue","id":<id>}
```

⚠️ **This listener is never removed.** If two `tp(...)->wait()` calls are in flight concurrently (e.g. two debuggee processes, or a loop that somehow overlaps), *both* accumulated `KeyCode::Enter` listeners fire on the next Enter press, and each writes to its own socket — so both would resolve on the same keypress rather than one-at-a-time. `EventBus` has no unsubscribe mechanism (see `console-framework.md`), so fixing this requires either adding one or tracking wait-listeners explicitly in `Server.php`.

### Any other method

```json
{"jsonrpc":"2.0","error":{"code":-32601,"message":"Method 'xyz' not found"},"id":<id>}
```

### Malformed request (missing/wrong `jsonrpc` field)

```json
{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":<id or null>}
```

## Response value objects exist but are unused

`Rpc\JsonRpcResult` and `Rpc\JsonRpcError` are both **empty files** (no class body at all). `Server.php` builds response arrays by hand with raw `$encoder->write([...])` calls instead of using typed value objects. The `Rpc\JsonRpc` interface declares one real method (`payload(): array`) and has four more (`encodeRequest`/`encodeResult`/`encodeError`/`parse`) present only as commented-out code. This layer was clearly meant to be more structured than it currently is — if you're touching `Rpc/`, either finish it (give `JsonRpcResult`/`JsonRpcError` real bodies and have `Server.php` use them) or remove the dead files/commented code so the interface reflects what's actually implemented.

## Divergence from `openrpc.json`

`docs/openrpc.json` documents a method called `appendLog` with params `message`, `microtime`, and a nested `details: {trace, rootDir, code}` object. The actual implementation (`Server.php`) has:

- Method name `log`, not `appendLog`.
- A second method, `wait`, not documented in the OpenRPC file at all.
- Flat params (`caller`, `trace`, `rootDir`, `code` all siblings), not a nested `details` object.
- `caller` is not present in the OpenRPC schema at all.

This file appears to have been written as a target/spec before (or independent of) the current implementation, and hasn't been kept in sync. Don't generate client/server code from it as-is — either regenerate it from the real `Server.php` switch statement, or delete it if it's not being used to drive anything.