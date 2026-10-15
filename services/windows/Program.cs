using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;

// No NuGet dependencies. The executable implements the Windows SCM protocol;
// registering php.exe itself with New-Service would not create a Windows service.
internal static class Program
{
    private sealed record HostConfig(string ServiceName, string PhpExecutable, string ProjectDirectory,
        string ConfigPath, string DataDirectory);
    private static HostConfig config = null!;
    private static IntPtr statusHandle;
    private static readonly ManualResetEventSlim stopRequested = new(false);
    private static readonly ServiceMainCallback mainCallback = ServiceMain;
    private static readonly ServiceControlCallback controlCallback = ServiceControl;
    private static readonly object logLock = new();
    private static readonly object statusLock = new();
    private static uint state = 2;

    public static int Main(string[] args)
    {
        try
        {
            if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Windows is required.");
            if (args.Length == 2 && args[0] == "--grant-service-logon")
            {
                GrantServiceLogon(args[1]);
                return 0;
            }
            if (args.Length != 2 || args[0] != "--config")
                throw new ArgumentException("Usage: Trademinator.Service.exe --config <service-host.json>");
            config = JsonSerializer.Deserialize<HostConfig>(File.ReadAllText(args[1]))
                ?? throw new InvalidDataException("Missing service host configuration.");
            foreach (string path in new[] { config.PhpExecutable, config.ProjectDirectory, config.ConfigPath, config.DataDirectory })
                if (string.IsNullOrEmpty(path) || !Path.IsPathFullyQualified(path))
                    throw new InvalidDataException("Every service path must be absolute.");
            if (!File.Exists(config.PhpExecutable) || !File.Exists(config.ConfigPath)
                || !File.Exists(Path.Combine(config.ProjectDirectory, "bin", "client.php")))
                throw new FileNotFoundException("PHP, configuration or Client entry point was not found.");
            if (string.IsNullOrWhiteSpace(config.ServiceName)) throw new InvalidDataException("ServiceName is required.");
            ServiceTableEntry[] table = {
                new() { Name = config.ServiceName, Callback = mainCallback },
                new() { Name = null, Callback = null }
            };
            if (!StartServiceCtrlDispatcher(table)) throw new Win32Exception(Marshal.GetLastWin32Error());
            return 0;
        }
        catch (Exception error)
        {
            Console.Error.WriteLine(error.Message);
            return 1;
        }
    }

    private static ProcessStartInfo ClientCommand(string command)
    {
        var info = new ProcessStartInfo(config.PhpExecutable) {
            WorkingDirectory = config.ProjectDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            RedirectStandardInput = true
        };
        info.ArgumentList.Add(Path.Combine(config.ProjectDirectory, "bin", "client.php"));
        info.ArgumentList.Add(command);
        info.ArgumentList.Add("--config=" + config.ConfigPath);
        info.ArgumentList.Add("--data-dir=" + config.DataDirectory);
        return info;
    }

    private static Process Launch(string command)
    {
        var process = new Process { StartInfo = ClientCommand(command) };
        process.OutputDataReceived += (_, item) => { if (item.Data != null) Log(item.Data); };
        process.ErrorDataReceived += (_, item) => { if (item.Data != null) Log(item.Data); };
        process.Start();
        process.StandardInput.Close();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();
        return process;
    }

    private static void ServiceMain(uint count, IntPtr arguments)
    {
        statusHandle = RegisterServiceCtrlHandlerEx(config.ServiceName, controlCallback, IntPtr.Zero);
        if (statusHandle == IntPtr.Zero) return;
        SetStatus(2, 0, 1, 30000);
        Process? daemon = null;
        uint failure = 0;
        try
        {
            daemon = Launch("run");
            SetStatus(4);
            while (!daemon.WaitForExit(500))
                if (stopRequested.IsSet) break;
            if (stopRequested.IsSet && !daemon.HasExited)
            {
                SetStatus(3, 0, 1, 45000);
                try
                {
                    using var stop = Launch("stop");
                    if (!stop.WaitForExit(10000)) stop.Kill(true);
                }
                catch (Exception error) { Log("Graceful stop request: " + error.Message); }
                if (!daemon.WaitForExit(30000))
                {
                    Log("Stop timeout: terminating the daemon process tree; recovery will run on restart.");
                    daemon.Kill(true);
                    daemon.WaitForExit(5000);
                }
            }
            else if (!stopRequested.IsSet)
            {
                // A daemon exiting without a service stop is unexpected, even with code 0.
                failure = 1066;
                Log("Daemon exited unexpectedly with code " + daemon.ExitCode);
            }
        }
        catch (Exception error) { failure = 1066; Log(error.ToString()); }
        finally
        {
            try { if (daemon != null && !daemon.HasExited) daemon.Kill(true); } catch { }
            daemon?.Dispose();
            SetStatus(1, failure);
        }
    }

    private static uint ServiceControl(uint control, uint eventType, IntPtr eventData, IntPtr context)
    {
        if (control is 1 or 5)
        {
            SetStatus(3, 0, 1, 45000);
            stopRequested.Set();
            return 0;
        }
        if (control == 4) { lock (statusLock) { SetStatus(state); } return 0; }
        return 120;
    }

    private static void SetStatus(uint nextState, uint error = 0, uint checkpoint = 0, uint waitHint = 0)
    {
        lock (statusLock)
        {
            state = nextState;
            var status = new ServiceStatus {
                Type = 0x10, State = nextState, Controls = nextState == 4 ? 0x5u : 0u,
                Win32ExitCode = error, ServiceExitCode = error == 0 ? 0u : 1u,
                Checkpoint = checkpoint, WaitHint = waitHint
            };
            SetServiceStatus(statusHandle, ref status);
        }
    }

    private static void Log(string message)
    {
        // Normal daemon logs have their own rotation. Bound the wrapper diagnostics too.
        lock (logLock)
        {
            try
            {
                string directory = Path.Combine(config.DataDirectory, "logs");
                Directory.CreateDirectory(directory);
                string path = Path.Combine(directory, "windows-service.log");
                if (File.Exists(path) && new FileInfo(path).Length >= 5 * 1024 * 1024)
                    File.Move(path, path + ".1", true);
                File.AppendAllText(path, DateTimeOffset.UtcNow.ToString("O") + " " + message + Environment.NewLine);
            }
            catch { /* Logging must not escape a native SCM callback. */ }
        }
    }

    private static void GrantServiceLogon(string sidText)
    {
        // Standard service-install prerequisite; credentials are never accepted or stored here.
        if (!ConvertStringSidToSid(sidText, out IntPtr sid)) throw new Win32Exception(Marshal.GetLastWin32Error());
        IntPtr policy = IntPtr.Zero, rightBuffer = IntPtr.Zero;
        try
        {
            var attributes = new LsaObjectAttributes { Length = (uint)Marshal.SizeOf<LsaObjectAttributes>() };
            uint result = LsaOpenPolicy(IntPtr.Zero, ref attributes, 0x00000810, out policy);
            if (result != 0) throw new Win32Exception((int)LsaNtStatusToWinError(result));
            const string right = "SeServiceLogonRight";
            rightBuffer = Marshal.StringToHGlobalUni(right);
            var rights = new[] { new LsaUnicodeString { Buffer = rightBuffer,
                Length = (ushort)(right.Length * 2), MaximumLength = (ushort)((right.Length + 1) * 2) } };
            result = LsaAddAccountRights(policy, sid, rights, 1);
            if (result != 0) throw new Win32Exception((int)LsaNtStatusToWinError(result));
        }
        finally
        {
            if (rightBuffer != IntPtr.Zero) Marshal.FreeHGlobal(rightBuffer);
            if (policy != IntPtr.Zero) LsaClose(policy);
            LocalFree(sid);
        }
    }

    [UnmanagedFunctionPointer(CallingConvention.Winapi)] private delegate void ServiceMainCallback(uint argc, IntPtr argv);
    [UnmanagedFunctionPointer(CallingConvention.Winapi)] private delegate uint ServiceControlCallback(uint control, uint eventType, IntPtr eventData, IntPtr context);
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] private struct ServiceTableEntry
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string? Name;
        public ServiceMainCallback? Callback;
    }
    [StructLayout(LayoutKind.Sequential)] private struct ServiceStatus
    {
        public uint Type, State, Controls, Win32ExitCode, ServiceExitCode, Checkpoint, WaitHint;
    }
    [StructLayout(LayoutKind.Sequential)] private struct LsaObjectAttributes
    {
        public uint Length;
        public IntPtr RootDirectory, ObjectName;
        public uint Attributes;
        public IntPtr SecurityDescriptor, SecurityQualityOfService;
    }
    [StructLayout(LayoutKind.Sequential)] private struct LsaUnicodeString
    {
        public ushort Length, MaximumLength;
        public IntPtr Buffer;
    }
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool StartServiceCtrlDispatcher([In] ServiceTableEntry[] table);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr RegisterServiceCtrlHandlerEx(string name, ServiceControlCallback callback, IntPtr context);
    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool SetServiceStatus(IntPtr handle, ref ServiceStatus status);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)] private static extern bool ConvertStringSidToSid(string sid, out IntPtr result);
    [DllImport("advapi32.dll")] private static extern uint LsaOpenPolicy(IntPtr systemName, ref LsaObjectAttributes attributes, uint access, out IntPtr handle);
    [DllImport("advapi32.dll")] private static extern uint LsaAddAccountRights(IntPtr policy, IntPtr sid, [In] LsaUnicodeString[] rights, uint count);
    [DllImport("advapi32.dll")] private static extern uint LsaNtStatusToWinError(uint status);
    [DllImport("advapi32.dll")] private static extern uint LsaClose(IntPtr handle);
    [DllImport("kernel32.dll")] private static extern IntPtr LocalFree(IntPtr memory);
}
