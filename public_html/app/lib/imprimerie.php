<?php
/**
 * Atelier d'impression : ordres de travail, suivi par étapes et consommation
 * de matière.
 *
 * Deux choses distinctes, volontairement séparées :
 *  - l'**ordre de travail** (`travail`) porte ce qui est vendu au client —
 *    une affiche, une bâche, dix mètres de DTF — et son avancement ;
 *  - la **consommation** (`consommation`) porte ce qui sort réellement du
 *    rouleau, matière utilisée *et* chute comprises.
 *
 * C'est pour cela que facturer un travail ne touche jamais au stock : la
 * matière en est déjà sortie par l'atelier. La facturer une seconde fois
 * comme marchandise la retirerait deux fois.
 */

/** Étapes du parcours, dans l'ordre où l'atelier les traverse. */
const ETAPES_TRAVAIL = [
    'ACCUEIL'    => ['label' => 'Accueil',           'couleur' => 'gris',   'aide' => 'Commande reçue, brief du client'],
    'DESIGN'     => ['label' => 'Design',            'couleur' => 'violet', 'aide' => 'Maquette en cours'],
    'VALIDATION' => ['label' => 'Validation client', 'couleur' => 'orange', 'aide' => 'Bon à tirer envoyé, en attente'],
    'IMPRESSION' => ['label' => 'Impression',        'couleur' => 'bleu',   'aide' => 'Sur machine'],
    'FINITION'   => ['label' => 'Finition',          'couleur' => 'bleu',   'aide' => 'Découpe, œillets, plastification…'],
    'PRET'       => ['label' => 'Prêt à livrer',     'couleur' => 'vert',   'aide' => 'Terminé, en attente du client'],
    'LIVRE'      => ['label' => 'Livré',             'couleur' => 'vert',   'aide' => 'Remis au client'],
    'ANNULE'     => ['label' => 'Annulé',            'couleur' => 'rouge',  'aide' => 'Commande abandonnée'],
];

/** Étapes affichées en colonnes sur le tableau de l'atelier. */
const ETAPES_ACTIVES = ['ACCUEIL', 'DESIGN', 'VALIDATION', 'IMPRESSION', 'FINITION', 'PRET'];

/** Étape dont dépend chaque intervenant : le poste retient qui y est passé. */
const INTERVENANT_ETAPE = [
    'DESIGN'     => 'designer_id',
    'IMPRESSION' => 'machiniste_id',
    'LIVRE'      => 'livreur_id',
];

const PRIORITES_TRAVAIL = [
    'NORMALE' => ['label' => 'Normale', 'couleur' => 'gris'],
    'URGENTE' => ['label' => 'Urgente', 'couleur' => 'rouge'],
];

/**
 * Comment se compte une ligne :
 *  - `M2`    largeur × hauteur × exemplaires (bâche, vinyle, panneau)
 *  - `ML`    longueur × exemplaires (film DTF, vinyle vendu au mètre)
 *  - `PIECE` les exemplaires eux-mêmes (carte de visite, t-shirt, tampon)
 */
const MODES_LIGNE = [
    'M2'    => ['label' => 'Au m²',            'unite' => 'm²',    'court' => 'm²'],
    'ML'    => ['label' => 'Au mètre linéaire', 'unite' => 'mètre', 'court' => 'm'],
    'PIECE' => ['label' => 'À la pièce',       'unite' => 'pièce',  'court' => 'pce'],
];

/** Pourquoi de la matière est partie en chute. */
const MOTIFS_CHUTE = [
    'PRODUCTION'        => 'Chute normale de production',
    'ERREUR_IMPRESSION' => "Erreur d'impression",
    'ERREUR_DESIGN'     => 'Erreur de fichier / design',
    'BOURRAGE'          => 'Bourrage machine',
    'FIN_ROULEAU'       => 'Fin de rouleau inutilisable',
    'TEST_MACHINE'      => 'Test / réglage machine',
    'ANNULATION_CLIENT' => 'Annulation du client',
    'AUTRE'             => 'Autre',
];

/**
 * Rôle d'un article dans l'atelier.
 *
 * Un **support** est ce qui se consomme au mètre : bâche, vinyle, film DTF,
 * papier. Une **prestation** est ce qui se vend : impression sur bâche,
 * transfert DTF, lettrage, plastification — et elle mange un support.
 */
const TYPES_ARTICLE_IMPRESSION = [
    'SUPPORT'    => 'Support (rouleau consommé)',
    'PRESTATION' => 'Prestation (ce qui se vend)',
];

const TYPES_MACHINE = [
    'IMPRESSION'  => 'Impression grand format',
    'DTF'         => 'DTF / textile',
    'SUBLIMATION' => 'Sublimation',
    'LASER'       => 'Laser / gravure',
    'DECOUPE'     => 'Découpe (plotter)',
    'THERMO'      => 'Presse à chaud',
    'FINITION'    => 'Finition',
    'AUTRE'       => 'Autre',
];

/** Pièces qu'un ordre de travail peut produire, une fois le prix arrêté. */
const PIECES_TRAVAIL = ['DEVIS', 'BON_LIVRAISON', 'FACTURE'];

/* ------------------------------------------------------------------ étapes */

/** Étape qui suit dans le parcours, ou null au bout (`LIVRE`, `ANNULE`). */
function etapeSuivante(string $etape): ?string
{
    $rang = array_search($etape, ETAPES_ACTIVES, true);
    if ($rang === false) return null;
    return ETAPES_ACTIVES[$rang + 1] ?? 'LIVRE';
}

/** Le dossier est-il sorti de l'atelier ? */
function travailClos(string $etape): bool
{
    return $etape === 'LIVRE' || $etape === 'ANNULE';
}

/** Durée lisible : « 40 min », « 3 h 15 », « 2 j 4 h ». */
function dureeCourte(float $secondes): string
{
    $secondes = max(0, $secondes);
    $minutes = (int) round($secondes / 60);
    if ($minutes < 60) return $minutes . ' min';

    $heures = intdiv($minutes, 60);
    if ($heures < 24) {
        $reste = $minutes % 60;
        return $heures . ' h' . ($reste ? ' ' . str_pad((string) $reste, 2, '0', STR_PAD_LEFT) : '');
    }

    $jours = intdiv($heures, 24);
    $reste = $heures % 24;
    return $jours . ' j' . ($reste ? ' ' . $reste . ' h' : '');
}

/* ------------------------------------------------------------------ lignes */

/** Quantité facturée d'une ligne, selon son mode de calcul. */
function unitesLigne(array $ligne): float
{
    $largeur  = max(0, num($ligne['largeur'] ?? 0));
    $hauteur  = max(0, num($ligne['hauteur'] ?? 0));
    $quantite = max(0, num($ligne['quantite'] ?? 0));

    switch ($ligne['mode'] ?? 'M2') {
        case 'ML':    return round($hauteur * $quantite, 4);
        case 'PIECE': return round($quantite, 4);
        default:      return round($largeur * $hauteur * $quantite, 4);
    }
}

/**
 * Ce qu'il faut sortir du rouleau pour produire une ligne.
 *
 * Un tirage se pose dans le sens de la laize : sa longueur descend le rouleau,
 * sa largeur doit y tenir. Plus large que la laize, il se découpe en lés — et
 * le rouleau y passe autant de fois. C'est le calcul que fait l'atelier de
 * tête, écrit ici une fois pour toutes.
 *
 * `$ligne` porte les dimensions et, le cas échéant, le `conso_unitaire` de la
 * prestation ; `$support` est la ligne `article` du rouleau, pour sa `laize`.
 * Le résultat est dans l'unité du support — des mètres, pour un rouleau.
 */
function consommationPrevue(array $ligne, ?array $support): float
{
    if (!$support) return 0.0;

    $quantite = max(0, num($ligne['quantite'] ?? 0));
    $largeur  = max(0, num($ligne['largeur'] ?? 0));
    $longueur = max(0, num($ligne['hauteur'] ?? 0));
    $laize    = max(0, num($support['laize'] ?? 0));
    $parUnite = max(0, num($ligne['conso_unitaire'] ?? 0));

    // Une consommation fixée sur la prestation l'emporte : c'est le cas du
    // transfert DTF vendu à la pièce, qui mange toujours la même longueur.
    if ($parUnite > 0) return round($parUnite * $quantite, 3);

    switch ($ligne['mode'] ?? 'M2') {
        case 'PIECE':
            return 0.0; // sans consommation unitaire, rien à déduire d'une pièce
        case 'ML':
            return round($longueur * $quantite, 3);
        default:
            if ($laize <= 0) return round($largeur * $longueur * $quantite, 3);
            $les = max(1, (int) ceil(round($largeur / $laize, 6)));
            return round($les * $longueur * $quantite, 3);
    }
}

/** Unité d'affichage d'une ligne (`m²`, `m`, `pce`). */
function uniteLigne(string $mode): string
{
    return MODES_LIGNE[$mode]['court'] ?? MODES_LIGNE['M2']['court'];
}

/**
 * Complète une ligne : quantité facturée, support prélevé, quantité à sortir.
 *
 * Le formulaire n'est pas le seul à créer des lignes — le comptoir, les
 * scripts et les reprises en créent aussi. La règle vit donc ici, une fois,
 * et non dans la lecture du JSON.
 *
 * Rejouer la fonction ne change rien : une valeur déjà posée est conservée.
 */
function completerLigne(array $ligne): array
{
    $ligne['mode'] = isset(MODES_LIGNE[$ligne['mode'] ?? '']) ? $ligne['mode'] : 'M2';
    $ligne['unites'] = $ligne['unites'] ?? unitesLigne($ligne);

    // Le support est celui de la ligne, ou celui que la prestation porte au
    // catalogue ; le facteur par unité vient toujours de la prestation.
    $supportId = ouNull($ligne['support_id'] ?? null);

    if (!empty($ligne['article_id'])) {
        $prestation = un('SELECT support_id, conso_unitaire FROM article WHERE id = ?',
            [$ligne['article_id']]);
        $supportId = $supportId ?: ($prestation['support_id'] ?? null);
        $ligne['conso_unitaire'] = num($prestation['conso_unitaire'] ?? 0);
    }

    $support = $supportId ? un('SELECT id, laize FROM article WHERE id = ?', [$supportId]) : null;
    $ligne['support_id'] = $support['id'] ?? null;

    // Une quantité corrigée à la main l'emporte ; sinon elle est calculée ici,
    // pour que l'appelant n'ait pas le dernier mot sur ce qui sort du stock.
    $saisie = $ligne['conso_prevue'] ?? null;
    $ligne['conso_prevue'] = ($saisie !== null && $saisie !== '')
        ? max(0, round(num($saisie), 3))
        : consommationPrevue($ligne, $support);

    return $ligne;
}

/**
 * Lignes d'un ordre de travail telles que reçues du formulaire (champ JSON).
 * Les dimensions sont conservées : c'est ce que le machiniste relit sur la
 * fiche d'atelier, et ce qui permet de recalculer une surface sans la ressaisir.
 */
function lireLignesTravail(string $json): array
{
    $brut = json_decode($json ?: '[]', true);
    if (!is_array($brut)) return [];

    $lignes = [];
    foreach ($brut as $ligne) {
        $designation = trim((string) ($ligne['designation'] ?? ''));
        if ($designation === '') continue;

        $mode = isset(MODES_LIGNE[$ligne['mode'] ?? '']) ? $ligne['mode'] : 'M2';
        $preparee = [
            'article_id'    => ouNull($ligne['article_id'] ?? null),
            'designation'   => $designation,
            'mode'          => $mode,
            'largeur'       => max(0, num($ligne['largeur'] ?? 0)),
            'hauteur'       => max(0, num($ligne['hauteur'] ?? 0)),
            'quantite'      => max(0, num($ligne['quantite'] ?? 0)),
            'prix_unitaire' => max(0, num($ligne['prix_unitaire'] ?? 0)),
            'remise'        => min(100, max(0, num($ligne['remise'] ?? 0))),
            'tva'           => min(100, max(0, num($ligne['tva'] ?? 0))),
        ];
        $preparee['unites'] = unitesLigne($preparee);
        if ($preparee['unites'] <= 0) continue;

        $preparee['support_id'] = $ligne['support_id'] ?? null;
        $preparee['conso_prevue'] = $ligne['conso_prevue'] ?? null;

        $lignes[] = completerLigne($preparee);
    }
    return $lignes;
}

/**
 * Totaux d'un ordre de travail.
 *
 * Le calcul est celui des documents commerciaux — la quantité facturée d'une
 * ligne étant sa surface, sa longueur ou son nombre d'exemplaires.
 */
function totauxTravail(array $lignes, float $remise = 0): array
{
    $pourCalcul = [];
    foreach ($lignes as $ligne) {
        // `array_merge` et non l'union `+` : celle-ci garderait le `quantite`
        // d'origine (le nombre d'exemplaires) au lieu des unités facturées.
        $pourCalcul[] = array_merge($ligne, [
            'quantite' => $ligne['unites'] ?? unitesLigne($ligne),
        ]);
    }

    $totaux = calculerTotaux($pourCalcul, $remise);

    // `calculerTotaux` travaille sur les unités : on rend à chaque ligne son
    // nombre d'exemplaires, et son compte d'unités.
    foreach ($totaux['lignes'] as $i => $ligne) {
        $totaux['lignes'][$i]['quantite'] = $lignes[$i]['quantite'] ?? 0;
        $totaux['lignes'][$i]['unites'] = $pourCalcul[$i]['quantite'];
    }

    return $totaux;
}

/** Écrit les lignes d'un ordre de travail (après calcul des totaux). */
function ecrireLignesTravail(string $travailId, array $lignesCalculees): void
{
    $aInserer = [];
    foreach ($lignesCalculees as $ordre => $ligne) {
        $aInserer[] = [
            'travail_id'    => $travailId,
            'article_id'    => ($ligne['article_id'] ?? null) ?: null,
            'designation'   => $ligne['designation'],
            'mode'          => $ligne['mode'] ?? 'M2',
            'largeur'       => $ligne['largeur'] ?? 0,
            'hauteur'       => $ligne['hauteur'] ?? 0,
            'quantite'      => $ligne['quantite'] ?? 0,
            'unites'        => $ligne['unites'] ?? 0,
            'support_id'    => ($ligne['support_id'] ?? null) ?: null,
            'conso_prevue'  => $ligne['conso_prevue'] ?? 0,
            'prix_unitaire' => $ligne['prix_unitaire'] ?? 0,
            'remise'        => $ligne['remise'] ?? 0,
            'tva'           => $ligne['tva'] ?? 0,
            'total_ht'      => $ligne['total_ht'],
            'total_ttc'     => $ligne['total_ttc'],
            'ordre'         => $ordre,
        ];
    }
    insererLot('travail_ligne', $aInserer);
}

/* --------------------------------------------------------- ordre de travail */

/**
 * Crée ou met à jour un ordre de travail.
 * `$id` nul crée le dossier, l'ouvre à l'accueil et journalise son entrée.
 *
 * @return array{id: string}|array{erreur: string}
 */
function enregistrerTravail(array $session, ?string $id, array $entete, array $lignes): array
{
    $designation = trim((string) ($entete['designation'] ?? ''));
    if (mb_strlen($designation) < 2) return ['erreur' => 'La désignation du travail est obligatoire'];
    if (!$lignes) return ['erreur' => 'Ajoutez au moins une ligne à produire'];

    $existant = null;
    if ($id !== null) {
        $existant = un('SELECT * FROM travail WHERE id = ?', [$id]);
        if (!$existant) return ['erreur' => 'Ordre de travail introuvable.'];
        if ($existant['etape'] === 'ANNULE') {
            return ['erreur' => "Ce travail est annulé, il n'est plus modifiable."];
        }
    }

    $lignes = array_map('completerLigne', $lignes);

    $totaux = totauxTravail($lignes, num($entete['remise'] ?? 0));
    $avance = max(0, min(num($entete['avance'] ?? 0), $totaux['total_ttc']));

    $valeurs = [
        'date'          => horodatage($entete['date'] ?? null) ?? maintenant(),
        'date_promise'  => horodatage($entete['date_promise'] ?? null),
        'designation'   => $designation,
        'description'   => ouNull($entete['description'] ?? null),
        'priorite'      => ($entete['priorite'] ?? '') === 'URGENTE' ? 'URGENTE' : 'NORMALE',
        'tiers_id'      => ouNull($entete['tiers_id'] ?? null),
        'contact'       => ouNull($entete['contact'] ?? null),
        'machine_id'    => ouNull($entete['machine_id'] ?? null),
        'designer_id'   => ouNull($entete['designer_id'] ?? null),
        'machiniste_id' => ouNull($entete['machiniste_id'] ?? null),
        'image_url'     => ouNull($entete['image_url'] ?? null),
        'remise'        => $totaux['remise'],
        'total_ht'      => $totaux['total_ht'],
        'total_tva'     => $totaux['total_tva'],
        'total_ttc'     => $totaux['total_ttc'],
        'avance'        => $avance,
        'notes'         => ouNull($entete['notes'] ?? null),
    ];

    $travailId = transaction(function () use ($session, $existant, $valeurs, $totaux) {
        if ($existant) {
            modifier('travail', $existant['id'], $valeurs);
            q('DELETE FROM travail_ligne WHERE travail_id = ?', [$existant['id']]);
            ecrireLignesTravail($existant['id'], $totaux['lignes']);
            return $existant['id'];
        }

        $id = inserer('travail', $valeurs + [
            'numero'  => numeroSuivant('OT'),
            'etape'   => 'ACCUEIL',
            'lieu_id' => $session['lieu_id'],
            'user_id' => $session['user_id'],
        ]);

        ecrireLignesTravail($id, $totaux['lignes']);
        inserer('travail_etape', [
            'travail_id' => $id,
            'etape'      => 'ACCUEIL',
            'user_id'    => $session['user_id'],
            'note'       => 'Commande reçue',
        ]);

        return $id;
    });

    return ['id' => $travailId];
}

/**
 * Fait passer un dossier à une autre étape et l'inscrit au journal.
 *
 * Le passage retient au dossier qui l'a fait : le designer sur `DESIGN`, le
 * machiniste sur `IMPRESSION`, le livreur sur `LIVRE` — sans écraser un nom
 * déjà saisi à l'accueil.
 *
 * Une annulation exige son motif : un dossier abandonné sans raison écrite ne
 * dit rien à celui qui le relira, et c'est justement ce qu'on veut compter.
 *
 * @return array{ok: true}|array{erreur: string}
 */
function changerEtape(array $session, string $travailId, string $etape, ?string $note = null): array
{
    if (!isset(ETAPES_TRAVAIL[$etape])) return ['erreur' => 'Étape inconnue.'];

    $travail = un('SELECT * FROM travail WHERE id = ?', [$travailId]);
    if (!$travail) return ['erreur' => 'Ordre de travail introuvable.'];
    if ($travail['etape'] === $etape) return ['ok' => true];

    $note = trim((string) $note);
    if ($etape === 'ANNULE' && $note === '') {
        return ['erreur' => "Indiquez pourquoi le client a annulé avant de valider."];
    }

    transaction(function () use ($session, $travail, $etape, $note) {
        $valeurs = ['etape' => $etape];

        $champIntervenant = INTERVENANT_ETAPE[$etape] ?? null;
        if ($champIntervenant && empty($travail[$champIntervenant])) {
            $valeurs[$champIntervenant] = $session['user_id'];
        }

        if ($etape === 'LIVRE' && empty($travail['date_livraison'])) {
            $valeurs['date_livraison'] = maintenant();
        }

        // Le motif suit le dossier tant qu'il est annulé, et s'efface si on le
        // rouvre : un dossier de nouveau en production n'a plus de motif.
        if ($etape === 'ANNULE') $valeurs['motif_annulation'] = mb_substr($note, 0, 255);
        elseif ($travail['etape'] === 'ANNULE') $valeurs['motif_annulation'] = null;

        modifier('travail', $travail['id'], $valeurs);

        inserer('travail_etape', [
            'travail_id' => $travail['id'],
            'etape'      => $etape,
            'user_id'    => $session['user_id'],
            'note'       => ouNull($note),
        ]);
    });

    // Arrivé sur machine, le dossier entame le rouleau : la matière prévue
    // sort du stock, une seule fois.
    $sorties = $etape === 'IMPRESSION' ? sortirMatiereTravail($session, $travail['id']) : 0;

    return ['ok' => true, 'sorties' => $sorties];
}

/**
 * Sort du stock la matière prévue par les lignes du dossier.
 *
 * Appelée quand le dossier arrive en impression : c'est le moment où le
 * rouleau est réellement entamé. `matiere_sortie_le` empêche un second
 * prélèvement — repasser par l'étape ne redéduit rien.
 *
 * La chute n'est pas devinée : elle reste déclarée à la main par le machiniste,
 * qui seul sait ce qui est parti à la poubelle.
 *
 * @return int nombre de sorties créées
 */
function sortirMatiereTravail(array $session, string $travailId): int
{
    $travail = un('SELECT * FROM travail WHERE id = ?', [$travailId]);
    if (!$travail || $travail['matiere_sortie_le']) return 0;

    $lignes = tous(
        'SELECT * FROM travail_ligne
          WHERE travail_id = ? AND support_id IS NOT NULL AND conso_prevue > 0
          ORDER BY ordre',
        [$travailId],
    );

    // Le dossier est marqué même sans ligne à sortir : sans cela, chaque
    // passage rejouerait la recherche pour rien.
    if (!$lignes) {
        modifier('travail', $travailId, ['matiere_sortie_le' => maintenant()]);
        return 0;
    }

    return transaction(function () use ($session, $travail, $lignes) {
        $sorties = 0;

        foreach ($lignes as $ligne) {
            $resultat = enregistrerConsommation($session, [
                'travail_id' => $travail['id'],
                'article_id' => $ligne['support_id'],
                'machine_id' => $travail['machine_id'],
                'quantite'   => num($ligne['conso_prevue']),
                'chute'      => 0,
                'motif'      => 'PRODUCTION',
                'note'       => 'Sortie automatique — ' . $ligne['designation'],
            ]);
            if (!isset($resultat['erreur'])) $sorties++;
        }

        modifier('travail', $travail['id'], ['matiere_sortie_le' => maintenant()]);

        return $sorties;
    });
}

/** Supprime un ordre de travail, ses lignes, son journal et rend la matière. */
function supprimerTravail(string $travailId): void
{
    transaction(function () use ($travailId) {
        foreach (colonne('SELECT id FROM consommation WHERE travail_id = ?', [$travailId]) as $id) {
            supprimerConsommation($id);
        }
        // `travail_ligne` et `travail_etape` partent en cascade.
        supprimer('travail', $travailId);
    });
}

/* ------------------------------------------------------------- consommation */

/**
 * Enregistre une sortie de matière et la retire du stock.
 *
 * Deux mouvements plutôt qu'un : `PRODUCTION` pour ce qui a servi, `CHUTE`
 * pour ce qui est perdu. Le stock diminue du même total dans les deux cas,
 * mais le rapport matière peut alors dire *ce qui est parti en fumée*.
 *
 * @return array{id: string}|array{erreur: string}
 */
function enregistrerConsommation(array $session, array $donnees): array
{
    $articleId = ouNull($donnees['article_id'] ?? null);
    if (!$articleId) return ['erreur' => 'Choisissez la matière consommée'];

    $article = un('SELECT id, nom, unite, gere_stock FROM article WHERE id = ?', [$articleId]);
    if (!$article) return ['erreur' => 'Matière introuvable.'];

    $utilisee = max(0, num($donnees['quantite'] ?? 0));
    $chute    = max(0, num($donnees['chute'] ?? 0));
    if ($utilisee + $chute <= 0) return ['erreur' => 'Indiquez la quantité utilisée ou la chute'];

    $travailId = ouNull($donnees['travail_id'] ?? null);
    $reference = $travailId ? valeur('SELECT numero FROM travail WHERE id = ?', [$travailId]) : null;

    $motif = isset(MOTIFS_CHUTE[$donnees['motif'] ?? '']) ? $donnees['motif'] : 'PRODUCTION';

    $id = transaction(function () use ($session, $donnees, $article, $utilisee, $chute, $travailId, $reference, $motif) {
        $id = inserer('consommation', [
            'travail_id'  => $travailId,
            'article_id'  => $article['id'],
            'variante_id' => ouNull($donnees['variante_id'] ?? null),
            'lieu_id'     => $session['lieu_id'],
            'machine_id'  => ouNull($donnees['machine_id'] ?? null),
            'user_id'     => $session['user_id'],
            'date'        => horodatage($donnees['date'] ?? null) ?? maintenant(),
            'quantite'    => $utilisee,
            'chute'       => $chute,
            'motif'       => $motif,
            'note'        => ouNull($donnees['note'] ?? null),
        ]);

        // Un consommable qu'on a choisi de ne pas suivre en stock est tout de
        // même journalisé ici : le rapport matière le verra, pas l'inventaire.
        if ($article['gere_stock']) {
            $commun = [
                'article_id'      => $article['id'],
                'variante_id'     => ouNull($donnees['variante_id'] ?? null),
                'lieu_id'         => $session['lieu_id'],
                'consommation_id' => $id,
                'reference'       => $reference,
                'user_id'         => $session['user_id'],
            ];

            if ($utilisee > 0) {
                appliquerMouvement($commun + [
                    'type'     => 'PRODUCTION',
                    'quantite' => -$utilisee,
                    'note'     => ouNull($donnees['note'] ?? null),
                ]);
            }
            if ($chute > 0) {
                appliquerMouvement($commun + [
                    'type'     => 'CHUTE',
                    'quantite' => -$chute,
                    'note'     => MOTIFS_CHUTE[$motif],
                ]);
            }
        }

        return $id;
    });

    return ['id' => $id];
}

/** Reprend une sortie de matière : le stock retrouve ce qu'elle avait retiré. */
function supprimerConsommation(string $consommationId): void
{
    transaction(function () use ($consommationId) {
        $mouvements = tous(
            'SELECT article_id, variante_id, lieu_id, quantite FROM mouvement_stock WHERE consommation_id = ?',
            [$consommationId],
        );

        restituerMouvements($mouvements);

        q('DELETE FROM mouvement_stock WHERE consommation_id = ?', [$consommationId]);
        supprimer('consommation', $consommationId);
    });
}

/** Matière consommée par un travail : total utilisé, total en chute. */
function bilanMatiere(string $travailId): array
{
    $ligne = un(
        'SELECT COALESCE(SUM(quantite), 0) utilisee, COALESCE(SUM(chute), 0) chute
           FROM consommation WHERE travail_id = ?',
        [$travailId],
    );

    return [
        'utilisee' => num($ligne['utilisee'] ?? 0),
        'chute'    => num($ligne['chute'] ?? 0),
        'total'    => num($ligne['utilisee'] ?? 0) + num($ligne['chute'] ?? 0),
    ];
}

/**
 * Taux de chute en pourcentage : part de la matière sortie qui n'a rien produit.
 * Sans sortie, il n'y a pas de taux — 0 plutôt qu'une division par zéro.
 */
function tauxChute(float $utilisee, float $chute): float
{
    $total = $utilisee + $chute;
    return $total > 0 ? round($chute / $total * 100, 2) : 0.0;
}

/* ------------------------------------------------------------- facturation */

/**
 * Tire une pièce commerciale d'un ordre de travail (devis, bon, facture).
 *
 * Les lignes reprennent la désignation, les dimensions et le prix du travail.
 * Un article suivi en stock n'est **pas** repris comme article de la pièce :
 * l'atelier en a déjà sorti la matière, et le document la sortirait une
 * seconde fois. La désignation, elle, garde la trace de ce qui a été produit.
 *
 * @return array{id: string}|array{erreur: string}
 */
function facturerTravail(array $session, string $travailId, string $type): array
{
    if (!in_array($type, PIECES_TRAVAIL, true)) return ['erreur' => 'Type de pièce non prévu.'];

    $travail = un('SELECT * FROM travail WHERE id = ?', [$travailId]);
    if (!$travail) return ['erreur' => 'Ordre de travail introuvable.'];
    if ($travail['etape'] === 'ANNULE') return ['erreur' => 'Ce travail est annulé.'];
    if ($travail['document_id']) {
        return ['erreur' => 'Une pièce commerciale a déjà été tirée de ce travail.'];
    }

    $lignes = tous('SELECT * FROM travail_ligne WHERE travail_id = ? ORDER BY ordre', [$travailId]);
    if (!$lignes) return ['erreur' => 'Ce travail ne porte aucune ligne.'];

    $params = [];
    $ids = array_values(array_filter(array_column($lignes, 'article_id')));
    $suivis = $ids
        ? colonne('SELECT id FROM article WHERE gere_stock = 1 AND id IN (' . clauseIn($ids, $params) . ')', $params)
        : [];
    $suivis = array_flip($suivis);

    $lignesDocument = [];
    foreach ($lignes as $ligne) {
        $mode = $ligne['mode'];
        $detail = $mode === 'PIECE'
            ? ''
            : ' (' . nombre($ligne['largeur']) . ' × ' . nombre($ligne['hauteur']) . ' m'
                . (num($ligne['quantite']) > 1 ? ' × ' . nombre($ligne['quantite']) : '') . ')';

        $lignesDocument[] = [
            'article_id'    => isset($suivis[$ligne['article_id']]) ? null : ($ligne['article_id'] ?: null),
            'variante_id'   => null,
            'designation'   => $ligne['designation'] . $detail,
            'quantite'      => num($ligne['unites']),
            'prix_unitaire' => num($ligne['prix_unitaire']),
            'remise'        => num($ligne['remise']),
            'tva'           => num($ligne['tva']),
        ];
    }

    $resultat = creerDocument($session, $type, [
        'tiers_id'      => $travail['tiers_id'],
        'date'          => maintenant(),
        'reference'     => $travail['numero'],
        'notes'         => $travail['notes'],
        'remise'        => num($travail['remise']),
        'montant_paye'  => num($travail['avance']),
        'mode_paiement' => 'ESPECES',
    ], $lignesDocument);

    if (isset($resultat['erreur'])) return $resultat;

    modifier('travail', $travailId, ['document_id' => $resultat['id']]);

    return $resultat;
}
