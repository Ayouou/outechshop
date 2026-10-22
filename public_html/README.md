# OUTECH — Gestion commerciale (version PHP / MySQL)

Portage en **PHP natif + MySQL** de l'application OUTECH (à l'origine Next.js +
PostgreSQL). Ventes, achats, stock, caisse, chèques/effets, dépenses,
**atelier d'impression** et rapports — pour TPE/PME marocaines.

Interface **en français**, montants en **dirhams**, TVA marocaine (0/7/10/14/20 %).

Une même installation sert **plusieurs sociétés clientes**. Chacune a son
espace, ses comptes et **sa propre base de données** ; une console réservée à
l'exploitant accepte les demandes, ouvre un essai de 7 jours, passe en Pro,
suspend, et peut consulter ou sauvegarder les données de n'importe quelle
société. Voir [Plateforme multi-sociétés](#plateforme-multi-sociétés).

## Stack

| Élément | Choix |
|---|---|
| Langage | PHP 8.1+ (aucun framework, aucune dépendance Composer) |
| Base de données | MySQL 5.7+ / MariaDB 10.4+ via PDO |
| Multi-sociétés | Une base par société + une base « console » pour le registre |
| Style | Tailwind (servi depuis `assets/`, aucun accès Internet requis) |
| Authentification | Session PHP + `password_hash` (bcrypt) |
| Exports | CSV (BOM UTF-8, séparateur `;`) |
| Import d'articles | .xlsx lu nativement (ZipArchive + SimpleXML) et .csv |
| Codes-barres | `jsbarcode` (CODE128) · QR catalogue : `qrcode-generator` |

Tout est servi localement : l'application fonctionne sans connexion Internet,
y compris la caisse.

## Installation

### 1. Créer la base « console » dans phpMyAdmin

1. **Nouvelle base de données** → nom `outech_console` → interclassement
   `utf8mb4_unicode_ci`.
2. Onglet **Importer** → choisir `install/console.sql` → **Exécuter**.

Cette base ne contient **aucune donnée commerciale** : seulement le registre
des sociétés, leurs abonnements et les comptes de l'exploitant. Les bases des
sociétés (dérivées de leur code, à partir de `install/outech.sql`) sont créées
ensuite par la console, une par client.

Si le compte MySQL a le droit de créer des bases, cette étape est facultative :
l'écran `/console/installation` crée `outech_console` lui-même.

Les deux fichiers ne contiennent que des `CREATE TABLE IF NOT EXISTS` : les
importer dans une base qui contient déjà autre chose n'efface rien.

### 2. Déposer les fichiers

Copiez le dossier dans la racine web (`C:\xampp\htdocs\outech` sous XAMPP,
`public_html/outech` chez un hébergeur). L'application fonctionne aussi bien à la
racine d'un domaine que dans un sous-dossier : le chemin de base est déduit
automatiquement.

`config.php` ne contient aucun identifiant : il se téléverse tel quel. Ce qui
change d'une machine à l'autre vit dans `.env`.

### 3. Renseigner la connexion — `.env`

Copiez `.env.exemple` en `.env` et renseignez les valeurs de l'hébergeur :

```ini
BD_HOTE=localhost
BD_PORT=3306
BD_BASE=u1234567_outech
BD_UTILISATEUR=u1234567_outech
BD_MOTDEPASSE=

SAAS_CONSOLE=u1234567_outech        # registre des sociétés
SAAS_PREFIXE=u1234567_              # préfixe imposé par l'hébergeur
SAAS_CREATION_BASES=false           # true si PHP a le droit de créer des bases

FUSEAU=Africa/Casablanca
DEBOGAGE=false
```

Un mot de passe contenant un `#`, ou finissant par une espace, se met entre
guillemets. `.htaccess` refuse l'accès direct à `.env` — sur un serveur qui
ignorerait cette règle, le fichier serait téléchargeable et livrerait les
données de toutes les sociétés : vérifiez que `https://votre-domaine/.env`
répond bien 403.

Sur le poste de développement, un **`.env.local`** l'emporte sur `.env` et ne
se téléverse pas : on garde ainsi la connexion XAMPP à côté de celle du
serveur, sans risque de confondre les deux.

Un seul compte MySQL sert à toutes les bases.

#### Hébergement mutualisé qui interdit `CREATE DATABASE`

Hostinger, cPanel et Plesk imposent un préfixe (`u1234567_`) et refusent la
création de bases depuis PHP. Avec `SAAS_CREATION_BASES=false`, la marche à
suivre pour chaque nouveau client est :

1. panneau de l'hébergeur → **Nouvelle base MySQL**, par exemple
   `u1234567_atlas`, en lui donnant **le même utilisateur** que les autres ;
2. console → *Nouvelle société* (ou acceptation de sa demande) ;
3. dans le dossier de la société, champ **Base de données** : coller
   `u1234567_atlas` ;
4. *Installer l'espace* — les tables se posent, l'essai démarre.

Sans cette base créée à l'avance, la console le dit clairement plutôt que de
renvoyer une erreur MySQL. Pensez à vérifier combien de bases votre offre
autorise : il en faut **une par société, plus une pour le registre**.

### 4. Mise en service

Ouvrez `http://localhost/outech/console/installation` : le formulaire crée la base
console et **votre** compte de gestionnaire de la plateforme. La page se referme
d'elle-même dès qu'un compte existe.

Vous entrez ensuite dans la console (`/console`) et créez vos sociétés : chaque
acceptation crée la base du client, sa société, son lieu d'affaires et son
compte administrateur, et démarre l'essai. Aucun client ne s'installe lui-même.

Le jeu de démonstration peut être posé dans l'espace d'une société :

```bash
php scripts/donnees-demo.php oulmadou-service-703481562907314885206194
```

`install/donnees-minimales.sql` et `install/donnees-demo.sql` restent
importables à la main dans la base d'une société (**après** `outech.sql`) pour qui
préfère phpMyAdmin. Ils posent le mot de passe `admin123` : à changer dès la
première connexion (*Les utilisateurs → Modifier*).

### Essayer sans Apache

```bash
php -S localhost:8000 router.php
```

## Structure

```
index.php            Contrôleur unique : toutes les URL passent par là
.env                 Identifiants et réglages de cette installation
config.php           Structure de la configuration (aucun identifiant)
router.php           Routeur du serveur intégré PHP (développement)
install/outech.sql      Schéma d'une société (une base par client)
install/console.sql  Schéma du registre : sociétés, abonnements, adresses, journal
assets/              Tailwind, jsbarcode, qrcode, feuille de style, favicon
app/routes.php       Table de routage : motif d'URL → fichier de page
app/lib/db.php       Connexions : une par base, `utiliserBase` / `dansBase`
app/lib/saas.php     Registre des sociétés, essais, provisionnement, accès
app/lib/journal.php  Journal des écritures d'une société (table `evenement`)
app/lib/ubl.php      Facture au format UBL 2.1 (Maroc : UBL ou CII)
app/lib/boutique.php Habillage du catalogue : thème, couleur, domaine propre
app/vues/boutique.php Les trois gabarits de la page publique
app/lib/console.php  Session de la console, ouverture d'un espace client
app/lib/             Métier : calculs, stock, documents, retours, sauvegarde…
app/vues/            Gabarit client, gabarit console, composants, icônes
app/pages/console/   Pages de la console (registre uniquement)
app/pages/           Une page = un fichier (logique en tête, rendu ensuite)
scripts/             Outils en ligne de commande (démo, bases, vérification)
```

Chaque page traite son propre POST en tête de fichier puis rend son HTML via
`page()` (coquille complète), `pageNue()` (connexion, caisse, impression,
catalogue public) ou `pageConsole()` (console de la plateforme).

Avant d'inclure la page, `index.php` appelle `contexteRequete()` : c'est **le**
point où la base de travail est choisie — registre pour la console, base de la
société de la session pour l'application, base désignée par l'adresse du
catalogue pour les pages publiques. Le reste du code, écrit pour une seule société, n'a
pas eu à changer.

## Plateforme multi-sociétés

### Une base par société

Les données d'un client vivent dans **sa** base MySQL — `outech_oulmadou_service_703481562907`,
dérivée du code société et bornée aux 64 caractères que MySQL autorise. Deux
sociétés ne partagent aucune table ; il n'existe aucune colonne `societe_id` à
filtrer, donc aucune requête ne peut fuiter d'un client à l'autre par oubli
d'une clause `WHERE`. La séparation est portée par la connexion elle-même.

Le registre (`outech_console`) ne contient que le dossier commercial : nom, code,
contact, statut, formule, dates. Aucune facture, aucun article.

### Le parcours d'un client

1. **Demande** — le prospect remplit `/demande` (ou vous saisissez son dossier
   dans la console). Rien n'est installé : la demande attend.
2. **Décision** — dans `/console/demandes`, vous **acceptez** ou **refusez**.
   Un refus ne crée aucune base et conserve le motif au dossier.
3. **Acceptation** — la base du client est créée, le schéma appliqué, la
   société, le lieu d'affaires et le compte administrateur posés. Un mot de
   passe provisoire est affiché **une seule fois** : c'est celui que vous
   transmettez avec le code société.
4. **Essai** — 7 jours par défaut (réglable). Un bandeau annonce au client les
   jours restants ; rien d'autre n'est bridé.
5. **Suite** — vous passez la société en **Pro** (avec ou sans date de fin), ou
   vous prolongez l'essai. À l'échéance, l'espace se ferme de lui-même : la
   date fait foi à la lecture, sans tâche planifiée.

Essai terminé, abonnement échu ou société suspendue, l'utilisateur arrive sur
`/abonnement` : ses données sont intactes et le lui disent. Rien n'est jamais
supprimé par la plateforme — même « Retirer du service » conserve la base.

### Les formules d'abonnement

| Formule | Prix | Soit par mois |
|---|---|---|
| 1 mois | 199 dhs | 199 dhs |
| 6 mois | 900 dhs | 150 dhs |
| 1 an | 1 500 dhs | 125 dhs |
| 2 ans | 2 300 dhs | 96 dhs |
| 3 ans | 2 900 dhs | 81 dhs |
| 6 ans | 5 000 dhs | 69 dhs |

Ces tarifs se modifient dans *Réglages → Tarifs*, sans toucher au code ; la
remise affichée au prospect (« −37 % ») se recalcule d'elle-même face au tarif
mensuel.

Le formulaire de demande fait choisir une formule — sans rien encaisser :
l'essai démarre de toute façon, et la réponse dit seulement à la plateforme
vers quoi le client s'orientait. Le dossier la rappelle, et le passage en Pro
la propose d'un clic : la date d'échéance se calcule sur la durée choisie, en
partant de l'échéance en cours s'il en reste. Une date à la main reste possible
pour un arrangement particulier.

Toutes les formules donnent le même logiciel : seule la durée change.

### Ce que le client saisit pour entrer

Trois champs : **code société**, identifiant, mot de passe.

Le code est composé du nom de la société suivi de **24 chiffres** tirés au
sort — `oulmadou-service-703481562907314885206194`. Le nom le rend
reconnaissable, les chiffres le rendent impossible à deviner et permettent à
deux sociétés de porter la même enseigne. Il est **généré, jamais saisi** : la
console l'affiche avec un bouton « copier », et le dossier fournit aussi un
lien `/login?e=<code>` qui le remplit à la place du client. Après une première
connexion, le code reste mémorisé dans un cookie sur l'appareil.

Un code compromis se remplace depuis le dossier (*Accès du client → Régénérer
le code*) : la base ne bouge pas, seules les anciennes adresses cessent de
fonctionner.

### Les pages publiques ne portent jamais le code

Le catalogue d'un commerçant s'ouvre sur `/c/epicerie-atlas` — l'adresse qu'il
a choisie lui-même dans *Catalogue → Adresse publique*, et rien d'autre. Un
code société n'a pas sa place sur une vitrine ou dans un QR code : c'est une
clé d'accès, pas une enseigne.

Ces adresses étant communes à toutes les sociétés, elles se réservent comme un
nom de domaine : la table `saas_catalogue` du registre les tient, une par
catalogue (donc une par lieu d'affaires), et le formulaire refuse une adresse
déjà prise en proposant une variante. Les photos suivent la même adresse
(`/p/epicerie-atlas/images/<id>`), si bien qu'aucune page publique ne laisse
filtrer par où entrer.

Retirer une société du service libère son adresse.

### Trois mises en page, une couleur

Le catalogue se présente de trois façons, au choix du commerçant, dans
*Catalogue → Apparence*. Les articles, les prix et les photos sont les mêmes
dans les trois : c'est la présentation qui change.

| Thème | Ce que c'est | Pour qui |
|---|---|---|
| **Vitrine** | Bandeau coloré, puis les articles en cartes | Commerce, quincaillerie, alimentation |
| **Liste** | Une ligne par article, groupée par rayon, recherche et intertitres collants | Gros catalogues : grossiste, pièces détachées |
| **Galerie** | Grandes images, texte posé dessus en dégradé | Imprimerie, décoration, textile |

La couleur se choisit parmi huit pastilles, retenues pour rester lisibles en
blanc dessus. Elle n'est pas écrite dans les gabarits : `paletteBoutique()`
décline la teinte en une gamme de dix niveaux (variables CSS `--b-50` à
`--b-900`), et les trois thèmes s'y réfèrent. Un commerçant ne peut donc pas
rendre sa page illisible, et changer de thème ne fait pas perdre la couleur.

Sur sa page publique, l'onglet du navigateur porte **le nom du commerçant**,
pas celui de la plateforme.

### Son propre nom de domaine

Un catalogue peut répondre sur le domaine du commerçant (`boutique-atlas.ma`)
en plus de son adresse `/c/<adresse>` — c'est la même page, pas une copie.
Le domaine se saisit dans *Catalogue*, avec ou sans `www`, avec ou sans
`https://` : il est ramené à sa forme simple. Comme les adresses, les domaines
sont **communs à toute la plateforme** (table `saas_domaine` du registre) :
deux commerçants ne peuvent pas revendiquer le même, et une société n'en a
qu'un.

**Deux étapes sur trois ne se font pas dans OUTECH** : faire pointer
l'enregistrement A du domaine vers l'IP du serveur, puis déclarer le domaine
chez l'hébergeur et obtenir son certificat HTTPS. L'application ne sait que
reconnaître l'hôte reçu et servir le bon catalogue à la racine — ce qu'elle
fait dès que les deux autres étapes sont faites. Tant qu'elles ne le sont pas,
le domaine ne répond simplement pas ; rien à corriger côté application.

### Commander par WhatsApp

Le commerçant coche *Recevoir les commandes sur WhatsApp* dans ses réglages de
catalogue et donne son numéro. Chaque article du catalogue public gagne alors un
bouton **Commander**, doublé d'un bouton flottant et d'un lien en pied de page.

Le client n'a rien à installer et aucun compte à créer : le lien ouvre sa
conversation WhatsApp avec le commerçant, **le message déjà écrit** — nom de
l'article, référence, prix et adresse du catalogue. C'est le panier le plus
court possible, et la commande arrive là où le commerçant regarde déjà.

Le numéro est accepté au format local comme international (`06 12 34 56 78`,
`+212 6 12 34 56 78`, `00212…`) et ramené à la forme attendue par WhatsApp.
Cocher l'option sans numéro exploitable est refusé — un bouton qui ne mène
nulle part est pire que pas de bouton. Un article épuisé n'en reçoit pas.

### Ce que vous gardez de côté

- **Ouvrir l'espace** — la console entre dans l'espace d'un client sous son
  compte administrateur, y compris s'il est suspendu ou échu. Un bandeau ambre
  le rappelle en permanence, et le geste est inscrit au journal.
- **Sauvegardes** — `/console/sauvegardes` télécharge le JSON complet de
  n'importe quelle société (documents, stock, tiers, règlements, photos), au
  format restaurable depuis *Paramètres → Sauvegarde* de son espace.
- **Journal** — acceptation, refus, passage en Pro, prolongation, suspension,
  ouverture d'espace, réinitialisation de mot de passe : tout est horodaté,
  avec l'auteur et l'adresse IP.

### Aucune limite pour les sociétés

Ni utilisateurs, ni articles, ni documents ne sont plafonnés. Le seul réglage
de volume est le **nombre maximum de sociétés** de la plateforme (30 par
défaut), qui protège l'hébergement, pas le client.

## Modules

- **Console de la plateforme** (`/console`, réservée à l'exploitant) — demandes à accepter ou refuser, dossier de chaque société (situation, contact, chiffres clés lus dans sa base, comptes, journal), essai et formule Pro, suspension, réinitialisation du mot de passe administrateur, ouverture de l'espace d'un client, sauvegarde par société, réglages (durée d'essai, plafond, inscriptions ouvertes ou non, gestionnaires).
- **La caisse** — recettes d'une journée : encaissé du jour et ventilation par mode de règlement, espèces réellement en caisse (entrées moins sorties), ventes du jour et reste à encaisser, détail des encaissements et des sorties. C'est l'écran d'accueil du caissier.
- **Tableau de bord** — 8 indicateurs, courbe des ventes, alertes de stock, échéances.
- **Clients / Fournisseurs** — fiches complètes (ICE, RC, plafond de crédit), groupes de clients avec remise, historique et solde dû.
- **Stock** — articles (référence, code-barres, prix, TVA, seuil d'alerte, **photo**), **variantes** avec stock propre, catégories, marques, **groupes de prix de vente**, **import Excel/CSV**, mouvements tracés, ajustements, étiquettes code-barres imprimables.
- **Documentation** — notes, procédures, contrats et fiches techniques, rattachables à un client, un article ou un document.
- **Ventes** — Devis → Commande client → Bon de livraison → Facture, avoirs/retours, échéances. Conversion en un clic avec chaînage. **Regroupement** de plusieurs bons en une facture unique. **Livraisons** : transporteur, numéro de suivi, statut et dates.
- **Achats** — bons de commande, achats, retours d'achat.
- **Point de vente** — caisse tactile plein écran, tuiles avec photo, **pavé numérique à chaque article touché**, quantité corrigeable au clavier, **plafonnée au stock disponible**, scan code-barres, remise, rendu monnaie. Remet un bon de livraison encaissé (ou une facture à la demande), imprimable en A4 ou sur rouleau 80 / 58 mm.
- **Imprimerie** (`/imprimerie`) — atelier d'impression : **catalogue des supports et prestations** (bâche, vinyle, film DTF, papier d'un côté ; impression, transfert DTF, lettrage de l'autre), tableau des dossiers poste par poste (accueil → design → validation client → impression → finition → prêt → livré), ordres de travail chiffrés au m², au mètre linéaire ou à la pièce, **fiche d'atelier imprimable**, machines et leur laize, **consommation de matière au mètre avec sa chute** (motif, machine, opérateur), rapport matière (reste en rouleau, utilisé, perdu, taux et coût de la chute) et **comptoir impression** pour la vente au mètre encaissée sur-le-champ. Un travail se convertit en devis, bon de livraison ou facture.
- **Chèques / Effets** — suivi des règlements, création automatique depuis un paiement, rapport consolidé.
- **Dépenses** — catégories, TVA, suivi des paiements.
- **Inventaire physique** — comptage figé, écarts, application au stock à la clôture.
- **Rapports** — CA, marge brute, résultat, meilleurs articles et clients, stock valorisé.
- **Catalogue public** — page publique `/c/<adresse-choisie>` + QR code, adresse réservée à l'échelle de la plateforme, activation et affichage des prix configurables, **commande par WhatsApp** en option, **trois mises en page**, **couleur au choix** et **nom de domaine propre**.
- **Paramètres** — société (ICE, RC, IF, patente, CNSS), pays, devise, TVA par défaut, lieux d'affaires multiples.
- **Sauvegarde** — export de la base entière dans un fichier JSON unique et restauration à l'identique.
- **Utilisateurs** — comptes, rôles `ADMIN`, `MANAGER`, `COMMERCIAL`, `CAISSIER` (accueil), `DESIGNER` et `MACHINISTE`, et affectation aux lieux.

## Règles métier

Identiques à l'application d'origine :

- **Stock** — seuls les bons de livraison, factures, avoirs, achats et retours d'achat génèrent des mouvements. Devis et commandes n'impactent pas le stock. À la modification ou l'annulation, les mouvements sont repris puis réappliqués. **Une conversion ne mouvemente le stock que si le document d'origine ne l'a pas déjà fait.**
- **Stock au comptoir** — la caisse ne vend jamais plus que le stock du lieu : quantité tapée, champ du panier, boutons + / − et douchette sont tous ramenés au disponible. Les articles non suivis en stock ne sont pas bornés ; le back-office reste libre de saisir en négatif.
- **Ventes comptabilisées** — le chiffre d'affaires additionne les factures et les bons de livraison *pas encore regroupés*. Dès qu'un bon est facturé, il passe en « converti » et c'est sa facture qui le porte.
- **Regroupement** — depuis la liste des bons : le premier bon coché fixe le client, ceux des autres clients se verrouillent. La facture reprend les lignes une à une, convertit la remise globale de chaque bon en remise de ligne pour préserver la ventilation de TVA, ne touche pas au stock, et **déplace** les règlements encaissés au comptoir.
- **Totaux** — la remise globale est répartie au prorata des lignes *avant* calcul de la TVA ; le pied de facture détaille la ventilation par taux.
- **Numérotation** — compteurs annuels par type : `FA2026/0001`, `DV2026/0001`, `BC2026/0001`, `OT2026/0001` pour les ordres de travail… L'incrément se fait en base (`INSERT … ON DUPLICATE KEY UPDATE`) : deux caisses simultanées ne peuvent pas obtenir le même numéro.
- **Suppressions** — un tiers, un article ou un lieu rattaché à des documents est désactivé, jamais supprimé. **Une facture ou un avoir ne se supprime jamais** : son numéro est attribué et la séquence ne se troue pas. L'annulation, elle, reste ouverte — le document demeure au dossier, sans effet sur le stock ni sur le chiffre d'affaires.
- **Pièce figée** — une facture ou un avoir cesse d'être modifiable dès qu'il porte un règlement, ou dès qu'il a été transmis à l'administration fiscale. La correction passe alors par un avoir. Le second signal se lit dans la table `efacture` : tant qu'elle n'existe pas, seul le règlement fige la pièce.
- **Facture UBL** — une facture ou un avoir se met au format **UBL 2.1** (`ublFacture()`), le seul que la plateforme de la DGI accepte avec le CII. Le papier n'est qu'un rendu : la pièce lue par l'administration est ce XML. La remise globale, un montant en dirhams réparti au prorata, y est **éclatée par taux de TVA** — la norme veut une remise par régime, sinon les catégories ne s'équilibrent plus ; le taux le plus lourd absorbe les centimes d'arrondi pour que le XML retombe au centime sur la facture imprimée. Les frais de port, non taxés, sortent en charge à taux zéro. Ce qui est propre au pays (`schemeID` de l'ICE, identifiants légaux) est rassemblé en tête de `app/lib/ubl.php` : c'est là, et nulle part ailleurs, que se branche un autre pays — les Émirats attendent PINT AE, un profil Peppol lui aussi bâti sur UBL.
- **Journal des écritures** — création, modification, annulation et suppression d'un document, encaissement et suppression d'un règlement sont inscrits dans la table `evenement` de la société, avec l'état avant, l'état après, l'auteur, l'adresse IP et l'horodatage. L'écriture se fait dans la transaction de l'opération : un incident les emporte ensemble. Rien n'y est jamais modifié ni supprimé — c'est la trace qui rend une facture opposable, et le socle de l'archivage sur dix ans. Une base installée avant cette version journalise dès que `bases.php --migrer` y a posé la table.
- **Règlements** — un paiement par chèque ou effet crée automatiquement la pièce correspondante dans le module Chèques/Effets, qu'il vienne du back-office ou de la caisse.
- **Retours** — le formulaire liste ce qui a été acheté (ou facturé) au tiers sur la période, déduit ce qui a déjà été retourné et propose le **prix d'origine**. Le serveur refuse tout retour portant sur un article non acheté à ce tiers, ou dépassant la quantité restante.
- **Variantes** — dès qu'un article possède une variante, il passe en type `VARIABLE` : ce sont les variantes qui portent le stock et se vendent. Une variante sans prix propre hérite de celui de l'article.
- **Groupes de prix** — le tarif est défini par article et s'applique à toutes ses variantes. Changer le client dans un document repositionne les prix des lignes.
- **Import d'articles** — correspondance sur la référence ou le code-barres : un article connu est mis à jour, jamais dupliqué. Catégories et marques absentes sont créées à la volée. Le fichier est analysé et présenté en aperçu avant toute écriture.
- **Habillage du catalogue** — le thème et la couleur sont validés à la lecture (`themeBoutique()`, `couleurBoutique()`) : une valeur inconnue en base retombe sur la vitrine et le marine, jamais sur une page cassée. La couleur ne descend jamais en classe Tailwind écrite à la main — une gamme de variables CSS est calculée à partir de la teinte, et les gabarits ne posent que `[var(--b-700)]`.
- **Domaine propre** — la racine sert le catalogue quand l'hôte de la requête figure au registre des domaines ; sinon la requête suit son cours normal. Un registre injoignable ne bloque pas le site : la résolution renvoie simplement « pas de domaine ».
- **Photos** — l'image est réduite par le navigateur (640 px, JPEG) avant l'envoi, puis stockée dans la table `image` et servie par `/api/images/<id>` avec un cache permanent. Le disque n'est pas utilisé : une photo en base part avec la sauvegarde.
- **Postes d'atelier** — un `DESIGNER` ou un `MACHINISTE` ne voit que l'imprimerie : le tableau de l'atelier et les ordres de travail, rien d'autre. Il fait avancer les dossiers et déclare la matière depuis le dossier, mais n'en ouvre aucun, n'annule rien, n'efface rien — et **aucun montant ne lui est affiché**, ni total, ni avance, ni coût de chute, jusque sur la fiche imprimée. La barrière est posée dans `index.php` (`verifierPortee`), pas seulement dans le menu : une adresse tapée à la main ne mène nulle part.
- **Accueil** — le `CAISSIER` reçoit les clients et ouvre les dossiers ; le tableau de l'atelier lui est fermé, puisqu'il porte les cumuls du mois, et sa liste d'ordres de travail est **bornée à la journée**.
- **Accès par rôle** — l'administrateur voit tout. Le **caissier** n'a ni tableau de bord ni rapports : il ouvre sur *La caisse*. Le **commercial** ouvre lui aussi sur *La caisse* : ni tableau de bord, ni rapports, ni atelier d'impression — il vend, facture et suit ses clients, la marge de l'entreprise et la production ne le regardent pas. La restriction est posée côté serveur (`exigerRole`), pas seulement dans le menu. L'administrateur d'une société reste l'administrateur **de sa société** : il ne voit rien de la plateforme.
- **Abonnement** — la situation d'une société se déduit de ses dates à chaque requête : un essai dont la date est passée ferme l'accès sans qu'aucune tâche planifiée n'ait à s'exécuter. Les données, elles, ne bougent pas.
- **Recettes du jour** — la caisse ne compte comme entrée que les règlements portés par une facture ou un bon de livraison, et comme sortie les achats réglés et les dépenses payées. Le solde en espèces ne retient que le mode « espèces ». Tout est cadré sur le lieu d'affaires de la session et la journée locale.
- **Support et prestation** — un *support* est le rouleau que l'on consomme : il porte le stock, la laize et le prix d'achat. Une *prestation* est ce que paie le client, et elle désigne le support qu'elle mange. Un tirage plus large que la laize se découpe en lés, et le rouleau y passe autant de fois — c'est ce que compte « à sortir » sur chaque ligne, corrigible à la main.
- **Sortie automatique** — la matière prévue quitte le stock quand le dossier arrive en **impression**, une seule fois (`matiere_sortie_le`) : repasser par l'étape ne reprélève rien. La chute, elle, reste déclarée par le machiniste — lui seul sait ce qui est parti à la poubelle.
- **Comptoir impression** — la vente sans dossier : dimensions, prix, encaissement, ticket 80 mm. Elle crée un bon de livraison encaissé et sort la matière du rouleau. Le document ne porte pas l'article support : le compter là le retirerait deux fois.
- **Matière d'imprimerie** — une sortie d'atelier retire du stock la matière **utilisée** *et* la **chute**, en deux mouvements distincts (`PRODUCTION` et `CHUTE`) : le stock est juste, et le rapport sait dire ce qui est parti en fumée. Reprendre une sortie rend au rouleau exactement ce qu'elle avait pris ; supprimer un ordre de travail reprend toutes les siennes.
- **Travail et facturation** — l'ordre de travail ne touche jamais au stock : la matière en sort par l'écran « consommation ». La pièce commerciale qu'on en tire reprend les lignes chiffrées (surface, longueur ou pièces), reporte l'avance, et **ne redécompte pas** un support déjà sorti par l'atelier. Un travail ne donne qu'une seule pièce.
- **Étapes d'atelier** — chaque passage est journalisé avec son auteur et son heure : la fiche affiche le temps passé à chaque poste. Le poste retient l'intervenant qui l'a franchi (designer, machiniste, livreur) sans écraser un nom saisi à l'accueil.
- **Sauvegarde** — le fichier contient toutes les tables, dans un ordre où chaque ligne suit celles dont elle dépend. La restauration vide la base et réinsère tout **dans une seule transaction** : le moindre incident laisse la base intacte.

## Sécurité

- **Cloisonnement des sociétés** — une base par client, choisie une seule fois
  par requête (`contexteRequete`). Un nom de base ne pouvant pas voyager en
  paramètre préparé, il est validé au caractère près (`[A-Za-z0-9_]`) avant
  d'entrer dans le SQL, et la console refuse d'installer une société sur une
  base déjà occupée.
- Toutes les requêtes SQL sont **préparées** — aucune valeur n'est concaténée.
- Tout POST exige un **jeton CSRF** de session (`champCsrf()` / `verifierCsrf()`).
- Les données affichées passent par `e()` (échappement HTML).
- Le cookie de session est `httponly` + `samesite=Lax`, et `secure` en HTTPS.
- La console a ses propres comptes (`saas_admin`), distincts de ceux des
  sociétés : le mot de passe d'un client n'ouvre jamais la console, et
  l'inverse est vrai aussi.
- Les mots de passe provisoires ne sont **jamais stockés en clair** : ils sont
  affichés une fois à leur création, puis oubliés.
- Le code société ne renseigne pas un attaquant : un code inconnu, une société
  refusée ou un mot de passe faux donnent le même genre de réponse laconique.
  Ses 24 chiffres tirés au sort le mettent hors de portée d'une énumération, et
  il se régénère en un clic s'il a circulé.
- `.htaccess` refuse l'accès direct à `config.php` et au dossier `app/`.

## Reprise des données depuis la version Next.js

Le format de sauvegarde est **le même** que celui de l'application d'origine.
Pour migrer :

1. Sur l'ancienne application : *Paramètres → Sauvegarde → Télécharger*
   (ou `npm run db:exporter`).
2. Ici : créez la société dans la console, ouvrez son espace, puis
   *Paramètres → Sauvegarde des données → Restaurer*.

Identifiants, numéros de documents, compteurs et photos sont conservés au
caractère près. Une reprise se fait société par société : le fichier d'un
client n'entre que dans sa base.

## Scripts

| Commande | Effet |
|---|---|
| `php scripts/bases.php` | État de chaque société : base, tables, documents, situation |
| `php scripts/bases.php --migrer [code]` | Applique `install/outech.sql` à toutes les bases (ou à une seule) après une évolution du schéma |
| `php scripts/donnees-demo.php <code>` | Données de démonstration dans l'espace d'une société |
| `php scripts/verifier.php <url> <code> <identifiant> <motdepasse> [console] [motdepasse]` | Test de fumée : parcourt toutes les routes, côté client et côté console |
| `php scripts/parcours.php <code>` | Rejoue les règles métier (achat, conversion, regroupement, retour, annulation, totaux) et vérifie leurs effets |
| `php scripts/parcours-imprimerie.php <code>` | Rejoue le parcours de l'atelier (travail, étapes, consommation, chute, reprise, facturation) et vérifie le stock à chaque pas |
| `php scripts/parcours-conformite.php <code>` | Vérifie le journal des écritures, le verrou des pièces comptables et l'équilibre de la facture UBL |
| `php scripts/ubl.php <code> [numero]` | Écrit sur la sortie standard la facture au format UBL 2.1 (la dernière émise, sans numéro) |

```bash
php scripts/verifier.php http://localhost:8000 <code-societe> admin motdepasse plateforme motdepasse
```

Une évolution de `install/outech.sql` doit être passée à **toutes** les bases :
c'est le rôle de `bases.php --migrer`, qui rejoue le schéma sans rien détruire
(`CREATE TABLE IF NOT EXISTS`).

## Impression

Les documents s'impriment via `/impression/<id>?format=<a4|80|58>` :

| Format | Usage |
|---|---|
| `a4` (défaut) | Pièce comptable complète : en-tête société, ventilation de TVA, règlements, pied de page |
| `80` | Rouleau thermique 80 mm |
| `58` | Rouleau thermique 58 mm |

Les tickets sont composés en chasse fixe et en noir : les imprimantes thermiques
ne rendent ni les gris ni les aplats de couleur. La taille du support est
déclarée par une règle `@page` rendue avec la page. Le navigateur gère l'export
PDF ; aucune dépendance serveur supplémentaire.

## Mise en production

- Passer `DEBOGAGE=false` dans `.env` (aucune erreur PHP affichée).
- Vérifier que `https://votre-domaine/.env` répond **403**, pas le contenu du
  fichier.
- Servir en **HTTPS** : le cookie de session prend alors l'attribut `secure`.
- Créer un utilisateur MySQL dédié plutôt que `root`. Il lui faut
  `SELECT, INSERT, UPDATE, DELETE` sur toutes les bases, plus `CREATE` si la
  console doit créer celles des nouvelles sociétés — sinon, `creation_bases`
  à `false` et création manuelle dans le panneau.
- Donner au compte de la console un mot de passe long : il ouvre l'espace de
  toutes les sociétés.
- Sauvegarder régulièrement — `/console/sauvegardes` télécharge chaque société
  en un fichier, et rien n'empêche d'y ajouter un export phpMyAdmin.
