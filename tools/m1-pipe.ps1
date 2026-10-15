param(
    [Parameter(Mandatory=$true)][ValidateSet('Serve','Request','HardenDirectory')][string]$Mode,
    [string]$PipeName,
    [string]$QueueDir,
    [string]$DataDir,
    [int]$ParentPid = 0
)
$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
try {
    if ($Mode -eq 'HardenDirectory') {
        if (-not [IO.Directory]::Exists($DataDir)) { throw 'State directory does not exist.' }
        if (([IO.File]::GetAttributes($DataDir) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'M1 state must not be a reparse point.' }
        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $system = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
        $inherit = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
        $acl = New-Object Security.AccessControl.DirectorySecurity
        $acl.SetAccessRuleProtection($true, $false)
        $acl.SetOwner($sid)
        foreach ($identity in @($sid, $system)) {
            $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', $inherit, 'None', 'Allow')
            $acl.AddAccessRule($rule)
        }
        [IO.Directory]::SetAccessControl($DataDir, $acl)
        # Existing state can predate M1. Remove explicit broad ACLs from its descendants.
        foreach ($item in Get-ChildItem -LiteralPath $DataDir -Force -Recurse) {
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse points are not allowed in M1 state.' }
            if ($item.PSIsContainer) {
                [IO.Directory]::SetAccessControl($item.FullName, $acl)
            } else {
                $fileAcl = New-Object Security.AccessControl.FileSecurity
                $fileAcl.SetAccessRuleProtection($true, $false)
                $fileAcl.SetOwner($sid)
                foreach ($identity in @($sid, $system)) {
                    $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'Allow')
                    $fileAcl.AddAccessRule($rule)
                }
                [IO.File]::SetAccessControl($item.FullName, $fileAcl)
            }
        }
        exit 0
    }
    if ($PipeName -notmatch '^trademinator-client-[a-f0-9]{32}$') { throw 'Invalid local pipe name.' }
    Add-Type -ReferencedAssemblies 'System.dll','System.Core.dll' -TypeDefinition @'
using System;
using System.IO;
using System.IO.Pipes;
using System.Text;
using System.Threading;
using System.Diagnostics;
using System.Security.AccessControl;
using System.Security.Principal;

public static class TrademinatorM1Pipe {
    static UTF8Encoding Utf8 = new UTF8Encoding(false, true);
    static string ReadLine(Stream stream, int maximum, int timeoutMs) {
        using (MemoryStream bytes = new MemoryStream()) {
            Stopwatch timer = Stopwatch.StartNew();
            byte[] chunk = new byte[1024];
            while (timer.ElapsedMilliseconds < timeoutMs) {
                IAsyncResult pending = stream.BeginRead(chunk, 0, chunk.Length, null, null);
                using (pending.AsyncWaitHandle) {
                    int remaining = Math.Max(1, timeoutMs - (int)timer.ElapsedMilliseconds);
                    if (!pending.AsyncWaitHandle.WaitOne(remaining)) { throw new TimeoutException("Local control read timed out."); }
                    int count = stream.EndRead(pending);
                    if (count == 0) { throw new IOException("Local control closed before a complete message."); }
                    for (int i = 0; i < count; i++) {
                        if (chunk[i] == 10) { return Utf8.GetString(bytes.ToArray()); }
                        if (bytes.Length >= maximum) { throw new IOException("Local control message exceeds the limit."); }
                        bytes.WriteByte(chunk[i]);
                    }
                }
            }
            throw new TimeoutException("Local control read timed out.");
        }
    }
    static void WriteLine(Stream stream, string text) {
        byte[] bytes = Utf8.GetBytes(text + "\n");
        IAsyncResult pending = stream.BeginWrite(bytes, 0, bytes.Length, null, null);
        using (pending.AsyncWaitHandle) {
            if (!pending.AsyncWaitHandle.WaitOne(3000)) { throw new TimeoutException("Local control write timed out."); }
            stream.EndWrite(pending);
        }
    }
    public static string Request(string name, string request) {
        using (NamedPipeClientStream pipe = new NamedPipeClientStream(".", name, PipeDirection.InOut, PipeOptions.Asynchronous, TokenImpersonationLevel.Identification)) {
            pipe.Connect(3000);
            WriteLine(pipe, request);
            return ReadLine(pipe, 131072, 10000);
        }
    }
    public static void Serve(string name, string directory, int parentPid) {
        if (parentPid <= 0) { throw new ArgumentException("A daemon parent PID is required."); }
        using (Process parent = Process.GetProcessById(parentPid)) {
            PipeSecurity security = new PipeSecurity();
            security.SetAccessRuleProtection(true, false);
            SecurityIdentifier sid = WindowsIdentity.GetCurrent().User;
            security.SetOwner(sid);
            security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.NetworkSid, null), PipeAccessRights.FullControl, AccessControlType.Deny));
            security.AddAccessRule(new PipeAccessRule(sid, PipeAccessRights.FullControl, AccessControlType.Allow));
            security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null), PipeAccessRights.FullControl, AccessControlType.Allow));
            string stop = Path.Combine(directory, "stop");
            while (!parent.HasExited && !File.Exists(stop)) {
                using (NamedPipeServerStream pipe = new NamedPipeServerStream(name, PipeDirection.InOut, 1, PipeTransmissionMode.Byte, PipeOptions.Asynchronous, 16384, 131072, security)) {
                    File.WriteAllText(Path.Combine(directory, "ready"), "ready", Utf8);
                    IAsyncResult wait = pipe.BeginWaitForConnection(null, null);
                    using (wait.AsyncWaitHandle) {
                        while (!wait.AsyncWaitHandle.WaitOne(100)) {
                            if (parent.HasExited || File.Exists(stop)) { return; }
                        }
                        pipe.EndWaitForConnection(wait);
                    }
                    string requestPath = null;
                    string responsePath = null;
                    try {
                        string request = ReadLine(pipe, 16384, 2000);
                        string id = Guid.NewGuid().ToString("N");
                        requestPath = Path.Combine(directory, id + ".request");
                        responsePath = Path.Combine(directory, id + ".response");
                        string temporary = requestPath + ".tmp";
                        File.WriteAllText(temporary, request, Utf8);
                        File.Move(temporary, requestPath);
                        Stopwatch timer = Stopwatch.StartNew();
                        while (!File.Exists(responsePath)) {
                            if (parent.HasExited || File.Exists(stop) || timer.ElapsedMilliseconds > 10000) { throw new TimeoutException("Daemon response timed out."); }
                            Thread.Sleep(10);
                        }
                        string response = File.ReadAllText(responsePath, Utf8).Trim();
                        if (Utf8.GetByteCount(response) > 131072 || response.IndexOf('\n') >= 0) { throw new IOException("Invalid daemon response."); }
                        WriteLine(pipe, response);
                    } catch (IOException) { /* One malformed/disconnected local client must not stop the bridge. */ }
                      catch (TimeoutException) { }
                      catch (DecoderFallbackException) { }
                    finally {
                        if (requestPath != null && File.Exists(requestPath)) { File.Delete(requestPath); }
                        if (responsePath != null && File.Exists(responsePath)) { File.Delete(responsePath); }
                    }
                }
            }
        }
    }
}
'@
    if ($Mode -eq 'Request') {
        $request = [Console]::ReadLine()
        if ($null -eq $request -or [Text.Encoding]::UTF8.GetByteCount($request) -gt 16384) { throw 'Invalid control request.' }
        [Console]::WriteLine([TrademinatorM1Pipe]::Request($PipeName, $request))
    } else {
        if (-not [IO.Directory]::Exists($QueueDir)) { throw 'Pipe queue directory is missing.' }
        [TrademinatorM1Pipe]::Serve($PipeName, $QueueDir, $ParentPid)
    }
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
