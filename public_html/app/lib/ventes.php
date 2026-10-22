<?php
/**
 * Éléments vendables et grilles tarifaires. Port de `src/lib/ventes.ts`.
 */

/** Clé identifiant un élément vendable : article seul, ou article + variante. */
function cleVente(string $articleId, ?string $varianteId): string
{
    return $articleId . ':' . ($varianteId ?? '');
}

/**
 * Liste les éléments vendables d'un lieu.
 *
 * Un article sans variante donne une entrée ; un article à variantes donne une
 * entrée par variante active (l'article lui-même n'est pas vendable, son stock
 * étant porté par les variantes). Une variante sans prix propre hérite de celui
 * de l'article.
 */
function articlesVendables(string $lieuId): array
{
    $articles = tous(
        "SELECT a.*, c.nom AS categorie_nom,
                (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s
                  WHERE s.article_id = a.id AND s.lieu_id = ? AND s.variante_id IS NULL) AS stock
           FROM article a
           LEFT JOIN categorie c ON c.id = a.categorie_id
          WHERE a.actif = 1
          ORDER BY a.nom",
        [$lieuId],
    );

    $variantes = tous(
        "SELECT v.*, (SELECT COALESCE(SUM(s.quantite), 0) FROM stock s
                       WHERE s.variante_id = v.id AND s.lieu_id = ?) AS stock
           FROM article_variante v
           JOIN article a ON a.id = v.article_id
          WHERE v.actif = 1 AND a.actif = 1
          ORDER BY v.nom, v.valeur",
        [$lieuId],
    );

    $parArticle = [];
    foreach ($variantes as $variante) $parArticle[$variante['article_id']][] = $variante;

    $options = [];
    foreach ($articles as $article) {
        $commun = [
            'article_id' => $article['id'],
            'tva'        => $article['tva'],
            'unite'      => $article['unite'],
            'categorie'  => $article['categorie_nom'],
            'image_url'  => $article['image_url'],
            'gere_stock' => (bool) $article['gere_stock'],
        ];

        $siennes = $parArticle[$article['id']] ?? [];

        if (!$siennes) {
            $options[] = $commun + [
                'cle'         => cleVente($article['id'], null),
                'variante_id' => null,
                'nom'         => $article['nom'],
                'reference'   => $article['reference'],
                'code_barre'  => $article['code_barre'],
                'prix_vente'  => $article['prix_vente'],
                'prix_achat'  => $article['prix_achat'],
                'stock'       => num($article['stock']),
            ];
            continue;
        }

        foreach ($siennes as $variante) {
            $options[] = $commun + [
                'cle'         => cleVente($article['id'], $variante['id']),
                'variante_id' => $variante['id'],
                'nom'         => $article['nom'] . ' — ' . $variante['nom'] . ' : ' . $variante['valeur'],
                'reference'   => $variante['reference'],
                'code_barre'  => $variante['code_barre'],
                'prix_vente'  => $variante['prix_vente'] ?? $article['prix_vente'],
                'prix_achat'  => $variante['prix_achat'] ?? $article['prix_achat'],
                'stock'       => num($variante['stock']),
            ];
        }
    }

    return $options;
}

/**
 * Tarifs par groupe de prix : `groupe_prix_id → article_id → prix`, et le
 * groupe de chaque tiers. Permet au formulaire de repricer les lignes quand le
 * client change.
 */
function tarifsParGroupe(): array
{
    $prixParGroupe = [];
    foreach (tous('SELECT pg.groupe_prix_id, pg.article_id, pg.prix
                     FROM prix_groupe pg
                     JOIN groupe_prix g ON g.id = pg.groupe_prix_id
                    WHERE g.actif = 1') as $ligne) {
        $prixParGroupe[$ligne['groupe_prix_id']][$ligne['article_id']] = num($ligne['prix']);
    }

    $groupeParTiers = [];
    foreach (tous('SELECT id, groupe_prix_id FROM tiers WHERE groupe_prix_id IS NOT NULL') as $t) {
        $groupeParTiers[$t['id']] = $t['groupe_prix_id'];
    }

    return ['prix_par_groupe' => $prixParGroupe, 'groupe_par_tiers' => $groupeParTiers];
}

/** Remise (%) accordée au groupe de clients d'un tiers. */
function remiseGroupeClient(?string $tiersId): float
{
    if (!$tiersId) return 0;
    return num(valeur(
        'SELECT g.remise_pourcent FROM tiers t
         JOIN groupe_client g ON g.id = t.groupe_id WHERE t.id = ?',
        [$tiersId],
    ));
}
