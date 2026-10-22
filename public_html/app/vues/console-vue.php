<?php
/**
 * Gabarit de la console de la plateforme.
 *
 * Volontairement distinct de celui des clients (fond ardoise, accent ambre) :
 * en un coup d'œil, on sait si l'on est chez soi ou chez un client.
 */

/** Début du document HTML de la console. */
function documentConsole(string $titre, string $classesBody = 'min-h-full'): void
{
    ?><!doctype html>
<html lang="fr" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre) ?> · Console <?= e(nomPlateforme()) ?></title>
<link rel="icon" href="<?= e(u('/assets/favicon.svg')) ?>">
<script src="<?= e(u('/assets/tailwind.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(u('/assets/outech.css')) ?>">
</head>
<body class="<?= e($classesBody) ?> bg-slate-100 text-slate-800 antialiased">
<?php
}

/** Page de la console : barre latérale, bandeau, contenu. */
function pageConsole(string $titre, callable $contenu): void
{
    $session = exigerConsole();
    $chemin = cheminDemande();
    $message = flash();

    documentConsole($titre);
    ?>
<div class="min-h-full">
  <div id="voile" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden" onclick="basculerMenu(false)"></div>

  <nav id="barre-laterale" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-300 transition-transform lg:translate-x-0">
    <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-white/10 px-4">
      <a href="<?= e(u('/console')) ?>" class="flex items-center gap-2">
        <span class="grid size-8 place-items-center rounded-lg bg-amber-400 text-sm font-bold text-slate-900">P</span>
        <span class="text-sm font-bold tracking-wide text-white">Plateforme</span>
      </a>
      <button type="button" onclick="basculerMenu(false)" class="rounded p-1 text-slate-400 hover:bg-white/10 lg:hidden" aria-label="Fermer">
        <?= icone('croix', 'size-5') ?>
      </button>
    </div>

    <div class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
      <?php foreach (navigationConsole() as $entree):
        $actif = $entree['href'] === '/console'
            ? $chemin === '/console'
            : ($chemin === $entree['href'] || str_starts_with($chemin, $entree['href'] . '/')); ?>
        <a href="<?= e(u($entree['href'])) ?>"
           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors <?= $actif ? 'bg-amber-400 font-medium text-slate-900' : 'hover:bg-white/5 hover:text-white' ?>">
          <?= icone($entree['icone'], 'size-[18px] shrink-0') ?>
          <span class="flex-1 truncate"><?= e($entree['libelle']) ?></span>
          <?php if (!empty($entree['pastille'])): ?>
            <span class="grid min-w-5 place-items-center rounded-full bg-rose-500 px-1.5 text-[11px] font-semibold text-white"><?= (int) $entree['pastille'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="shrink-0 border-t border-white/10 px-4 py-3 text-[11px] text-slate-500">
      <?= e(nombreSocietes()) ?> / <?= e(reglage('max_societes')) ?> sociétés · essai <?= e(dureeEssai()) ?> j
    </div>
  </nav>

  <div class="lg:pl-64">
    <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-800 bg-slate-900 px-4 text-slate-200">
      <button type="button" onclick="basculerMenu(true)" class="rounded p-1.5 hover:bg-white/10 lg:hidden" aria-label="Ouvrir le menu">
        <?= icone('menu', 'size-5') ?>
      </button>

      <span class="rounded-lg bg-amber-400/10 px-3 py-1.5 text-sm font-medium text-amber-300">
        Console de la plateforme
      </span>

      <span class="ml-auto hidden items-center gap-2 rounded-lg bg-white/5 px-3 py-1.5 text-sm sm:flex">
        <span class="text-slate-400"><?= icone('calendrier', 'size-4') ?></span><?= e(dateFr('now')) ?>
      </span>

      <div class="relative">
        <span class="mr-2 hidden text-sm sm:inline"><?= e($session['nom']) ?></span>
        <form method="post" action="<?= e(u('/console/deconnexion')) ?>" class="inline">
          <?= champCsrf() ?>
          <button type="submit" class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-rose-300 hover:bg-rose-500/10">
            <?= icone('deconnexion', 'size-4') ?> Quitter
          </button>
        </form>
      </div>
    </header>

    <main class="p-4 sm:p-6">
      <?php if ($message): ?>
        <div class="mb-4"><?= alerte($message['message'], $message['ton']) ?></div>
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
</script>
<?= scriptCopie() ?>
<?php
    documentFin();
}

/** Page de console sans coquille : connexion, installation. */
function pageConsoleNue(string $titre, callable $contenu, string $classesBody = 'min-h-full'): void
{
    documentConsole($titre, $classesBody);
    $contenu();
    documentFin();
}

/* ------------------------------------------------------------- fragments */

/**
 * Encadré affichant une fois les identifiants d'un espace fraîchement créé.
 * Le mot de passe n'est stocké nulle part : c'est le seul moment où il est
 * lisible, il faut donc le recopier maintenant.
 */
function encadreIdentifiants(array $acces): string
{
    $lignes = '';
    foreach ([
        'Code société' => $acces['code'],
        'Identifiant'  => $acces['username'],
        'Mot de passe' => $acces['motdepasse'],
    ] as $libelle => $valeur) {
        $lignes .= '<div class="mt-2"><p class="text-xs font-medium opacity-80">' . e($libelle) . '</p>'
            . champCopiable((string) $valeur, ['classe' => 'mt-1']) . '</div>';
    }

    return alerte(
        '<p class="font-medium">Identifiants à transmettre au client</p>' . $lignes
        . '<p class="mt-3 text-xs opacity-80">Ce mot de passe ne sera plus affiché : copiez-le maintenant.</p>',
        'succes');
}
