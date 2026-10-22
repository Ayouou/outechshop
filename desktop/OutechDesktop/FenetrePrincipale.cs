using System;
using System.Diagnostics;
using System.IO;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace OutechDesktop
{
    /// <summary>
    /// Fenêtre de l'application : l'interface web d'OUTECH dans WebView2, servie
    /// soit par le PHP embarqué (local), soit par le serveur en ligne.
    /// </summary>
    internal sealed class FenetrePrincipale : Form
    {
        private readonly Reglages _reglages;
        private readonly WebView2 _vue;
        private ServeurPhp _serveur;
        private string _accueil;

        public bool ChangerDeMode { get; private set; }

        public FenetrePrincipale(Reglages reglages)
        {
            _reglages = reglages;

            Text = reglages.EstLocal ? "OUTECH — local" : "OUTECH — " + reglages.UrlServeur;
            Width = 1280;
            Height = 820;
            StartPosition = FormStartPosition.CenterScreen;
            WindowState = FormWindowState.Maximized;
            try { Icon = System.Drawing.Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            _vue = new WebView2 { Dock = DockStyle.Fill };

            var menu = new MenuStrip();
            var outech = new ToolStripMenuItem("OUTECH");
            outech.DropDownItems.Add("Accueil", null, (s, e) => Naviguer(_accueil));
            outech.DropDownItems.Add(new ToolStripMenuItem("Recharger", null, (s, e) => _vue.CoreWebView2?.Reload(), Keys.F5));
            outech.DropDownItems.Add(new ToolStripSeparator());
            outech.DropDownItems.Add("Changer de mode (local / serveur)…", null, (s, e) => { ChangerDeMode = true; Close(); });
            if (reglages.EstLocal)
            {
                outech.DropDownItems.Add("Ouvrir le dossier des données", null,
                    (s, e) => Process.Start("explorer.exe", "\"" + Reglages.DossierDonnees + "\""));
            }
            outech.DropDownItems.Add(new ToolStripSeparator());
            outech.DropDownItems.Add("Quitter", null, (s, e) => Close());
            menu.Items.Add(outech);

            var mode = new ToolStripMenuItem(reglages.EstLocal ? "● Mode local" : "● Mode serveur") { Enabled = false };
            menu.Items.Add(mode);

            Controls.Add(_vue);
            Controls.Add(menu);
            MainMenuStrip = menu;

            Shown += async (s, e) =>
            {
                try
                {
                    if (_reglages.EstLocal)
                    {
                        Cursor = Cursors.WaitCursor;
                        _serveur = new ServeurPhp();
                        _serveur.Demarrer();
                        // Premier lancement : rien n'est encore installé, on
                        // ouvre directement l'écran de mise en service.
                        var premier = !File.Exists(Path.Combine(Reglages.DossierDonnees, "outech_console.sqlite"));
                        _accueil = _serveur.Adresse + (premier ? "/console/installation" : "/");
                        Cursor = Cursors.Default;
                    }
                    else
                    {
                        _accueil = _reglages.UrlServeur.TrimEnd('/') + "/";
                    }

                    Directory.CreateDirectory(Reglages.DossierLocal);
                    var env = await CoreWebView2Environment.CreateAsync(null, Path.Combine(Reglages.DossierLocal, "WebView2"));
                    await _vue.EnsureCoreWebView2Async(env);
                    _vue.CoreWebView2.Settings.IsStatusBarEnabled = false;
                    _vue.CoreWebView2.NewWindowRequested += NouvelleFenetre;
                    _vue.CoreWebView2.NavigationStarting += NavigationExterne;
                    Naviguer(_accueil);
                }
                catch (WebView2RuntimeNotFoundException)
                {
                    Cursor = Cursors.Default;
                    if (MessageBox.Show(this,
                            "Le composant Microsoft Edge WebView2 manque sur cet ordinateur.\n\nL'installer maintenant ?",
                            "OUTECH", MessageBoxButtons.YesNo, MessageBoxIcon.Warning) == DialogResult.Yes)
                    {
                        Process.Start("https://go.microsoft.com/fwlink/p/?LinkId=2124703");
                    }
                    Close();
                }
                catch (Exception ex)
                {
                    Cursor = Cursors.Default;
                    MessageBox.Show(this, "Démarrage impossible :\n\n" + ex.Message, "OUTECH",
                        MessageBoxButtons.OK, MessageBoxIcon.Error);
                    Close();
                }
            };

            FormClosed += (s, e) => _serveur?.Dispose();
        }

        private void Naviguer(string url)
        {
            if (url != null && _vue.CoreWebView2 != null) _vue.CoreWebView2.Navigate(url);
        }

        /// <summary>L'adresse appartient-elle à OUTECH (local ou serveur choisi) ?</summary>
        private bool EstInterne(string url)
        {
            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri)) return true;
            if (uri.Scheme != "http" && uri.Scheme != "https") return uri.Scheme == "about" || uri.Scheme == "data" || uri.Scheme == "blob";
            if (!Uri.TryCreate(_accueil, UriKind.Absolute, out var base_)) return true;
            return string.Equals(uri.Host, base_.Host, StringComparison.OrdinalIgnoreCase);
        }

        /// <summary>Liens externes (WhatsApp, sites web…) : navigateur du système.</summary>
        private void NavigationExterne(object sender, CoreWebView2NavigationStartingEventArgs e)
        {
            if (EstInterne(e.Uri)) return;
            e.Cancel = true;
            OuvrirDehors(e.Uri);
        }

        private void NouvelleFenetre(object sender, CoreWebView2NewWindowRequestedEventArgs e)
        {
            if (!EstInterne(e.Uri))
            {
                e.Handled = true;
                OuvrirDehors(e.Uri);
            }
            // Pages internes (impression, aperçus) : WebView2 ouvre sa propre
            // fenêtre, qui partage la session.
        }

        private static void OuvrirDehors(string url)
        {
            try { Process.Start(new ProcessStartInfo(url) { UseShellExecute = true }); } catch { }
        }
    }
}
