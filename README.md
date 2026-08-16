<p align="center">
    <img src="assets/logo.webp" width="120" alt="Tapper">

</p>
<p align="center">
    <a href="https://github.com/tapperphp/tapper/actions"><img alt="GitHub Actions Workflow Status" src="https://img.shields.io/github/actions/workflow/status/tapperphp/tapper/tests.yml?&style=for-the-badge&label=Tests&branch=master"></a>
    <img alt="Development Statue" src="https://img.shields.io/badge/status-in%20development-yellow?style=for-the-badge">
</p>

Tapper is a tiny debugger for PHP. Call `tp($value)` anywhere in your code and
the payload streams live into a terminal UI running as a separate process —
like `console.log`, but with a dedicated, scrollable, filterable viewer
instead of stdout.

```PHP
<?php

tp('👋 Hello, this is Tapper');
tp('A tiny debugger for PHP');
tp('You can send debug messages, just like console.log in JS');

tp('It can also send structured data:');
tp(['fruits' => ['apple', 'banana', 'pineapple']]);

tp('Or even very long structured data');
tp(['fruits' => ['apple', 'banana', 'pineapple', 'orange', 'strawberry', 'blueberry', 'pear'], 'accounts' => [['name' => 'Alice', 'email' => 'alice@ec.net', 'subscribe' => false, 'age' => 26], ['name' => 'John', 'email' => 'john@ec.net', 'subscribe' => true, 'age' => 21], ['name' => 'Jane', 'email' => 'jane@ec.net', 'subscribe' => true, 'age' => 31]]]);

tp('You can pause code execution...');
foreach (range(1, 3) as $i) {
    tp("Paused in loop at iteration $i")->wait();
    tp('That will run after wait');
}
```

👇👇👇👇👇👇👇👇👇👇👇

![Tapper demo animation](assets/demo.webp)

## Installation

Tapper hasn't been released yet and isn't on Packagist, so `composer require`
won't work. For now, clone the repo and install it from source:

```bash
git clone https://github.com/tapperphp/tapper.git
cd tapper
composer install
```

Requires PHP 8.2+ (tested on 8.2, 8.3 and 8.4).

## Usage

Start the TUI in one terminal — it opens a local socket and waits for a script to connect:

```bash
php bin/tapper
```

Then, in another terminal, run any PHP script that calls `tp()` (see the
example above, or `examples/BasicExample.php`) — its output streams into the
TUI live:

```bash
php examples/BasicExample.php
```

### Keyboard shortcuts

- `↑`/`↓` (or `k`/`j`), `g`/`G` — move the cursor, jump to top/bottom
- `Enter` / `Space` — open the details view for the selected log (syntax-highlighted
  payload, source snippet, stack trace); `↑`/`↓`/`←`/`→` scroll it, `Backspace`/`Esc` closes it
- `/` — filter logs live as you type; `Enter` confirms, `Esc` clears the filter and returns to the live tail
- `Enter` — also resumes a script paused on `tp(...)->wait()`
- `Ctrl+L` — clear all logs
- `?` — shortcuts popup, `q` — quit

## Documentation

For the two-process architecture, the TUI's component model, and the wire
protocol between a debuggee script and the TUI, see [`docs/`](docs).

## Testing

```bash
composer test:unit   # Pest
composer test:lint   # Pint (check only)
```

## 🚧 Project Status

This project is in active development and not yet ready for production use.  
Expect breaking changes before v1.0 is released.
