<?php
/**
 * Dossier d'un ordre de travail : avancement, matière consommée, facturation.
 *
 * C'est la page de référence du machiniste : il y lit ce qu'il doit produire,
 * y déclare ce qu'il a passé dans la machine, et fait avancer le dossier.
 */

$session = exigerRole(...ROLES_IMPRIMERIE);

$travailId = $params['id'];
$travail = un(
    'SELECT t.*, ti.nom AS client, ti.telephone AS client_telephone, m.nom AS machine,
            ua.nom AS accueil_nom, ud.nom AS designer_nom, um.nom AS machiniste_nom, ul.nom AS livreur_nom,
            d.numero AS document_numero, d.type AS document_type
       FROM travail t
       LEFT JOIN tiers ti ON ti.id = t.tiers_id
       LEFT JOIN machine m ON m.id = t.machine_id
       LEFT JOIN utilisateur ua ON ua.id = t.user_id
       LEFT JOIN utilisateur ud ON ud.id = t.designer_id
       LEFT JOIN utilisateur um ON um.id = t.machiniste_id
       LEFT JOIN utilisateur ul ON ul.id = t.livreur_id
       LEFT JOIN document d ON d.id = t.document_id
      WHERE t.id = ?',
    [$travailId],
);
if (!$travail) introuvable('Ordre de travail introuvable');

if (estPost()) {
    $action = champ('_action');

    if ($action === 'etape') {
        $etape = (string) champ('etape', '');
        if ($etape === 'ANNULE') exigerRole(...ROLES_IMPRIMERIE_GESTION);

        $resultat = changerEtape($session, $travailId, $etape, champ('note_etape'));

        $sorties = $resultat['sorties'] ?? 0;
        $succes = 'Étape mise à jour.' . ($sorties > 0
            ? ' Matière sortie du stock (' . $sorties . ' ligne' . ($sorties > 1 ? 's' : '') . ').' : '');

        rediriger('/imprimerie/travaux/' . $travailId, $resultat['erreur'] ?? $succes,
            isset($resultat['erreur']) ? 'erreur' : 'succes');
    }

    if ($action === 'consommation') {
        $resultat = enregistrerConsommation($session, [
            'travail_id' => $travailId,
            'article_id' => champ('article_id'),
            'machine_id' => champ('machine_id') ?: $travail['machine_id'],
            'date'       => champ('date'),
            'quantite'   => champNombre('quantite'),
            'chute'      => champNombre('chute'),
            'motif'      => champ('motif'),
            'note'       => champ('note'),
        ]);
        rediriger('/imprimerie/travaux/' . $travailId,
            $resultat['erreur'] ?? 'Matière sortie du stock.',
            isset($resultat['erreur']) ? 'erreur' : 'succes');
    }

    if ($action === 'supprimer-consommation') {
        exigerRole('ADMIN', 'MANAGER');
        supprimerConsommation((string) champ('id', ''));
        rediriger('/imprimerie/travaux/' . $travailId, 'Sortie reprise, la matière revient au stock.');
    }

    if ($action === 'facturer') {
        exigerRole(...ROLES_IMPRIMERIE_GESTION);
        $resultat = facturerTravail($session, $travailId, (string) champ('type', 'FACTURE'));
        if (isset($resultat['erreur'])) {
            rediriger('/imprimerie/travaux/' . $travailId, $resultat['erreur'], 'erreur');
        }
        rediriger(urlDocument((string) champ('type', 'FACTURE'), $resultat['id']), 'Pièce créée depuis le travail.');
    }
}

$lignes = tous(
    'SELECT l.*, s.nom AS support_nom, s.unite AS support_unite
       FROM travail_ligne l
       LEFT JOIN article s ON s.id = l.support_id
      WHERE l.travail_id = ? ORDER BY l.ordre',
    [$travailId],
);

$consommations = tous(
    'SELECT c.*, a.nom AS article_nom, a.unite, a.prix_achat, m.nom AS machine_nom, u.nom AS user_nom
       FROM consommation c
       JOIN article a ON a.id = c.article_id
       LEFT JOIN machine m ON m.id = c.machine_id
       LEFT JOIN utilisateur u ON u.id = c.user_id
      WHERE c.travail_id = ? ORDER BY c.date DESC',
    [$travailId],
);

$journal = tous(
    'SELECT e.*, u.nom AS user_nom FROM travail_etape e
       LEFT JOIN utilisateur u ON u.id = e.user_id
      WHERE e.travail_id = ? ORDER BY e.created_at',
    [$travailId],
);

$matieres = tous(
    'SELECT a.id, a.nom, a.unite, COALESCE(SUM(s.quantite), 0) AS stock
       FROM article a
       LEFT JOIN stock s ON s.article_id = a.id AND s.lieu_id = ?
      WHERE a.actif = 1 AND a.gere_stock = 1
      GROUP BY a.id, a.nom, a.unite
      ORDER BY a.nom',
    [$session['lieu_id']],
);

$machines = tous('SELECT id, nom FROM machine WHERE actif = 1 ORDER BY nom');
$bilan = bilanMatiere($travailId);

$role = $session['role'];

page('Travail ' . $travail['numero'], function () use (
    $travail, $lignes, $consommations, $journal, $matieres, $machines, $bilan, $role
) {
    $montants = voitMontants($role);
    $gere = in_array($role, ROLES_GESTION, true);
    $clos = travailClos($travail['etape']);
    $suivante = etapeSuivante($travail['etape']);

    $actions = boutonLien('/imprimerie/travaux', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g'])
        . boutonLien('/imprimerie/fiche/' . $travail['id'], "Fiche d'atelier",
            ['variante' => 'secondaire', 'icone' => 'imprimante', 'attrs' => ['target' => '_blank']]);
    if (!$clos && $gere) {
        $actions .= boutonLien('/imprimerie/travaux/' . $travail['id'] . '/modifier', 'Modifier',
            ['variante' => 'secondaire', 'icone' => 'crayon']);
    }
    if ($suivante) {
        $actions .= actionBouton('etape', ['id' => $travail['id'], 'etape' => $suivante],
            'Passer en « ' . ETAPES_TRAVAIL[$suivante]['label'] . ' »', ['icone' => 'coche']);
    }

    echo enTetePage(
        $travail['numero'] . ' — ' . $travail['designation'],
        dateFr($travail['date']) . ' · ' . ($travail['client'] ?? 'Client de passage'),
        $actions,
    );

    $optionsEtapes = [];
    foreach (ETAPES_TRAVAIL as $cle => $etape) $optionsEtapes[$cle] = $etape['label'];

    $optionsMatieres = ['' => '— Choisir la matière —'];
    foreach ($matieres as $matiere) {
        $optionsMatieres[$matiere['id']] = $matiere['nom']
            . ' — reste ' . nombre($matiere['stock']) . ' ' . $matiere['unite'];
    }

    $optionsMachines = ['' => '— Machine du dossier —'];
    foreach ($machines as $machine) $optionsMachines[$machine['id']] = $machine['nom'];

    $reste = num($travail['total_ttc']) - num($travail['avance']);
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5 lg:col-span-2">

        <?= carteDebut('Suivi du dossier', ['actions' => badgeStatut(ETAPES_TRAVAIL, $travail['etape'])
            . ' ' . badgeStatut(PRIORITES_TRAVAIL, $travail['priorite'])]) ?>
          <?php if ($travail['etape'] === 'ANNULE' && $travail['motif_annulation']): ?>
            <div class="mb-4"><?= alerte('<strong>Annulé par le client</strong> — ' . e($travail['motif_annulation']), 'erreur') ?></div>
          <?php endif; ?>

          <form method="post" class="flex flex-wrap items-end gap-3">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="etape">
            <div class="min-w-48 flex-1"><?= champBloc('Étape', listeDeroulante('etape', $optionsEtapes, $travail['etape'])) ?></div>
            <div class="min-w-48 flex-[2]"><?= champBloc('Note de passage', saisie('note_etape', '', ['attrs' => ['placeholder' => 'BAT envoyé au client, impression relancée…']]),
                ['aide' => "Obligatoire pour une annulation : c'est le motif du client"]) ?></div>
            <?= bouton('Enregistrer') ?>
          </form>

          <ol class="mt-5 space-y-3 border-t border-slate-100 pt-4">
            <?php if (!$journal): ?>
              <li class="text-sm text-slate-400">Aucun passage enregistré.</li>
            <?php endif; ?>
            <?php foreach ($journal as $i => $passage):
              $debut = enDate($passage['created_at']);
              $fin = isset($journal[$i + 1]) ? enDate($journal[$i + 1]['created_at']) : null;
              // Le dernier passage court encore, sauf si le dossier est sorti.
              $arret = $fin ?? (travailClos($travail['etape']) ? null : new DateTime());
              $duree = ($debut && $arret) ? dureeCourte((float) ($arret->getTimestamp() - $debut->getTimestamp())) : null; ?>
              <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                <span class="w-32 shrink-0 text-xs text-slate-400"><?= e(dateHeureFr($passage['created_at'])) ?></span>
                <?= badgeStatut(ETAPES_TRAVAIL, $passage['etape']) ?>
                <span class="text-slate-600"><?= e($passage['user_nom'] ?? '—') ?></span>
                <?php if ($duree): ?><span class="text-xs text-slate-400">(<?= e($duree) ?>)</span><?php endif; ?>
                <?php if ($passage['note']): ?>
                  <span class="w-full pl-32 text-xs text-slate-500"><?= e($passage['note']) ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?= carteFin() ?>

        <?= carteDebut('À produire', ['sans_padding' => true]) ?>
          <?php
          $colonnesLignes = ['Désignation', 'Support', 'Calcul', 'Dimensions',
              ['libelle' => 'Quantité', 'classe' => 'text-right'],
              ['libelle' => 'À sortir', 'classe' => 'text-right']];
          if ($montants) {
              $colonnesLignes[] = ['libelle' => 'P.U.', 'classe' => 'text-right'];
              $colonnesLignes[] = ['libelle' => 'Total HT', 'classe' => 'text-right'];
          }
          echo tableauDebut($colonnesLignes);
          if (!$lignes) echo ligneVide(count($colonnesLignes));
          ?>
          <?php foreach ($lignes as $ligne): ?>
            <tr>
              <td class="<?= CLASSES_TD ?> font-medium text-slate-800"><?= e($ligne['designation']) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($ligne['support_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e(MODES_LIGNE[$ligne['mode']]['label'] ?? $ligne['mode']) ?></td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500">
                <?= $ligne['mode'] === 'PIECE' ? '—'
                    : e(nombre($ligne['largeur']) . ' × ' . nombre($ligne['hauteur']) . ' m × ' . nombre($ligne['quantite'])) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(nombre($ligne['unites']) . ' ' . uniteLigne($ligne['mode'])) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= num($ligne['conso_prevue']) > 0 ? 'text-slate-700' : 'text-slate-400' ?>">
                <?= num($ligne['conso_prevue']) > 0
                    ? e(nombre($ligne['conso_prevue']) . ' ' . ($ligne['support_unite'] ?? '')) : '—' ?>
              </td>
              <?php if ($montants): ?>
                <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($ligne['prix_unitaire'], devise())) ?></td>
                <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($ligne['total_ht'], devise())) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <?= carteDebut('Matière consommée', [
            'sous_titre' => $travail['matiere_sortie_le']
                ? 'Sortie automatique le ' . dateHeureFr($travail['matiere_sortie_le'])
                    . ' — ajoutez ici la chute et les reprises'
                : 'La matière prévue sortira au passage en impression ; la chute se déclare ici',
            'sans_padding' => true,
        ]) ?>
          <form method="post" class="grid grid-cols-1 gap-4 border-b border-slate-100 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="consommation">
            <?= champBloc('Matière', listeDeroulante('article_id', $optionsMatieres, ''), ['obligatoire' => true, 'classe' => 'lg:col-span-2']) ?>
            <?= champBloc('Machine', listeDeroulante('machine_id', $optionsMachines, $travail['machine_id'] ?? '')) ?>
            <?= champBloc('Date', saisie('date', dateInput('now'), ['type' => 'date'])) ?>
            <?= champBloc('Quantité utilisée', saisie('quantite', '', ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0, 'placeholder' => '5']]),
                ['aide' => 'Dans l\'unité de la matière (mètres pour un rouleau)']) ?>
            <?= champBloc('Chute', saisie('chute', '', ['type' => 'number', 'attrs' => ['step' => '0.001', 'min' => 0, 'placeholder' => '0']]),
                ['aide' => 'Perte non réutilisable']) ?>
            <?= champBloc('Motif', listeDeroulante('motif', MOTIFS_CHUTE, 'PRODUCTION')) ?>
            <div class="flex items-end"><?= bouton('Sortir du stock', ['icone' => 'rouleau']) ?></div>
            <?= champBloc('Note', saisie('note', ''), ['classe' => 'sm:col-span-2 lg:col-span-4']) ?>
          </form>

          <?= tableauDebut(['Action', 'Date', 'Matière', 'Machine', 'Par',
              ['libelle' => 'Utilisée', 'classe' => 'text-right'],
              ['libelle' => 'Chute', 'classe' => 'text-right'], 'Motif']) ?>
          <?php if (!$consommations) echo ligneVide(8, 'Aucune sortie de matière'); ?>
          <?php foreach ($consommations as $conso): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <?php if (in_array($role, ['ADMIN', 'MANAGER'], true)) echo menuActions([
                    actionMenu('supprimer-consommation', ['id' => $conso['id']], 'Reprendre', [
                        'icone' => 'poubelle', 'classe' => 'text-rose-600',
                        'confirmation' => 'Reprendre cette sortie ? La matière revient au stock.',
                    ])]); ?>
              </td>
              <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($conso['date'])) ?></td>
              <td class="<?= CLASSES_TD ?>"><?= e($conso['article_nom']) ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($conso['machine_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-slate-500"><?= e($conso['user_nom'] ?? '—') ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(nombre($conso['quantite']) . ' ' . $conso['unite']) ?></td>
              <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap <?= num($conso['chute']) > 0 ? 'font-medium text-amber-600' : 'text-slate-400' ?>">
                <?= e(nombre($conso['chute'])) ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-xs text-slate-500"><?= e(MOTIFS_CHUTE[$conso['motif']] ?? $conso['motif']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut('Le dossier') ?>
          <?= ligneInfo('Client', $travail['client'] ?? 'Client de passage') ?>
          <?= ligneInfo('Contact', $travail['contact'] ?: $travail['client_telephone']) ?>
          <?= ligneInfo('Reçu par', $travail['accueil_nom']) ?>
          <?= ligneInfo('Designer', $travail['designer_nom']) ?>
          <?= ligneInfo('Machiniste', $travail['machiniste_nom']) ?>
          <?= ligneInfo('Livreur', $travail['livreur_nom']) ?>
          <?= ligneInfo('Machine', $travail['machine']) ?>
          <?= ligneInfo('Promis pour le', $travail['date_promise'] ? dateFr($travail['date_promise']) : null) ?>
          <?= ligneInfo('Livré le', $travail['date_livraison'] ? dateHeureFr($travail['date_livraison']) : null) ?>
          <?php if ($travail['etape'] === 'ANNULE') echo ligneInfo("Motif d'annulation", $travail['motif_annulation']); ?>
          <?php if ($travail['description']): ?>
            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-line text-slate-600"><?= e($travail['description']) ?></p>
          <?php endif; ?>
        <?= carteFin() ?>

        <?php if ($montants): ?>
        <?= carteDebut('Montants') ?>
          <?= ligneInfo('Total HT', montant($travail['total_ht'], devise())) ?>
          <?= ligneInfo('Remise', montant($travail['remise'], devise())) ?>
          <?= ligneInfo('TVA', montant($travail['total_tva'], devise())) ?>
          <?= ligneInfo('Total TTC', montant($travail['total_ttc'], devise())) ?>
          <?= ligneInfo('Avance reçue', montant($travail['avance'], devise())) ?>
          <?= ligneInfo('Reste dû', montant($reste, devise())) ?>
        <?= carteFin() ?>
        <?php endif; ?>

        <?= carteDebut('Bilan matière') ?>
          <?= ligneInfo('Utilisée', nombre($bilan['utilisee'])) ?>
          <?= ligneInfo('Chute', nombre($bilan['chute'])) ?>
          <?= ligneInfo('Sortie totale', nombre($bilan['total'])) ?>
          <?= ligneInfo('Taux de chute', pourcent(tauxChute($bilan['utilisee'], $bilan['chute']))) ?>
        <?= carteFin() ?>

        <?php if ($gere): ?>
        <?= carteDebut('Facturation') ?>
          <?php if ($travail['document_id']): ?>
            <p class="text-sm text-slate-600">
              Pièce liée :
              <a href="<?= e(u(urlDocument($travail['document_type'], $travail['document_id']))) ?>"
                 class="font-medium text-marine-600 hover:underline"><?= e($travail['document_numero']) ?></a>
            </p>
          <?php elseif ($clos && $travail['etape'] === 'ANNULE'): ?>
            <p class="text-sm text-slate-500">Travail annulé — rien à facturer.</p>
          <?php else: ?>
            <p class="mb-3 text-sm text-slate-500">
              Les lignes du travail deviennent celles de la pièce. La matière, déjà sortie
              par l'atelier, n'est pas décomptée une seconde fois.
            </p>
            <div class="flex flex-wrap gap-2">
              <?php foreach (PIECES_TRAVAIL as $type): ?>
                <?= actionBouton('facturer', ['type' => $type], meta($type)['label'],
                    ['variante' => $type === 'FACTURE' ? 'primaire' : 'secondaire']) ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?= carteFin() ?>
        <?php endif; ?>

        <?php if ($travail['image_url']): ?>
          <?= carteDebut('Aperçu validé') ?>
            <img src="<?= e(urlImage($travail['image_url'])) ?>" alt="" class="w-full rounded-lg ring-1 ring-slate-200">
          <?= carteFin() ?>
        <?php endif; ?>

        <?php if ($travail['notes']): ?>
          <?= carteDebut('Notes internes') ?>
            <p class="text-sm whitespace-pre-line text-slate-600"><?= e($travail['notes']) ?></p>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>
    </div>
<?php });
