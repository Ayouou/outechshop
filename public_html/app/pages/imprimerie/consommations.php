<?php
/**
 * Journal de la matière : chaque sortie de rouleau, avec sa chute.
 *
 * La sortie peut être rattachée à un ordre de travail ou non — une vente de
 * film DTF au mètre au comptoir n'a pas de dossier d'atelier, mais elle sort
 * quand même du stock.
 */

// Le journal chiffre les chutes : il reste entre les mains de la gestion.
$session = exigerRole('ADMIN', 'MANAGER');

if (estPost()) {
    if (champ('_action') === 'supprimer') {
        supprimerConsommation((string) champ('id', ''));
        rediriger('/imprimerie/consommations', 'Sortie reprise, la matière revient au stock.');
    }

    $resultat = enregistrerConsommation($session, [
        'travail_id' => champ('travail_id'),
        'article_id' => champ('article_id'),
        'machine_id' => champ('machine_id'),
        'date'       => champ('date'),
        'quantite'   => champNombre('quantite'),
        'chute'      => champNombre('chute'),
        'motif'      => champ('motif'),
        'note'       => champ('note'),
    ]);

    rediriger('/imprimerie/consommations', $resultat['erreur'] ?? 'Matière sortie du stock.',
        isset($resultat['erreur']) ? 'erreur' : 'succes');
}

$params = lireParams();

$au = param('au') ? finJour(param('au')) : finJour('now');
$du = param('du') ? debutJour(param('du')) : debutJour(date('Y-m-01'));

$args = [$session['lieu_id'], $du, $au];
$conditions = ['c.lieu_id = ?', 'c.date BETWEEN ? AND ?'];

$articleFiltre = (string) param('article', '');
if ($articleFiltre !== '') {
    $conditions[] = 'c.article_id = ?';
    $args[] = $articleFiltre;
}

$motifFiltre = (string) param('motif', '');
if ($motifFiltre === 'CHUTES') {
    $conditions[] = 'c.chute > 0';
} elseif (isset(MOTIFS_CHUTE[$motifFiltre])) {
    $conditions[] = 'c.motif = ?';
    $args[] = $motifFiltre;
}

$recherche = rechercheSur(['a.nom', 't.numero', 't.designation', 'c.note'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$jointures = 'FROM consommation c
       JOIN article a ON a.id = c.article_id
       LEFT JOIN travail t ON t.id = c.travail_id
       LEFT JOIN machine m ON m.id = c.machine_id
       LEFT JOIN utilisateur u ON u.id = c.user_id';

$total = (int) valeur("SELECT COUNT(*) $jointures WHERE $where", $args);

$cumuls = un(
    "SELECT COALESCE(SUM(c.quantite), 0) utilisee, COALESCE(SUM(c.chute), 0) chute,
            COALESCE(SUM(c.chute * a.prix_achat), 0) valeur_chute
       $jointures WHERE $where",
    $args,
);

$sorties = tous(
    "SELECT c.*, a.nom AS article_nom, a.unite, a.prix_achat,
            t.numero AS travail_numero, t.designation AS travail_designation,
            m.nom AS machine_nom, u.nom AS user_nom
       $jointures WHERE $where
      ORDER BY c.date DESC, c.created_at DESC
      LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

$matieres = tous(
    'SELECT a.id, a.nom, a.unite, COALESCE(SUM(s.quantite), 0) AS stock
       FROM article a
       LEFT JOIN stock s ON s.article_id = a.id AND s.lieu_id = ?
      WHERE a.actif = 1 AND a.gere_stock = 1
      GROUP BY a.id, a.nom, a.unite ORDER BY a.nom',
    [$session['lieu_id']],
);

$machines = tous('SELECT id, nom FROM machine WHERE actif = 1 ORDER BY nom');

$travauxOuverts = tous(
    "SELECT id, numero, designation FROM travail
      WHERE lieu_id = ? AND etape NOT IN ('LIVRE', 'ANNULE') ORDER BY date DESC LIMIT 100",
    [$session['lieu_id']],
);

page('Consommation & chutes', function () use (
    $sorties, $total, $params, $cumuls, $matieres, $machines, $travauxOuverts,
    $articleFiltre, $motifFiltre, $du, $au
) {
    $utilisee = num($cumuls['utilisee']);
    $chute = num($cumuls['chute']);

    echo enTetePage('Consommation & chutes', 'Ce qui sort des rouleaux, dossier par dossier');
    ?>
    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <?= tuile('Matière utilisée', nombre($utilisee), ['icone' => 'rouleau', 'detail' => 'Sur la période']) ?>
      <?= tuile('Chute', nombre($chute), ['icone' => 'ciseaux', 'couleur' => 'text-amber-600']) ?>
      <?= tuile('Taux de chute', pourcent(tauxChute($utilisee, $chute)), ['icone' => 'baisse']) ?>
      <?= tuile('Coût de la chute', montant($cumuls['valeur_chute'], devise()),
          ['icone' => 'portefeuille', 'couleur' => 'text-rose-600', 'detail' => "Au prix d'achat"]) ?>
    </div>

    <?= carteDebut('Sortir de la matière', [
        'sous_titre' => 'Rattachez la sortie à un travail, ou laissez vide pour une vente au comptoir',
    ]) ?>
      <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?= champCsrf() ?>
        <?php
        $optionsMatieres = ['' => '— Choisir la matière —'];
        foreach ($matieres as $matiere) {
            $optionsMatieres[$matiere['id']] = $matiere['nom']
                . ' — reste ' . nombre($matiere['stock']) . ' ' . $matiere['unite'];
        }

        $optionsTravaux = ['' => '— Sans dossier —'];
        foreach ($travauxOuverts as $travail) {
            $optionsTravaux[$travail['id']] = $travail['numero'] . ' — ' . $travail['designation'];
        }

        $optionsMachines = ['' => '— Aucune —'];
        foreach ($machines as $machine) $optionsMachines[$machine['id']] = $machine['nom'];
        ?>
        <?= champBloc('Matière', listeDeroulante('article_id', $optionsMatieres, ''), ['obligatoire' => true, 'classe' => 'lg:col-span-2']) ?>
        <?= champBloc('Ordre de travail', listeDeroulante('travail_id', $optionsTravaux, '')) ?>
        <?= champBloc('Machine', listeDeroulante('machine_id', $optionsMachines, '')) ?>
        <?= champBloc('Date', saisie('date', dateInput('now'), ['type' => 'date'])) ?>
        <?= champBloc('Quantité utilisée', saisie('quantite', '', ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]])) ?>
        <?= champBloc('Chute', saisie('chute', '', ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0]])) ?>
        <?= champBloc('Motif', listeDeroulante('motif', MOTIFS_CHUTE, 'PRODUCTION')) ?>
        <?= champBloc('Note', saisie('note', ''), ['classe' => 'sm:col-span-2 lg:col-span-3']) ?>
        <div class="flex items-end"><?= bouton('Enregistrer la sortie', ['icone' => 'rouleau']) ?></div>
      </form>
    <?= carteFin() ?>

    <div class="mt-5">
      <?= carteDebut(null, ['sans_padding' => true]) ?>
        <?php
        $filtres = '<input type="date" name="du" value="' . e(dateInput($du)) . '" class="' . CLASSES_SAISIE_AUTO . '">'
            . '<input type="date" name="au" value="' . e(dateInput($au)) . '" class="' . CLASSES_SAISIE_AUTO . '">';

        $filtres .= '<select name="article" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Toutes les matières</option>';
        foreach ($matieres as $matiere) {
            $filtres .= '<option value="' . e($matiere['id']) . '"' . ($articleFiltre === $matiere['id'] ? ' selected' : '') . '>'
                . e($matiere['nom']) . '</option>';
        }
        $filtres .= '</select>';

        $filtres .= '<select name="motif" class="' . CLASSES_SAISIE_AUTO . '"><option value="">Tous les motifs</option>'
            . '<option value="CHUTES"' . ($motifFiltre === 'CHUTES' ? ' selected' : '') . '>Avec chute seulement</option>';
        foreach (MOTIFS_CHUTE as $cle => $libelle) {
            $filtres .= '<option value="' . e($cle) . '"' . ($motifFiltre === $cle ? ' selected' : '') . '>'
                . e($libelle) . '</option>';
        }
        $filtres .= '</select>';

        echo barreOutils('/imprimerie/consommations', $params, $filtres, 'Matière, travail, note…');

        echo tableauDebut(['Action', 'Date', 'Matière', 'Travail', 'Machine', 'Par',
            ['libelle' => 'Utilisée', 'classe' => 'text-right'],
            ['libelle' => 'Chute', 'classe' => 'text-right'], 'Motif']);

        if (!$sorties) echo ligneVide(9, 'Aucune sortie sur la période');

        foreach ($sorties as $sortie) { ?>
          <tr class="hover:bg-slate-50">
            <td class="<?= CLASSES_TD ?>">
              <?= menuActions([actionMenu('supprimer', ['id' => $sortie['id']], 'Reprendre', [
                  'icone' => 'poubelle', 'classe' => 'text-rose-600',
                  'confirmation' => 'Reprendre cette sortie ? La matière revient au stock.',
              ])]) ?>
            </td>
            <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($sortie['date'])) ?></td>
            <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($sortie['article_nom']) ?></td>
            <td class="<?= CLASSES_TD ?> max-w-52 truncate">
              <?php if ($sortie['travail_id']): ?>
                <a href="<?= e(u('/imprimerie/travaux/' . $sortie['travail_id'])) ?>" class="text-marine-600 hover:underline">
                  <?= e($sortie['travail_numero']) ?></a>
                <span class="text-slate-500"> — <?= e($sortie['travail_designation']) ?></span>
              <?php else: ?>
                <span class="text-slate-400">Vente au comptoir</span>
              <?php endif; ?>
            </td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($sortie['machine_nom'] ?? '—') ?></td>
            <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($sortie['user_nom'] ?? '—') ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(nombre($sortie['quantite']) . ' ' . $sortie['unite']) ?></td>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= num($sortie['chute']) > 0 ? 'font-medium text-amber-600' : 'text-slate-400' ?>">
              <?= e(nombre($sortie['chute'])) ?>
            </td>
            <td class="<?= CLASSES_TD ?> text-xs text-slate-500"><?= e(MOTIFS_CHUTE[$sortie['motif']] ?? $sortie['motif']) ?></td>
          </tr>
        <?php }

        echo tableauFin();
        echo pagination('/imprimerie/consommations', $total, $params);
        echo carteFin();
        ?>
    </div>
<?php });
