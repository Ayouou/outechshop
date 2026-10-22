<?php
/**
 * Catalogue public `/c/<adresse>` — consultable sans compte.
 *
 * L'adresse est celle que le commerçant a choisie ; `contexteRequete()` l'a
 * déjà résolue en société, branché la requête sur sa base et vérifié son
 * abonnement. Il ne reste qu'à charger le catalogue qui porte ce nom, puis à
 * le rendre dans le thème et la couleur qu'il s'est donnés.
 */

$catalogue = un(
    'SELECT c.*, l.nom AS lieu_nom, l.ville, l.telephone FROM catalogue c
       JOIN lieu_affaire l ON l.id = c.lieu_id WHERE c.slug = ?',
    [$params['adresse']],
);

if (!$catalogue || !$catalogue['actif']) introuvable('Catalogue introuvable');

$q = (string) param('q', '');
$args = [$catalogue['lieu_id']];
$where = assembler(['a.actif = 1', rechercheSur(['a.nom', 'a.description'], $q, $args)]);

$articles = tous(
    "SELECT a.*, c.nom AS categorie_nom, m.nom AS marque_nom,
            (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s
              WHERE s.article_id = a.id AND s.lieu_id = ?) AS stock
       FROM article a
       LEFT JOIN categorie c ON c.id = a.categorie_id
       LEFT JOIN marque m ON m.id = a.marque_id
      WHERE $where ORDER BY a.nom LIMIT 200",
    $args,
);

$societe = societe();
$devise = $societe['devise'] ?? 'dhs';

/**
 * Commande par WhatsApp : le visiteur n'a pas de compte et n'en aura pas.
 * Le lien ouvre sa conversation avec le commerçant, l'article déjà écrit —
 * c'est le panier le plus court qui soit.
 */
$whatsapp = !empty($catalogue['whatsapp_actif']) ? numeroWhatsapp($catalogue['whatsapp'] ?? null) : null;
$entree = trim((string) ($catalogue['whatsapp_message'] ?? '')) ?: 'Bonjour, je souhaite commander :';

$commander = function (?array $article = null) use ($whatsapp, $entree, $catalogue, $devise) {
    if ($whatsapp === null) return null;

    $message = $entree;
    if ($article) {
        $message .= "\n- " . $article['nom'];
        if ($article['reference']) $message .= ' (réf. ' . $article['reference'] . ')';
        if ($catalogue['afficher_prix']) $message .= ' — ' . montant($article['prix_vente'], $devise);
    }
    $message .= "\n\n(vu sur " . $catalogue['titre'] . ' : ' . adresseCatalogue($catalogue) . ')';

    return lienWhatsapp($whatsapp, $message);
};

$contexte = [
    'catalogue' => $catalogue,
    'articles'  => $articles,
    'societe'   => $societe,
    'devise'    => $devise,
    'q'         => $q,
    'whatsapp'  => $whatsapp,
    'commander' => $commander,
];

$theme = themeBoutique($catalogue);

// Le nom du commerçant remplace celui de la plateforme dans l'onglet : sur son
// catalogue — a fortiori sur son propre domaine — c'est lui qu'on visite.
pageNue($catalogue['titre'], function () use ($theme, $contexte, $catalogue) {
    // La gamme de couleurs est calculée à partir de la teinte du commerçant et
    // posée en variables CSS : les gabarits n'écrivent jamais une couleur en dur.
    echo styleBoutique($catalogue);
    rendreBoutique($theme, $contexte);
}, 'min-h-full', $societe['nom'] ?? null);
