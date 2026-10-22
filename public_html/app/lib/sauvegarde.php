<?php
/**
 * Sauvegarde et restauration complètes de la base. Port de `src/lib/sauvegarde.ts`.
 *
 * L'export produit un seul fichier JSON contenant *toutes* les tables, y
 * compris les photos d'articles (encodées en base64). La restauration vide la
 * base et réinsère ces lignes telles quelles : mêmes identifiants, mêmes
 * numéros de documents, mêmes compteurs.
 *
 * Le fichier reste compatible avec celui de l'application Next.js : les noms de
 * tables et de colonnes sont traduits dans les deux sens (`LigneDocument` /
 * `ligne_document`, `totalTTC` / `total_ttc`).
 */

const SAUVEGARDE_FORMAT = 'outech-sauvegarde';
const SAUVEGARDE_VERSION = 1;

/**
 * Formats acceptés à la restauration.
 *
 * Le marqueur écrit dans les nouveaux fichiers porte le nom actuel du
 * logiciel, mais les sauvegardes déjà entre les mains des clients — et celles
 * de l'application Next.js d'origine — portent l'ancien. Les refuser
 * reviendrait à rendre illisibles des fichiers parfaitement valides.
 */
const SAUVEGARDE_FORMATS_LUS = ['outech-sauvegarde', 'gnz-sauvegarde'];

/**
 * Ordre d'insertion : une table n'apparaît qu'après celles dont elle dépend.
 * La suppression parcourt cette liste à l'envers.
 */
const SAUVEGARDE_ORDRE = [
    'Societe'          => 'societe',
    'Image'            => 'image',
    'LieuAffaire'      => 'lieu_affaire',
    'User'             => 'utilisateur',
    'UserLieu'         => 'utilisateur_lieu',
    'GroupeClient'     => 'groupe_client',
    'GroupePrix'       => 'groupe_prix',
    'Tiers'            => 'tiers',
    'Categorie'        => 'categorie',
    'Marque'           => 'marque',
    'Article'          => 'article',
    'ArticleVariante'  => 'article_variante',
    'PrixGroupe'       => 'prix_groupe',
    'Stock'            => 'stock',
    'Machine'          => 'machine',
    'Document'         => 'document',
    'LigneDocument'    => 'ligne_document',
    'Paiement'         => 'paiement',
    'Effet'            => 'effet',
    'MouvementStock'   => 'mouvement_stock',
    'CategorieDepense' => 'categorie_depense',
    'Depense'          => 'depense',
    'Inventaire'       => 'inventaire',
    'LigneInventaire'  => 'ligne_inventaire',
    'Catalogue'        => 'catalogue',
    'Livraison'        => 'livraison',
    'Travail'          => 'travail',
    'TravailLigne'     => 'travail_ligne',
    'TravailEtape'     => 'travail_etape',
    'Consommation'     => 'consommation',
    'Documentation'    => 'documentation',
    'Sequence'         => 'sequence',
];

/**
 * Colonnes qui pointent vers la même table : parents insérés d'abord, sinon
 * MySQL refuse la clé étrangère.
 */
const SAUVEGARDE_AUTO = [
    'categorie'         => 'parent_id',
    'categorie_depense' => 'parent_id',
    'document'          => 'parent_id',
];

/** `total_ttc` → `totalTTC` (nom de colonne de l'application d'origine). */
function versCamel(string $nom): string
{
    // Les sigles comptables terminent en capitales dans le schéma d'origine
    // (`totalHT`, `montantTTC`, `totalTVA`) — mais pas `imageUrl`.
    $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $nom))));
    return preg_replace_callback('/(Ht|Ttc|Tva)$/', fn($m) => strtoupper($m[1]), $camel) ?? $camel;
}

/** `totalTTC` → `total_ttc`. */
function versSnake(string $nom): string
{
    $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $nom) ?? $nom;
    return strtolower($snake);
}

/* ------------------------------------------------------------------ export */

function exporterTout(): array
{
    $tables = [];

    foreach (SAUVEGARDE_ORDRE as $modele => $table) {
        $lignes = [];
        foreach (tous('SELECT * FROM `' . $table . '`') as $ligne) {
            $sortie = [];
            foreach ($ligne as $colonne => $valeur) {
                // Les photos voyagent en base64, comme dans le format d'origine.
                if ($table === 'image' && $colonne === 'donnees' && $valeur !== null) {
                    $valeur = base64_encode(is_resource($valeur) ? (string) stream_get_contents($valeur) : (string) $valeur);
                }
                $sortie[versCamel($colonne)] = $valeur;
            }
            $lignes[] = $sortie;
        }
        $tables[$modele] = $lignes;
    }

    return [
        'format'   => SAUVEGARDE_FORMAT,
        'version'  => SAUVEGARDE_VERSION,
        'genereLe' => (new DateTime())->format('c'),
        'societe'  => valeur('SELECT nom FROM societe ORDER BY created_at LIMIT 1'),
        'tables'   => $tables,
    ];
}

/* ------------------------------------------------------------- restauration */

function verifierSauvegarde($donnees): array
{
    if (!is_array($donnees)) {
        throw new RuntimeException("Fichier illisible : ce n'est pas une sauvegarde OUTECH.");
    }
    if (!in_array($donnees['format'] ?? null, SAUVEGARDE_FORMATS_LUS, true)) {
        throw new RuntimeException("Ce fichier n'est pas une sauvegarde OUTECH.");
    }
    if (!is_numeric($donnees['version'] ?? null) || $donnees['version'] > SAUVEGARDE_VERSION) {
        throw new RuntimeException("Sauvegarde en version {$donnees['version']} : mettez l'application à jour avant de la restaurer.");
    }
    if (!isset($donnees['tables']) || !is_array($donnees['tables'])) {
        throw new RuntimeException('Sauvegarde incomplète : aucune table trouvée.');
    }
    return $donnees;
}

/**
 * Réordonne les lignes d'une table auto-référencée pour qu'un parent précède
 * toujours ses enfants.
 */
function parentsDAbord(array $lignes, string $colonne): array
{
    $restantes = [];
    foreach ($lignes as $ligne) $restantes[(string) ($ligne['id'] ?? '')] = true;

    $placees = [];
    $ordonnees = [];
    $candidates = $lignes;

    while ($candidates) {
        $acceptees = [];
        foreach ($candidates as $ligne) {
            $parent = $ligne[$colonne] ?? null;
            // Un parent absent de la table sera refusé par MySQL, pas par nous.
            if (!$parent || !isset($restantes[(string) $parent]) || isset($placees[(string) $parent])) {
                $acceptees[] = $ligne;
            }
        }

        // Aucun progrès : les lignes restantes forment un cycle de parenté.
        if (!$acceptees) {
            foreach ($candidates as $ligne) $ordonnees[] = $ligne;
            break;
        }

        foreach ($acceptees as $ligne) {
            $ordonnees[] = $ligne;
            $placees[(string) ($ligne['id'] ?? '')] = true;
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn($ligne) => !isset($placees[(string) ($ligne['id'] ?? '')]),
        ));
    }

    return $ordonnees;
}

/**
 * Remplace intégralement le contenu de la base par celui de la sauvegarde.
 *
 * Tout se joue dans une transaction : en cas d'erreur (fichier corrompu,
 * contrainte violée), rien n'est écrit et la base reste telle quelle.
 */
function restaurerTout(array $sauvegarde): array
{
    $compteurs = [];

    transaction(function () use ($sauvegarde, &$compteurs) {
        // Les contraintes sont relâchées le temps du vidage : l'ordre inverse
        // suffit en théorie, mais une base incohérente bloquerait la reprise.
        q('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse(SAUVEGARDE_ORDRE) as $table) {
            q('DELETE FROM `' . $table . '`');
        }

        q('SET FOREIGN_KEY_CHECKS = 1');

        foreach (SAUVEGARDE_ORDRE as $modele => $table) {
            $colonnes = colonnesTable($table);
            $brutes = $sauvegarde['tables'][$modele] ?? [];

            $lignes = [];
            foreach ($brutes as $brute) {
                $sortie = [];
                foreach ($brute as $cle => $valeur) {
                    $colonne = versSnake($cle);
                    if (!isset($colonnes[$colonne])) continue;

                    if ($table === 'image' && $colonne === 'donnees' && $valeur !== null) {
                        $valeur = base64_decode((string) $valeur, true) ?: '';
                    } elseif (is_bool($valeur)) {
                        $valeur = $valeur ? 1 : 0;
                    } elseif (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $valeur)) {
                        // Dates ISO produites par l'application d'origine.
                        $valeur = horodatage($valeur);
                    }

                    $sortie[$colonne] = $valeur;
                }
                if ($sortie) $lignes[] = $sortie;
            }

            if (isset(SAUVEGARDE_AUTO[$table]) && $lignes) {
                $lignes = parentsDAbord($lignes, SAUVEGARDE_AUTO[$table]);
            }

            // Insertion ligne à ligne pour les tables auto-référencées (l'ordre
            // compte), par paquets pour les autres.
            if (isset(SAUVEGARDE_AUTO[$table])) {
                foreach ($lignes as $ligne) insererLot($table, [$ligne]);
            } else {
                insererLot($table, $lignes);
            }

            $compteurs[$modele] = count($lignes);
        }
    });

    return $compteurs;
}
