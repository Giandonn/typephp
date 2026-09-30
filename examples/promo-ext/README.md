# promo-ext

One example, one shared PHP source (`src/promo.php`, a small e-commerce promotion/pricing
engine), two build targets:

| Config | Mode | Output | Use |
| --- | --- | --- | --- |
| `project.yml` | `ext` | `promo.so` | Load into any host PHP with `extension=` |
| `project.fpm.yml` | `bin` + `sapi: fpm` | `promo_fpm` | Standalone php-fpm binary with the same logic compiled in |

The extension exports:

- `PROMO_VERSION` — built-in rule/version constant.
- `promo_rules(): array` — the built-in promotion rules.
- `promo_quote(array $cart, array $context = []): array` — cart discount and payable amount.

## Layout

```
promo-ext/
├── project.yml            # ① ext build
├── project.fpm.yml        # ② fpm build (php-builder)
├── src/promo.php          # shared business logic (AOT, used by both)
├── src/probe.php          # request-isolation probe (AOT, ② only; ① ignores it)
├── demo.php               # ① host-side usage example (not compiled)
├── public/index.php       # ② request script (embedded-files, nginx docroot)
├── conf/php-fpm.conf      # ② fpm pool
├── conf/nginx.conf        # ② user-space nginx
├── bench/reference.php    # ② pure-PHP reference implementation
├── bench/concurrency.php  # ② concurrency validator
├── run-fpm.sh             # ② build → start fpm → start nginx → validate → teardown
└── README.md
```

Both targets compile the same `src/promo.php`; only ② additionally compiles `src/probe.php` and
embeds `public/index.php`. `demo.php` is not part of `sources`, so it is never compiled: it plays
the role of a host project that only has the `.so` and none of the PHP sources.

## Build the extension

From the `compiler` directory:

```sh
./tpc examples/promo-ext/project.yml --no-progress -j4
```

This produces `promo.so` in the current directory. The module name registered inside PHP is
`typephp_promo` (the `typephp_` prefix is added by the compiler); the file name stays `promo.so`.

Load and run:

```sh
php -d extension="$PWD/promo.so" examples/promo-ext/demo.php
php -d extension="$PWD/promo.so" -m | grep typephp_promo
php -d extension="$PWD/promo.so" --ri typephp_promo
```

## Build and test the php-fpm binary

```sh
cd examples/promo-ext
./run-fpm.sh
```

`run-fpm.sh` builds `project.fpm.yml`, starts the resulting php-fpm and a user-space nginx, then
runs the concurrency validator and tears everything down. Overridable variables: `RUN_DIR` (default
`/tmp/typephp-fpm-bench`), `FPM_PORT` (19000), `NGINX_PORT` (18080), `CONCURRENCY` (1,8,32,64),
`REQUESTS` (200), `CASES` (32), `SEED`, `PHP_BIN`.

Build only:

```sh
./tpc examples/promo-ext/project.fpm.yml --no-progress -j4 -o /tmp/promo_fpm   # from compiler/
```

The result is a self-contained FPM binary: PHP 8.5 runtime, the AOT module and the request script
are all linked in, so it does not need a host PHP on the target machine.

### What the concurrency test checks

Every request carries a unique `id` and deterministic cart parameters; the same cases are repeated
and spread across workers. Verified per response:

1. **`id` echo matches** — no cross-request crosstalk.
2. **`seq` is always 1** — `promo_probe_seq()`'s function static is reset per request, so no
   request-level state leaks across requests in a long-lived worker.
3. **Result matches the pure-PHP reference** field by field (money to 2 decimals, including
   coupon applied/reason).

Measured locally (`pm.max_children = 4`):

```
并发     请求     一致     失败     worker     seq 取值
1          200        200        0          4          1x200
8          200        200        0          4          1x200
32         200        200        0          4          1x200
64         200        200        0          4          1x200

合计 800 请求，一致 800，失败 0
参与处理的 worker 进程：4 个；seq 取值分布：1x800
```

## Notes

**Both targets**

- Each target needs its own `build-dir` (`build/ext` vs `build/fpm`). With a shared build directory,
  the intermediate objects of the same source file are reused across targets, and `promo.so` ends
  up linked against the `promo_fpm` module namespace (`undefined symbol: ...promo_fpm...`).

**Extension target**

- The extension links PHPX (`libphpx.so`) only. Zend/PHP symbols are resolved from the host SAPI,
  so the host PHP must match the ABI it was built against (version, ZTS/NTS, debug flags).
- Because it depends on `libphpx.so` at runtime, keep the PHPX library available on the target
  machine (an rpath is written at build time).

**FPM target**

- `embedded-files` is required: with `sapi` builds that declare no embedded file, the compiler
  compiles `typephp_opcode_table.cc` but does not link `opcode_unserialize_*.c`, which fails with
  `undefined reference to typephp_opcache_load` (`compiler/src/Translator.php:2509-2516`).
- The request script is compiled into the binary, so **editing `public/index.php` requires a
  rebuild**; the docroot copy is not read at runtime.
- Non-root friendly: the FPM pool sets no `user`/`group` and uses `pm = static`; nginx runs as a
  user-space instance (`nginx -p <prefix> -c <conf>`) on a high port, with logs and temp dirs under
  `RUN_DIR`.
