using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Threading;

namespace OutechDesktop
{
    /// <summary>
    /// Serveur PHP embarqué (mode local) : `php -S 127.0.0.1:port router.php`
    /// avec la base SQLite dans %APPDATA%\OUTECH\donnees.
    /// </summary>
    internal sealed class ServeurPhp : IDisposable
    {
        private Process _process;
        private StreamWriter _journal;
        private readonly IntPtr _job;

        public int Port { get; private set; }
        public string Adresse => "http://127.0.0.1:" + Port;

        public ServeurPhp()
        {
            // Un « job » Windows : si OUTECH.exe se ferme, même brutalement,
            // php.exe est arrêté avec lui.
            _job = CreerJob();
        }

        public static string DossierBase => AppDomain.CurrentDomain.BaseDirectory;

        public void Demarrer()
        {
            var php = Path.Combine(DossierBase, "php", "php.exe");
            var www = Path.Combine(DossierBase, "www");
            if (!File.Exists(php)) throw new FileNotFoundException("PHP introuvable : " + php);
            if (!File.Exists(Path.Combine(www, "router.php"))) throw new FileNotFoundException("Application introuvable : " + www);

            Directory.CreateDirectory(Reglages.DossierDonnees);
            var sessions = Path.Combine(Reglages.DossierLocal, "sessions");
            Directory.CreateDirectory(sessions);

            Port = PortLibre();

            var info = new ProcessStartInfo
            {
                FileName = php,
                Arguments = "-c \"" + Path.Combine(DossierBase, "php", "php.ini") + "\" -S 127.0.0.1:" + Port + " router.php",
                WorkingDirectory = www,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };
            info.EnvironmentVariables["BD_PILOTE"] = "sqlite";
            info.EnvironmentVariables["BD_DOSSIER"] = Reglages.DossierDonnees;
            info.EnvironmentVariables["OUTECH_SESSIONS"] = sessions;
            // Lu par php.ini : extension_dir se résoudrait sinon depuis www.
            info.EnvironmentVariables["OUTECH_PHP_EXT"] = Path.Combine(DossierBase, "php", "ext");
            info.EnvironmentVariables["OUTECH_BUREAU"] = "1";

            Directory.CreateDirectory(Reglages.DossierLocal);
            _journal = new StreamWriter(Path.Combine(Reglages.DossierLocal, "php.log"), false) { AutoFlush = true };

            _process = new Process { StartInfo = info, EnableRaisingEvents = true };
            _process.OutputDataReceived += (s, e) => Ecrire(e.Data);
            _process.ErrorDataReceived += (s, e) => Ecrire(e.Data);
            _process.Start();
            _process.BeginOutputReadLine();
            _process.BeginErrorReadLine();
            if (_job != IntPtr.Zero) AssignProcessToJobObject(_job, _process.Handle);

            AttendrePret();
        }

        private void Ecrire(string ligne)
        {
            if (ligne == null) return;
            try { lock (_journal) _journal.WriteLine(ligne); } catch { }
        }

        private void AttendrePret()
        {
            var limite = DateTime.UtcNow.AddSeconds(20);
            while (DateTime.UtcNow < limite)
            {
                if (_process.HasExited)
                    throw new InvalidOperationException("PHP s'est arrêté au démarrage (voir " +
                        Path.Combine(Reglages.DossierLocal, "php.log") + ").");
                try
                {
                    using (var client = new TcpClient())
                    {
                        client.Connect(IPAddress.Loopback, Port);
                        return;
                    }
                }
                catch (SocketException)
                {
                    Thread.Sleep(150);
                }
            }
            throw new TimeoutException("Le serveur local ne répond pas.");
        }

        private static int PortLibre()
        {
            var l = new TcpListener(IPAddress.Loopback, 0);
            l.Start();
            var port = ((IPEndPoint)l.LocalEndpoint).Port;
            l.Stop();
            return port;
        }

        public void Dispose()
        {
            try { if (_process != null && !_process.HasExited) _process.Kill(); } catch { }
            try { _journal?.Dispose(); } catch { }
            if (_job != IntPtr.Zero) CloseHandle(_job);
        }

        // ------------------------------------------------------------ job Windows

        private static IntPtr CreerJob()
        {
            var job = CreateJobObject(IntPtr.Zero, null);
            if (job == IntPtr.Zero) return IntPtr.Zero;

            var infos = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
            infos.BasicLimitInformation.LimitFlags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
            var taille = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
            var ptr = Marshal.AllocHGlobal(taille);
            try
            {
                Marshal.StructureToPtr(infos, ptr, false);
                SetInformationJobObject(job, 9 /* ExtendedLimitInformation */, ptr, (uint)taille);
            }
            finally
            {
                Marshal.FreeHGlobal(ptr);
            }
            return job;
        }

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr CreateJobObject(IntPtr a, string nom);

        [DllImport("kernel32.dll")]
        private static extern bool SetInformationJobObject(IntPtr job, int type, IntPtr info, uint taille);

        [DllImport("kernel32.dll")]
        private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);

        [DllImport("kernel32.dll")]
        private static extern bool CloseHandle(IntPtr h);

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_BASIC_LIMIT_INFORMATION
        {
            public long PerProcessUserTimeLimit;
            public long PerJobUserTimeLimit;
            public uint LimitFlags;
            public UIntPtr MinimumWorkingSetSize;
            public UIntPtr MaximumWorkingSetSize;
            public uint ActiveProcessLimit;
            public UIntPtr Affinity;
            public uint PriorityClass;
            public uint SchedulingClass;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct IO_COUNTERS
        {
            public ulong ReadOperationCount, WriteOperationCount, OtherOperationCount;
            public ulong ReadTransferCount, WriteTransferCount, OtherTransferCount;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
        {
            public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
            public IO_COUNTERS IoInfo;
            public UIntPtr ProcessMemoryLimit;
            public UIntPtr JobMemoryLimit;
            public UIntPtr PeakProcessMemoryUsed;
            public UIntPtr PeakJobMemoryUsed;
        }
    }
}
