<?php
/**
 * Les trois gabarits du catalogue public.
 *
 * Chacun reçoit le même contexte et rend la page entière. Ils ne diffèrent pas
 * par la couleur — celle-ci vient des variables CSS et vaut pour les trois —
 * mais par la façon de présenter les articles : cartes, lignes ou images.
 *
 * Les couleurs s'écrivent `[var(--b-700)]` : la gamme est calculée en PHP à
 * partir de la teinte choisie, et Tailwind se contente de la poser.
 */

/** Le gabarit demandé, ou la vitrine par défaut. */
function rendreBoutique(string $theme, array $ctx): void
{
    match ($theme) {
        'liste'   => boutiqueListe($ctx),
        'galerie' => boutiqueGalerie($ctx),
        default   => boutiqueVitrine($ctx),
    };
}

/** Prix, ou la mention qui le remplace quand il est masqué. */
function prixBoutique(array $article, array $ctx, string $classes): string
{
    if (!$ctx['catalogue']['afficher_prix']) {
        return '<span class="text-sm text-slate-400">Prix sur demande</span>';
    }
    return '<span class="' . $classes . '">' . e(montant($article['prix_vente'], $ctx['devise'])) . '</span>';
}

/** Un article est-il en rupture ? Un article non suivi ne l'est jamais. */
function ruptureBoutique(array $article): bool
{
    return $article['gere_stock'] && num($article['stock']) <= 0;
}

/** Bouton de commande WhatsApp, ou rien : ni sans numéro, ni sur un article épuisé. */
function commanderBoutique(array $article, array $ctx, string $classes, bool $texte = true): string
{
    if (ruptureBoutique($article)) return '';
    $lien = ($ctx['commander'])($article);
    if (!$lien) return '';

    return '<a href="' . e($lien) . '" target="_blank" rel="noopener" class="' . $classes . '">'
        . icone('whatsapp', 'size-4') . ($texte ? ' Commander' : '')
        . '</a>';
}

/** Champ de recherche, identique partout, habillé selon le fond qui le porte. */
function rechercheBoutique(array $ctx, string $classes): string
{
    return '<form><input name="q" value="' . e($ctx['q']) . '" type="search"'
        . ' placeholder="Rechercher un article…" aria-label="Rechercher un article"'
        . ' class="' . $classes . '"></form>';
}

/** Pied de page : qui vend, où, et comment le joindre. */
function piedBoutique(array $ctx): void
{
    $catalogue = $ctx['catalogue'];
    $lien = ($ctx['commander'])();
    ?>
    <footer class="mt-auto border-t border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500">
      <p class="font-semibold text-slate-800"><?= e($ctx['societe']['nom'] ?? 'OUTECH') ?></p>
      <p class="mt-1"><?= e($catalogue['lieu_nom'] . ($catalogue['ville'] ? ' · ' . $catalogue['ville'] : '')) ?></p>
      <?php if ($catalogue['telephone']): ?>
        <p>Tél : <a class="hover:underline" href="tel:<?= e($catalogue['telephone']) ?>"><?= e($catalogue['telephone']) ?></a></p>
      <?php endif; ?>
      <?php if ($lien): ?>
        <p class="mt-5">
          <a href="<?= e($lien) ?>" target="_blank" rel="noopener"
             class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-4 py-2.5 font-medium text-white hover:opacity-90">
            <?= icone('whatsapp', 'size-4') ?> Nous écrire sur WhatsApp
          </a>
        </p>
      <?php endif; ?>
    </footer>
    <?php
}

/** Bouton flottant : commander reste à portée de pouce pendant qu'on défile. */
function flottantBoutique(array $ctx): void
{
    $lien = ($ctx['commander'])();
    if (!$lien) return;
    ?>
    <a href="<?= e($lien) ?>" target="_blank" rel="noopener" aria-label="Commander sur WhatsApp"
       class="fixed right-4 bottom-4 z-30 flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-3 font-medium text-white shadow-lg transition-transform hover:scale-105">
      <?= icone('whatsapp', 'size-6') ?><span class="hidden sm:inline">Commander</span>
    </a>
    <?php
}

/** Message affiché quand la recherche ne donne rien. */
function videBoutique(array $ctx): void
{
    ?>
    <p class="py-20 text-center text-slate-400">
      <?= $ctx['q'] !== ''
        ? 'Aucun article ne correspond à « ' . e($ctx['q']) . ' ».'
        : "Ce catalogue ne contient encore aucun article." ?>
    </p>
    <?php
}

// --------------------------------------------------------------- vitrine

/**
 * Bandeau coloré, puis les articles en cartes.
 *
 * Le thème qui ne trahit personne : la photo compte sans être reine, le prix
 * se lit, et la grille tient de deux à deux cents références.
 */
function boutiqueVitrine(array $ctx): void
{
    $catalogue = $ctx['catalogue'];
    ?>
<div class="flex min-h-screen flex-col bg-slate-50">
  <header class="bg-[var(--b-900)] px-4 py-12 text-[var(--b-contraste)]">
    <div class="mx-auto max-w-5xl">
      <h1 class="text-3xl font-bold tracking-tight sm:text-4xl"><?= e($catalogue['titre']) ?></h1>
      <?php if ($catalogue['sous_titre']): ?>
        <p class="mt-2 text-base opacity-75"><?= e($catalogue['sous_titre']) ?></p>
      <?php endif; ?>
      <div class="mt-7 max-w-md">
        <?= rechercheBoutique($ctx, 'w-full rounded-xl border-0 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none ring-2 ring-transparent focus:ring-white/40') ?>
      </div>
    </div>
  </header>

  <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-10">
    <?php if (!$ctx['articles']) { videBoutique($ctx); } else { ?>
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($ctx['articles'] as $article): $rupture = ruptureBoutique($article); ?>
          <article class="flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 transition-shadow hover:shadow-md">
            <div class="grid aspect-square place-items-center overflow-hidden bg-slate-50">
              <?php if ($article['image_url']): ?>
                <img src="<?= e(urlImage($article['image_url'])) ?>" alt="<?= e($article['nom']) ?>"
                     loading="lazy" class="size-full object-cover">
              <?php else: ?>
                <span class="text-3xl text-[var(--b-200)]">▦</span>
              <?php endif; ?>
            </div>
            <div class="flex flex-1 flex-col gap-1 p-3">
              <p class="text-sm font-medium text-slate-800"><?= e($article['nom']) ?></p>
              <?php if ($article['marque_nom']): ?>
                <p class="text-xs text-slate-400"><?= e($article['marque_nom']) ?></p>
              <?php endif; ?>
              <div class="mt-auto flex items-end justify-between gap-2 pt-2">
                <?= prixBoutique($article, $ctx, 'font-bold text-[var(--b-700)]') ?>
                <?php if ($article['gere_stock']): ?>
                  <span class="text-xs <?= $rupture ? 'text-rose-600' : 'text-emerald-600' ?>">
                    <?= $rupture ? 'Épuisé' : 'Disponible' ?>
                  </span>
                <?php endif; ?>
              </div>
              <?= commanderBoutique($article, $ctx,
                    'mt-2 flex items-center justify-center gap-1.5 rounded-lg bg-[#25D366] px-3 py-2 '
                  . 'text-sm font-medium text-white transition-opacity hover:opacity-90') ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php } ?>
  </main>

  <?php piedBoutique($ctx); flottantBoutique($ctx); ?>
</div>
    <?php
}

// ----------------------------------------------------------------- liste

/**
 * Une ligne par article, groupées par catégorie.
 *
 * Pour les catalogues qui se comptent en centaines : la recherche reste
 * collée en haut, et l'œil descend une colonne de prix alignés plutôt qu'une
 * mosaïque. Les catégories font les intertitres, eux aussi collants — on sait
 * toujours dans quel rayon on se trouve.
 */
function boutiqueListe(array $ctx): void
{
    $catalogue = $ctx['catalogue'];

    // Les articles arrivent triés par nom : on les regroupe sans retrier, pour
    // que l'ordre à l'intérieur d'un rayon reste alphabétique.
    $rayons = [];
    foreach ($ctx['articles'] as $article) {
        $rayons[$article['categorie_nom'] ?: 'Divers'][] = $article;
    }
    ksort($rayons, SORT_NATURAL | SORT_FLAG_CASE);
    ?>
<div class="flex min-h-screen flex-col bg-white">
  <header class="sticky top-0 z-20 border-t-4 border-[var(--b-600)] bg-white/95 shadow-sm backdrop-blur">
    <div class="mx-auto flex max-w-3xl flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="min-w-0">
        <h1 class="truncate text-lg font-bold text-slate-900"><?= e($catalogue['titre']) ?></h1>
        <?php if ($catalogue['sous_titre']): ?>
          <p class="truncate text-sm text-slate-500"><?= e($catalogue['sous_titre']) ?></p>
        <?php endif; ?>
      </div>
      <div class="sm:w-64 sm:shrink-0">
        <?= rechercheBoutique($ctx, 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-[var(--b-600)] focus:ring-1 focus:ring-[var(--b-600)]') ?>
      </div>
    </div>
  </header>

  <main class="mx-auto w-full max-w-3xl flex-1 px-4 pb-10">
    <?php if (!$ctx['articles']) { videBoutique($ctx); } else { ?>
      <?php foreach ($rayons as $rayon => $articles): ?>
        <h2 class="sticky top-[4.5rem] z-10 -mx-4 bg-[var(--b-50)] px-4 py-2 text-xs font-bold
                   tracking-wider text-[var(--b-800)] uppercase sm:top-20">
          <?= e($rayon) ?>
          <span class="font-normal opacity-60">· <?= count($articles) ?></span>
        </h2>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($articles as $article): $rupture = ruptureBoutique($article); ?>
            <li class="flex items-center gap-3 py-3">
              <div class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-50">
                <?php if ($article['image_url']): ?>
                  <img src="<?= e(urlImage($article['image_url'])) ?>" alt="" loading="lazy" class="size-full object-cover">
                <?php else: ?>
                  <span class="text-[var(--b-200)]">▦</span>
                <?php endif; ?>
              </div>

              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-800"><?= e($article['nom']) ?></p>
                <p class="truncate text-xs text-slate-400">
                  <?= e(implode(' · ', array_filter([$article['marque_nom'], $article['reference']]))) ?>
                </p>
                <?php if ($rupture): ?>
                  <p class="text-xs font-medium text-rose-600">Épuisé</p>
                <?php endif; ?>
              </div>

              <div class="flex shrink-0 items-center gap-2 text-right">
                <?= prixBoutique($article, $ctx, 'text-sm font-bold whitespace-nowrap text-[var(--b-700)]') ?>
                <?= commanderBoutique($article, $ctx,
                      'grid size-9 place-items-center rounded-lg bg-[#25D366] text-white '
                    . 'transition-opacity hover:opacity-90', false) ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php } ?>
  </main>

  <?php piedBoutique($ctx); flottantBoutique($ctx); ?>
</div>
    <?php
}

// --------------------------------------------------------------- galerie

/**
 * Grandes images, texte au strict nécessaire.
 *
 * Ici la photo occupe toute la carte et le texte se pose dessus : c'est le
 * thème des ateliers, où l'on montre un travail plutôt qu'un article. La
 * couleur ne sert qu'aux détails — un filet, un prix, le titre.
 */
function boutiqueGalerie(array $ctx): void
{
    $catalogue = $ctx['catalogue'];
    ?>
<div class="flex min-h-screen flex-col bg-white">
  <header class="border-b border-slate-100 px-4 pt-16 pb-10 text-center">
    <div class="mx-auto max-w-3xl">
      <span class="mx-auto mb-6 block h-1 w-12 rounded-full bg-[var(--b-600)]"></span>
      <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl"><?= e($catalogue['titre']) ?></h1>
      <?php if ($catalogue['sous_titre']): ?>
        <p class="mx-auto mt-4 max-w-xl text-lg text-slate-500"><?= e($catalogue['sous_titre']) ?></p>
      <?php endif; ?>
      <div class="mx-auto mt-8 max-w-sm">
        <?= rechercheBoutique($ctx, 'w-full border-0 border-b border-slate-300 bg-transparent px-1 py-2 text-center text-sm outline-none focus:border-[var(--b-600)]') ?>
      </div>
    </div>
  </header>

  <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-12">
    <?php if (!$ctx['articles']) { videBoutique($ctx); } else { ?>
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($ctx['articles'] as $article): $rupture = ruptureBoutique($article); ?>
          <figure class="group relative overflow-hidden rounded-2xl bg-slate-100">
            <div class="aspect-[4/5] w-full overflow-hidden">
              <?php if ($article['image_url']): ?>
                <img src="<?= e(urlImage($article['image_url'])) ?>" alt="<?= e($article['nom']) ?>" loading="lazy"
                     class="size-full object-cover transition-transform duration-500 group-hover:scale-105">
              <?php else: ?>
                <div class="grid size-full place-items-center bg-[var(--b-50)]">
                  <span class="text-5xl text-[var(--b-200)]">▦</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Le dégradé garantit la lisibilité du texte quelle que soit la photo. -->
            <figcaption class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/45 to-transparent p-5 text-white">
              <p class="text-base font-semibold"><?= e($article['nom']) ?></p>
              <?php if ($article['marque_nom']): ?>
                <p class="text-xs opacity-70"><?= e($article['marque_nom']) ?></p>
              <?php endif; ?>
              <div class="mt-3 flex items-center justify-between gap-3">
                <?= $ctx['catalogue']['afficher_prix']
                      ? '<span class="text-lg font-bold">' . e(montant($article['prix_vente'], $ctx['devise'])) . '</span>'
                      : '<span class="text-sm opacity-70">Prix sur demande</span>' ?>
                <?php if ($rupture): ?>
                  <span class="rounded-full bg-white/20 px-2.5 py-1 text-xs font-medium">Épuisé</span>
                <?php else: ?>
                  <?= commanderBoutique($article, $ctx,
                        'flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs '
                      . 'font-semibold text-slate-900 transition-transform hover:scale-105') ?>
                <?php endif; ?>
              </div>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php } ?>
  </main>

  <?php piedBoutique($ctx); flottantBoutique($ctx); ?>
</div>
    <?php
}
