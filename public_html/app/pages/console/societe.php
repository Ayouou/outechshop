<?php
/**
 * Console — dossier d'une société : décision, abonnement, accès et données.
 *
 * Toutes les décisions passent par un POST `_action` (jeton CSRF vérifié en
 * amont) et sont inscrites au journal.
 */

exigerConsole();

$societe = un('SELECT * FROM saas_societe WHERE id = ?', [$params['id']]);
if (!$societe) introuvable('Société introuvable');

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    try {
        switch ($action) {
            case 'accepter':
                if (!placeDisponible() && $societe['statut'] === 'DEMANDE') {
                    throw new RuntimeException(
                        'Plafond de ' . reglage('max_societes') . ' sociétés atteint : relevez-le dans Réglages.');
                }
                $acces = accepterSociete($societe, [
                    'username' => (string) champ('username', 'admin'),
                    'nom'      => (string) champ('admin_nom', ''),
                ]);
                // Le mot de passe n'existe en clair qu'ici : on le passe à la
                // page suivante, qui l'affiche une fois puis l'oublie.
                if ($acces['motdepasse']) {
                    $_SESSION['acces_cree'] = $acces + ['code' => $societe['code']];
                }
                rediriger('/console/societes/' . $societe['id'],
                    'Société acceptée : espace installé, essai de ' . dureeEssai() . ' jours ouvert.');

            case 'refuser':
                modifier('saas_societe', $societe['id'], [
                    'statut'      => 'REFUSEE',
                    'motif_refus' => ouNull(champ('motif', '')),
                    'refuse_le'   => maintenant(),
                ]);
                journaliser('refus', $societe['id'], (string) champ('motif', 'Sans motif.'));
                rediriger('/console/societes/' . $societe['id'], 'Demande refusée.', 'info');

            case 'pro':
                // Deux façons d'arriver là : une formule au tarif (le cas
                // courant, la date se calcule seule), ou une date posée à la
                // main pour un arrangement particulier.
                $cle = (string) champ('abonnement', '');
                $formule = abonnement($cle);
                $fin = ouNull(champ('abonnement_fin', ''));

                if ($formule) {
                    $depart = ($societe['formule'] === 'PRO' && $societe['abonnement_fin']
                        && strtotime((string) $societe['abonnement_fin']) > time())
                            ? new DateTime((string) $societe['abonnement_fin'])
                            : new DateTime();
                    $echeance = $depart->modify('+' . $formule['mois'] . ' months');
                } else {
                    $echeance = $fin ? new DateTime(finJour($fin)) : null;
                }

                modifier('saas_societe', $societe['id'], [
                    'formule'            => 'PRO',
                    'statut'             => 'ACTIVE',
                    'abonnement_fin'     => $echeance?->format('Y-m-d H:i:s.v'),
                    'abonnement_choisi'  => $formule ? $cle : null,
                    'abonnement_montant' => $formule ? $formule['prix'] : null,
                ]);

                journaliser('passage-pro', $societe['id'],
                    ($formule ? 'Formule ' . libelleAbonnement($cle) . '. ' : '')
                    . ($echeance ? 'Jusqu\'au ' . dateFr($echeance) . '.' : 'Sans date de fin.'));

                rediriger('/console/societes/' . $societe['id'],
                    'Société passée en Pro' . ($echeance ? " jusqu'au " . dateFr($echeance) : '') . '.');

            case 'prolonger':
                $jours = max(1, (int) champ('jours', dureeEssai()));
                $depart = ($societe['formule'] === 'ESSAI' && $societe['essai_fin']
                    && strtotime((string) $societe['essai_fin']) > time())
                        ? new DateTime((string) $societe['essai_fin'])
                        : new DateTime();
                $fin = $depart->modify('+' . $jours . ' days');

                modifier('saas_societe', $societe['id'], [
                    'formule'   => 'ESSAI',
                    'statut'    => 'ACTIVE',
                    'essai_fin' => $fin->format('Y-m-d H:i:s.v'),
                ]);
                journaliser('prolongation', $societe['id'],
                    'Essai prolongé de ' . $jours . ' jours, jusqu\'au ' . dateFr($fin) . '.');
                rediriger('/console/societes/' . $societe['id'], 'Essai prolongé de ' . $jours . ' jours.');

            case 'suspendre':
                modifier('saas_societe', $societe['id'], ['statut' => 'SUSPENDUE']);
                journaliser('suspension', $societe['id'], (string) champ('motif', 'Sans motif.'));
                rediriger('/console/societes/' . $societe['id'], 'Société suspendue.', 'info');

            case 'reactiver':
                modifier('saas_societe', $societe['id'], ['statut' => 'ACTIVE']);
                journaliser('reactivation', $societe['id'], 'Accès rétabli.');
                rediriger('/console/societes/' . $societe['id'], 'Société réactivée.');

            case 'provisionner':
                $acces = provisionner($societe, ['username' => (string) champ('username', 'admin')]);
                modifier('saas_societe', $societe['id'], [
                    'base'           => $acces['base'],
                    'admin_username' => $acces['username'],
                ]);
                journaliser('provisionnement', $societe['id'], 'Espace installé (' . $acces['base'] . ').');
                if ($acces['motdepasse']) {
                    $_SESSION['acces_cree'] = $acces + ['code' => $societe['code']];
                }
                rediriger('/console/societes/' . $societe['id'], 'Espace installé.');

            case 'motdepasse':
                $acces = reinitialiserAdmin($societe, (string) champ('username', ''));
                $_SESSION['acces_cree'] = $acces + ['code' => $societe['code']];
                rediriger('/console/societes/' . $societe['id'], 'Nouveau mot de passe généré.');

            case 'code':
                // Le code change, la base ne bouge pas : les données restent
                // exactement où elles sont. Seuls les liens déjà transmis au
                // client cessent de fonctionner — d'où la confirmation.
                $ancien = $societe['code'];
                $nouveau = codeSociete($societe['nom']);

                modifier('saas_societe', $societe['id'], ['code' => $nouveau]);
                journaliser('code', $societe['id'], 'Code société régénéré (' . $ancien . ' → ' . $nouveau . ').');
                rediriger('/console/societes/' . $societe['id'],
                    'Nouveau code société — transmettez-le au client, l\'ancien ne fonctionne plus.');

            case 'notes':
                modifier('saas_societe', $societe['id'], ['notes' => ouNull(champ('notes', ''))]);
                rediriger('/console/societes/' . $societe['id'], 'Notes enregistrées.');

            case 'ouvrir':
                ouvrirEspace($societe);
                break;

            case 'retirer':
                // Le registre oublie la société ; sa base MySQL, elle, reste en
                // place. Aucune donnée client n'est détruite par la console.
                // Son adresse publique, en revanche, retourne au pot commun.
                modifier('saas_societe', $societe['id'], ['statut' => 'REFUSEE']);
                libererAdressePublique($societe['id']);
                journaliser('retrait', $societe['id'],
                    'Retirée du service. Base conservée : ' . ($societe['base'] ?: '—'));
                rediriger('/console/societes', 'Société retirée du service — sa base est conservée.', 'info');
        }
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}

$situation = situation($societe);
$stats = statsSociete($societe);
$journal = tous('SELECT * FROM saas_journal WHERE societe_id = ? ORDER BY created_at DESC LIMIT 30',
    [$societe['id']]);

// Comptes de l'espace client : lus dans sa base, jamais recopiés ailleurs.
$comptes = empty($societe['base']) ? [] : dansBase($societe['base'],
    fn() => tous('SELECT nom, username, role, actif, dernier_login FROM utilisateur ORDER BY created_at'));

$acces = $_SESSION['acces_cree'] ?? null;
unset($_SESSION['acces_cree']);

pageConsole($societe['nom'], function () use ($societe, $situation, $stats, $journal, $comptes, $acces, $erreur) {
    $actions = '';
    if (!empty($societe['base'])) {
        $actions .= actionBouton('ouvrir', [], "Ouvrir l'espace", ['variante' => 'secondaire', 'icone' => 'oeil'])
            . boutonLien('/console/sauvegardes/' . $societe['id'], 'Télécharger les données',
                ['variante' => 'secondaire', 'icone' => 'telecharger']);
    }
    $actions .= boutonLien('/console/societes/' . $societe['id'] . '/modifier', 'Modifier', ['variante' => 'secondaire', 'icone' => 'crayon']);

    // Le code entier vit dans la carte « Accès du client », avec son bouton
    // « copier » : ici, sa partie lisible suffit à situer le dossier.
    echo enTetePage($societe['nom'],
        nomDuCode($societe['code']) . ' · ' . ($societe['ville'] ?: 'ville non renseignée'), $actions);

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    if ($acces)  echo '<div class="mb-4">' . encadreIdentifiants($acces) . '</div>';
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="space-y-5 lg:col-span-2">

        <?= carteDebut('Décision', ['sous_titre' => 'Acceptation, essai, abonnement et accès']) ?>
          <div class="mb-4 flex flex-wrap items-center gap-3">
            <?= badgeSituation($societe) ?>
            <span class="text-sm text-slate-500">
              <?= e($societe['formule'] === 'PRO' ? 'Formule Pro' : 'Formule Essai') ?>
              <?php $fin = $societe['formule'] === 'ESSAI' ? $societe['essai_fin'] : $societe['abonnement_fin']; ?>
              <?= $fin ? ' · jusqu\'au ' . e(dateFr($fin)) : ' · sans date de fin' ?>
            </span>
          </div>

          <?php if ($situation === 'DEMANDE'): ?>
            <div class="grid gap-4 sm:grid-cols-2">
              <form method="post" class="rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
                <?= champCsrf() ?><input type="hidden" name="_action" value="accepter">
                <p class="mb-3 text-sm font-medium text-emerald-900">
                  Accepter : l'espace est installé et l'essai de <?= e(dureeEssai()) ?> jours démarre.
                </p>
                <?= champBloc('Identifiant du compte', saisie('username', 'admin'), ['classe' => 'mb-3']) ?>
                <?= champBloc('Nom du responsable', saisie('admin_nom', $societe['contact_nom'] ?? ''), ['classe' => 'mb-3']) ?>
                <?= bouton('Accepter la société', ['variante' => 'succes', 'classe' => 'w-full']) ?>
              </form>

              <form method="post" class="rounded-xl bg-rose-50 p-4 ring-1 ring-rose-200">
                <?= champCsrf() ?><input type="hidden" name="_action" value="refuser">
                <p class="mb-3 text-sm font-medium text-rose-900">Refuser la demande.</p>
                <?= champBloc('Motif (conservé au dossier)', zoneTexte('motif', '', ['lignes' => 3]), ['classe' => 'mb-3']) ?>
                <?= bouton('Refuser', ['variante' => 'danger', 'classe' => 'w-full']) ?>
              </form>
            </div>
          <?php else: ?>
            <div class="grid gap-4 sm:grid-cols-2">
              <form method="post" class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <?= champCsrf() ?><input type="hidden" name="_action" value="pro">
                <p class="mb-1 text-sm font-medium text-slate-800">Passer en Pro</p>
                <?php if ($societe['abonnement_choisi']): ?>
                  <p class="mb-3 text-xs text-slate-500">
                    Formule demandée : <strong><?= e(libelleAbonnement($societe['abonnement_choisi'])) ?></strong>
                  </p>
                <?php endif; ?>

                <div class="mb-3 space-y-1.5">
                  <?php foreach (abonnements() as $cle => $formule):
                    $choisie = ($societe['abonnement_choisi'] ?: '1a') === $cle; ?>
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-emerald-500">
                      <input type="radio" name="abonnement" value="<?= e($cle) ?>"<?= $choisie ? ' checked' : '' ?>
                             class="size-4 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                      <span class="flex-1"><?= e($formule['libelle']) ?></span>
                      <span class="font-medium text-slate-700"><?= e(montant($formule['prix'])) ?></span>
                    </label>
                  <?php endforeach; ?>
                  <label class="flex cursor-pointer items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-emerald-500">
                    <input type="radio" name="abonnement" value=""
                           class="size-4 border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="flex-1 text-slate-500">Date choisie à la main</span>
                  </label>
                </div>

                <?= champBloc('Fin d\'abonnement', saisie('abonnement_fin', dateInput($societe['abonnement_fin']), ['type' => 'date']),
                    ['aide' => 'Utilisée seulement avec « à la main ». Vide = sans date de fin.', 'classe' => 'mb-3']) ?>
                <?= bouton('Activer la formule Pro', ['variante' => 'succes', 'classe' => 'w-full']) ?>
              </form>

              <form method="post" class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                <?= champCsrf() ?><input type="hidden" name="_action" value="prolonger">
                <p class="mb-3 text-sm font-medium text-slate-800">Prolonger l'essai</p>
                <?= champBloc('Nombre de jours', saisie('jours', dureeEssai(), ['type' => 'number', 'attrs' => ['min' => 1, 'max' => 365]]),
                    ['aide' => 'Ajoutés à la date de fin si l\'essai court encore.', 'classe' => 'mb-3']) ?>
                <?= bouton('Prolonger', ['classe' => 'w-full']) ?>
              </form>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
              <?php if ($societe['statut'] === 'SUSPENDUE'): ?>
                <?= actionBouton('reactiver', [], 'Réactiver l\'accès', ['variante' => 'succes']) ?>
              <?php else: ?>
                <?= actionBouton('suspendre', [], 'Suspendre l\'accès', ['variante' => 'danger',
                    'confirmation' => 'Suspendre l\'accès de cette société ? Ses données sont conservées.']) ?>
              <?php endif; ?>

              <?php if (empty($societe['base'])): ?>
                <?= actionBouton('provisionner', ['username' => 'admin'], 'Installer l\'espace') ?>
              <?php else: ?>
                <?= actionBouton('motdepasse', ['username' => $societe['admin_username'] ?: 'admin'],
                    'Réinitialiser le mot de passe', ['variante' => 'secondaire',
                    'confirmation' => 'Générer un nouveau mot de passe pour le compte administrateur ?']) ?>
              <?php endif; ?>

              <?= actionBouton('retirer', [], 'Retirer du service', ['variante' => 'fantome',
                  'confirmation' => 'Retirer cette société du service ? Sa base de données est conservée.']) ?>
            </div>
          <?php endif; ?>
        <?= carteFin() ?>

        <?= carteDebut('Ce que contient l\'espace', ['sous_titre' => 'Lu dans la base ' . ($societe['base'] ?: '—')]) ?>
          <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <?= tuile('Utilisateurs', nombre($stats['utilisateurs'])) ?>
            <?= tuile('Articles', nombre($stats['articles'])) ?>
            <?= tuile('Clients / fournisseurs', nombre($stats['tiers'])) ?>
            <?= tuile('Documents', nombre($stats['documents'])) ?>
            <?= tuile('Chiffre d\'affaires', montant($stats['ca'])) ?>
            <?= tuile('Poids des données', poids((int) $stats['taille']), [
                'detail' => $stats['derniere_activite'] ? 'Dernière écriture ' . dateFr($stats['derniere_activite']) : 'Aucune écriture']) ?>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Comptes de la société', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Nom', 'Identifiant', 'Rôle', 'État', 'Dernière connexion']) ?>
            <?php foreach ($comptes as $c): ?>
              <tr>
                <td class="<?= CLASSES_TD ?>"><?= e($c['nom']) ?></td>
                <td class="<?= CLASSES_TD ?> font-mono text-xs"><?= e($c['username']) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= e(ROLES[$c['role']] ?? $c['role']) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= $c['actif'] ? badge('Actif', 'vert') : badge('Désactivé', 'gris') ?></td>
                <td class="<?= CLASSES_TD ?>"><?= $c['dernier_login'] ? e(dateHeureFr($c['dernier_login'])) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$comptes) echo ligneVide(5, 'Espace pas encore installé'); ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <?= carteDebut('Journal du dossier', ['sans_padding' => true]) ?>
          <?= tableauDebut(['Quand', 'Action', 'Détail', 'Par']) ?>
            <?php foreach ($journal as $ligne): ?>
              <tr>
                <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e(dateHeureFr($ligne['created_at'])) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= badge($ligne['action']) ?></td>
                <td class="<?= CLASSES_TD ?>"><?= e($ligne['detail'] ?: '—') ?></td>
                <td class="<?= CLASSES_TD ?>"><?= e($ligne['acteur'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$journal) echo ligneVide(4, 'Aucune action'); ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut('Le dossier') ?>
          <?= ligneInfo('Base de données', $societe['base'] ?: 'non installée') ?>
          <?= ligneInfo('Contact', $societe['contact_nom']) ?>
          <?= ligneInfo('Téléphone', $societe['contact_telephone']) ?>
          <?= ligneInfo('E-mail', $societe['contact_email']) ?>
          <?= ligneInfo('Ville', $societe['ville']) ?>
          <?= ligneInfo('Activité', $societe['activite']) ?>
          <?= ligneInfo('Demande reçue le', dateHeureFr($societe['created_at'])) ?>
          <?= ligneInfo('Acceptée le', $societe['accepte_le'] ? dateHeureFr($societe['accepte_le']) : null) ?>
          <?= ligneInfo('Compte administrateur', $societe['admin_username']) ?>
          <?= ligneInfo('Formule', $societe['abonnement_choisi']
              ? libelleAbonnement($societe['abonnement_choisi'])
              : ($societe['formule'] === 'PRO' ? 'Pro, sans formule tarifée' : null)) ?>
          <?php if ($societe['motif_refus']): ?>
            <?= ligneInfo('Motif du refus', $societe['motif_refus']) ?>
          <?php endif; ?>
        <?= carteFin() ?>

        <?php if ($societe['message']): ?>
          <?= carteDebut('Message du demandeur') ?>
            <p class="text-sm whitespace-pre-line text-slate-700"><?= e($societe['message']) ?></p>
          <?= carteFin() ?>
        <?php endif; ?>

        <?= carteDebut('Accès du client') ?>
          <p class="mb-1.5 text-sm font-medium text-slate-700">Code société</p>
          <?= champCopiable($societe['code']) ?>

          <p class="mt-4 mb-1.5 text-sm font-medium text-slate-700">Lien de connexion direct</p>
          <?= champCopiable(urlAbsolue('/login?e=' . rawurlencode($societe['code']))) ?>
          <p class="mt-2 text-xs text-slate-500">
            Ce lien remplit le code à la place du client — il n'a plus que son identifiant et son
            mot de passe à saisir, et le code reste ensuite mémorisé sur son appareil.
          </p>

          <div class="mt-4 border-t border-slate-100 pt-3">
            <?= actionBouton('code', [], 'Régénérer le code', ['variante' => 'fantome', 'classe' => 'w-full',
                'confirmation' => "Générer un nouveau code société ? Les liens et le code déjà transmis "
                    . "cesseront de fonctionner. Les données de la société ne sont pas touchées."]) ?>
            <p class="mt-1 text-xs text-slate-500">
              À utiliser si le code a circulé là où il ne devait pas.
            </p>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Notes internes') ?>
          <form method="post" class="space-y-3">
            <?= champCsrf() ?><input type="hidden" name="_action" value="notes">
            <?= zoneTexte('notes', $societe['notes'], ['lignes' => 6]) ?>
            <?= bouton('Enregistrer', ['variante' => 'secondaire', 'classe' => 'w-full']) ?>
          </form>
        <?= carteFin() ?>
      </div>
    </div>
<?php });
