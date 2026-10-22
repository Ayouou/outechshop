<?php
/**
 * Habillage du catalogue public : thème, couleur, domaine propre.
 *
 * Le commerçant choisit une mise en page et une couleur ; la page publique
 * n'est plus la même pour tout le monde. Trois thèmes, parce qu'un quincaillier
 * avec six cents références et un atelier d'impression avec douze visuels ne
 * regardent pas le même écran.
 *
 * La couleur ne descend pas dans des classes Tailwind écrites à la main : une
 * seule teinte est déclinée en variables CSS, et les gabarits s'y réfèrent.
 * Le commerçant n'a donc aucun moyen de casser la lisibilité de sa page.
 */

/**
 * Les trois mises en page.
 *
 * `apercu` sert à dessiner la vignette du sélecteur : ce sont des proportions,
 * pas des captures — une capture vieillirait au premier changement.
 */
const THEMES_BOUTIQUE = [
    'vitrine' => [
        'nom'         => 'Vitrine',
        'resume'      => 'Bandeau, puis les articles en cartes.',
        'detail'      => 'Le passe-partout : lisible avec dix articles comme avec deux cents.',
        'pour'        => 'Commerce, quincaillerie, alimentation',
    ],
    'liste' => [
        'nom'         => 'Liste',
        'resume'      => 'Une ligne par article, prix à droite.',
        'detail'      => "Fait pour les gros catalogues : on cherche, on lit le prix, on commande. "
                       . "Sur téléphone, quatre fois plus d'articles à l'écran.",
        'pour'        => 'Grossiste, pièces détachées, droguerie',
    ],
    'galerie' => [
        'nom'         => 'Galerie',
        'resume'      => 'Grandes images, texte discret.',
        'detail'      => "Quand c'est le visuel qui vend. Les articles sans photo y paraissent nus : "
                       . 'à réserver aux catalogues illustrés.',
        'pour'        => 'Imprimerie, décoration, textile',
    ],
];

/**
 * Couleurs proposées. Le commerçant choisit une pastille, pas un code
 * hexadécimal : toutes ont été retenues pour rester lisibles en blanc dessus.
 */
const COULEURS_BOUTIQUE = [
    '#1b2153' => 'Marine',
    '#0e6e5c' => 'Vert',
    '#9a3412' => 'Terracotta',
    '#7c2d92' => 'Prune',
    '#0369a1' => 'Azur',
    '#b45309' => 'Ambre',
    '#be123c' => 'Grenat',
    '#1f2937' => 'Ardoise',
];

const COULEUR_BOUTIQUE_DEFAUT = '#1b2153';
const THEME_BOUTIQUE_DEFAUT = 'vitrine';

/** Thème valide du catalogue, quoi qu'il y ait en base. */
function themeBoutique(?array $catalogue): string
{
    $theme = (string) ($catalogue['theme'] ?? '');
    return isset(THEMES_BOUTIQUE[$theme]) ? $theme : THEME_BOUTIQUE_DEFAUT;
}

/** Couleur valide du catalogue — six chiffres hexadécimaux, ou le défaut. */
function couleurBoutique(?array $catalogue): string
{
    $couleur = strtolower(trim((string) ($catalogue['couleur'] ?? '')));
    return preg_match('/^#[0-9a-f]{6}$/', $couleur) ? $couleur : COULEUR_BOUTIQUE_DEFAUT;
}

/** `#1b2153` → `[27, 33, 83]`. */
function hexVersRvb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** RVB → TSL, la teinte en degrés, saturation et lumière en pourcentage. */
function rvbVersTsl(array $rvb): array
{
    [$r, $v, $b] = array_map(fn($c) => $c / 255, $rvb);
    $max = max($r, $v, $b);
    $min = min($r, $v, $b);
    $lumiere = ($max + $min) / 2;

    if ($max === $min) return [0.0, 0.0, $lumiere * 100];

    $delta = $max - $min;
    $saturation = $lumiere > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

    $teinte = match ($max) {
        $r      => (($v - $b) / $delta) + ($v < $b ? 6 : 0),
        $v      => (($b - $r) / $delta) + 2,
        default => (($r - $v) / $delta) + 4,
    };

    return [$teinte * 60, $saturation * 100, $lumiere * 100];
}

/**
 * Décline une teinte en une gamme complète.
 *
 * Une seule couleur suffit : la teinte est conservée, la lumière est fixée par
 * palier. Les paliers clairs perdent un peu de saturation, sinon les fonds
 * pastel tirent sur le fluo.
 */
function paletteBoutique(string $hex): array
{
    [$teinte, $saturation, ] = rvbVersTsl(hexVersRvb($hex));

    // Une teinte trop terne donnerait une page grise : on lui rend du corps,
    // sans jamais aller jusqu'au saturé pur.
    $saturation = max(12.0, min(75.0, $saturation));

    $paliers = [
        50 => [97, .35], 100 => [94, .45], 200 => [86, .60], 300 => [76, .75],
        400 => [64, .90], 500 => [54, 1.0], 600 => [45, 1.0], 700 => [36, .95],
        800 => [28, .90], 900 => [20, .85],
    ];

    $palette = [];
    foreach ($paliers as $niveau => [$lumiere, $facteur]) {
        $palette[$niveau] = sprintf('hsl(%.1f %.1f%% %d%%)', $teinte, $saturation * $facteur, $lumiere);
    }

    return $palette;
}

/**
 * Le texte qui se pose sur la couleur : blanc, ou presque noir.
 *
 * On calcule la luminance perçue plutôt que la moyenne des canaux — un jaune
 * et un bleu de même moyenne ne se lisent pas pareil.
 */
function contrasteBoutique(string $hex): string
{
    [$r, $v, $b] = hexVersRvb($hex);
    $luminance = (0.2126 * $r + 0.7152 * $v + 0.0722 * $b) / 255;

    return $luminance > 0.6 ? '#111827' : '#ffffff';
}

/** Bloc `<style>` du catalogue : la gamme, puis rien d'autre. */
function styleBoutique(array $catalogue): string
{
    $couleur = couleurBoutique($catalogue);
    $palette = paletteBoutique($couleur);

    $lignes = ['  --b-base: ' . $couleur . ';', '  --b-contraste: ' . contrasteBoutique($couleur) . ';'];
    foreach ($palette as $niveau => $valeur) $lignes[] = "  --b-$niveau: $valeur;";

    return "<style>\n:root {\n" . implode("\n", $lignes) . "\n}\n</style>\n";
}

/**
 * Domaine propre — la partie qui tient au serveur, pas à l'application.
 *
 * Brancher son domaine tient en trois gestes, et deux ne se font pas ici :
 * pointer le DNS, déclarer le domaine chez l'hébergeur, obtenir le certificat.
 * L'application ne sait que reconnaître l'hôte et servir le bon catalogue —
 * le jour où les deux autres sont faits.
 */
function normaliserDomaine(?string $valeur): ?string
{
    $domaine = strtolower(trim((string) $valeur));
    if ($domaine === '') return null;

    // Collé depuis un navigateur, le domaine arrive avec son protocole et sa
    // barre finale : on ne garde que l'hôte.
    $domaine = preg_replace('#^https?://#', '', $domaine);
    $domaine = explode('/', $domaine)[0];
    $domaine = explode(':', $domaine)[0];
    $domaine = preg_replace('/^www\./', '', $domaine);

    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domaine)) {
        return null;
    }

    return mb_strlen($domaine) <= 190 ? $domaine : null;
}

/** Hôte de la requête, ramené à la même forme que les domaines enregistrés. */
function hoteDemande(): ?string
{
    return normaliserDomaine($_SERVER['HTTP_HOST'] ?? null);
}

/**
 * Un domaine est-il libre pour cette société ?
 *
 * Les domaines sont communs à toute la plateforme, comme les adresses : deux
 * commerçants ne peuvent pas revendiquer le même.
 */
function domaineDisponible(?string $domaine, string $societeId): bool
{
    if ($domaine === null) return true;

    return dansConsole(function () use ($domaine, $societeId) {
        $pris = un('SELECT societe_id FROM saas_domaine WHERE domaine = ?', [$domaine]);
        return !$pris || $pris['societe_id'] === $societeId;
    });
}

/** Inscrit (ou déplace) le domaine d'une société au registre. */
function reserverDomaine(?string $domaine, string $societeId, string $adresse): void
{
    dansConsole(function () use ($domaine, $societeId, $adresse) {
        // Une société n'a qu'un domaine : changer le sien retire l'ancien.
        q('DELETE FROM saas_domaine WHERE societe_id = ?', [$societeId]);
        if ($domaine === null) return;

        q('INSERT INTO saas_domaine (domaine, societe_id, adresse, updated_at) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE societe_id = VALUES(societe_id),
                                   adresse = VALUES(adresse),
                                   updated_at = VALUES(updated_at)',
            [$domaine, $societeId, $adresse, maintenant()]);
    });
}

/**
 * Adresse de catalogue servie par cet hôte, s'il en existe une.
 *
 * Renvoie `null` pour l'hôte de la plateforme : c'est ce qui distingue
 * « quelqu'un a branché son domaine » de « quelqu'un visite le site normal ».
 */
function catalogueParDomaine(?string $hote): ?string
{
    if ($hote === null) return null;

    try {
        $ligne = dansConsole(fn() => un('SELECT adresse FROM saas_domaine WHERE domaine = ?', [$hote]));
    } catch (Throwable $e) {
        // Registre injoignable ou table pas encore posée : le site normal
        // continue de répondre, c'est le domaine propre qui attendra.
        return null;
    }

    return $ligne['adresse'] ?? null;
}

/**
 * Adresse publique d'un catalogue, telle qu'on la donne à un client.
 *
 * Le domaine propre l'emporte dès qu'il est branché : c'est celui que le
 * commerçant a payé et qu'il veut voir dans ses messages et sur son QR code.
 */
function adresseCatalogue(array $catalogue): string
{
    $domaine = normaliserDomaine($catalogue['domaine'] ?? null);
    if ($domaine !== null) return 'https://' . $domaine;

    return urlAbsolue('/c/' . $catalogue['slug']);
}
