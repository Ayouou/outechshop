<?php
/**
 * Impression des synthèses : rapport de caisse, rapport d'activité, tableau de
 * bord. Le pendant de `impression.php`, mais pour une période au lieu d'une
 * pièce.
 *
 * Les chiffres sont ceux de `lib/synthese.php` — exactement ceux qu'affichait
 * la page d'où l'on vient. Le navigateur gère l'export PDF ; aucune dépendance
 * serveur supplémentaire.
 *
 * Le rapport de caisse sort aussi sur rouleau : c'est le ticket que le caissier
 * arrache en fin de journée, avant de compter son tiroir.
 */

$rapports = [
    'caisse' => [
        'titre'   => 'Rapport de caisse',
        'retour'  => '/caisse',
        'roles'   => ROLES_GESTION,
        'defaut'  => 'now',
        'formats' => ['a4', '80', '58'],
    ],
    'activite' => [
        'titre'   => "Rapport d'activité",
        'retour'  => '/rapports',
        'roles'   => ROLES_SYNTHESE,
        'defaut'  => date('Y-m-01'),
        'formats' => ['a4'],
    ],
    'tableau-de-bord' => [
        'titre'   => 'Tableau de bord',
        'retour'  => '/tableau-de-bord',
        'roles'   => ROLES_SYNTHESE,
        'defaut'  => (new DateTime())->modify('-29 days')->format('Y-m-d'),
        'formats' => ['a4'],
    ],
];

$quoi = (string) ($params['rapport'] ?? '');
if (!isset($rapports[$quoi])) introuvable('Rapport introuvable');

$rapport = $rapports[$quoi];
$session = exigerRole(...$rapport['roles']);

[$du, $au] = periodeDemandee($rapport['defaut']);
$format = formatImpression(param('format'), $rapport['formats']);
$largeur = FORMATS_IMPRESSION[$format]['largeur_mm'];
$marge = FORMATS_IMPRESSION[$format]['marge_mm'];

$donnees = match ($quoi) {
    'caisse'          => syntheseCaisse($session['lieu_id'], $du, $au),
    'activite'        => syntheseActivite($session['lieu_id'], $du, $au),
    'tableau-de-bord' => syntheseTableauDeBord($session['lieu_id'], $du, $au),
};

$societe = societe();
$lieu = lieuCourant();
$utilisateur = utilisateurCourant();

pageNue($rapport['titre'], function () use (
    $quoi, $rapport, $donnees, $du, $au, $format, $largeur, $marge, $societe, $lieu, $utilisateur
) {
    $devise = devise();
    $unJour = periodeUnJour($du, $au);

    /* ------------------------------------------------------ briques A4 */

    /** En-tête d'un tableau du rapport. */
    $tete = fn(array $colonnes) => '<thead><tr class="bg-slate-100 text-slate-600">'
        . implode('', array_map(
            fn($c) => '<th class="border border-slate-300 px-2 py-1.5 text-[11px] font-semibold uppercase '
                . (is_array($c) && !empty($c['droite']) ? 'text-right' : 'text-left') . '">'
                . e(is_array($c) ? $c['libelle'] : $c) . '</th>',
            $colonnes,
        ))
        . '</tr></thead>';

    /**
     * Tableau d'impression. `$lignes` : tableaux de cellules, chacune
     * `['texte' => …, 'droite' => bool, 'gras' => bool]` ou une simple chaîne.
     */
    $tableau = function (array $colonnes, array $lignes, ?array $total = null,
                         string $vide = 'Rien sur la période') use ($tete) {
        $cellule = fn($c, bool $pied = false) => '<td class="border border-slate-300 px-2 py-1 '
            . ((is_array($c) && !empty($c['droite'])) ? 'text-right ' : '')
            . ((is_array($c) && !empty($c['gras'])) || $pied ? 'font-semibold ' : '')
            . '">' . e(is_array($c) ? ($c['texte'] ?? '') : $c) . '</td>';

        $html = '<table class="mt-2 w-full border-collapse text-[11px]">' . $tete($colonnes) . '<tbody>';
        if (!$lignes) {
            $html .= '<tr><td class="border border-slate-300 px-2 py-3 text-center text-slate-400" colspan="'
                . count($colonnes) . '">' . e($vide) . '</td></tr>';
        }
        foreach ($lignes as $ligne) {
            $html .= '<tr>' . implode('', array_map($cellule, $ligne)) . '</tr>';
        }
        if ($total) {
            $html .= '<tr class="bg-slate-50">'
                . implode('', array_map(fn($c) => $cellule($c, true), $total)) . '</tr>';
        }
        return $html . '</tbody></table>';
    };

    /** Titre de section, qui ne se sépare pas de son tableau à la coupe. */
    $section = fn(string $titre, string $contenu) =>
        '<section class="mt-6 break-inside-avoid">'
        . '<h2 class="text-[13px] font-semibold tracking-wide text-marine-800 uppercase">' . e($titre) . '</h2>'
        . $contenu . '</section>';

    /** Bandeau de chiffres clés : deux à quatre par ligne. */
    $chiffres = function (array $valeurs) {
        $html = '<div class="mt-3 grid grid-cols-4 gap-2">';
        foreach ($valeurs as $v) {
            $html .= '<div class="break-inside-avoid rounded border border-slate-300 px-3 py-2">'
                . '<p class="text-[10px] tracking-wide text-slate-500 uppercase">' . e($v['libelle']) . '</p>'
                . '<p class="mt-0.5 text-sm font-bold text-slate-900">' . e($v['valeur']) . '</p>'
                . (isset($v['detail']) ? '<p class="text-[10px] text-slate-500">' . e($v['detail']) . '</p>' : '')
                . '</div>';
        }
        return $html . '</div>';
    };

    $quand = fn($date) => $unJour ? heureFr($date) : dateHeureFr($date);
    ?>
<div class="min-h-screen bg-slate-100 py-8">
  <!--
    `@page` ne se laisse pas cibler par une classe : la taille du support doit
    être écrite globalement. Cette règle arrive après outech.css, elle remplace
    donc le A4 par défaut quand un rouleau est demandé.
  -->
  <style>
    @media print {
      @page { size: <?= $largeur ?>mm <?= $format === 'a4' ? '297mm' : 'auto' ?>; margin: <?= $marge ?>mm; }
    }
  </style>

  <div class="sans-impression mx-auto mb-6 max-w-[210mm] px-4">
    <form method="get" class="flex flex-wrap items-end gap-2">
      <a href="<?= e(u($rapport['retour'] . '?' . requetePeriode($du, $au))) ?>" class="<?= classesBouton('secondaire') ?>">
        <?= icone('fleche-g', 'size-4') ?> Retour
      </a>

      <!-- La période se règle ici aussi : on imprime souvent une autre semaine
           que celle qu'on regardait. -->
      <label class="text-xs text-slate-500">Du
        <input type="date" name="du" value="<?= e(substr($du, 0, 10)) ?>" class="<?= CLASSES_SAISIE ?> mt-1">
      </label>
      <label class="text-xs text-slate-500">Au
        <input type="date" name="au" value="<?= e(substr($au, 0, 10)) ?>" class="<?= CLASSES_SAISIE ?> mt-1">
      </label>
      <input type="hidden" name="format" value="<?= e($format) ?>">
      <?= bouton('Appliquer', ['variante' => 'secondaire']) ?>

      <?php if (count($rapport['formats']) > 1): ?>
        <span class="ml-2 flex flex-wrap items-center gap-2">
          <?php foreach ($rapport['formats'] as $cle): ?>
            <a href="<?= e(u('/impression/rapport/' . $quoi . '?' . requetePeriode($du, $au) . '&format=' . $cle)) ?>"
               class="<?= $cle === $format ? classesBouton('primaire') : classesBouton('secondaire') ?>"><?= e(FORMATS_IMPRESSION[$cle]['label']) ?></a>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>

      <button type="button" onclick="window.print()" class="<?= classesBouton('primaire', 'ml-auto') ?>">
        <?= icone('imprimante', 'size-4') ?> Imprimer / PDF
      </button>
    </form>
  </div>

<?php if ($format === 'a4'): ?>
  <div class="zone-impression mx-auto max-w-[210mm] bg-white p-10 text-slate-800 shadow-lg">
    <header class="flex items-start justify-between gap-8 border-b-2 border-marine-600 pb-5">
      <div>
        <p class="text-xl font-bold text-marine-800"><?= e($societe['nom'] ?? 'OUTECH') ?></p>
        <div class="mt-1.5 space-y-0.5 text-[11px] text-slate-600">
          <?php foreach ([['', $societe['adresse'] ?? null], ['', $societe['ville'] ?? null],
                          ['Tél : ', $societe['telephone'] ?? null], ['ICE : ', $societe['ice'] ?? null]] as [$prefixe, $valeur]): ?>
            <?php if ($valeur): ?><p><?= e($prefixe . $valeur) ?></p><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="text-right">
        <p class="text-lg font-bold tracking-wide text-slate-800 uppercase"><?= e($rapport['titre']) ?></p>
        <p class="mt-1 text-xs text-slate-600">Période <?= e(libellePeriode($du, $au)) ?></p>
        <?php if ($lieu): ?><p class="text-xs text-slate-600">Lieu : <?= e($lieu['nom']) ?></p><?php endif; ?>
        <p class="mt-1 text-[11px] text-slate-500">
          Édité le <?= e(dateHeureFr('now')) ?><?= $utilisateur ? ' par ' . e($utilisateur['nom']) : '' ?>
        </p>
      </div>
    </header>

    <?php if ($quoi === 'caisse'): ?>
      <?php
      extract($donnees);
      echo $chiffres([
          ['libelle' => $unJour ? 'Encaissé dans la journée' : 'Encaissé sur la période',
           'valeur' => montant($totalEncaisse, $devise),
           'detail' => count($entrees) . ' règlement' . (count($entrees) > 1 ? 's' : '')],
          ['libelle' => 'Espèces en caisse', 'valeur' => montant($especesEntrees - $especesSorties, $devise),
           'detail' => montant($especesEntrees, $devise) . ' entrés · ' . montant($especesSorties, $devise) . ' sortis'],
          ['libelle' => $unJour ? 'Ventes du jour' : 'Ventes de la période', 'valeur' => montant($totalVentes, $devise),
           'detail' => count($ventes) . ' vente' . (count($ventes) > 1 ? 's' : '')],
          ['libelle' => 'Reste à encaisser', 'valeur' => montant($resteAEncaisser, $devise),
           'detail' => $unJour ? 'Sur les ventes du jour' : 'Sur les ventes de la période'],
      ]);

      $lignesModes = [];
      foreach (MODES_PAIEMENT as $mode => $libelle) {
          if (!isset($parMode[$mode])) continue;
          $lignesModes[] = [
              $libelle,
              ['texte' => (string) $parMode[$mode]['nombre'], 'droite' => true],
              ['texte' => montant($parMode[$mode]['total'], $devise), 'droite' => true],
          ];
      }
      echo $section('Encaissements par mode', $tableau(
          ['Mode', ['libelle' => 'Nombre', 'droite' => true], ['libelle' => 'Montant', 'droite' => true]],
          $lignesModes,
          $lignesModes ? ['Total', ['texte' => (string) count($entrees), 'droite' => true],
              ['texte' => montant($totalEncaisse, $devise), 'droite' => true]] : null,
          'Aucun encaissement',
      ));

      echo $section($unJour ? 'Ventes du jour' : 'Ventes de la période', $tableau(
          [$unJour ? 'Heure' : 'Date', 'Numéro', 'Client',
           ['libelle' => 'Total', 'droite' => true], ['libelle' => 'Payé', 'droite' => true], 'Paiement'],
          array_map(fn($v) => [
              $quand($v['date']), $v['numero'], $v['tiers_nom'] ?? 'Client de passage',
              ['texte' => montant($v['total_ttc'], $devise), 'droite' => true],
              ['texte' => montant($v['montant_paye'], $devise), 'droite' => true],
              STATUTS_PAIEMENT[$v['statut_paiement']]['label'] ?? $v['statut_paiement'],
          ], $ventes),
          $ventes ? ['Total', '', '',
              ['texte' => montant($totalVentes, $devise), 'droite' => true],
              ['texte' => montant($totalVentes - $resteAEncaisser, $devise), 'droite' => true], ''] : null,
          'Aucune vente sur la période',
      ));

      echo $section('Détail des encaissements', $tableau(
          [$unJour ? 'Heure' : 'Date', 'Document', 'Client', 'Mode', 'Encaissé par',
           ['libelle' => 'Montant', 'droite' => true]],
          array_map(fn($r) => [
              $quand($r['date']), $r['numero'], $r['tiers_nom'] ?? 'Client de passage',
              MODES_PAIEMENT[$r['mode']] ?? $r['mode'], $r['user_nom'] ?? '—',
              ['texte' => montant($r['montant'], $devise), 'droite' => true],
          ], $entrees),
          $entrees ? ['Total', '', '', '', '', ['texte' => montant($totalEncaisse, $devise), 'droite' => true]] : null,
          'Aucun encaissement',
      ));

      $sorties = [];
      $totalSorties = 0.0;
      foreach ($sortiesAchats as $r) {
          $totalSorties += num($r['montant']);
          $sorties[] = [$quand($r['date']), $r['numero'] . ' — ' . ($r['tiers_nom'] ?? 'Fournisseur'),
              MODES_PAIEMENT[$r['mode']] ?? $r['mode'],
              ['texte' => '− ' . montant($r['montant'], $devise), 'droite' => true]];
      }
      foreach ($depenses as $d) {
          $totalSorties += num($d['montant_paye']);
          $sorties[] = [$quand($d['date']), $d['designation'] . ' — ' . ($d['categorie_nom'] ?? 'Dépense'),
              MODES_PAIEMENT[$d['mode']] ?? $d['mode'],
              ['texte' => '− ' . montant($d['montant_paye'], $devise), 'droite' => true]];
      }
      echo $section('Sorties de caisse', $tableau(
          [$unJour ? 'Heure' : 'Date', 'Objet', 'Mode', ['libelle' => 'Montant', 'droite' => true]],
          $sorties,
          $sorties ? ['Total', '', '', ['texte' => '− ' . montant($totalSorties, $devise), 'droite' => true]] : null,
          'Aucune sortie de caisse',
      ));
      ?>

      <!-- Un rapport de caisse se signe : c'est la pièce qui accompagne le
           comptage du tiroir. -->
      <section class="mt-10 flex gap-8 break-inside-avoid">
        <?php foreach (['Visa du caissier', 'Visa du responsable'] as $visa): ?>
          <div class="flex-1">
            <p class="text-[11px] text-slate-500"><?= e($visa) ?></p>
            <div class="mt-1 h-16 rounded border border-slate-300"></div>
          </div>
        <?php endforeach; ?>
      </section>

    <?php elseif ($quoi === 'activite'): ?>
      <?php
      extract($donnees);
      echo $chiffres([
          ['libelle' => "Chiffre d'affaires HT", 'valeur' => montant($caHT, $devise),
           'detail' => count($factures) . ' vente(s) · ' . montant($caTTC, $devise) . ' TTC'],
          ['libelle' => 'Marge brute', 'valeur' => montant($margeBrute, $devise),
           'detail' => 'Coût des ventes : ' . montant($coutVentes, $devise)],
          ['libelle' => 'Dépenses', 'valeur' => montant($totalDepenses, $devise),
           'detail' => (int) $depenses['n'] . ' dépense(s)'],
          ['libelle' => 'Résultat', 'valeur' => montant($resultat, $devise), 'detail' => 'Marge brute − dépenses'],
          ['libelle' => 'Encaissé sur la période', 'valeur' => montant($encaisse, $devise),
           'detail' => 'Reste : ' . montant($caTTC - $encaisse, $devise)],
          ['libelle' => 'Achats', 'valeur' => montant($totalAchats, $devise),
           'detail' => (int) $achats['n'] . ' achat(s)'],
          ['libelle' => "Stock au prix d'achat", 'valeur' => montant($valeurStock, $devise),
           'detail' => 'Valeur immobilisée'],
          ['libelle' => 'Stock au prix de vente', 'valeur' => montant($valeurStockVente, $devise),
           'detail' => 'Potentiel de vente'],
      ]);

      echo $section("Chiffre d'affaires jour par jour", '<div class="mt-2">' . courbeVentes($points) . '</div>');

      echo $section('Meilleurs articles', $tableau(
          ['Article', ['libelle' => 'Quantité', 'droite' => true],
           ['libelle' => 'CA HT', 'droite' => true], ['libelle' => 'Marge', 'droite' => true]],
          array_map(fn($l) => [
              $l['nom'],
              ['texte' => nombre($l['quantite']), 'droite' => true],
              ['texte' => montant($l['ca'], $devise), 'droite' => true],
              ['texte' => montant($l['marge'], $devise), 'droite' => true],
          ], array_values($topArticles)),
          null,
          'Aucune vente sur la période',
      ));

      echo $section('Meilleurs clients', $tableau(
          ['Client', ['libelle' => 'Documents', 'droite' => true], ['libelle' => 'CA TTC', 'droite' => true]],
          array_map(fn($l) => [
              $l['nom'],
              ['texte' => (string) $l['documents'], 'droite' => true],
              ['texte' => montant($l['ca'], $devise), 'droite' => true],
          ], array_values($topClients)),
          null,
          'Aucune vente sur la période',
      ));
      ?>

    <?php else: ?>
      <?php
      extract($donnees);
      echo $chiffres(array_map(fn($t) => [
          'libelle' => $t['libelle'], 'valeur' => montant($valeurs[$t['cle']], $devise),
      ], TUILES_TABLEAU_DE_BORD));

      echo $section('Ventes de la période — ' . $ventes['n'] . ' facture' . ($ventes['n'] > 1 ? 's' : ''),
          '<div class="mt-2">' . courbeVentes($points) . '</div>');

      echo $section('Documents récents', $tableau(
          ['Date', 'Numéro', 'Tiers', ['libelle' => 'Total', 'droite' => true], 'Paiement'],
          array_map(fn($d) => [
              dateFr($d['date']), $d['numero'], $d['tiers_nom'] ?? 'Client de passage',
              ['texte' => montant($d['total_ttc'], $devise), 'droite' => true],
              STATUTS_PAIEMENT[$d['statut_paiement']]['label'] ?? $d['statut_paiement'],
          ], $derniersDocs),
          null,
          'Aucun document',
      ));

      echo $section('Alertes de stock', $tableau(
          ['Article', ['libelle' => 'Stock', 'droite' => true], ['libelle' => 'Alerte', 'droite' => true]],
          array_map(fn($s) => [
              $s['nom'],
              ['texte' => nombre($s['quantite']), 'droite' => true],
              ['texte' => nombre($s['stock_alerte']), 'droite' => true],
          ], $stockCritique),
          null,
          'Aucune alerte de stock',
      ));

      echo $section('Échéances chèques / effets', $tableau(
          ['Échéance', 'Tiers', ['libelle' => 'Montant', 'droite' => true]],
          array_map(fn($e) => [
              dateFr($e['date_echeance']), $e['tiers_nom'] ?? '—',
              ['texte' => montant($e['montant'], $devise), 'droite' => true],
          ], $effetsProches),
          null,
          'Aucun effet en attente',
      ));
      ?>
    <?php endif; ?>

    <footer class="mt-8 border-t border-slate-200 pt-3 text-center text-[10px] text-slate-500">
      <?= e($societe['nom'] ?? 'OUTECH') ?> · <?= e($rapport['titre']) ?> ·
      période <?= e(libellePeriode($du, $au)) ?> · document interne
    </footer>
  </div>

<?php else: ?>
  <!--
    Rapport de caisse sur rouleau thermique : chasse fixe et noir uniquement —
    ces imprimantes ne rendent ni les gris ni les aplats de couleur.
  -->
  <?php
  extract($donnees);
  $colonnes = $format === '58' ? 32 : 42;
  $filet = fn() => '<p class="overflow-hidden whitespace-nowrap">' . str_repeat('-', $colonnes) . '</p>';
  $ligneTicket = fn(string $gauche, string $droite, bool $gras = false) =>
      '<p class="flex justify-between gap-2' . ($gras ? ' font-bold' : '') . '">'
      . '<span class="min-w-0 truncate">' . e($gauche) . '</span>'
      . '<span class="shrink-0 tabular-nums">' . e($droite) . '</span></p>';

  $totalSorties = 0.0;
  foreach ($sortiesAchats as $r) $totalSorties += num($r['montant']);
  foreach ($depenses as $d) $totalSorties += num($d['montant_paye']);
  ?>
  <div class="zone-impression mx-auto bg-white p-3 font-mono text-[11px] leading-snug text-black shadow-lg" style="width: <?= $largeur ?>mm">
    <div class="text-center">
      <p class="text-sm font-bold uppercase"><?= e($societe['nom'] ?? 'OUTECH') ?></p>
      <?php if (!empty($societe['adresse'])): ?><p><?= e($societe['adresse']) ?></p><?php endif; ?>
      <?php if (!empty($societe['telephone'])): ?><p>Tel : <?= e($societe['telephone']) ?></p><?php endif; ?>
    </div>

    <?= $filet() ?>
    <p class="text-center text-xs font-bold uppercase">Rapport de caisse</p>
    <p class="text-center"><?= e(libellePeriode($du, $au)) ?></p>
    <?= $filet() ?>

    <?php if ($lieu): ?><p>Lieu : <?= e($lieu['nom']) ?></p><?php endif; ?>
    <?php if ($utilisateur): ?><p>Édité par : <?= e($utilisateur['nom']) ?></p><?php endif; ?>
    <p>Le <?= e(dateHeureFr('now')) ?></p>

    <?= $filet() ?>
    <p class="font-bold">ENCAISSEMENTS PAR MODE</p>
    <?php foreach (MODES_PAIEMENT as $mode => $libelle): ?>
      <?php if (!isset($parMode[$mode])) continue; ?>
      <?= $ligneTicket($libelle . ' (' . $parMode[$mode]['nombre'] . ')', nombre($parMode[$mode]['total'])) ?>
    <?php endforeach; ?>
    <?php if (!$parMode): ?><p>Aucun encaissement</p><?php endif; ?>
    <?= $ligneTicket('TOTAL ENCAISSE', nombre($totalEncaisse), true) ?>

    <?= $filet() ?>
    <p class="font-bold">ESPECES</p>
    <?= $ligneTicket('Entrées', nombre($especesEntrees)) ?>
    <?= $ligneTicket('Sorties', '- ' . nombre($especesSorties)) ?>
    <?= $ligneTicket('EN CAISSE', nombre($especesEntrees - $especesSorties), true) ?>

    <?= $filet() ?>
    <p class="font-bold">VENTES</p>
    <?= $ligneTicket('Nombre', (string) count($ventes)) ?>
    <?= $ligneTicket('Total', nombre($totalVentes)) ?>
    <?= $ligneTicket('Reste a encaisser', nombre($resteAEncaisser)) ?>

    <?php if ($totalSorties > 0.005): ?>
      <?= $filet() ?>
      <p class="font-bold">SORTIES DE CAISSE</p>
      <?php foreach ($sortiesAchats as $r): ?>
        <?= $ligneTicket($r['numero'], '- ' . nombre($r['montant'])) ?>
      <?php endforeach; ?>
      <?php foreach ($depenses as $d): ?>
        <?= $ligneTicket($d['designation'], '- ' . nombre($d['montant_paye'])) ?>
      <?php endforeach; ?>
      <?= $ligneTicket('TOTAL SORTIES', '- ' . nombre($totalSorties), true) ?>
    <?php endif; ?>

    <?= $filet() ?>
    <p>Visa : ______________________</p>
    <!-- Marge basse : le massicot des thermiques coupe quelques millimètres trop haut. -->
    <p class="pt-6">&nbsp;</p>
  </div>
<?php endif; ?>
</div>
<?php });
