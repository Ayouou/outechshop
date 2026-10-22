<?php
/** Paramètres : société et lieux d'affaires. */

$session = exigerRole('ADMIN', 'MANAGER');

$erreur = null;

if (estPost()) {
    $action = (string) champ('_action', '');

    if ($action === 'societe') {
        $nom = (string) champ('nom', '');
        $email = ouNull(champ('email'));

        if ($nom === '') {
            $erreur = 'Le nom de la société est obligatoire';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = 'E-mail invalide';
        } else {
            $donnees = [
                'nom'               => $nom,
                'ice'               => ouNull(champ('ice')),
                'rc'                => ouNull(champ('rc')),
                'identifiant_fiscal'=> ouNull(champ('identifiant_fiscal')),
                'cnss'              => ouNull(champ('cnss')),
                'patente'           => ouNull(champ('patente')),
                'adresse'           => ouNull(champ('adresse')),
                'ville'             => ouNull(champ('ville')),
                'telephone'         => ouNull(champ('telephone')),
                'email'             => $email,
                'site_web'          => ouNull(champ('site_web')),
                'devise'            => champ('devise') ?: 'dhs',
                'tva_defaut'        => min(100, max(0, champNombre('tva_defaut', 20))),
                'pied_facture'      => ouNull(champ('pied_facture')),
            ];

            $existante = un('SELECT id FROM societe ORDER BY created_at LIMIT 1');
            if ($existante) modifier('societe', $existante['id'], $donnees);
            else inserer('societe', $donnees);

            rediriger('/parametres', 'Paramètres enregistrés.');
        }
    }

    if ($action === 'lieu' && $session['role'] === 'ADMIN') {
        $code = (string) champ('code', '');
        $nom = (string) champ('nom_lieu', '');

        if ($code === '' || mb_strlen($nom) < 2) {
            $erreur = 'Le code et le nom du lieu sont obligatoires';
        } else {
            $donnees = [
                'code'      => $code,
                'nom'       => $nom,
                'ville'     => ouNull(champ('ville_lieu')),
                'adresse'   => ouNull(champ('adresse_lieu')),
                'telephone' => ouNull(champ('telephone_lieu')),
            ];
            try {
                $lieuId = ouNull(champ('lieu_id'));
                if ($lieuId) modifier('lieu_affaire', $lieuId, $donnees);
                else inserer('lieu_affaire', $donnees + ['actif' => 1]);
                rediriger('/parametres', "Lieu d'affaires enregistré.");
            } catch (PDOException $e) {
                $erreur = "Ce code de lieu d'affaires est déjà utilisé.";
            }
        }
    }

    if ($action === 'supprimer-lieu' && $session['role'] === 'ADMIN') {
        $lieuId = (string) champ('lieu_id', '');
        $documents = (int) valeur('SELECT COUNT(*) FROM document WHERE lieu_id = ?', [$lieuId]);
        if ($documents > 0) {
            // Un lieu rattaché à des documents est désactivé, jamais supprimé.
            modifier('lieu_affaire', $lieuId, ['actif' => 0]);
            rediriger('/parametres', 'Lieu rattaché à des documents : il a été désactivé.', 'attention');
        }
        supprimer('lieu_affaire', $lieuId);
        rediriger('/parametres', 'Lieu supprimé.');
    }
}

$societe = un('SELECT * FROM societe ORDER BY created_at LIMIT 1') ?? [
    'nom' => 'OUTECH', 'ice' => '', 'rc' => '', 'identifiant_fiscal' => '', 'cnss' => '',
    'patente' => '', 'adresse' => '', 'ville' => '', 'telephone' => '', 'email' => '',
    'site_web' => '', 'devise' => 'dhs', 'tva_defaut' => '20', 'pied_facture' => '',
];

$lieux = tous(
    'SELECT l.*, (SELECT COUNT(*) FROM document d WHERE d.lieu_id = l.id) AS documents
       FROM lieu_affaire l ORDER BY l.nom',
);

page('Paramètres', function () use ($societe, $lieux, $erreur, $session) {
    echo enTetePage('Paramètres', 'Société, mentions légales et lieux d\'affaires');

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Société', ['sous_titre' => 'Ces informations figurent en tête des documents imprimés']) ?>
          <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <?= champCsrf() ?>
            <input type="hidden" name="_action" value="societe">
            <?= champBloc('Raison sociale', saisie('nom', $societe['nom'], ['attrs' => ['required' => true]]), ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
            <?= champBloc('ICE', saisie('ice', $societe['ice'])) ?>
            <?= champBloc('RC', saisie('rc', $societe['rc'])) ?>
            <?= champBloc('Identifiant fiscal', saisie('identifiant_fiscal', $societe['identifiant_fiscal'])) ?>
            <?= champBloc('Patente', saisie('patente', $societe['patente'])) ?>
            <?= champBloc('CNSS', saisie('cnss', $societe['cnss'])) ?>
            <?= champBloc('Téléphone', saisie('telephone', $societe['telephone'])) ?>
            <?= champBloc('E-mail', saisie('email', $societe['email'], ['type' => 'email'])) ?>
            <?= champBloc('Site web', saisie('site_web', $societe['site_web'])) ?>
            <?= champBloc('Ville', saisie('ville', $societe['ville'])) ?>
            <?= champBloc('Adresse', saisie('adresse', $societe['adresse'])) ?>
            <?= champBloc('Devise', saisie('devise', $societe['devise']), ['aide' => 'Affichée après chaque montant']) ?>
            <?= champBloc('TVA par défaut (%)', saisie('tva_defaut', num($societe['tva_defaut']), ['type' => 'number', 'attrs' => ['step' => '0.01', 'min' => 0, 'max' => 100]])) ?>
            <?= champBloc('Pied de facture', zoneTexte('pied_facture', $societe['pied_facture'], ['lignes' => 3, 'attrs' => ['placeholder' => 'Mentions légales, conditions de règlement…']]), ['classe' => 'sm:col-span-2']) ?>
            <div class="sm:col-span-2 flex justify-end"><?= bouton('Enregistrer') ?></div>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut("Lieux d'affaires", ['sans_padding' => true]) ?>
          <?= tableauDebut(['Lieu', ['libelle' => 'Documents', 'classe' => 'text-right'], '']) ?>
          <?php if (!$lieux) echo ligneVide(3); ?>
          <?php foreach ($lieux as $lieu): ?>
            <tr class="hover:bg-slate-50">
              <td class="<?= CLASSES_TD ?>">
                <span class="font-medium text-slate-800"><?= e($lieu['nom']) ?></span>
                <span class="block text-xs text-slate-400"><?= e($lieu['code'] . ($lieu['ville'] ? ' · ' . $lieu['ville'] : '')) ?></span>
                <?php if (!$lieu['actif']) echo badge('Inactif', 'gris'); ?>
              </td>
              <td class="<?= CLASSES_TD ?> text-right"><?= (int) $lieu['documents'] ?></td>
              <td class="<?= CLASSES_TD ?> text-right">
                <?php if ($session['role'] === 'ADMIN'): ?>
                  <?= actionBouton('supprimer-lieu', ['lieu_id' => $lieu['id']], 'Effacer', [
                      'variante' => 'fantome', 'classe' => 'text-rose-600',
                      'confirmation' => "Supprimer « {$lieu['nom']} » ? S'il porte des documents, il sera seulement désactivé.",
                  ]) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?= tableauFin() ?>
        <?= carteFin() ?>

        <?php if ($session['role'] === 'ADMIN'): ?>
          <?= carteDebut("Nouveau lieu d'affaires") ?>
            <form method="post" class="space-y-3">
              <?= champCsrf() ?>
              <input type="hidden" name="_action" value="lieu">
              <?= champBloc('Code', saisie('code', '', ['attrs' => ['required' => true, 'placeholder' => 'DEPOT2']]), ['obligatoire' => true]) ?>
              <?= champBloc('Nom', saisie('nom_lieu', '', ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
              <?= champBloc('Ville', saisie('ville_lieu', '')) ?>
              <?= champBloc('Adresse', saisie('adresse_lieu', '')) ?>
              <?= champBloc('Téléphone', saisie('telephone_lieu', '')) ?>
              <?= bouton('Ajouter le lieu', ['classe' => 'w-full']) ?>
            </form>
          <?= carteFin() ?>
        <?php endif; ?>

        <?php if ($session['role'] === 'ADMIN'): ?>
          <?= carteDebut('Sauvegarde') ?>
            <p class="mb-3 text-sm text-slate-600">Export complet de la base dans un fichier JSON, et restauration à l'identique.</p>
            <?= boutonLien('/parametres/sauvegarde', 'Ouvrir la sauvegarde', ['variante' => 'secondaire', 'classe' => 'w-full']) ?>
          <?= carteFin() ?>
        <?php endif; ?>
      </div>
    </div>
<?php });
