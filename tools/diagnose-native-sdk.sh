#!/usr/bin/env bash
# Mageia PHP 8.5 SDK diagnostics, not a replacement for validate-native.php.
# Run from any directory: bash tools/diagnose-native-sdk.sh
set -euo pipefail
umask 077

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

# Apply SDK selection inside this process only. Never use live credentials/data.
unset PHPX_HOME PHP_HOME LD_LIBRARY_PATH TRADEMINATOR_API_KEY TRADEMINATOR_M0_DIAGNOSTIC
export TPC_BIN="${TPC_BIN:-$HOME/.local/share/typephp/vendor/bin/tpc.php}"

for tool in phpx-env8.5 php8.5 timeout; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf 'Required command not found: %s\n' "$tool" >&2
        exit 1
    fi
done
if ! grep -Fq 'M0 diagnostic v5: enabled' src/Application.php; then
    printf 'The v5 diagnostic patch is not present in this checkout.\n' >&2
    exit 1
fi
if [[ ! -r vendor/autoload.php ]]; then
    printf 'Run composer install from the committed lockfile first.\n' >&2
    exit 1
fi
compiler=("$TPC_BIN")
if [[ "$TPC_BIN" == *.php ]]; then
    if [[ ! -r "$TPC_BIN" ]]; then
        printf 'Compiler launcher not readable: %s\n' "$TPC_BIN" >&2
        exit 1
    fi
    compiler=(php8.5 "$TPC_BIN")
fi

phpx-env8.5 --check
phpx-env8.5 "${compiler[@]}" --version

mkdir -p build/validation
run_dir="$(mktemp -d "$root/build/client-aot-diagnostic.XXXXXX")"
binary="$run_dir/probe-client-aot"
printf 'Diagnostic build directory: %s\n' "$run_dir"

# New fixture configuration and state: no dependence on an older validation run.
phpx-env8.5 php8.5 -r '
    $dir = $argv[1];
    $config = ["mode" => "fixture", "data_dir" => $dir."/state", "ipc_port" => 0,
        "interval_ms" => 20, "ws_retry_seconds" => 1, "server_url" => ""];
    if (file_put_contents($dir."/fixture.json", json_encode($config, JSON_THROW_ON_ERROR)) === false) {
        fwrite(STDERR, "Cannot create diagnostic fixture.\n"); exit(1);
    }
' "$run_dir"

# A fresh output path prevents an old executable from being run after a failure.
timeout --kill-after=5s 600s phpx-env8.5 "${compiler[@]}" \
    project-client-aot.yml --build-dir "$run_dir/objects" -o "$binary" \
    -O2 --no-progress --no-color </dev/null 2>&1 | tee "$run_dir/build.log"
if [[ ! -x "$binary" ]]; then
    printf 'Compiler did not create the diagnostic executable.\n' >&2
    exit 1
fi

export TRADEMINATOR_M0_DIAGNOSTIC=1
failed=0
run_probe() {
    local name="$1" probe_exit=0
    shift
    printf '\n===== %s =====\n' "$name"
    timeout --kill-after=5s 20s phpx-env8.5 "$binary" "$@" \
        >"$run_dir/$name.stdout.log" 2>"$run_dir/$name.stderr.log" || probe_exit=$?
    cat "$run_dir/$name.stdout.log"
    cat "$run_dir/$name.stderr.log"
    printf '%s exit code: %s\n' "$name" "$probe_exit"
    if [[ "$probe_exit" -ne 0 ]]; then failed=1; fi
    if ! grep -Fq 'M0 diagnostic v5: enabled' "$run_dir/$name.stderr.log"; then
        printf 'Diagnostic marker missing: failure may precede Application::run().\n'
        failed=1
    fi
}

{
    printf 'Binary tested: %s\n' "$binary"
    run_probe math math
    run_probe daemon run --config="$run_dir/fixture.json" --seconds=6
    printf '\nIndividual logs and fresh fixture: %s\n' "$run_dir"
    printf 'Combined log: %s/build/validation/client-aot-diagnostic.log\n' "$root"
    # Reflect probe failures. A zero code still is not an M0 acceptance decision.
    exit "$failed"
} 2>&1 | tee "$run_dir/diagnostic.log" build/validation/client-aot-diagnostic.log
