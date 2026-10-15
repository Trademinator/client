#!/usr/bin/env bash
# Remove only this user's service registration, preserving configuration and all data.
set -euo pipefail
[[ $EUID -ne 0 ]] || { printf 'Run as the installing user, without sudo.\n' >&2; exit 1; }
case "$(uname -s)" in
    Linux)
        systemctl --user disable --now trademinator-client.service
        rm -f -- "$HOME/.config/systemd/user/trademinator-client.service"
        systemctl --user daemon-reload
        ;;
    Darwin)
        if launchctl print "gui/$UID/com.trademinator.client" >/dev/null 2>&1; then
            launchctl bootout "gui/$UID/com.trademinator.client"
        fi
        rm -f -- "$HOME/Library/LaunchAgents/com.trademinator.client.plist"
        ;;
    *) printf 'Unsupported platform.\n' >&2; exit 1;;
esac
printf 'Service registration removed. Configuration, logs, backups and databases were preserved.\n'
