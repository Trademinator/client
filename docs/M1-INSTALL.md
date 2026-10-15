# M1 installation

This release deploys the local core: a single-instance daemon, private local control, SQLite accounting, backups, market checkpoints, and approved Hard T0/T1 archives. It does not connect to an exchange, consume authenticated Server signals, or submit orders. Those belong to later milestones. The simulator supplies financial activity for M1 validation.

The supported deployment in this package is PHP source. M0 source, native build tooling, and documentation are retained; the M0 command is now `php bin/m0.php`. Previously compiled M0 executables do **not** contain M1. Rebuilding and validating an M1 native/AOT executable is a separate step.

## Preserve the old installation first

Stop the old M0 process. Copy the **entire old installation** to a dated backup outside the directory you will replace. Keep your local configuration, database, SQLite `-wal`/`-shm` companions if present, logs, soak-test evidence, native executables, SDK patches, and local changes. The replacement archive cannot contain files that existed only on your computer.

On Linux or macOS, from the directory containing the old `client` directory:

```bash
stamp=$(date -u +%Y%m%dT%H%M%SZ)
tar -czf "client-M0-local-$stamp.tar.gz" client
tar -tzf "client-M0-local-$stamp.tar.gz" >/dev/null
```

On Windows, after stopping M0, from the directory containing `client`:

```powershell
$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
tar.exe -czf "client-M0-local-$stamp.tar.gz" client
if ($LASTEXITCODE -ne 0) { throw 'M0 backup failed; do not replace the old installation.' }
tar.exe -tzf "client-M0-local-$stamp.tar.gz" | Select-Object -First 15
```

Keep this backup private if it contains credentials. If you are replacing files inside an existing Git checkout, retain its `.git` directory; Git metadata is not included in this source archive. Extract the M1 archive into a fresh staging directory, inspect it, and then replace the source installation. Do not merge M0 databases into M1: M0 and M1 have separate schemas, and no migration of local M0 samples is implied.

## Runtime requirements

- 64-bit PHP 8.3 or newer, with `bcmath` and `pdo_sqlite`. `proc_open` and PHP stream functions must be enabled.
- A local writable filesystem for the data directory. Do not use a network share or a directory synchronized by a cloud client for the active SQLite database.
- Linux: systemd user services for the supplied service installer; foreground mode also works without systemd.
- macOS: a user LaunchAgent; it starts after that user logs in. This service mode requires PHP `pcntl` so logout/shutdown signals trigger a graceful stop.
- Windows: Windows PowerShell 5.1 for the named-pipe bridge. The true Windows service option additionally requires the .NET 8 SDK to build its supplied host and the .NET 8 runtime to run it. Normal foreground mode and the login-task option do not need .NET 8.

M1 bootstraps directly from `m1/bootstrap.php` and does not require Composer dependencies. The full tarball also bundles the locked Composer dependencies for preserved M0 tools, so `composer install` is not needed for the supplied archive. PHP and its required extensions still must be installed on your machine.

```bash
php bin/client.php doctor
php bin/client.php version
```

`doctor` must report `ready`, 64-bit architecture, and both required extensions enabled. Windows/macOS service installation and Windows named-pipe behavior require validation on those operating systems; Linux results do not certify them. Native M1/AOT validation is not claimed.

## Configuration and data outside the executable directory

Use the same OS user for the foreground process, service, CLI control, and database. Do not introduce a shared `trademinator` service account pointing at a different user's state.

Linux example, run from the extracted source directory as your normal user:

```bash
mkdir -p "$HOME/.config/trademinator"
cp -n config.m1.example.json "$HOME/.config/trademinator/config.m1.json"
chmod 600 "$HOME/.config/trademinator/config.m1.json"
M1_CONFIG="$HOME/.config/trademinator/config.m1.json"
M1_DATA="$HOME/.local/share/trademinator/client"
php bin/client.php init "--config=$M1_CONFIG" "--data-dir=$M1_DATA"
php bin/client.php run "--config=$M1_CONFIG" "--data-dir=$M1_DATA" --seconds=60
```

For macOS, the same commands work with `M1_DATA="$HOME/Library/Application Support/Trademinator"`. Keep Unix data paths short enough for a Unix domain socket; the complete `runtime/control.sock` path must be no more than 100 bytes.

Windows PowerShell example:

```powershell
$configDir = Join-Path $env:LOCALAPPDATA 'Trademinator\Config'
New-Item -ItemType Directory -Path $configDir -Force | Out-Null
$M1Config = Join-Path $configDir 'config.m1.json'
if (-not (Test-Path -LiteralPath $M1Config)) { Copy-Item config.m1.example.json $M1Config }
$M1Data = Join-Path $env:LOCALAPPDATA 'Trademinator\Client'
php bin/client.php init "--config=$M1Config" "--data-dir=$M1Data"
php bin/client.php run "--config=$M1Config" "--data-dir=$M1Data" --seconds=60
```

Use `config.m1.example.json`, not M0's `config.example.json`. Backups have a fixed daily/every-1,000-completed-trades policy; do not add invented backup timing fields. Configuration controls daemon polling and logging. CLI `--data-dir` selects the explicit data directory for every command.

The active database is `client.sqlite`; runtime control files are in `runtime/`, logs in `logs/`, backups in `backups/`, and complete hard-point archives in `hard-points/`. Keep the entire data directory when upgrading. Do not remove `runtime/daemon.lock` to bypass a running-process error.

## Linux user service

After the foreground test passes:

```bash
bash tools/install-m1.sh --config "$M1_CONFIG" --data-dir "$M1_DATA" --php "$(command -v php)"
systemctl --user status trademinator-client.service
journalctl --user -u trademinator-client.service -n 50
php bin/client.php status "--config=$M1_CONFIG" "--data-dir=$M1_DATA"
systemctl --user reload trademinator-client.service
systemctl --user stop trademinator-client.service
systemctl --user start trademinator-client.service
```

Do not run this installer with `sudo`. To keep the service running after logout and start it at boot, an administrator can enable lingering for the same user, for example `sudo loginctl enable-linger luis`. The database stays owned by that user. Without lingering, user-session lifetime controls availability.

Uninstall registration with `bash tools/uninstall-m1.sh`. Configuration, database, logs, and archives remain untouched.

## macOS LaunchAgent

```bash
bash tools/install-m1.sh --config "$M1_CONFIG" --data-dir "$M1_DATA" --php "$(command -v php)"
launchctl print "gui/$UID/com.trademinator.client"
php bin/client.php status "--config=$M1_CONFIG" "--data-dir=$M1_DATA"
php bin/client.php reload "--config=$M1_CONFIG" "--data-dir=$M1_DATA"
```

The registration is `~/Library/LaunchAgents/com.trademinator.client.plist`. It runs under your login session, not as a pre-login system daemon. Normal daemon logs rotate; the LaunchAgent's startup-error file `logs/launchagent-stderr.log` should be included in your normal log maintenance if startup fails repeatedly.

Uninstall registration with `bash tools/uninstall-m1.sh`. To stop temporarily use `launchctl bootout "gui/$UID/com.trademinator.client"`; restart with `launchctl bootstrap "gui/$UID" "$HOME/Library/LaunchAgents/com.trademinator.client.plist"`.

## Windows: true service or explicitly a login task

For a true Windows service, open an elevated PowerShell **as the same Windows account that owns the Client data**, then re-create `$M1Config` and `$M1Data` as above and run:

```powershell
.\tools\install-m1.ps1 -Config $M1Config -DataDir $M1Data -Php (Get-Command php.exe).Source -Mode Service
```

The installer builds `services/windows/Trademinator.Service.csproj` into your local application-data directory. The native Windows service protocol is implemented by that host, which starts PHP, forwards service-stop requests to the Client, and applies a restart-on-failure policy. Registering `php.exe` directly as a service would not provide that behavior.

Supply your Windows **account password**, not your Windows Hello PIN, in the interactive credential prompt. The installer verifies that it resolves to your current SID. Credentials go to Windows Service Control Manager; they are not written to source, JSON files, command-line arguments, or logs. The installer grants that account `Log on as a service`; domain policy can override this right. A passwordless/Hello-only account should use foreground mode or the login task unless you configure an appropriate account password.

The installer prints the exact service name, which includes a hash of your SID. Use that name:

```powershell
$ServiceName = 'TrademinatorClient-REPLACE_WITH_PRINTED_SUFFIX'
Get-Service -Name $ServiceName
php bin/client.php status "--config=$M1Config" "--data-dir=$M1Data"
php bin/client.php reload "--config=$M1Config" "--data-dir=$M1Data"
Stop-Service -Name $ServiceName
Start-Service -Name $ServiceName
```

Stop via `Stop-Service` before offline database operations. Sending the daemon a direct `stop` while the Windows service remains active is treated by its host as an unexpected exit and may trigger a restart. Wrapper logs are in `logs/windows-service.log`; normal Client logs are separate.

Without elevation, .NET, or a service-logon password, explicitly choose a **scheduled login task**, which is not a Windows service:

```powershell
.\tools\install-m1.ps1 -Config $M1Config -DataDir $M1Data -Mode Task
```

The task runs under your interactive user at login. It does not promise operation before login or after logout. The installer prints its task name. Stop the daemon through the CLI before unregistering the task.

Uninstall registration with `.\tools\uninstall-m1.ps1 -Mode Service` from the same elevated user, or `.\tools\uninstall-m1.ps1 -Mode Task` for the login task. Optional `-Name` must match a custom installation name. User data and host binaries are retained; `Log on as a service` is retained because another service might depend on it.

## Upgrades and rollback

Stop the OS service/task and verify the daemon has exited. Back up the entire persistent data directory. Replace only source files, preserving the external configuration/data paths, and reinstall service registration if its source/PHP paths changed. Windows registration must be uninstalled before reinstalling it. Keep the old source archive and the matching pre-upgrade data backup together for rollback.

Do not run M0 on M1 data, run two versions against one directory, or restore an older database over a running daemon. Complete [M1-TESTING.md](M1-TESTING.md) before leaving the daemon running unattended.
