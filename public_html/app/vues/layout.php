<?php
/**
 * Gabarit des pages : entête HTML, barre latérale, bandeau supérieur.
 * Équivalent de `src/app/(app)/layout.tsx` + `Coquille` + `BarreLaterale`.
 */

/**
 * Une entrée du menu est-elle active ?
 * Entrées sans enfants : correspondance exacte, sinon « / » les allumerait
 * toutes. Les autres s'allument aussi sur leurs sous-pages.
 */
function entreeActive(string $chemin, string $href): bool
{
    if ($href === '/tableau-de-bord' || $href === '/caisse') return $chemin === $href;
    return $chemin === $href || str_starts_with($chemin, $href . '/');
}

/** Début du document HTML, commun à toutes les pages. */
function documentDebut(string $titre, string $classesBody = 'min-h-full', ?string $suffixe = 'OUTECH'): void
{
    // Sur le domaine d'un commerçant, la page ne porte plus le nom de la
    // plateforme : c'est sa boutique, pas la nôtre.
    $titre = $suffixe === null || $suffixe === '' ? $titre : $titre . ' · ' . $suffixe;
    ?><!doctype html>
<html lang="fr" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre) ?></title>
<link rel="icon" href="<?= e(u('/assets/favicon.svg')) ?>">
<script src="<?= e(u('/assets/tailwind.js')) ?>"></script>
<script>
  // Palette « marine » de l'application d'origine (globals.css).
  tailwind.config = {
    theme: { extend: { colors: { marine: {
      50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8',
      500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#29306b', 900: '#1b2153', 950: '#131845',
    } } } },
  };
</script>
<link rel="stylesheet" href="<?= e(u('/assets/outech.css')) ?>">
</head>
<body class="<?= e($classesBody) ?> bg-[#f1f4f9] text-slate-800 antialiased">
<?php
}

function documentFin(): void
{
    echo "</body>\n</html>";
}

/**
 * Bandeaux d'en-tête : essai en cours, et rappel permanent quand l'espace est
 * ouvert depuis la console de la plateforme.
 */
function bandeauSociete(): string
{
    $societe = societeSession();
    if (!$societe) return '';

    $html = '';

    if (enEspaceConsole()) {
        $html .= '<div class="flex flex-wrap items-center justify-between gap-2 bg-amber-400 px-4 py-2 text-sm '
            . 'font-medium text-slate-900 sans-impression">'
            . '<span>Espace de « ' . e($societe['nom']) . ' » ouvert depuis la console — '
            . 'vous agissez au nom de ce client.</span>'
            . '<form method="post" action="' . e(u('/deconnexion')) . '">' . champCsrf()
            . '<button type="submit" class="rounded-lg bg-slate-900 px-3 py-1 text-xs font-medium text-white '
            . 'hover:bg-slate-800">Refermer l&rsquo;espace</button></form>'
            . '</div>';
    }

    if (situation($societe) === 'ESSAI') {
        $jours = max(0, (int) joursRestants($societe));
        $urgent = $jours <= 3;
        $contact = reglage('contact_plateforme');

        $html .= '<div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm sans-impression '
            . ($urgent ? 'bg-rose-50 text-rose-800' : 'bg-blue-50 text-blue-800') . '">'
            . '<span><strong>Période d&rsquo;essai</strong> — il vous reste ' . $jours . ' jour'
            . ($jours > 1 ? 's' : '') . ' sur ' . dureeEssai() . '.</span>'
            . ($contact !== '' ? '<span class="text-xs opacity-80">Passer en Pro : ' . e($contact) . '</span>' : '')
            . '</div>';
    }

    return $html;
}

/**
 * Page de l'application : barre latérale, bandeau, contenu.
 * `$contenu` est appelé à l'endroit du corps de page.
 */
function page(string $titre, callable $contenu, array $options = []): void
{
    $session = exigerSession();
    $chemin = cheminDemande();
    $entrees = navigationPour($session['role']);
    $accueil = accueilPour($session['role']);
    $lieu = lieuCourant();
    $message = flash();

    documentDebut($titre);
    ?>
<div class="min-h-full">
  <div id="voile" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden" onclick="basculerMenu(false)"></div>

  <nav id="barre-laterale" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-marine-900 text-slate-300 transition-transform lg:translate-x-0 sans-impression">
    <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-white/10 px-4">
      <a href="<?= e(u($accueil)) ?>" class="flex items-center gap-2">
        <span class="grid size-8 place-items-center rounded-lg bg-marine-500 text-sm font-bold text-white">O</span>
        <span class="text-lg font-bold tracking-wide text-white">OUTECH</span>
      </a>
      <button type="button" onclick="basculerMenu(false)" class="rounded p-1 text-slate-400 hover:bg-white/10 lg:hidden" aria-label="Fermer">
        <?= icone('croix', 'size-5') ?>
      </button>
    </div>

    <div class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
      <?php foreach ($entrees as $entree): $actif = entreeActive($chemin, $entree['href']); ?>
        <?php if (empty($entree['enfants'])): ?>
          <a href="<?= e(u($entree['href'])) ?>"
             class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors <?= $actif ? 'bg-marine-600 font-medium text-white' : 'hover:bg-white/5 hover:text-white' ?>">
            <?= icone($entree['icone'], 'size-[18px] shrink-0') ?>
            <span class="truncate"><?= e($entree['libelle']) ?></span>
          </a>
        <?php else: ?>
          <details<?= $actif ? ' open' : '' ?> class="group">
            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors <?= $actif ? 'bg-white/10 font-medium text-white' : 'hover:bg-white/5 hover:text-white' ?>">
              <?= icone($entree['icone'], 'size-[18px] shrink-0') ?>
              <span class="flex-1 truncate text-left"><?= e($entree['libelle']) ?></span>
              <span class="shrink-0 transition-transform group-open:rotate-180"><?= icone('chevron', 'size-4') ?></span>
            </summary>
            <ul class="mt-0.5 mb-1 ml-5 space-y-0.5 border-l border-white/10 pl-3">
              <?php foreach ($entree['enfants'] as $enfant): ?>
                <li>
                  <a href="<?= e(u($enfant['href'])) ?>"
                     class="block truncate rounded-md px-3 py-1.5 text-[13px] transition-colors <?= $chemin === $enfant['href'] ? 'bg-marine-600 font-medium text-white' : 'hover:bg-white/5 hover:text-white' ?>">
                    <?= e($enfant['libelle']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="shrink-0 border-t border-white/10 px-4 py-3 text-[11px] text-slate-500">
      OUTECH · v1.0 — © <?= date('Y') ?>
    </div>
  </nav>

  <div class="lg:pl-64">
    <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-marine-800 bg-marine-900 px-4 text-slate-200 sans-impression">
      <button type="button" onclick="basculerMenu(true)" class="rounded p-1.5 hover:bg-white/10 lg:hidden" aria-label="Ouvrir le menu">
        <?= icone('menu', 'size-5') ?>
      </button>

      <a href="<?= e(u('/pos')) ?>" class="flex items-center gap-2 rounded-lg bg-white/10 px-3 py-1.5 text-sm font-medium hover:bg-white/20">
        <?= icone('ecran', 'size-4') ?> Point de Vente
      </a>

      <span class="ml-auto hidden items-center gap-2 rounded-lg bg-white/5 px-3 py-1.5 text-sm sm:flex">
        <span class="text-slate-400"><?= icone('calendrier', 'size-4') ?></span><?= e(dateFr('now')) ?>
      </span>

      <span class="hidden items-center gap-2 rounded-lg bg-white/5 px-3 py-1.5 text-sm md:flex">
        <span class="text-slate-400"><?= icone('magasin', 'size-4') ?></span><?= e($lieu['nom'] ?? '—') ?>
      </span>

      <span class="hidden items-center gap-2 rounded-lg bg-white/5 px-3 py-1.5 text-sm xl:flex">
        <span class="text-slate-400"><?= icone('carnet', 'size-4') ?></span><?= e($session['societe_nom'] ?? societe()['nom']) ?>
      </span>

      <div class="relative ml-auto sm:ml-0">
        <button type="button" onclick="basculerProfil()" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-white/10">
          <span class="grid size-7 place-items-center rounded-full bg-marine-500 text-xs font-semibold text-white">
            <?= e(mb_strtoupper(mb_substr($session['nom'], 0, 2))) ?>
          </span>
          <span class="hidden text-sm sm:block"><?= e($session['nom']) ?></span>
          <span class="text-slate-400"><?= icone('chevron', 'size-4') ?></span>
        </button>

        <div id="menu-profil" class="absolute right-0 z-20 mt-2 hidden w-52 overflow-hidden rounded-xl bg-white text-slate-700 shadow-lg ring-1 ring-slate-200">
          <div class="border-b border-slate-100 px-4 py-3">
            <p class="text-sm font-medium text-slate-900"><?= e($session['nom']) ?></p>
            <p class="text-xs text-slate-500"><?= e(ROLES[$session['role']] ?? $session['role']) ?></p>
          </div>
          <a href="<?= e(u('/parametres')) ?>" class="block px-4 py-2.5 text-sm hover:bg-slate-50">Paramètres</a>
          <form method="post" action="<?= e(u('/deconnexion')) ?>">
            <?= champCsrf() ?>
            <button type="submit" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-rose-600 hover:bg-rose-50">
              <?= icone('deconnexion', 'size-4') ?> Se déconnecter
            </button>
          </form>
        </div>
      </div>
    </header>

    <?= bandeauSociete() ?>

    <main class="p-4 sm:p-6">
      <?php if ($message): ?>
        <div class="mb-4 sans-impression"><?= alerte(e($message['message']), $message['ton']) ?></div>
      <?php endif; ?>
      <?php $contenu(); ?>
    </main>
  </div>
</div>

<script>
  function basculerMenu(ouvrir) {
    document.getElementById('barre-laterale').classList.toggle('-translate-x-full', !ouvrir);
    document.getElementById('voile').classList.toggle('hidden', !ouvrir);
  }
  function basculerProfil() {
    document.getElementById('menu-profil').classList.toggle('hidden');
  }
  document.addEventListener('click', (e) => {
    const menu = document.getElementById('menu-profil');
    if (menu && !menu.classList.contains('hidden')
        && !e.target.closest('#menu-profil') && !e.target.closest('button[onclick="basculerProfil()"]')) {
      menu.classList.add('hidden');
    }

    // Un menu d'actions ouvert se referme dès qu'on clique ailleurs — sans
    // cela, `<details>` reste ouvert et deux menus peuvent l'être à la fois.
    document.querySelectorAll('details.menu-actions[open]').forEach((detail) => {
      if (!detail.contains(e.target)) detail.open = false;
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.menu-actions[open]').forEach((d) => { d.open = false; });
  });

  /**
   * Place le panneau d'un menu d'actions sous son bouton.
   *
   * Le panneau est en `position: fixed`, donc hors du conteneur
   * `overflow-x-auto` du tableau, qui le découpait. En contrepartie ses
   * coordonnées se calculent ici : sous le bouton, retourné au-dessus s'il
   * déborde en bas, ramené dans la fenêtre s'il déborde à droite.
   */
  function placerPanneau(detail) {
    const bouton = detail.querySelector('summary');
    const panneau = detail.querySelector('.menu-actions-panneau');
    const marge = 8;

    const zone = bouton.getBoundingClientRect();
    const largeur = panneau.offsetWidth;
    const hauteur = panneau.offsetHeight;

    let haut = zone.bottom + 4;
    if (haut + hauteur > window.innerHeight - marge) {
      haut = Math.max(marge, zone.top - hauteur - 4);
    }

    let gauche = zone.left;
    if (gauche + largeur > window.innerWidth - marge) {
      gauche = Math.max(marge, window.innerWidth - largeur - marge);
    }

    panneau.style.top = haut + 'px';
    panneau.style.left = gauche + 'px';
  }

  /**
   * Tant qu'un menu est ouvert, son panneau est replacé à chaque image.
   *
   * Plutôt que d'écouter `scroll` et `resize` — qu'il faudrait capter sur la
   * fenêtre *et* sur chaque conteneur défilant, et qui manquent à l'appel dans
   * certains navigateurs embarqués — on suit le bouton en continu. La boucle
   * ne tourne que menu ouvert : le reste du temps, elle n'existe pas.
   */
  let suiviMenu = null;

  function suivreMenuOuvert() {
    const ouvert = document.querySelector('details.menu-actions[open]');
    if (!ouvert) { suiviMenu = null; return; }
    placerPanneau(ouvert);
    suiviMenu = requestAnimationFrame(suivreMenuOuvert);
  }

  function positionnerMenu(detail) {
    if (!detail.open) return;

    // Un seul menu ouvert à la fois.
    document.querySelectorAll('details.menu-actions[open]').forEach((autre) => {
      if (autre !== detail) autre.open = false;
    });

    placerPanneau(detail);
    if (suiviMenu === null) suivreMenuOuvert();
  }

  // Ceinture et bretelles : `requestAnimationFrame` ne tourne pas dans un
  // onglet en arrière-plan, ces écouteurs si. L'un ou l'autre suffit.
  const replacerMenus = () => {
    document.querySelectorAll('details.menu-actions[open]').forEach(placerPanneau);
  };
  document.addEventListener('scroll', replacerMenus, true);
  window.addEventListener('resize', replacerMenus);
</script>
<?php
    documentFin();
}

/**
 * Page sans coquille : connexion, point de vente, impression, catalogue public.
 */
function pageNue(string $titre, callable $contenu, string $classesBody = 'min-h-full',
                 ?string $suffixe = 'OUTECH'): void
{
    documentDebut($titre, $classesBody, $suffixe);
    $contenu();
    documentFin();
}
