<?php
/** Comptes utilisateurs (réservé à l'administrateur). */

$session = exigerRole('ADMIN');

if (estPost()) {
    $id = (string) champ('id', '');
    $action = (string) champ('_action', '');

    // On ne se désactive ni ne se supprime soi-même : plus personne ne pourrait
    // administrer l'application.
    if ($id === $session['user_id']) {
        rediriger('/utilisateurs', 'Vous ne pouvez pas modifier votre propre compte ici.', 'erreur');
    }

    if ($action === 'basculer') {
        $actif = valeur('SELECT actif FROM utilisateur WHERE id = ?', [$id]);
        if ($actif !== null) modifier('utilisateur', $id, ['actif' => $actif ? 0 : 1]);
        rediriger('/utilisateurs', 'Statut mis à jour.');
    }

    if ($action === 'supprimer') {
        supprimer('utilisateur', $id);
        rediriger('/utilisateurs', 'Utilisateur supprimé.');
    }
}

$utilisateurs = tous(
    'SELECT us.*, (SELECT GROUP_CONCAT(l.nom ORDER BY l.nom SEPARATOR ", ")
                     FROM utilisateur_lieu ul JOIN lieu_affaire l ON l.id = ul.lieu_id
                    WHERE ul.user_id = us.id) AS lieux
       FROM utilisateur us ORDER BY us.nom',
);

page('Les utilisateurs', function () use ($utilisateurs, $session) {
    echo enTetePage('Les utilisateurs', 'Comptes, rôles et lieux d\'affaires',
        boutonLien('/utilisateurs/nouveau', 'Ajouter', ['icone' => 'plus']));

    echo carteDebut(null, ['sans_padding' => true]);
    echo tableauDebut(['Action', 'Nom', 'Identifiant', 'Rôle', 'Lieux', 'Dernière connexion', 'Statut']);

    if (!$utilisateurs) echo ligneVide(7);

    foreach ($utilisateurs as $utilisateur) {
        $soi = $utilisateur['id'] === $session['user_id'];
        $elements = [lienMenu('/utilisateurs/' . $utilisateur['id'], 'Modifier', 'crayon')];

        if (!$soi) {
            $elements[] = actionMenu('basculer', ['id' => $utilisateur['id']],
                $utilisateur['actif'] ? 'Désactiver' : 'Activer', ['icone' => 'flux']);
            $elements[] = actionMenu('supprimer', ['id' => $utilisateur['id']], 'Effacer', [
                'icone' => 'poubelle', 'classe' => 'text-rose-600',
                'confirmation' => "Supprimer le compte « {$utilisateur['nom']} » ?",
            ]);
        }
        ?>
        <tr class="hover:bg-slate-50">
          <td class="<?= CLASSES_TD ?>"><?= menuActions($elements) ?></td>
          <td class="<?= CLASSES_TD ?>">
            <a href="<?= e(u('/utilisateurs/' . $utilisateur['id'])) ?>" class="font-medium text-marine-600 hover:underline"><?= e($utilisateur['nom']) ?></a>
            <?php if ($soi) echo ' ' . badge('Vous', 'bleu'); ?>
          </td>
          <td class="<?= CLASSES_TD ?> font-mono text-xs text-slate-500"><?= e($utilisateur['username']) ?></td>
          <td class="<?= CLASSES_TD ?>"><?= e(ROLES[$utilisateur['role']] ?? $utilisateur['role']) ?></td>
          <td class="<?= CLASSES_TD ?> max-w-56 truncate text-slate-500"><?= e($utilisateur['lieux'] ?? 'Tous') ?></td>
          <td class="<?= CLASSES_TD ?> whitespace-nowrap text-slate-500"><?= e($utilisateur['dernier_login'] ? dateHeureFr($utilisateur['dernier_login']) : '—') ?></td>
          <td class="<?= CLASSES_TD ?>"><?= badge($utilisateur['actif'] ? 'Actif' : 'Inactif', $utilisateur['actif'] ? 'vert' : 'gris') ?></td>
        </tr>
    <?php }

    echo tableauFin();
    echo carteFin();
});
