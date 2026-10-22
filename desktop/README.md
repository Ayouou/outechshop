# OUTECH — version de bureau (.exe)

`OUTECH.exe` ouvre l'application dans sa propre fenêtre (Microsoft Edge WebView2).
Au premier lancement, l'utilisateur choisit :

| Mode | Ce qui se passe |
|---|---|
| **Local** | Un PHP embarqué démarre sur `127.0.0.1`, base **SQLite** dans `%APPDATA%\OUTECH\donnees`. Aucun Internet, aucun MySQL. |
| **Serveur** | La fenêtre ouvre l'adresse du serveur en ligne (par défaut `https://outech.shop`). |

Changer de mode : menu **OUTECH → Changer de mode**, ou lancer avec `--choisir-mode`
(raccourci « OUTECH (changer de mode) »), ou maintenir **Maj** au démarrage.

## Construction

Tout est fait par GitHub Actions (`.github/workflows/build-exe.yml`) :

1. `dotnet publish` compile `OutechDesktop` (.NET Framework 4.8, déjà présent sur Windows 10/11) ;
2. `desktop/preparer.ps1` ajoute un PHP 8.3 réduit (≈15 Mo) et l'application web (`www`) ;
3. Inno Setup produit `OUTECH-Setup-x.y.z.exe`.

L'installateur se récupère dans **Actions → dernier run → Artifacts**. Pour publier une
Release : `git tag v1.0.0 && git push --tags`.

## Base SQLite

Le même code PHP sert MySQL et SQLite. `BD_PILOTE=sqlite` (posé par OUTECH.exe) active
`app/lib/sqlite.php`, qui traduit les quelques tournures MySQL (`ON DUPLICATE KEY`,
`GROUP_CONCAT … SEPARATOR`, `CREATE TABLE … ENGINE=InnoDB`, etc.). Chaque société a son
fichier `outech_<code>.sqlite`. Sauvegarder = copier le dossier `donnees`.
