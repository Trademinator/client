#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Config,
    [Parameter(Mandatory = $true)][string]$DataDir,
    [string]$Php = 'php.exe',
    [ValidateSet('Service', 'Task')][string]$Mode = 'Service',
    [ValidatePattern('^[a-zA-Z0-9_-]{1,100}$')][string]$Name,
    [switch]$NoStart
)
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'This installer is for Windows.' }
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$userSid = $identity.User
$userName = $identity.Name
$sha = [Security.Cryptography.SHA256]::Create()
try { $suffix = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($userSid.Value)))).Replace('-', '').Substring(0, 12).ToLowerInvariant() }
finally { $sha.Dispose() }
if (-not $Name) { $Name = "TrademinatorClient-$suffix" }
$project = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$configPath = (Resolve-Path -LiteralPath $Config).ProviderPath
$phpPath = (Get-Command $Php -CommandType Application -ErrorAction Stop).Source
$dataPath = [IO.Path]::GetFullPath($DataDir)
if ($dataPath.StartsWith($project.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase) -or $dataPath -eq $project) {
    throw 'Keep the data directory outside the replaceable source directory.'
}
if ($configPath.StartsWith($project.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Keep configuration outside the replaceable source directory.'
}
foreach ($path in @($project, $configPath, $phpPath, $dataPath)) {
    if ($path.Contains('"') -or $path.Contains("`r") -or $path.Contains("`n")) { throw 'Unsupported character in installation path.' }
    if ($path.StartsWith('\\')) { throw 'Use local absolute paths for service binaries, configuration and data.' }
}
if (Get-Service -Name $Name -ErrorAction SilentlyContinue) { throw "Service $Name already exists. Stop and uninstall its registration before reinstalling; data is retained." }
if (Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue) { throw "Task $Name already exists. Uninstall its registration before reinstalling; data is retained." }

function Set-PrivateAcl([string]$Path, [bool]$Directory) {
    if ($Directory) { $acl = New-Object Security.AccessControl.DirectorySecurity }
    else { $acl = New-Object Security.AccessControl.FileSecurity }
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($userSid)
    foreach ($sid in @($userSid, (New-Object Security.Principal.SecurityIdentifier 'S-1-5-18'), (New-Object Security.Principal.SecurityIdentifier 'S-1-5-32-544'))) {
        if ($Directory) {
            $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        } else { $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'Allow') }
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
}

if ($Mode -eq 'Service') {
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Service installation requires an elevated PowerShell opened as the SAME Windows user who will run the Client. Alternatively choose -Mode Task without elevation.'
    }
    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) { throw 'Install the .NET 8 SDK or newer capable of targeting net8.0-windows before building the service host.' }
}
New-Item -ItemType Directory -Path $dataPath -Force | Out-Null
Set-PrivateAcl $dataPath $true
Set-PrivateAcl $configPath $false
& $phpPath (Join-Path $project 'bin\client.php') doctor "--config=$configPath" "--data-dir=$dataPath"
if ($LASTEXITCODE -ne 0) { throw 'Client doctor failed.' }
& $phpPath (Join-Path $project 'bin\client.php') init "--config=$configPath" "--data-dir=$dataPath"
if ($LASTEXITCODE -ne 0) { throw 'Client initialization failed.' }

$hostDirectory = Join-Path $env:LOCALAPPDATA "Trademinator\ServiceHost\$Name"
New-Item -ItemType Directory -Path $hostDirectory -Force | Out-Null
Set-PrivateAcl $hostDirectory $true
$hostConfig = Join-Path $hostDirectory 'service-host.json'
@{
    ServiceName = $Name
    PhpExecutable = $phpPath
    ProjectDirectory = $project
    ConfigPath = $configPath
    DataDirectory = $dataPath
} | ConvertTo-Json | Set-Content -LiteralPath $hostConfig -Encoding UTF8
Set-PrivateAcl $hostConfig $false

if ($Mode -eq 'Task') {
    $arguments = '"' + (Join-Path $project 'bin\client.php') + '" run "--config=' + $configPath + '" "--data-dir=' + $dataPath + '"'
    $action = New-ScheduledTaskAction -Execute $phpPath -Argument $arguments -WorkingDirectory $project
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $userName
    $taskPrincipal = New-ScheduledTaskPrincipal -UserId $userSid.Value -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $Name -Action $action -Trigger $trigger -Principal $taskPrincipal -Settings $settings -Description 'Trademinator M1 per-user login task (not a Windows service)' | Out-Null
    if (-not $NoStart) { Start-ScheduledTask -TaskName $Name }
    Write-Host "Installed login task $Name for $userName. This is NOT a Windows service and requires that user's login session."
    Write-Host "Inspect: Get-ScheduledTask -TaskName '$Name' | Get-ScheduledTaskInfo"
    return
}

& dotnet.exe publish (Join-Path $project 'services\windows\Trademinator.Service.csproj') --configuration Release --output $hostDirectory --self-contained false --nologo
if ($LASTEXITCODE -ne 0) { throw 'Windows service-host build failed.' }
$hostExecutable = Join-Path $hostDirectory 'Trademinator.Service.exe'
if (-not (Test-Path -LiteralPath $hostExecutable)) { throw 'The Windows service executable was not produced.' }
$credential = Get-Credential -UserName $userName -Message 'Windows service logon: enter this same Windows account password (not a Windows Hello PIN). It is sent to Windows Service Control Manager and is never written to a project/config file.'
if ($null -eq $credential) { throw 'Service installation cancelled before registration.' }
$credentialSid = (New-Object Security.Principal.NTAccount($credential.UserName)).Translate([Security.Principal.SecurityIdentifier])
if ($credentialSid.Value -ne $userSid.Value) { throw 'Use the SAME Windows account as the Client data owner; a shared or other service account is not permitted.' }
& $hostExecutable --grant-service-logon $userSid.Value
if ($LASTEXITCODE -ne 0) { throw 'Could not grant Log on as a service. Domain policy may require an administrator to grant it.' }
$binaryPath = '"' + $hostExecutable + '" --config "' + $hostConfig + '"'
New-Service -Name $Name -DisplayName "Trademinator Client ($userName)" -BinaryPathName $binaryPath -StartupType Automatic -Credential $credential -Description 'Trademinator M1, running under its data owner account' | Out-Null
& sc.exe failure $Name 'reset=' '86400' 'actions=' 'restart/5000/restart/15000/restart/60000'
if ($LASTEXITCODE -ne 0) { throw 'Service registered, but configuring restart policy failed. Review the service before starting.' }
& sc.exe failureflag $Name 1
if ($LASTEXITCODE -ne 0) { throw 'Service registered, but configuring failure detection failed.' }
if (-not $NoStart) { Start-Service -Name $Name }
Write-Host "Installed Windows service $Name as $userName. Inspect: Get-Service -Name '$Name'"
Write-Host "Wrapper diagnostics: $dataPath\logs\windows-service.log"
Write-Host 'The .NET 8 runtime is required on this machine. Keep the source deployment and host installation paths in place.'
