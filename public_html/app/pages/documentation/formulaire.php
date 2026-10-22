<?php
/** Saisie d'une fiche de documentation. */

$session = exigerSession();

$id = $params['id'] ?? null;
$fiche = null;

if ($id) {
    $fiche = un('SELECT * FROM documentation WHERE id = ?', [$id]);
    if (!$fiche) introuvable('Fiche introuvable');
}

$erreur = null;
$v = $fiche ?? [
    'titre' => '', 'type' => 'NOTE', 'categorie' => '', 'contenu' => '', 'lien_url' => '',
    'tiers_id' => param('tiers', ''), 'article_id' => param('article', ''), 'document_id' => param('document', ''),
];

if (estPost()) {
    $donnees = [
        'titre'       => (string) champ('titre', ''),
        'type'        => isset(TYPES_DOCUMENTATION[champ('type')]) ? champ('type') : 'NOTE',
        'categorie'   => ouNull(champ('categorie')),
        'contenu'     => ouNull(champ('contenu')),
        'lien_url'    => ouNull(champ('lien_url')),
        'tiers_id'    => ouNull(champ('tiers_id')),
        'article_id'  => ouNull(champ('article_id')),
        'document_id' => ouNull(champ('document_id')),
    ];
    $v = array_merge($v, $donnees);

    if (mb_strlen($donnees['titre']) < 2) {
        $erreur = 'Le titre est obligatoire';
    } else {
        if ($fiche) {
            modifier('documentation', $fiche['id'], $donnees);
            rediriger('/documentation/' . $fiche['id'], 'Fiche enregistrée.');
        }
        $nouveau = inserer('documentation', $donnees + ['user_id' => $session['user_id']]);
        rediriger('/documentation/' . $nouveau, 'Fiche créée.');
    }
}

$tiers = tous('SELECT id, nom FROM tiers WHERE actif = 1 ORDER BY nom');
$articles = tous('SELECT id, nom FROM article WHERE actif = 1 ORDER BY nom');
$documents = tous('SELECT id, numero FROM document WHERE lieu_id = ? ORDER BY date DESC LIMIT 100', [$session['lieu_id']]);

page($fiche ? 'Modifier la fiche' : 'Nouvelle fiche', function () use ($fiche, $v, $erreur, $tiers, $articles, $documents) {
    echo enTetePage(
        $fiche ? 'Modifier « ' . $fiche['titre'] . ' »' : 'Nouvelle fiche',
        'Note, procédure, contrat ou fiche technique',
        boutonLien($fiche ? '/documentation/' . $fiche['id'] : '/documentation', 'Retour',
            ['variante' => 'secondaire', 'icone' => 'fleche-g']),
    );

    $optionsTiers = ['' => '— Aucun —'];
    foreach ($tiers as $t) $optionsTiers[$t['id']] = $t['nom'];
    $optionsArticles = ['' => '— Aucun —'];
    foreach ($articles as $a) $optionsArticles[$a['id']] = $a['nom'];
    $optionsDocuments = ['' => '— Aucun —'];
    foreach ($documents as $d) $optionsDocuments[$d['id']] = $d['numero'];
    ?>
    <div class="max-w-4xl">
      <form method="post" class="space-y-5">
        <?= champCsrf() ?>
        <?php if ($erreur) echo alerte(e($erreur), 'erreur'); ?>

        <?= carteDebut('Contenu') ?>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?= champBloc('Titre', saisie('titre', $v['titre'], ['attrs' => ['required' => true]]), ['obligatoire' => true, 'classe' => 'sm:col-span-2']) ?>
            <?= champBloc('Type', listeDeroulante('type', TYPES_DOCUMENTATION, $v['type'])) ?>
            <?= champBloc('Catégorie', saisie('categorie', $v['categorie'], ['attrs' => ['placeholder' => 'Qualité, RH, technique…']])) ?>
            <?= champBloc('Lien externe', saisie('lien_url', $v['lien_url'], ['attrs' => ['placeholder' => 'https://…']]), ['classe' => 'sm:col-span-2']) ?>
            <?= champBloc('Texte', zoneTexte('contenu', $v['contenu'], ['lignes' => 12]), ['classe' => 'sm:col-span-3']) ?>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Rattachement', ['sous_titre' => 'La fiche apparaît alors sur l\'élément concerné']) ?>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?= champBloc('Client / fournisseur', listeDeroulante('tiers_id', $optionsTiers, $v['tiers_id'])) ?>
            <?= champBloc('Article', listeDeroulante('article_id', $optionsArticles, $v['article_id'])) ?>
            <?= champBloc('Document', listeDeroulante('document_id', $optionsDocuments, $v['document_id'])) ?>
          </div>
        <?= carteFin() ?>

        <div class="flex justify-end"><?= bouton($fiche ? 'Enregistrer' : 'Créer la fiche') ?></div>
      </form>
    </div>
<?php });
