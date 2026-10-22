<?php
/**
 * Grille tarifaire d'un groupe de prix.
 * Un champ vide supprime le tarif : l'article reprend son prix de base.
 */

exigerSession();

$id = $params['id'];
$groupe = un('SELECT * FROM groupe_prix WHERE id = ?', [$id]);
if (!$groupe) introuvable('Groupe de prix introuvable');

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'infos') {
        $nom = (string) champ('nom', '');
        if (mb_strlen($nom) < 2) {
            $erreur = 'Le nom est obligatoire';
        } else {
            try {
                modifier('groupe_prix', $id, [
                    'nom'         => $nom,
                    'description' => ouNull(champ('description')),
                    'actif'       => champBool('actif') ? 1 : 0,
                ]);
                rediriger('/stock/groupes-prix/' . $id, 'Groupe enregistré.');
            } catch (PDOException $e) {
                $erreur = 'Un groupe de prix portant ce nom existe déjà.';
            }
        }
    }

    if ($action === 'tarifs') {
        $aDefinir = [];
        $aSupprimer = [];

        foreach ($_POST as $cle => $brut) {
            if (!str_starts_with($cle, 'prix-')) continue;
            $articleId = substr($cle, 5);
            $texte = trim((string) $brut);

            if ($texte === '') { $aSupprimer[] = $articleId; continue; }

            $prix = nombreSaisi($texte, -1);
            if ($prix < 0) { $erreur = 'Les prix doivent être des nombres positifs.'; break; }
            $aDefinir[$articleId] = $prix;
        }

        if (!$erreur) {
            transaction(function () use ($id, $aDefinir, $aSupprimer) {
                if ($aSupprimer) {
                    $args = [$id];
                    q('DELETE FROM prix_groupe WHERE groupe_prix_id = ? AND article_id IN ('
                        . clauseIn($aSupprimer, $args) . ')', $args);
                }
                foreach ($aDefinir as $articleId => $prix) {
                    q('INSERT INTO prix_groupe (id, groupe_prix_id, article_id, prix) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE prix = VALUES(prix)',
                        [cuid(), $id, $articleId, $prix]);
                }
            });
            rediriger('/stock/groupes-prix/' . $id, count($aDefinir) . ' tarif(s) enregistré(s).');
        }
    }
}

$articles = tous('SELECT id, nom, reference, prix_vente FROM article WHERE actif = 1 ORDER BY nom');

$tarifs = [];
foreach (tous('SELECT article_id, prix FROM prix_groupe WHERE groupe_prix_id = ?', [$id]) as $ligne) {
    $tarifs[$ligne['article_id']] = $ligne['prix'];
}

$clients = (int) valeur('SELECT COUNT(*) FROM tiers WHERE groupe_prix_id = ?', [$id]);

page('Grille tarifaire', function () use ($groupe, $articles, $tarifs, $clients, $erreur) {
    echo enTetePage(
        $groupe['nom'],
        count($tarifs) . ' tarif(s) défini(s) · ' . $clients . ' client(s) rattaché(s)',
        boutonLien('/stock/groupes-prix', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-4">
      <div class="lg:self-start">
        <?= carteDebut('Informations') ?>
          <form method="post" class="space-y-4">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="infos">
            <?= champBloc('Nom', saisie('nom', $groupe['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Description', zoneTexte('description', $groupe['description'], ['lignes' => 3])) ?>
            <?= caseACocher('actif', (bool) $groupe['actif'], 'Groupe actif') ?>
            <?= bouton('Enregistrer', ['classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="lg:col-span-3">
        <?= carteDebut('Grille tarifaire', ['sous_titre' => "Laisser vide pour appliquer le prix de base de l'article", 'sans_padding' => true]) ?>
          <form method="post">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="tarifs">
            <?= tableauDebut(['Article', 'Référence', ['libelle' => 'Prix de base', 'classe' => 'text-right'], ['libelle' => 'Prix du groupe', 'classe' => 'text-right']]) ?>
            <?php if (!$articles) echo ligneVide(4, 'Aucun article actif'); ?>
            <?php foreach ($articles as $article): ?>
              <tr class="hover:bg-slate-50">
                <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($article['nom']) ?></td>
                <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($article['reference']) ?></td>
                <td class="<?= CLASSES_TD ?> text-right text-slate-500 whitespace-nowrap"><?= e(montant($article['prix_vente'], devise())) ?></td>
                <td class="<?= CLASSES_TD ?> text-right">
                  <input type="number" step="0.01" min="0" name="prix-<?= e($article['id']) ?>"
                         value="<?= e($tarifs[$article['id']] ?? '') ?>"
                         class="<?= CLASSES_SAISIE ?> ml-auto max-w-32 text-right">
                </td>
              </tr>
            <?php endforeach; ?>
            <?= tableauFin() ?>
            <div class="flex justify-end border-t border-slate-100 px-5 py-3"><?= bouton('Enregistrer les tarifs') ?></div>
          </form>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
