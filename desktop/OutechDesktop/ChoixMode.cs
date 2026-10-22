using System;
using System.Drawing;
using System.Windows.Forms;

namespace OutechDesktop
{
    /// <summary>
    /// Fenêtre du premier lancement : travailler sur cet ordinateur (local) ou
    /// se connecter au serveur en ligne.
    /// </summary>
    internal sealed class ChoixMode : Form
    {
        private readonly RadioButton _local;
        private readonly RadioButton _serveur;
        private readonly TextBox _url;

        public string Mode => _local.Checked ? "local" : "serveur";
        public string UrlServeur => _url.Text.Trim();

        public ChoixMode(Reglages actuels)
        {
            Text = "OUTECH — mode de fonctionnement";
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            MinimizeBox = false;
            StartPosition = FormStartPosition.CenterScreen;
            ClientSize = new Size(480, 300);
            Font = new Font("Segoe UI", 10f);

            var titre = new Label
            {
                Text = "Comment voulez-vous utiliser OUTECH ?",
                Font = new Font("Segoe UI", 12f, FontStyle.Bold),
                AutoSize = true,
                Location = new Point(20, 18),
            };

            _local = new RadioButton
            {
                Text = "Local — sur cet ordinateur, sans Internet",
                AutoSize = true,
                Location = new Point(24, 62),
            };
            var aideLocal = new Label
            {
                Text = "Les données restent sur ce PC (dossier %APPDATA%\\OUTECH).",
                ForeColor = Color.DimGray,
                AutoSize = true,
                Location = new Point(44, 88),
            };

            _serveur = new RadioButton
            {
                Text = "Serveur — se connecter à OUTECH en ligne",
                AutoSize = true,
                Location = new Point(24, 126),
            };
            var aideServeur = new Label
            {
                Text = "Adresse du serveur :",
                ForeColor = Color.DimGray,
                AutoSize = true,
                Location = new Point(44, 154),
            };
            _url = new TextBox
            {
                Text = actuels.UrlServeur,
                Location = new Point(46, 178),
                Width = 400,
            };

            _local.Checked = !actuels.EstServeur;
            _serveur.Checked = actuels.EstServeur;
            _url.Enabled = _serveur.Checked;
            _serveur.CheckedChanged += (s, e) => _url.Enabled = _serveur.Checked;

            var note = new Label
            {
                Text = "Vous pourrez changer plus tard : menu OUTECH → Changer de mode.",
                ForeColor = Color.DimGray,
                AutoSize = true,
                Location = new Point(20, 220),
            };

            var ok = new Button { Text = "Continuer", DialogResult = DialogResult.OK, Location = new Point(250, 252), Width = 110 };
            var annuler = new Button { Text = "Annuler", DialogResult = DialogResult.Cancel, Location = new Point(366, 252), Width = 90 };
            ok.Click += Valider;
            AcceptButton = ok;
            CancelButton = annuler;

            Controls.AddRange(new Control[] { titre, _local, aideLocal, _serveur, aideServeur, _url, note, ok, annuler });
        }

        private void Valider(object sender, EventArgs e)
        {
            if (_local.Checked) return;

            var url = UrlServeur;
            if (!url.StartsWith("http://", StringComparison.OrdinalIgnoreCase)
                && !url.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
            {
                url = "https://" + url;
                _url.Text = url;
            }
            if (!Uri.TryCreate(url, UriKind.Absolute, out _))
            {
                MessageBox.Show(this, "Adresse du serveur invalide.", "OUTECH", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                DialogResult = DialogResult.None;
            }
        }
    }
}
