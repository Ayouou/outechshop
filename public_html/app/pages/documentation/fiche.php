<?php
/** Lecture d'une fiche de documentation. */

exigerSession();

$fiche = un(
    'SELECT d.*, t.nom AS tiers_nom, a.nom AS article_nom,
            doc.numero AS document_numero, doc.type AS document_type, us.nom AS user_nom
       FROM documentation d
       LEFT JOIN tiers t ON t.id = d.tiers_id
       LEFT JOIN article a ON a.id = d.article_id
       LEFT JOIN document doc ON doc.id = d.document_id
       LEFT JOIN utilisateur us ON us.id = d.user_id
      WHERE d.id = ?',
    [$params['id']],
);
if (!$fiche) introuvable('Fiche introuvable');

const COULEURS_DOCUMENTATION = [
    'NOTE' => 'gris', 'PROCEDURE' => 'bleu', 'CONTRAT' => 'violet',
    'FICHE_TECHNIQUE' => 'vert', 'AUTRE' => 'orange',
];

page($fiche['titre'], function () use ($fiche) {
    echo enTetePage(
        $fiche['titre'],
        (TYPES_DOCUMENTATION[$fiche['type']] ?? $fiche['type'])
        . ($fiche['categorie'] ? ' · ' . $fiche['categorie'] : '')
        . ' · mise à jour le ' . dateFr($fiche['updated_at']),
        boutonLien('/documentation', 'Retour', ['variante' => 'secondaire', 'icone' => 'fleche-g'])
        . boutonLien('/documentation/' . $fiche['id'] . '/modifier', 'Modifier', ['icone' => 'crayon']),
    );
    ?>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Contenu', ['actions' => badge(TYPES_DOCUMENTATION[$fiche['type']] ?? $fiche['type'], COULEURS_DOCUMENTATION[$fiche['type']] ?? 'gris')]) ?>
          <?php if ($fiche['contenu']): ?>
            <div class="whitespace-pre-wrap text-sm leading-relaxed text-slate-700"><?= e($fiche['contenu']) ?></div>
          <?php else: ?>
            <p class="text-sm text-slate-400">Cette fiche ne contient pas de texte.</p>
          <?php endif; ?>

          <?php if ($fiche['lien_url']): ?>
            <p class="mt-4">
              <a href="<?= e($fiche['lien_url']) ?>" target="_blank" rel="noopener noreferrer"
                 class="text-marine-600 hover:underline break-all"><?= e($fiche['lien_url']) ?> →</a>
            </p>
          <?php endif; ?>
        <?= carteFin() ?>
      </div>

      <?= carteDebut('Rattachements') ?>
        <dl class="space-y-2 text-sm">
          <?php if ($fiche['tiers_id']): ?>
            <div class="flex justify-between gap-3 border-b border-slate-100 pb-2">
              <dt class="text-slate-500">Tiers</dt>
              <dd><a href="<?= e(u('/tiers/' . $fiche['tiers_id'])) ?>" class="text-marine-600 hover:underline"><?= e($fiche['tiers_nom']) ?></a></dd>
            </div>
          <?php endif; ?>
          <?php if ($fiche['article_id']): ?>
            <div class="flex justify-between gap-3 border-b border-slate-100 pb-2">
              <dt class="text-slate-500">Article</dt>
              <dd><a href="<?= e(u('/stock/articles/' . $fiche['article_id'])) ?>" class="text-marine-600 hover:underline"><?= e($fiche['article_nom']) ?></a></dd>
            </div>
          <?php endif; ?>
          <?php if ($fiche['document_id']): ?>
            <div class="flex justify-between gap-3 border-b border-slate-100 pb-2">
              <dt class="text-slate-500">Document</dt>
              <dd><a href="<?= e(u(urlDocument($fiche['document_type'], $fiche['document_id']))) ?>" class="text-marine-600 hover:underline"><?= e($fiche['document_numero']) ?></a></dd>
            </div>
          <?php endif; ?>
          <div class="flex justify-between gap-3">
            <dt class="text-slate-500">Auteur</dt>
            <dd class="font-medium"><?= e($fiche['user_nom'] ?? '—') ?></dd>
          </div>
        </dl>
      <?= carteFin() ?>
    </div>
<?php });
