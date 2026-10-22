<?php
/**
 * Tableau de l'atelier : où en est chaque dossier, poste par poste.
 *
 * Une colonne par étape, les urgences et les retards en tête. C'est l'écran
 * qu'on laisse ouvert dans l'atelier — d'où le passage d'étape en un clic.
 */

// L'accueil n'entre pas ici : le tableau porte les cumuls du mois.
$session = exigerRole(...ROLES_IMPRIMERIE);

if (estPost() && champ('_action') === 'etape') {
    $etape = (string) champ('etape', '');

    // Annuler un dossier, ou le rouvrir, relève de la gestion : le refus est
    // ici, pas seulement dans le rendu de la carte.
    if ($etape === 'ANNULE' || $etape === 'ACCUEIL') exigerRole(...ROLES_IMPRIMERIE_GESTION);

    $resultat = changerEtape($session, (string) champ('id', ''), $etape, champ('note'));

    $succes = $etape === 'ANNULE' ? 'Travail annulé, motif enregistré.'
        : ($etape === 'ACCUEIL' ? 'Dossier rouvert à l\'accueil.' : 'Étape mise à jour.');

    // Le passage en impression entame les rouleaux : autant le dire.
    $sorties = $resultat['sorties'] ?? 0;
    if ($sorties > 0) {
        $succes .= ' Matière sortie du stock (' . $sorties . ' ligne' . ($sorties > 1 ? 's' : '') . ').';
    }

    rediriger('/imprimerie', $resultat['erreur'] ?? $succes,
        isset($resultat['erreur']) ? 'erreur' : 'succes');
}

$lieuId = $session['lieu_id'];
$debutMois = debutJour(date('Y-m-01'));
$finMois = finJour('now');

$ouverts = tous(
    "SELECT t.*, ti.nom AS client, m.nom AS machine
       FROM travail t
       LEFT JOIN tiers ti ON ti.id = t.tiers_id
       LEFT JOIN machine m ON m.id = t.machine_id
      WHERE t.lieu_id = ? AND t.etape NOT IN ('LIVRE', 'ANNULE')
      ORDER BY t.priorite = 'URGENTE' DESC, COALESCE(t.date_promise, t.date), t.date",
    [$lieuId],
);

$parEtape = array_fill_keys(ETAPES_ACTIVES, []);
$enRetard = 0;
$maintenant = new DateTime();

foreach ($ouverts as $travail) {
    if (isset($parEtape[$travail['etape']])) $parEtape[$travail['etape']][] = $travail;
    $promise = enDate($travail['date_promise']);
    if ($promise && $promise < $maintenant) $enRetard++;
}

// Les dossiers abandonnés par le client, les plus récents d'abord : ils ne
// sont plus dans le flux, mais leur motif est ce qu'on relit en fin de mois.
$annules = tous(
    "SELECT t.*, ti.nom AS client, u.nom AS annule_par,
            (SELECT e.created_at FROM travail_etape e
              WHERE e.travail_id = t.id AND e.etape = 'ANNULE'
              ORDER BY e.created_at DESC LIMIT 1) AS annule_le
       FROM travail t
       LEFT JOIN tiers ti ON ti.id = t.tiers_id
       LEFT JOIN utilisateur u ON u.id = (
            SELECT e.user_id FROM travail_etape e
             WHERE e.travail_id = t.id AND e.etape = 'ANNULE'
             ORDER BY e.created_at DESC LIMIT 1)
      WHERE t.lieu_id = ? AND t.etape = 'ANNULE'
      ORDER BY annule_le DESC, t.date DESC
      LIMIT 12",
    [$lieuId],
);

$annulesMois = (int) valeur(
    "SELECT COUNT(*) FROM travail t
      WHERE t.lieu_id = ? AND t.etape = 'ANNULE'
        AND EXISTS (SELECT 1 FROM travail_etape e
                     WHERE e.travail_id = t.id AND e.etape = 'ANNULE'
                       AND e.created_at BETWEEN ? AND ?)",
    [$lieuId, $debutMois, $finMois],
);

$livresMois = (int) valeur(
    "SELECT COUNT(*) FROM travail
      WHERE lieu_id = ? AND etape = 'LIVRE' AND date_livraison BETWEEN ? AND ?",
    [$lieuId, $debutMois, $finMois],
);

// Matière du mois : ce qui a servi, ce qui est parti en chute, et ce que la
// chute a coûté au prix d'achat.
$matiere = un(
    'SELECT COALESCE(SUM(c.quantite), 0) utilisee,
            COALESCE(SUM(c.chute), 0) chute,
            COALESCE(SUM(c.chute * a.prix_achat), 0) valeur_chute
       FROM consommation c JOIN article a ON a.id = c.article_id
      WHERE c.lieu_id = ? AND c.date BETWEEN ? AND ?',
    [$lieuId, $debutMois, $finMois],
);

$utilisee = num($matiere['utilisee']);
$chute = num($matiere['chute']);

// Rouleaux sous le seuil d'alerte : ce qu'il faut racheter avant la panne sèche.
$alertes = tous(
    'SELECT a.id, a.nom, a.unite, a.stock_alerte,
            COALESCE(SUM(s.quantite), 0) AS quantite
       FROM article a
       LEFT JOIN stock s ON s.article_id = a.id AND s.lieu_id = ?
      WHERE a.actif = 1 AND a.gere_stock = 1 AND a.stock_alerte > 0
        AND EXISTS (SELECT 1 FROM consommation c WHERE c.article_id = a.id)
      GROUP BY a.id, a.nom, a.unite, a.stock_alerte
     HAVING quantite <= a.stock_alerte
      ORDER BY quantite',
    [$lieuId],
);

$role = $session['role'];

page("Atelier d'impression", function () use (
    $parEtape, $ouverts, $enRetard, $livresMois, $utilisee, $chute, $matiere, $alertes,
    $annules, $annulesMois, $role
) {
    // Les boutons suivent les droits : l'atelier n'ouvre pas de dossier, et le
    // journal matière ne s'ouvre qu'à la gestion.
    $actions = '';
    if (in_array($role, ROLES_GESTION, true)) {
        $actions .= boutonLien('/imprimerie/travaux/nouveau', 'Nouveau travail', ['icone' => 'plus']);
    }
    if (in_array($role, ['ADMIN', 'MANAGER'], true)) {
        $actions .= boutonLien('/imprimerie/consommations', 'Sortir de la matière',
            ['variante' => 'secondaire', 'icone' => 'rouleau']);
    }

    echo enTetePage("Tableau de l'atelier", 'De l\'accueil à la livraison, en un coup d\'œil', $actions);
    ?>
    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-6">
      <?= tuile('Dossiers en cours', (string) count($ouverts), ['icone' => 'flux']) ?>
      <?= tuile('En retard', (string) $enRetard, [
          'icone' => 'horloge', 'couleur' => $enRetard > 0 ? 'text-rose-600' : 'text-slate-900',
          'detail' => 'Date promise dépassée']) ?>
      <?= tuile('Livrés ce mois', (string) $livresMois, ['icone' => 'coche']) ?>
      <?= tuile('Annulés ce mois', (string) $annulesMois, [
          'icone' => 'croix', 'couleur' => $annulesMois > 0 ? 'text-rose-600' : 'text-slate-900',
          'detail' => 'Abandonnés par le client']) ?>
      <?= tuile('Matière utilisée', nombre($utilisee), ['icone' => 'rouleau', 'detail' => 'Ce mois-ci']) ?>
      <?= tuile('Chute du mois', nombre($chute), [
          'icone' => 'ciseaux', 'couleur' => 'text-amber-600',
          'detail' => pourcent(tauxChute($utilisee, $chute))
              . (voitMontants($role) ? ' · ' . montant($matiere['valeur_chute'], devise()) : '')]) ?>
    </div>

    <?php if ($alertes): ?>
      <div class="mb-5">
        <?php
        $noms = [];
        foreach ($alertes as $alerte) {
            $noms[] = e($alerte['nom']) . ' <strong>(' . e(nombre($alerte['quantite']) . ' ' . $alerte['unite']) . ')</strong>';
        }
        // Le renvoi vers le rapport n'est proposé qu'à ceux qui peuvent l'ouvrir.
        $lien = in_array($role, ROLES_SYNTHESE, true)
            ? ' — <a class="underline" href="' . e(u('/imprimerie/rapport')) . '">voir le rapport matière</a>'
            : '';
        echo alerte('Matière au seuil d\'alerte : ' . implode(' · ', $noms) . $lien, 'attention');
        ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
      <?php foreach (ETAPES_ACTIVES as $etape):
        $dossiers = $parEtape[$etape];
        $meta = ETAPES_TRAVAIL[$etape];
        $suivante = etapeSuivante($etape); ?>
        <section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
          <header class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-800"><?= e($meta['label']) ?></h2>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"><?= count($dossiers) ?></span>
          </header>

          <div class="space-y-2 p-3">
            <?php if (!$dossiers): ?>
              <p class="py-6 text-center text-xs text-slate-400">Rien à ce poste</p>
            <?php endif; ?>

            <?php foreach ($dossiers as $travail):
              $promise = enDate($travail['date_promise']);
              $retard = $promise && $promise < new DateTime(); ?>
              <article class="rounded-lg p-3 ring-1 ring-inset <?= $retard ? 'bg-rose-50/60 ring-rose-200' : 'bg-slate-50 ring-slate-200' ?>">
                <div class="flex items-start justify-between gap-2">
                  <a href="<?= e(u('/imprimerie/travaux/' . $travail['id'])) ?>" class="text-xs font-semibold text-marine-600 hover:underline">
                    <?= e($travail['numero']) ?>
                  </a>
                  <?php if ($travail['priorite'] === 'URGENTE') echo badge('Urgent', 'rouge'); ?>
                </div>
                <p class="mt-1 text-sm font-medium text-slate-800"><?= e($travail['designation']) ?></p>
                <p class="text-xs text-slate-500"><?= e($travail['client'] ?? 'Client de passage') ?></p>
                <?php if ($travail['machine']): ?>
                  <p class="mt-1 text-xs text-slate-400"><?= e($travail['machine']) ?></p>
                <?php endif; ?>
                <?php if ($promise): ?>
                  <p class="mt-1 text-xs <?= $retard ? 'font-medium text-rose-600' : 'text-slate-500' ?>">
                    Promis le <?= e(dateFr($travail['date_promise'])) ?>
                  </p>
                <?php endif; ?>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <?php if ($suivante): ?>
                    <?= actionBouton('etape', ['id' => $travail['id'], 'etape' => $suivante],
                        '→ ' . ETAPES_TRAVAIL[$suivante]['label'],
                        ['variante' => 'secondaire', 'classe' => '!px-2 !py-1 text-xs']) ?>
                  <?php endif; ?>

                  <!--
                    Annulation : le motif se saisit sur la carte même. Le champ
                    est `required`, donc rien ne part tant qu'il est vide —
                    c'est la règle que le serveur applique de son côté. L'atelier
                    ne la voit pas : ce n'est pas lui qui reçoit le client.
                  -->
                  <?php if (in_array($role, ROLES_GESTION, true)): ?>
                  <details class="w-full">
                    <summary class="cursor-pointer list-none text-xs text-slate-400 hover:text-rose-600">
                      Annulé par le client ?
                    </summary>
                    <form method="post" class="mt-2 space-y-1.5">
                      <?= champCsrf() ?>
                      <input type="hidden" name="_action" value="etape">
                      <input type="hidden" name="id" value="<?= e($travail['id']) ?>">
                      <input type="hidden" name="etape" value="ANNULE">
                      <input type="text" name="note" required maxlength="255"
                             placeholder="Motif : le client s'est désisté…"
                             class="block w-full rounded-lg border-0 bg-white px-2 py-1.5 text-xs text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-rose-500">
                      <button type="submit" class="<?= classesBouton('danger', '!px-2 !py-1 text-xs w-full') ?>">
                        <?= icone('croix', 'size-3.5') ?> Valider l'annulation
                      </button>
                    </form>
                  </details>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <?php if ($annules): ?>
      <div class="mt-5">
        <?= carteDebut('Travaux annulés', [
            'sous_titre' => 'Ce que les clients ont abandonné, et pourquoi',
            'sans_padding' => true,
        ]) ?>
          <?php
          $colonnes = ['N°', 'Annulé le', 'Client', 'Travail', 'Motif', 'Par'];
          if (voitMontants($role)) $colonnes[] = ['libelle' => 'Montant', 'classe' => 'text-right'];
          $colonnes[] = '';
          echo tableauDebut($colonnes);
          ?>
          <?php foreach ($annules as $travail): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?> whitespace-nowrap">
                <a href="<?= e(u('/imprimerie/travaux/' . $travail['id'])) ?>"
                   class="font-medium text-marine-600 hover:underline"><?= e($travail['numero']) ?></a>
              </td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500">
                <?= e($travail['annule_le'] ? dateFr($travail['annule_le']) : dateFr($travail['date'])) ?>
              </td>
              <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($travail['client'] ?? 'Client de passage') ?></td>
              <td class="<?= CLASSES_TD ?> max-w-52 truncate"><?= e($travail['designation']) ?></td>
              <td class="<?= CLASSES_TD ?> max-w-64 text-rose-700"><?= e($travail['motif_annulation'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($travail['annule_par'] ?? '—') ?></td>
              <?php if (voitMontants($role)): ?>
                <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap text-slate-500">
                  <?= e(montant($travail['total_ttc'], devise())) ?>
                </td>
              <?php endif; ?>
              <td class="<?= CLASSES_TD ?> text-right">
                <?php if (in_array($role, ROLES_GESTION, true)) echo actionBouton('etape', ['id' => $travail['id'], 'etape' => 'ACCUEIL'], 'Rouvrir', [
                    'variante' => 'secondaire', 'classe' => '!px-2 !py-1 text-xs',
                    'confirmation' => "Rouvrir {$travail['numero']} ? Il repart à l'accueil et perd son motif d'annulation.",
                ]); ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>
    <?php endif; ?>
<?php });
