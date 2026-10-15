#Requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('Service', 'Task')][string]$Mode = 'Service',
    [ValidatePattern('^[a-zA-Z0-9_-]{1,100}$')][string]$Name
)
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'This uninstaller is for Windows.' }
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $Name) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $suffix = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($identity.User.Value)))).Replace('-', '').Substring(0, 12).ToLowerInvariant() }
    finally { $sha.Dispose() }
    $Name = "TrademinatorClient-$suffix"
}
if ($Mode -eq 'Task') {
    $task = Get-ScheduledTask -TaskName $Name -ErrorAction Stop
    if ($task.Principal.UserId -match '^S-1-') { $taskSid = $task.Principal.UserId }
    else { $taskSid = (New-Object Security.Principal.NTAccount($task.Principal.UserId)).Translate([Security.Principal.SecurityIdentifier]).Value }
    if ($taskSid -ne $identity.User.Value) { throw 'The task belongs to another Windows account.' }
    $registration = Join-Path $env:LOCALAPPDATA "Trademinator\ServiceHost\$Name\service-host.json"
    if (Test-Path -LiteralPath $registration) {
        $installed = Get-Content -LiteralPath $registration -Raw | ConvertFrom-Json
        if ($installed.ServiceName -ne $Name) { throw 'Registration metadata does not match this task.' }
        if ($task.State -eq 'Running') {
            & $installed.PhpExecutable (Join-Path $installed.ProjectDirectory 'bin\client.php') stop "--config=$($installed.ConfigPath)" "--data-dir=$($installed.DataDirectory)"
            if ($LASTEXITCODE -ne 0) { throw 'Graceful daemon stop failed. Inspect the task before forcing its termination.' }
        }
    } elseif ($task.State -eq 'Running') {
        throw 'Task metadata is missing. Stop its daemon through the Client CLI before uninstalling.'
    }
    $deadline = (Get-Date).AddSeconds(15)
    while ((Get-ScheduledTask -TaskName $Name).State -eq 'Running' -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 200
    }
    if ((Get-ScheduledTask -TaskName $Name).State -eq 'Running') { throw 'The daemon has not exited after the stop request; registration was retained for inspection.' }
    Unregister-ScheduledTask -TaskName $Name -Confirm:$false
} else {
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run elevated as the SAME installing Windows user.' }
    $service = Get-CimInstance Win32_Service -Filter "Name='$Name'"
    if (-not $service) { throw "Service $Name does not exist." }
    $ownerSid = (New-Object Security.Principal.NTAccount($service.StartName)).Translate([Security.Principal.SecurityIdentifier]).Value
    if ($ownerSid -ne $identity.User.Value) { throw 'The service belongs to another Windows account.' }
    Stop-Service -Name $Name
    & sc.exe delete $Name
    if ($LASTEXITCODE -ne 0) { throw 'Service deletion failed.' }
}
Write-Host 'Registration removed. Source, service-host binary, configuration, data, logs and backups were preserved.'
Write-Host 'Log on as a service is retained because other services may depend on that user right.'
