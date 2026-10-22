using System;
using System.Collections.Generic;
using System.IO;

namespace OutechDesktop
{
    /// <summary>
    /// Choix de l'utilisateur (mode local ou serveur), gardé dans
    /// %APPDATA%\OUTECH\reglages.ini.
    /// </summary>
    internal sealed class Reglages
    {
        public const string UrlServeurParDefaut = "https://outech.shop";

        /// <summary>"local" ou "serveur" ; vide tant que l'utilisateur n'a pas choisi.</summary>
        public string Mode { get; set; } = "";
        public string UrlServeur { get; set; } = UrlServeurParDefaut;

        public bool EstLocal => Mode == "local";
        public bool EstServeur => Mode == "serveur";
        public bool Choisi => EstLocal || EstServeur;

        public static string DossierAppli =>
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "OUTECH");

        public static string DossierDonnees => Path.Combine(DossierAppli, "donnees");

        public static string DossierLocal =>
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "OUTECH");

        private static string Fichier => Path.Combine(DossierAppli, "reglages.ini");

        public static Reglages Charger()
        {
            var r = new Reglages();
            try
            {
                if (!File.Exists(Fichier)) return r;
                var valeurs = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                foreach (var ligne in File.ReadAllLines(Fichier))
                {
                    var i = ligne.IndexOf('=');
                    if (i > 0) valeurs[ligne.Substring(0, i).Trim()] = ligne.Substring(i + 1).Trim();
                }
                if (valeurs.TryGetValue("mode", out var mode)) r.Mode = mode.ToLowerInvariant();
                if (valeurs.TryGetValue("url", out var url) && url != "") r.UrlServeur = url;
            }
            catch
            {
                // Fichier illisible : on repart des valeurs par défaut.
            }
            return r;
        }

        public void Enregistrer()
        {
            Directory.CreateDirectory(DossierAppli);
            File.WriteAllLines(Fichier, new[] { "mode=" + Mode, "url=" + UrlServeur });
        }
    }
}
