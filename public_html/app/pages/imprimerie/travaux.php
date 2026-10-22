<?php
/** Ordres de travail de l'atelier : liste, filtres et passage d'étape. */

$session = exigerRole(...ROLES_IMPRIMERIE);

if (estPost()) {
    $action = champ('_action');
    $travailId = (string) champ('id', '');

    // Les gestes réservés sont refusés ici aussi, pas seulement cachés du menu :
    // un formulaire recopié depuis une autre session ne doit rien pouvoir.
    if ($action === 'etape') {
        $etape = (string) champ('etape', '');
        if ($etape === 'ANNULE') exigerRole(...ROLES_IMPRIMERIE_GESTION);

        $resultat = changerEtape($session, $travailId, $etape, champ('note'));

        $succes = $etape === 'ANNULE' ? 'Travail annulé, motif enregistré.' : 'Étape mise à jour.';

        $sorties = $resultat['sorties'] ?? 0;
        if ($sorties > 0) {
            $succes .= ' Matière sortie du stock (' . $sorties . ' ligne' . ($sorties > 1 ? 's' : '') . ').';
        }

        rediriger('/imprimerie/travaux', $resultat['erreur'] ?? $succes,
            isset($resultat['erreur']) ? 'erreur' : 'succes');
    }

    if ($action === 'supprimer') {
        exigerRole('ADMIN', 'MANAGER');
        supprimerTravail($travailId);
        rediriger('/imprimerie/travaux', 'Ordre de travail supprimé, matière rendue au stock.');
    }
}

$params = lireParams();
$args = [$session['lieu_id']];
$conditions = ['t.lieu_id = ?'];

// L'accueil travaille sur la journée : il ouvre les dossiers du jour et rend
// ceux qu'on vient chercher, sans avoir sous les yeux l'activité du mois.
$duJourSeulement = $session['role'] === 'CAISSIER';
if ($duJourSeulement) {
    $conditions[] = 't.date BETWEEN ? AND ?';
    $args[] = debutJour('now');
    $args[] = finJour('now');
}

$etapeFiltre = (string) param('etape', '');
if (isset(ETAPES_TRAVAIL[$etapeFiltre])) {
    $conditions[] = 't.etape = ?';
    $args[] = $etapeFiltre;
} elseif ($etapeFiltre === 'OUVERTS') {
    $conditions[] = "t.etape NOT IN ('LIVRE', 'ANNULE')";
} elseif ($etapeFiltre === 'RETARD') {
    $conditions[] = "t.etape NOT IN ('LIVRE', 'ANNULE') AND t.date_promise IS NOT NULL AND t.date_promise < ?";
    $args[] = maintenant();
}

$machineFiltre = (string) param('machine', '');
if ($machineFiltre !== '') {
    $conditions[] = 't.machine_id = ?';
    $args[] = $machineFiltre;
}

$recherche = rechercheSur(['t.numero', 't.designation', 'ti.nom'], $params['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur(
    "SELECT COUNT(*) FROM travail t LEFT JOIN tiers ti ON ti.id = t.tiers_id WHERE $where",
    $args,
);

$travaux = tous(
    "SELECT t.*, ti.nom AS client, m.nom AS machine,
            (SELECT COALESCE(SUM(c.quantite + c.chute), 0) FROM consommation c WHERE c.travail_id = t.id) AS matiere
       FROM travail t
       LEFT JOIN tiers ti ON ti.id = t.tiers_id
       LEFT JOIN machine m ON m.id = t.machine_id
      WHERE $where
      ORDER BY t.priorite = 'URGENTE' DESC, t.date DESC
      LIMIT {$params['take']} OFFSET {$params['skip']}",
    $args,
);

$machines = tous('SELECT id, nom FROM machine ORDER BY nom');

$role = $session['role'];

page('Ordres de travail', function () use (
    $travaux, $total, $params, $etapeFiltre, $machineFiltre, $machines, $role, $duJourSeulement
) {
    $montants = voitMontants($role);

    echo enTetePage('Ordres de travail',
        $duJourSeulement ? 'Les dossiers ouverts aujourd\'hui' : 'Tout ce qui est passé par l\'atelier',
        in_array($role, ROLES_GESTION, true)
            ? boutonLien('/imprimerie/travaux/nouveau', 'Nouveau travail', ['icone' => 'plus'])
            : '');

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = '<select name="etape" class="' . CLASSES_SAISIE_AUTO . '">'
        . '<option value="">Toutes les étapes</option>'
        . '<option value="OUVERTS"' . ($etapeFiltre === 'OUVERTS' ? ' selected' : '') . '>En cours (non livrés)</option>'
        . '<option value="RETARD"' . ($etapeFiltre === 'RETARD' ? ' selected' : '') . '>En retard</option>';
    foreach (ETAPES_TRAVAIL as $cle => $etape) {
        $filtres .= '<option value="' . e($cle) . '"' . ($etapeFiltre === $cle ? ' selected' : '') . '>'
            . e($etape['label']) . '</option>';
    }
    $filtres .= '</select>';

    $filtres .= '<select name="machine" class="' . CLASSES_SAISIE_AUTO . '">'
        . '<option value="">Toutes les machines</option>';
    foreach ($machines as $machine) {
        $filtres .= '<option value="' . e($machine['id']) . '"' . ($machineFiltre === $machine['id'] ? ' selected' : '') . '>'
            . e($machine['nom']) . '</option>';
    }
    $filtres .= '</select>';

    echo barreOutils('/imprimerie/travaux', $params, $filtres, 'N°, désignation, client…');

    $colonnes = ['Action', 'N°', 'Date', 'Client', 'Travail', 'Machine', 'Promis',
        ['libelle' => 'Matière', 'classe' => 'text-right']];
    if ($montants) $colonnes[] = ['libelle' => 'Total TTC', 'classe' => 'text-right'];
    $colonnes[] = 'Étape';

    echo tableauDebut($colonnes);

    if (!$travaux) echo ligneVide(count($colonnes), 'Aucun ordre de travail');

    $maintenant = new DateTime();

    foreach ($travaux as $travail) {
        $suivante = etapeSuivante($travail['etape']);
        $elements = [
            lienMenu('/imprimerie/travaux/' . $travail['id'], 'Ouvrir le dossier', 'oeil'),
            lienMenu('/imprimerie/fiche/' . $travail['id'], "Fiche d'atelier", 'imprimante'),
        ];

        // L'atelier fait avancer les dossiers ; il ne les modifie pas, ne les
        // annule pas et n'en efface aucun. C'est l'affaire de la gestion.
        $gere = in_array($role, ROLES_GESTION, true);

        if (!travailClos($travail['etape'])) {
            if ($gere) {
                $elements[] = lienMenu('/imprimerie/travaux/' . $travail['id'] . '/modifier', 'Modifier', 'crayon');
            }
            if ($suivante) {
                $elements[] = actionMenu('etape', ['id' => $travail['id'], 'etape' => $suivante],
                    'Passer en « ' . ETAPES_TRAVAIL[$suivante]['label'] . ' »', ['icone' => 'coche']);
            }
            // L'annulation ne passe pas par un simple bouton : le motif se
            // saisit ici même, et le serveur le refuse vide.
            if ($gere) $elements[] = '<form method="post" class="space-y-2 border-t border-slate-100 px-4 py-3">'
                . champCsrf()
                . '<input type="hidden" name="_action" value="etape">'
                . '<input type="hidden" name="id" value="' . e($travail['id']) . '">'
                . '<input type="hidden" name="etape" value="ANNULE">'
                . '<span class="block text-xs font-medium text-slate-600">Annulé par le client — motif</span>'
                . '<input type="text" name="note" required maxlength="255" placeholder="Pourquoi ?" '
                . 'class="block w-full rounded-lg border-0 bg-white px-2 py-1.5 text-xs text-slate-900 '
                . 'ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-rose-500">'
                . '<button type="submit" class="' . classesBouton('danger', 'w-full !px-2 !py-1 text-xs') . '">'
                . "Valider l'annulation</button></form>";
        }

        if (in_array($role, ['ADMIN', 'MANAGER'], true)) {
            $elements[] = actionMenu('supprimer', ['id' => $travail['id']], 'Effacer', [
                'icone' => 'poubelle', 'classe' => 'text-rose-600',
                'confirmation' => "Supprimer {$travail['numero']} ? La matière consommée sera rendue au stock.",
            ]);
        }

        $promise = enDate($travail['date_promise']);
        $enRetard = $promise && $promise < $maintenant && !travailClos($travail['etape']);
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>"><?= menuActions($elements) ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap">
            <a href="<?= e(u('/imprimerie/travaux/' . $travail['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($travail['numero']) ?></a>
            <?php if ($travail['priorite'] === 'URGENTE') echo ' ' . badge('Urgent', 'rouge'); ?>
          </td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($travail['date'])) ?></td>
          <td class="<?= CLASSES_TD ?> max-w-40 truncate"><?= e($travail['client'] ?? 'Client de passage') ?></td>
          <td class="<?= CLASSES_TD ?> max-w-64">
            <div class="truncate"><?= e($travail['designation']) ?></div>
            <?php if ($travail['etape'] === 'ANNULE' && $travail['motif_annulation']): ?>
              <div class="truncate text-xs text-rose-600" title="<?= e($travail['motif_annulation']) ?>">
                Annulé : <?= e($travail['motif_annulation']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="<?= CLASSES_TD ?>"><?= e($travail['machine'] ?? '—') ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap <?= $enRetard ? 'font-medium text-rose-600' : 'text-slate-500' ?>">
            <?= $promise ? e(dateFr($travail['date_promise'])) : '—' ?>
          </td>
          <td class="<?= CLASSES_TD ?> text-right text-slate-500"><?= num($travail['matiere']) > 0 ? e(nombre($travail['matiere'])) : '—' ?></td>
          <?php if ($montants): ?>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($travail['total_ttc'], devise())) ?></td>
          <?php endif; ?>
          <td class="<?= CLASSES_TD ?>"><?= badgeStatut(ETAPES_TRAVAIL, $travail['etape']) ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo pagination('/imprimerie/travaux', $total, $params);
    echo carteFin();
});
