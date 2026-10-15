#!/usr/bin/env bash
# Install for the current user. Configuration and data must live outside this release.
set -euo pipefail
umask 077

usage() {
    cat <<'EOF'
Usage: tools/install-m1.sh --config FILE --data-dir DIRECTORY [--php /path/to/php] [--no-start]
Linux: current-user systemd unit. macOS: current-user LaunchAgent.
Run as the user who owns the Client data, without sudo. No data is deleted.
EOF
}
fail() { printf '%s\n' "$*" >&2; exit 1; }
config= data_dir= php_bin=php start=yes
while (($#)); do
    case "$1" in
        --config|--data-dir|--php)
            (($# >= 2)) || fail "Missing value for $1"
            case "$1" in --config) config=$2;; --data-dir) data_dir=$2;; --php) php_bin=$2;; esac
            shift 2;;
        --no-start) start=no; shift;;
        --help|-h) usage; exit 0;;
        *) fail "Unknown argument: $1";;
    esac
done
[[ $EUID -ne 0 ]] || fail 'Run this installer as the data owner, without sudo.'
[[ -n "$config" && -n "$data_dir" ]] || { usage >&2; exit 1; }
[[ -f "$config" ]] || fail "Configuration does not exist: $config"
project_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
config=$(cd -- "$(dirname -- "$config")" && printf '%s/%s' "$(pwd -P)" "$(basename -- "$config")")
php_bin=$(command -v -- "$php_bin") || fail 'PHP executable was not found.'
php_bin=$(cd -- "$(dirname -- "$php_bin")" && printf '%s/%s' "$(pwd -P)" "$(basename -- "$php_bin")")
mkdir -p -- "$data_dir"
data_dir=$(cd -- "$data_dir" && pwd -P)
[[ -O "$data_dir" && -O "$config" ]] || fail 'Configuration and data directory must belong to the current user.'
for path in "$project_dir" "$config" "$data_dir" "$php_bin"; do
    [[ "$path" != *$'\n'* && "$path" != *$'\r'* ]] || fail 'Newlines are not supported in installation paths.'
done
case "$data_dir/" in "$project_dir/"*) fail 'Keep data outside the replaceable source directory.';; esac
case "$config" in "$project_dir/"*) fail 'Keep configuration outside the replaceable source directory.';; esac
chmod 700 -- "$data_dir"
chmod 600 -- "$config"
"$php_bin" "$project_dir/bin/client.php" doctor --config="$config" --data-dir="$data_dir"
# Stop the previous registration before init takes the exclusive database lock.
platform=$(uname -s)
case "$platform" in
    Linux)
        command -v systemctl >/dev/null || fail 'This installer requires systemd user services.'
        systemctl --user show-environment >/dev/null || fail 'No current-user systemd session is available. Run from your user session; do not use sudo.'
        if systemctl --user is-active --quiet trademinator-client.service; then
            systemctl --user stop trademinator-client.service
        fi
        ;;
    Darwin)
        command -v launchctl >/dev/null || fail 'launchctl was not found.'
        "$php_bin" -r 'exit(extension_loaded("pcntl") ? 0 : 1);' || fail 'A macOS LaunchAgent requires PHP pcntl for graceful SIGTERM handling at logout. Use foreground mode or enable pcntl.'
        launchctl print "gui/$UID" >/dev/null 2>&1 || fail 'Run this LaunchAgent installer from your logged-in macOS user session.'
        if launchctl print "gui/$UID/com.trademinator.client" >/dev/null 2>&1; then
            launchctl bootout "gui/$UID/com.trademinator.client"
        fi
        ;;
    *) fail 'Supported platforms are Linux with systemd and macOS.';;
esac
"$php_bin" "$project_dir/bin/client.php" init --config="$config" --data-dir="$data_dir"

systemd_quote() {
    local value=$1
    value=${value//\\/\\\\}; value=${value//\"/\\\"}; value=${value//%/%%}
    [[ ${2:-} != exec ]] || value=${value//\$/\$\$}
    printf '"%s"' "$value"
}
xml_escape() {
    local value=$1
    value=${value//&/\&amp;}; value=${value//</\&lt;}; value=${value//>/\&gt;}
    value=${value//\"/\&quot;}; value=${value//\'/\&apos;}
    printf '%s' "$value"
}

case "$platform" in
    Linux)
        command -v systemctl >/dev/null || fail 'This installer requires systemd user services.'
        unit_dir="$HOME/.config/systemd/user"
        mkdir -p -- "$unit_dir"
        unit="$unit_dir/trademinator-client.service"
        {
            printf '[Unit]\nDescription=Trademinator Client M1 (%s)\nAfter=default.target\n\n[Service]\nType=simple\n' "$USER"
            printf 'WorkingDirectory=%s\n' "$(systemd_quote "$project_dir")"
            printf 'ExecStart=%s %s run %s %s\n' "$(systemd_quote "$php_bin" exec)" "$(systemd_quote "$project_dir/bin/client.php" exec)" "$(systemd_quote "--config=$config" exec)" "$(systemd_quote "--data-dir=$data_dir" exec)"
            printf 'ExecStop=%s %s stop %s %s\n' "$(systemd_quote "$php_bin" exec)" "$(systemd_quote "$project_dir/bin/client.php" exec)" "$(systemd_quote "--config=$config" exec)" "$(systemd_quote "--data-dir=$data_dir" exec)"
            printf 'ExecReload=%s %s reload %s %s\n' "$(systemd_quote "$php_bin" exec)" "$(systemd_quote "$project_dir/bin/client.php" exec)" "$(systemd_quote "--config=$config" exec)" "$(systemd_quote "--data-dir=$data_dir" exec)"
            printf 'Restart=on-failure\nRestartSec=5\nTimeoutStopSec=45\nUMask=0077\nNoNewPrivileges=true\nPrivateTmp=true\n\n[Install]\nWantedBy=default.target\n'
        } > "$unit.tmp"
        mv -- "$unit.tmp" "$unit"
        systemctl --user daemon-reload
        systemctl --user enable trademinator-client.service
        [[ $start != yes ]] || systemctl --user start trademinator-client.service
        printf 'Installed %s\n' "$unit"
        printf 'Inspect: systemctl --user status trademinator-client.service\nLogs: journalctl --user -u trademinator-client.service\n'
        printf 'For boot and operation after logout, an administrator may enable lingering for your user with loginctl enable-linger.\n'
        ;;
    Darwin)
        command -v launchctl >/dev/null || fail 'launchctl was not found.'
        plist_dir="$HOME/Library/LaunchAgents"
        mkdir -p -- "$plist_dir" "$data_dir/logs"
        plist="$plist_dir/com.trademinator.client.plist"
        cat > "$plist.tmp" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>com.trademinator.client</string>
  <key>ProgramArguments</key><array>
    <string>$(xml_escape "$php_bin")</string>
    <string>$(xml_escape "$project_dir/bin/client.php")</string>
    <string>run</string><string>$(xml_escape "--config=$config")</string><string>$(xml_escape "--data-dir=$data_dir")</string>
  </array>
  <key>WorkingDirectory</key><string>$(xml_escape "$project_dir")</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><dict><key>SuccessfulExit</key><false/></dict>
  <key>ThrottleInterval</key><integer>5</integer>
  <key>ExitTimeOut</key><integer>45</integer>
  <key>Umask</key><integer>63</integer>
  <key>StandardOutPath</key><string>/dev/null</string>
  <key>StandardErrorPath</key><string>$(xml_escape "$data_dir/logs/launchagent-stderr.log")</string>
</dict></plist>
EOF
        plutil -lint "$plist.tmp"
        mv -- "$plist.tmp" "$plist"
        [[ $start != yes ]] || launchctl bootstrap "gui/$UID" "$plist"
        printf 'Installed %s\n' "$plist"
        printf 'Inspect: launchctl print gui/%s/com.trademinator.client\n' "$UID"
        printf 'This LaunchAgent starts at login; it is not a pre-login system daemon.\n'
        ;;
    *) fail 'Supported platforms are Linux with systemd and macOS.';;
esac
