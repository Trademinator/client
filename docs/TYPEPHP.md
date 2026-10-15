# M0 native-build experiments

The selected candidate is **swoole/typephp**, the PHP-to-C++17 compiler, not the
unrelated similarly named PHP typing library. The evaluated release is v0.9.4;
source documentation was inspected at commit
`c0610e12a33b81d59da0d8917c9c8530d6a7533f`.

TypePHP uses PHPX/Zend runtime interoperability. Composer files can be embedded
and executed as PHP opcodes; embedding is not equivalent to compiling every
dependency to native machine code. These experiments keep that distinction
visible.

| Project | Native source requested | Runtime PHP dependency boundary |
| --- | --- | --- |
| `project.yml` | `native/main.php` | Application and Composer dependencies embedded |
| `project-math.yml` | Math entry, `MathProbe`, both shared package source directories | Remaining Composer bootstrap/dependencies embedded |
| `project-client-aot.yml` | Entry plus the whole `src/` directory | Third-party Composer dependencies embedded |

`php tools/validate-native.php` compiles all three, invokes the resulting binaries,
and records build/run exit codes in `build/validation/native-report.json`. Runtime
probes must produce both REST and WebSocket fixture observations. The shared math
probe must pass its decimal expectations. Exit 2 means the compiler could not
start; exit 1 means a compile/run/check failed. Build/run success still requires
reviewing generated code and logs for source fallback before calling a source
directory fully AOT-compiled.

The script runs with a non-interactive compiler input and a ten-minute limit per
subprocess. Prepare the toolchain first; do not rely on an interactive compiler
prompt to install PHP. `--dry` generation alone, a successful `php bin/client.php`
run, and a successful embedded runtime probe each answer different questions.
None independently proves complete AOT compatibility.

`TPC_BIN` accepts a native `tpc` executable or a PHP `.php` launcher. For a
Composer installation in a separate directory:

```bash
export TPC_BIN="$HOME/.local/share/typephp/vendor/bin/tpc.php"
php tools/validate-native.php
```

PHP launchers run through `PHP_BINARY`, the same CLI interpreter that runs the
validator, without depending on their shebang or executable bit. Set `TPC_BIN`
to the script path alone, not a shell command such as `php /path/to/tpc.php`.
Native compiler executables and generated probes still execute directly.
Process launch failures include the underlying PHP warning and command in the
corresponding log file.

## Build-host requirements

1. A 64-bit TypePHP-compatible PHP build (8.4 or 8.5 for the evaluated compiler),
   with matching full version, architecture, ZTS/NTS mode, headers, `php-config`
   and Embed SAPI library.
2. PHPX built against that same PHP ABI and discoverable by the compiler/runtime.
3. A supported C++17 compiler and native libraries required by PHP/PHPX.
4. BCMath, curl, GMP, mbstring, OpenSSL, PDO SQLite and their transitive runtime
   requirements. The compiler configurations declare extension dependencies;
   these declarations do not bundle extension implementations.
5. Matching PHP CLI and Zend OPcache for the `embedded-files` build step.
6. `composer install` using this project's lockfile.

Compile separately on Linux, macOS and Windows. A PHP runtime matrix does not
validate the native toolchain. On Windows, set `TPC_BIN` to the executable path
and supply the matching DLLs through the toolchain's documented environment.
When `tools/validate-native.php` launches generated Windows probes, it points
`PHPRC` at `PHP_HOME\php.ini` (falling back to the directory containing
`PHP_BINARY`) and clears `PHP_INI_SCAN_DIR`. This keeps the relocated probes on
the same packaged extension configuration as the validator without depending on
machine-wide PHP ini files.

## Acceptance checks after successful builds

- Inspect build logs/generated output for fallback or omitted sources. Record
  which application/shared-library functions actually execute as native code.
- Move a binary and its required native libraries to a clean location without
  this checkout or `vendor/`; run `doctor`, `math`, and a fixture probe there.
  A binary working beside its source directory does not prove source isolation.
- Run local `status`/`stop` while the native daemon has a stalled request. Check
  cancellation, clean shutdown, SQLite reopening and no leftover port binding.
- Run real public exchange WebSocket and REST paths and authenticated Server
  reads; record the actual observed transport counters and Server status.
- Run an extended test (start with 24 hours) on each target OS. Include process
  RSS, PHP allocator usage, file handles and SQLite size after warm-up. Exercise
  repeated disconnects and reconnects, not just an uninterrupted feed.

If source restrictions require an embedded Composer boundary, record the
performance and packaging trade-off before deciding whether it satisfies M0.
Do not silently relabel a hybrid build as a fully compiled client or abandon
TypePHP without that evidence.

Primary references:

- https://github.com/swoole/typephp
- https://github.com/swoole/typephp/blob/master/docs/en/EMBEDDED_FILES.md
- https://github.com/swoole/typephp/blob/master/docs/en/INCOMPATIBLE_PHP_FEATURES.md
- https://github.com/swoole/typephp/blob/master/docs/en/COMPILER_CLI.md
