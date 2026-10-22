<?php
/**
 * Liste d'un type de document (ventes ou achats).
 * Port de `documents/ListeDocuments.tsx` + `Regroupement.tsx`.
 */

$session = exigerSession();

$sens = str_starts_with(cheminDemande(), '/ventes') ? 'VENTE' : 'ACHAT';
$type = typeDepuisChemin($sens, $params['chemin'] ?? '');
if (!$type) introuvable('Type de document inconnu');

$meta = meta($type);
$base = urlListe($type);

if (estPost()) {
    $action = (string) champ('_action', '');
    $id = (string) champ('id', '');

    if ($action === 'convertir') {
        $resultat = convertirDocument($session, $id, (string) champ('cible', ''));
        if (isset($resultat['erreur'])) rediriger($base, $resultat['erreur'], 'erreur');
        rediriger(urlDocument((string) champ('cible'), $resultat['id']), 'Document converti.');
    }

    if ($action === 'annuler') {
        annulerDocument($id, $session);
        rediriger($base, 'Document annulé, mouvements de stock repris.');
    }

    if ($action === 'supprimer') {
        $refus = supprimerDocument($id, $session);
        if ($refus) rediriger($base, $refus, 'erreur');
        rediriger($base, 'Document supprimé.');
    }

    if ($action === 'regrouper') {
        $resultat = regrouperEnFacture($session, (array) ($_POST['bons'] ?? []));
        if (isset($resultat['erreur'])) rediriger($base . '?afacturer=1', $resultat['erreur'], 'erreur');
        rediriger(urlDocument('FACTURE', $resultat['id']), 'Facture créée à partir des bons sélectionnés.');
    }
}

$params2 = lireParams();

// Les bons de livraison se cochent pour être réunis en une seule facture.
$avecSelection = $type === 'BON_LIVRAISON';
$seulementAFacturer = $avecSelection && param('afacturer') === '1';

$args = [$type, $session['lieu_id']];
$conditions = ['d.type = ?', 'd.lieu_id = ?'];
if ($seulementAFacturer) $conditions[] = "d.statut NOT IN ('CONVERTI', 'ANNULE')";
$recherche = rechercheSur(['d.numero', 'd.reference', 't.nom'], $params2['q'], $args);
if ($recherche) $conditions[] = $recherche;
$where = assembler($conditions);

$total = (int) valeur(
    "SELECT COUNT(*) FROM document d LEFT JOIN tiers t ON t.id = d.tiers_id WHERE $where",
    $args,
);

$documents = tous(
    "SELECT d.*, t.nom AS tiers_nom,
            (SELECT COUNT(*) FROM ligne_document l WHERE l.document_id = d.id) AS nb_lignes
       FROM document d LEFT JOIN tiers t ON t.id = d.tiers_id
      WHERE $where ORDER BY d.date DESC LIMIT {$params2['take']} OFFSET {$params2['skip']}",
    $args,
);

// Les bons émis par la caisse attendent d'être regroupés : le raccourci
// n'apparaît sur les factures que lorsqu'il y a effectivement de quoi faire.
$bonsAFacturer = $type === 'FACTURE'
    ? (int) valeur("SELECT COUNT(*) FROM document WHERE type = 'BON_LIVRAISON' AND lieu_id = ?
                     AND statut NOT IN ('CONVERTI', 'ANNULE')", [$session['lieu_id']])
    : 0;

page($meta['pluriel'], function () use ($meta, $base, $type, $documents, $total, $params2, $avecSelection, $bonsAFacturer, $seulementAFacturer) {
    $actions = '';
    if ($bonsAFacturer > 0) {
        $actions .= boutonLien('/ventes/bons-livraison?afacturer=1', "Bons à facturer ($bonsAFacturer)",
            ['variante' => 'secondaire', 'icone' => 'copie']);
    }
    $actions .= boutonLien("$base/nouveau", 'Ajouter', ['icone' => 'plus']);

    echo enTetePage($meta['pluriel'], $meta['sens'] === 'VENTE' ? 'Documents de vente' : "Documents d'achat", $actions);

    echo carteDebut(null, ['sans_padding' => true]);

    $filtres = $avecSelection
        ? '<label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="afacturer" value="1"'
            . ($seulementAFacturer ? ' checked' : '')
            . ' class="size-4 rounded border-slate-300 text-marine-600">À facturer seulement</label>'
        : '';

    echo barreOutils($base, $params2, $filtres
        . boutonLien("/api/export/documents?type=$type", 'Exporter', ['variante' => 'secondaire', 'icone' => 'telecharger']),
        'Numéro, tiers, référence…');

    // Le formulaire de regroupement enveloppe le tableau : les cases cochées
    // partent avec le bouton « Facturer la sélection ».
    if ($avecSelection) {
        echo '<form method="post" id="regroupement">' . champCsrf()
            . '<input type="hidden" name="_action" value="regrouper">';
    }

    $colonnes = [];
    if ($avecSelection) $colonnes[] = ['libelle' => '', 'classe' => 'w-10'];
    $colonnes = array_merge($colonnes, ['Action', 'Date', 'Numéro',
        $meta['sens'] === 'VENTE' ? 'Client' : 'Fournisseur',
        ['libelle' => 'Articles', 'classe' => 'text-right'],
        ['libelle' => 'Total TTC', 'classe' => 'text-right']]);
    if ($meta['payable']) $colonnes[] = ['libelle' => 'Payé', 'classe' => 'text-right'];
    $colonnes[] = 'Statut';
    if ($meta['payable']) $colonnes[] = 'Paiement';

    echo tableauDebut($colonnes);
    if (!$documents) echo ligneVide(count($colonnes));

    foreach ($documents as $document) {
        $facturable = $document['statut'] !== 'CONVERTI' && $document['statut'] !== 'ANNULE';

        $elements = [
            lienMenu("$base/{$document['id']}", 'Voir', 'oeil'),
            '<a href="' . e(u('/impression/' . $document['id'])) . '" target="_blank" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50 text-slate-700">'
                . icone('imprimante', 'size-4') . 'Imprimer / PDF</a>',
        ];

        if ($facturable) {
            $elements[] = lienMenu("$base/{$document['id']}/modifier", 'Modifier', 'crayon');
        }
        if ($document['statut'] === 'VALIDE') {
            foreach ($meta['conversions'] as $cible) {
                $elements[] = actionMenu('convertir', ['id' => $document['id'], 'cible' => $cible],
                    'Convertir en ' . meta($cible)['label'], [
                        'icone' => 'flux',
                        'confirmation' => "Convertir {$document['numero']} en " . meta($cible)['label'] . ' ?',
                    ]);
            }
        }
        if ($document['statut'] !== 'ANNULE') {
            $elements[] = actionMenu('annuler', ['id' => $document['id']], 'Annuler', [
                'icone' => 'croix',
                'confirmation' => "Annuler {$document['numero']} ? Les mouvements de stock seront repris.",
            ]);
        }
        $elements[] = actionMenu('supprimer', ['id' => $document['id']], 'Effacer', [
            'icone' => 'poubelle', 'classe' => 'text-rose-600',
            'confirmation' => "Supprimer définitivement {$document['numero']} ?",
        ]);
        ?>
        <tr class="hover:bg-slate-50">
          <?php if ($avecSelection): ?>
            <td class="<?= CLASSES_TD ?>">
              <?php if ($facturable): ?>
                <input type="checkbox" form="regroupement" name="bons[]" value="<?= e($document['id']) ?>"
                       data-tiers="<?= e($document['tiers_id'] ?? '') ?>"
                       class="case-bon size-4 rounded border-slate-300 text-marine-600 focus:ring-marine-500">
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td class="<?= CLASSES_TD ?>"><?= menuActions($elements) ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap"><?= e(dateFr($document['date'])) ?></td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u("$base/{$document['id']}")) ?>" class="font-medium text-marine-600 hover:underline"><?= e($document['numero']) ?></a>
            <?php if ($document['reference']): ?><span class="block text-xs text-slate-400">Réf. <?= e($document['reference']) ?></span><?php endif; ?>
          </td>
          <td class="<?= CLASSES_TD ?> max-w-48 truncate"><?= e($document['tiers_nom'] ?? ($meta['sens'] === 'VENTE' ? 'Client de passage' : '—')) ?></td>
          <td class="<?= CLASSES_TD ?> text-right"><?= (int) $document['nb_lignes'] ?></td>
          <td class="<?= CLASSES_TD ?> text-right font-medium whitespace-nowrap"><?= e(montant($document['total_ttc'], devise())) ?></td>
          <?php if ($meta['payable']): ?>
            <td class="<?= CLASSES_TD ?> text-right whitespace-nowrap"><?= e(montant($document['montant_paye'], devise())) ?></td>
          <?php endif; ?>
          <td class="<?= CLASSES_TD ?>"><?= badgeStatut(STATUTS_DOCUMENT, $document['statut']) ?></td>
          <?php if ($meta['payable']): ?>
            <td class="<?= CLASSES_TD ?>">
              <?= $document['statut'] === 'CONVERTI'
                  // Le règlement a suivi la facture : afficher « Impayé » ici
                  // ferait croire à une créance qui n'existe plus.
                  ? badge('Sur facture', 'violet')
                  : badgeStatut(STATUTS_PAIEMENT, $document['statut_paiement']) ?>
            </td>
          <?php endif; ?>
        </tr>
    <?php }

    echo tableauFin();

    if ($avecSelection) {
        ?>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3">
          <p class="text-sm text-slate-600" id="recap">Aucun bon sélectionné.</p>
          <button type="submit" form="regroupement" id="facturer" disabled
                  class="<?= classesBouton('primaire') ?>"><?= icone('recu', 'size-4') ?> Facturer la sélection</button>
        </div>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
          Le premier bon coché fixe le client : ceux des autres clients se verrouillent, une facture ne portant qu'un seul client.
          Les ventes de passage forment leur propre groupe.
        </p>
        </form>

        <script>
          // Sélection homogène : dès qu'un bon est coché, ceux d'un autre client
          // sont désactivés — une facture ne peut porter qu'un seul client.
          const cases = [...document.querySelectorAll('.case-bon')];
          const recap = document.getElementById('recap');
          const bouton = document.getElementById('facturer');

          function rafraichir() {
            const cochees = cases.filter((c) => c.checked);
            const tiers = cochees.length ? cochees[0].dataset.tiers : null;

            for (const c of cases) {
              c.disabled = tiers !== null && c.dataset.tiers !== tiers;
              c.closest('tr').classList.toggle('opacity-40', c.disabled);
            }

            bouton.disabled = cochees.length === 0;
            recap.textContent = cochees.length
              ? cochees.length + ' bon(s) sélectionné(s)'
              : 'Aucun bon sélectionné.';
          }

          cases.forEach((c) => c.addEventListener('change', rafraichir));
          rafraichir();
        </script>
        <?php
    }

    echo pagination($base, $total, $params2);
    echo carteFin();
});
