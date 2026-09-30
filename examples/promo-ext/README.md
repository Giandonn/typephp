# promo-ext

This example compiles ordinary PHP business logic into a loadable PHP extension.
It implements a small e-commerce promotion/pricing engine and exposes it to any host
PHP process through `extension=`.

The extension exports:

- `PROMO_VERSION` — built-in rule/version constant.
- `promo_rules(): array` — the built-in promotion rules.
- `promo_quote(array $cart, array $context = []): array` — cart discount and payable amount.

## Files

```
promo-ext/
├── project.yml      # mode: ext
├── src/promo.php    # extension source (compiled, not shipped)
└── demo.php         # host-side usage example (not compiled)
```

`demo.php` is not part of `sources`, so it is never compiled into the extension. It plays
the role of the host project that only has the `.so` and none of the PHP sources.

## Build

From the `compiler` directory:

```sh
./tpc examples/promo-ext/project.yml --no-progress -j4
```

This produces `promo.so` in the current directory. The module name registered inside PHP is
`typephp_promo` (the `typephp_` prefix is added by the compiler); the file name stays `promo.so`.

## Load and run

```sh
php -d extension="$PWD/promo.so" examples/promo-ext/demo.php
```

Check the module registration:

```sh
php -d extension="$PWD/promo.so" -m | grep typephp_promo
php -d extension="$PWD/promo.so" --ri typephp_promo
```

## Notes

- The extension links PHPX (`libphpx.so`) only. Zend/PHP symbols are resolved from the host
  SAPI, so the host PHP must match the ABI this extension was built against (version, ZTS/NTS,
  debug flags).
- Because the extension depends on `libphpx.so` at runtime, keep the PHPX library available on
  the target machine (an rpath is written at build time). To ship a single file per deployment,
  also distribute the matching PHPX library.
