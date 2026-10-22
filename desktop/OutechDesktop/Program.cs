using System;
using System.Linq;
using System.Windows.Forms;

namespace OutechDesktop
{
    internal static class Program
    {
        [STAThread]
        private static void Main(string[] args)
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            var reglages = Reglages.Charger();

            // Maj enfoncée au lancement, ou `--choisir-mode` : on redemande.
            var redemander = args.Contains("--choisir-mode") || (Control.ModifierKeys & Keys.Shift) != 0;

            while (true)
            {
                if (!reglages.Choisi || redemander)
                {
                    using (var choix = new ChoixMode(reglages))
                    {
                        if (choix.ShowDialog() != DialogResult.OK) return;
                        reglages.Mode = choix.Mode;
                        if (choix.Mode == "serveur") reglages.UrlServeur = choix.UrlServeur;
                        reglages.Enregistrer();
                    }
                }

                using (var fenetre = new FenetrePrincipale(reglages))
                {
                    Application.Run(fenetre);
                    if (!fenetre.ChangerDeMode) return;
                }
                redemander = true;
            }
        }
    }
}
