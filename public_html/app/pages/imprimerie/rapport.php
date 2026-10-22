<?php
/**
 * Rapport matière : ce qu'il reste, ce qui a servi, ce qui est parti en chute.
 *
 * Même restriction que les autres synthèses : le caissier n'y a pas accès.
 */

$session = exigerRole(...ROLES_SYNTHESE);
$lieuId = $session['lieu_id'];

$au = param('au') ? finJour(param('au')) : finJour('now');
$du = param('du') ? debutJour(param('du')) : debutJour(date('Y-m-01'));

// Les matières de l'atelier : les articles suivis en stock qui sont déjà
// passés par une consommation. La jointure, elle, est bornée à la période.
$matieres = tous(
    'SELECT a.id, a.nom, a.unite, a.laize, a.prix_achat, a.stock_alerte,
            COALESCE((SELECT SUM(s.quantite) FROM stock s
                       WHERE s.article_id = a.id AND s.lieu_id = ?), 0) AS reste,
            COALESCE(SUM(c.quantite), 0) AS utilisee,
            COALESCE(SUM(c.chute), 0)    AS chute
       FROM article a
       LEFT JOIN consommation c
              ON c.article_id = a.id AND c.lieu_id = ? AND c.date BETWEEN ? AND ?
      WHERE a.actif = 1 AND a.gere_stock = 1
        AND EXISTS (SELECT 1 FROM consommation c2 WHERE c2.article_id = a.id)
      GROUP BY a.id, a.nom, a.unite, a.laize, a.prix_achat, a.stock_alerte
      ORDER BY (COALESCE(SUM(c.quantite), 0) + COALESCE(SUM(c.chute), 0)) DESC, a.nom',
    [$lieuId, $lieuId, $du, $au],
);

$args = [$lieuId, $du, $au];

$parMotif = tous(
    'SELECT c.motif, COALESCE(SUM(c.chute), 0) AS chute,
            COALESCE(SUM(c.chute * a.prix_achat), 0) AS cout, COUNT(*) AS sorties
       FROM consommation c JOIN article a ON a.id = c.article_id
      WHERE c.lieu_id = ? AND c.date BETWEEN ? AND ? AND c.chute > 0
      GROUP BY c.motif ORDER BY chute DESC',
    $args,
);

$parMachine = tous(
    'SELECT COALESCE(m.nom, ?) AS machine,
            COALESCE(SUM(c.quantite), 0) AS utilisee, COALESCE(SUM(c.chute), 0) AS chute
       FROM consommation c LEFT JOIN machine m ON m.id = c.machine_id
      WHERE c.lieu_id = ? AND c.date BETWEEN ? AND ?
      GROUP BY machine ORDER BY (COALESCE(SUM(c.quantite), 0) + COALESCE(SUM(c.chute), 0)) DESC',
    array_merge(['Sans machine'], $args),
);

$parOperateur = tous(
    'SELECT COALESCE(u.nom, ?) AS operateur,
            COALESCE(SUM(c.quantite), 0) AS utilisee, COALESCE(SUM(c.chute), 0) AS chute
       FROM consommation c LEFT JOIN utilisateur u ON u.id = c.user_id
      WHERE c.lieu_id = ? AND c.date BETWEEN ? AND ?
      GROUP BY operateur ORDER BY (COALESCE(SUM(c.quantite), 0) + COALESCE(SUM(c.chute), 0)) DESC',
    array_merge(['Non renseigné'], $args),
);

// Production de la période : combien de dossiers, et ce qu'ils ont rapporté.
$production = un(
    "SELECT COUNT(*) AS dossiers,
            SUM(etape = 'LIVRE') AS livres,
            COALESCE(SUM(CASE WHEN etape <> 'ANNULE' THEN total_ttc ELSE 0 END), 0) AS chiffre
       FROM travail WHERE lieu_id = ? AND date BETWEEN ? AND ?",
    $args,
);

$totalUtilisee = 0.0; $totalChute = 0.0; $coutChute = 0.0; $valeurReste = 0.0;
foreach ($matieres as $matiere) {
    $totalUtilisee += num($matiere['utilisee']);
    $totalChute += num($matiere['chute']);
    $coutChute += num($matiere['chute']) * num($matiere['prix_achat']);
    $valeurReste += num($matiere['reste']) * num($matiere['prix_achat']);
}

page('Rapport matière', function () use (
    $matieres, $parMotif, $parMachine, $parOperateur, $production,
    $totalUtilisee, $totalChute, $coutChute, $valeurReste, $du, $au
) {
    echo enTetePage('Rapport matière', 'Ce qu\'il reste en rouleau, ce qui a servi, ce qui est perdu');
    ?>
    <form method="get" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
      <label class="block">
        <span class="mb-1.5 block text-sm font-medium text-slate-700">Du</span>
        <input type="date" name="du" value="<?= e(dateInput($du)) ?>" class="<?= CLASSES_SAISIE_AUTO ?>">
      </label>
      <label class="block">
        <span class="mb-1.5 block text-sm font-medium text-slate-700">Au</span>
        <input type="date" name="au" value="<?= e(dateInput($au)) ?>" class="<?= CLASSES_SAISIE_AUTO ?>">
      </label>
      <button type="submit" class="<?= classesBouton('secondaire') ?>">Actualiser</button>
      <span class="text-sm text-slate-500">
        <?= (int) $production['dossiers'] ?> dossier(s) sur la période, dont <?= (int) $production['livres'] ?> livré(s)
        — <?= e(montant($production['chiffre'], devise())) ?>
      </span>
    </form>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5">
      <?= tuile('Matière utilisée', nombre($totalUtilisee), ['icone' => 'rouleau']) ?>
      <?= tuile('Chute', nombre($totalChute), ['icone' => 'ciseaux', 'couleur' => 'text-amber-600']) ?>
      <?= tuile('Taux de chute', pourcent(tauxChute($totalUtilisee, $totalChute)), ['icone' => 'baisse']) ?>
      <?= tuile('Coût de la chute', montant($coutChute, devise()),
          ['icone' => 'portefeuille', 'couleur' => 'text-rose-600', 'detail' => "Au prix d'achat"]) ?>
      <?= tuile('Stock matière restant', montant($valeurReste, devise()), ['icone' => 'boite', 'detail' => 'Valorisé au prix d\'achat']) ?>
    </div>

    <?= carteDebut('Par matière', ['sans_padding' => true,
        'sous_titre' => 'Le reste est le stock du jour ; utilisé et chute portent sur la période']) ?>
      <?= tableauDebut(['Matière',
          ['libelle' => 'Reste', 'classe' => 'text-right'],
          ['libelle' => 'Équivalent m²', 'classe' => 'text-right'],
          ['libelle' => 'Utilisée', 'classe' => 'text-right'],
          ['libelle' => 'Chute', 'classe' => 'text-right'],
          ['libelle' => 'Sortie totale', 'classe' => 'text-right'],
          ['libelle' => 'Taux de chute', 'classe' => 'text-right'],
          ['libelle' => 'Coût de la chute', 'classe' => 'text-right'], 'État']) ?>
      <?php if (!$matieres) echo ligneVide(9, 'Aucune matière suivie par l\'atelier'); ?>
      <?php foreach ($matieres as $matiere):
        $utilisee = num($matiere['utilisee']);
        $chute = num($matiere['chute']);
        $reste = num($matiere['reste']);
        $taux = tauxChute($utilisee, $chute);
        $alerte = num($matiere['stock_alerte']) > 0 && $reste <= num($matiere['stock_alerte']); ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u('/stock/articles/' . $matiere['id'])) ?>" class="font-medium text-marine-600 hover:underline">
              <?= e($matiere['nom']) ?></a>
            <span class="text-xs text-slate-400"><?= e($matiere['unite']) ?></span>
          </td>
          <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap <?= $alerte ? 'text-rose-600' : '' ?>">
            <?= e(nombre($reste)) ?>
          </td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-slate-500">
            <?= num($matiere['laize']) > 0 ? e(nombre($reste * num($matiere['laize'])) . ' m²') : '—' ?>
          </td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(nombre($utilisee)) ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= $chute > 0 ? 'font-medium text-amber-600' : 'text-slate-400' ?>">
            <?= e(nombre($chute)) ?>
          </td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-slate-500"><?= e(nombre($utilisee + $chute)) ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(pourcent($taux)) ?></td>
          <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($chute * num($matiere['prix_achat']), devise())) ?></td>
          <td class="<?= CLASSES_TD ?>"><?= $alerte ? badge('À racheter', 'rouge') : badge('Suffisant', 'vert') ?></td>
        </tr>
      <?php endforeach; ?>
      <?= tableauFin() ?>
    <?= carteFin() ?>

    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
      <?= carteDebut('Pourquoi la matière est perdue', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Motif', ['libelle' => 'Chute', 'classe' => 'text-right'],
            ['libelle' => 'Coût', 'classe' => 'text-right']]) ?>
        <?php if (!$parMotif) echo ligneVide(3, 'Aucune chute sur la période'); ?>
        <?php foreach ($parMotif as $motif): ?>
          <tr>
            <td class="<?= CLASSES_TD ?>"><?= e(MOTIFS_CHUTE[$motif['motif']] ?? $motif['motif']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(nombre($motif['chute'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($motif['cout'], devise())) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Par machine', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Machine', ['libelle' => 'Utilisée', 'classe' => 'text-right'],
            ['libelle' => 'Chute', 'classe' => 'text-right'], ['libelle' => 'Taux', 'classe' => 'text-right']]) ?>
        <?php if (!$parMachine) echo ligneVide(4); ?>
        <?php foreach ($parMachine as $ligne): ?>
          <tr>
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['machine']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['utilisee'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['chute'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent(tauxChute(num($ligne['utilisee']), num($ligne['chute'])))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>

      <?= carteDebut('Par opérateur', ['sans_padding' => true]) ?>
        <?= tableauDebut(['Opérateur', ['libelle' => 'Utilisée', 'classe' => 'text-right'],
            ['libelle' => 'Chute', 'classe' => 'text-right'], ['libelle' => 'Taux', 'classe' => 'text-right']]) ?>
        <?php if (!$parOperateur) echo ligneVide(4); ?>
        <?php foreach ($parOperateur as $ligne): ?>
          <tr>
            <td class="<?= CLASSES_TD ?>"><?= e($ligne['operateur']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['utilisee'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(nombre($ligne['chute'])) ?></td>
            <td class="<?= CLASSES_TD ?> text-right"><?= e(pourcent(tauxChute(num($ligne['utilisee']), num($ligne['chute'])))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= tableauFin() ?>
      <?= carteFin() ?>
    </div>
<?php });
