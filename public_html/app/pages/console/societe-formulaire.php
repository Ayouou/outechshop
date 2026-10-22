<?php
/**
 * Console — création d'une société par la plateforme, ou modification de son
 * dossier. Créer ici revient à saisir la demande à la place du client : rien
 * n'est installé tant que la société n'est pas acceptée.
 */

exigerConsole();

$societe = isset($params['id']) ? un('SELECT * FROM saas_societe WHERE id = ?', [$params['id']]) : null;
if (isset($params['id']) && !$societe) introuvable('Société introuvable');

$nouvelle = $societe === null;

$valeurs = [
    'nom'               => $societe['nom'] ?? '',
    'code'              => $societe['code'] ?? '',
    'base'              => $societe['base'] ?? '',
    'contact_nom'       => $societe['contact_nom'] ?? '',
    'contact_email'     => $societe['contact_email'] ?? '',
    'contact_telephone' => $societe['contact_telephone'] ?? '',
    'ville'             => $societe['ville'] ?? '',
    'activite'          => $societe['activite'] ?? '',
    'notes'             => $societe['notes'] ?? '',
];

$erreur = null;
$installer = false;

if (estPost()) {
    foreach (array_keys($valeurs) as $cle) $valeurs[$cle] = (string) champ($cle, '');
    $installer = $nouvelle && champBool('installer');

    // Le code est tiré à la création (nom + 24 chiffres) et ne bouge plus :
    // c'est la clé que le client tape pour entrer, la changer le mettrait
    // dehors. Modifier le nom de la société ne le régénère donc pas.
    $valeurs['code'] = $nouvelle ? codeSociete($valeurs['nom']) : $societe['code'];

    if ($valeurs['nom'] === '') {
        $erreur = 'Le nom de la société est requis.';
    } elseif ($valeurs['base'] !== '' && !baseValide($valeurs['base'])) {
        $erreur = 'Nom de base invalide : lettres, chiffres et tirets bas uniquement.';
    } elseif ($nouvelle && $installer && !placeDisponible()) {
        $erreur = 'Plafond de ' . reglage('max_societes') . ' sociétés atteint : relevez-le dans Réglages.';
    } else {
        $donnees = [
            'nom'               => $valeurs['nom'],
            'code'              => $valeurs['code'],
            'base'              => ouNull($valeurs['base']),
            'contact_nom'       => ouNull($valeurs['contact_nom']),
            'contact_email'     => ouNull($valeurs['contact_email']),
            'contact_telephone' => ouNull($valeurs['contact_telephone']),
            'ville'             => ouNull($valeurs['ville']),
            'activite'          => ouNull($valeurs['activite']),
            'notes'             => ouNull($valeurs['notes']),
        ];

        if ($nouvelle) {
            $id = inserer('saas_societe', $donnees + ['statut' => 'DEMANDE', 'formule' => 'ESSAI']);
            journaliser('creation', $id, 'Société créée depuis la console.');

            if ($installer) {
                try {
                    $creee = un('SELECT * FROM saas_societe WHERE id = ?', [$id]);
                    $acces = accepterSociete($creee, ['username' => (string) champ('username', 'admin')]);
                    if ($acces['motdepasse']) {
                        $_SESSION['acces_cree'] = $acces + ['code' => $creee['code']];
                    }
                    rediriger('/console/societes/' . $id,
                        'Société créée et espace installé — essai de ' . dureeEssai() . ' jours.');
                } catch (Throwable $e) {
                    rediriger('/console/societes/' . $id,
                        "Société enregistrée, mais l'espace n'a pas pu être installé : " . $e->getMessage(), 'erreur');
                }
            }

            rediriger('/console/societes/' . $id, 'Société enregistrée — reste à l\'accepter.');
        }

        modifier('saas_societe', $societe['id'], $donnees);
        journaliser('modification', $societe['id'], 'Dossier modifié.');
        rediriger('/console/societes/' . $societe['id'], 'Dossier mis à jour.');
    }
}

pageConsole($nouvelle ? 'Nouvelle société' : 'Modifier le dossier',
    function () use ($valeurs, $erreur, $nouvelle, $societe, $installer) {
    echo enTetePage(
        $nouvelle ? 'Nouvelle société' : 'Modifier · ' . $societe['nom'],
        $nouvelle ? 'La société entre au registre ; l\'espace est installé à l\'acceptation.' : null,
        boutonLien($nouvelle ? '/console/societes' : '/console/societes/' . $societe['id'], 'Retour', ['variante' => 'secondaire']),
    );

    if ($erreur) echo '<div class="mb-4">' . alerte(e($erreur), 'erreur') . '</div>';
    ?>

    <form method="post" class="max-w-3xl space-y-5">
      <?= champCsrf() ?>

      <?= carteDebut('La société') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <?= champBloc('Nom de la société', saisie('nom', $valeurs['nom'], [
              'attrs' => ['required' => true],
          ]), ['obligatoire' => true]) ?>
          <?= champBloc('Ville', saisie('ville', $valeurs['ville'])) ?>
          <?= champBloc('Activité', saisie('activite', $valeurs['activite']),
              ['aide' => 'Épicerie, quincaillerie, prêt-à-porter…', 'classe' => 'sm:col-span-2']) ?>
        </div>

        <div class="mt-4 border-t border-slate-100 pt-4">
          <p class="mb-1.5 text-sm font-medium text-slate-700">Code société</p>
          <?php if ($nouvelle): ?>
            <p class="text-sm text-slate-500">
              Généré à l'enregistrement : le nom de la société suivi de
              <?= CHIFFRES_CODE ?> chiffres tirés au sort. Il s'affichera ici avec un bouton pour le copier.
            </p>
          <?php else: ?>
            <?= champCopiable($societe['code']) ?>
            <p class="mt-1.5 text-xs text-slate-500">
              C'est la clé que le client saisit pour entrer : elle ne change pas, même si le nom
              de la société est corrigé.
            </p>
          <?php endif; ?>
        </div>
      <?= carteFin() ?>

      <?= carteDebut('Le contact') ?>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <?= champBloc('Responsable', saisie('contact_nom', $valeurs['contact_nom'])) ?>
          <?= champBloc('Téléphone', saisie('contact_telephone', $valeurs['contact_telephone'])) ?>
          <?= champBloc('E-mail', saisie('contact_email', $valeurs['contact_email'], ['type' => 'email'])) ?>
        </div>
      <?= carteFin() ?>

      <?= carteDebut('Technique', ['sous_titre' => 'À laisser vide dans presque tous les cas']) ?>
        <?= champBloc('Base de données', saisie('base', $valeurs['base'], ['attrs' => ['placeholder' => nomBasePour($valeurs['code'] ?: 'code')]]),
            ['aide' => 'Vide : le nom est dérivé du code société. À renseigner si la base a été créée à la main dans le panneau de l\'hébergeur.']) ?>
      <?= carteFin() ?>

      <?= carteDebut('Notes internes') ?>
        <?= zoneTexte('notes', $valeurs['notes'], ['lignes' => 4]) ?>
      <?= carteFin() ?>

      <?php if ($nouvelle): ?>
        <?= carteDebut('Mise en service') ?>
          <?= caseACocher('installer', $installer, 'Installer l\'espace tout de suite et démarrer l\'essai de ' . dureeEssai() . ' jours') ?>
          <div class="mt-4 max-w-xs">
            <?= champBloc('Identifiant du compte administrateur', saisie('username', 'admin'),
                ['aide' => 'Le mot de passe est généré et affiché une seule fois.']) ?>
          </div>
        <?= carteFin() ?>
      <?php endif; ?>

      <div class="flex justify-end gap-2">
        <?= boutonLien($nouvelle ? '/console/societes' : '/console/societes/' . $societe['id'], 'Annuler', ['variante' => 'secondaire']) ?>
        <?= bouton($nouvelle ? 'Enregistrer la société' : 'Enregistrer') ?>
      </div>
    </form>

<?php });
