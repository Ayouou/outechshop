<?php
/**
 * Composants d'interface — équivalents PHP de `src/components/ui.tsx`.
 * Chaque fonction renvoie du HTML (les pages font `echo`).
 */

const COULEURS_BADGE = [
    'gris'   => 'bg-slate-100 text-slate-700 ring-slate-200',
    'bleu'   => 'bg-blue-50 text-blue-700 ring-blue-200',
    'vert'   => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'orange' => 'bg-amber-50 text-amber-700 ring-amber-200',
    'rouge'  => 'bg-rose-50 text-rose-700 ring-rose-200',
    'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
];

function badge(string $texte, string $couleur = 'gris'): string
{
    $classes = COULEURS_BADGE[$couleur] ?? COULEURS_BADGE['gris'];
    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap '
        . $classes . '">' . e($texte) . '</span>';
}

/** Badge d'un statut de document / paiement / effet / livraison. */
function badgeStatut(array $table, ?string $cle): string
{
    $entree = $table[$cle] ?? ['label' => $cle ?? '—', 'couleur' => 'gris'];
    return badge($entree['label'], $entree['couleur']);
}

const VARIANTES_BOUTON = [
    'primaire'   => 'bg-marine-600 text-white hover:bg-marine-700 shadow-sm',
    'secondaire' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
    'danger'     => 'bg-rose-600 text-white hover:bg-rose-700 shadow-sm',
    'succes'     => 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm',
    'fantome'    => 'text-slate-600 hover:bg-slate-100',
];

const BASE_BOUTON = 'inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60';

function classesBouton(string $variante = 'primaire', string $extra = ''): string
{
    return BASE_BOUTON . ' ' . (VARIANTES_BOUTON[$variante] ?? VARIANTES_BOUTON['primaire']) . ' ' . $extra;
}

function bouton(string $libelle, array $options = []): string
{
    $attrs = attributs([
        'type'  => $options['type'] ?? 'submit',
        'name'  => $options['name'] ?? null,
        'value' => $options['value'] ?? null,
        'class' => classesBouton($options['variante'] ?? 'primaire', $options['classe'] ?? ''),
    ] + ($options['attrs'] ?? []));

    return '<button ' . $attrs . '>'
        . (isset($options['icone']) ? icone($options['icone'], 'size-4') : '')
        . e($libelle) . '</button>';
}

function boutonLien(string $href, string $libelle, array $options = []): string
{
    $attrs = attributs([
        'href'  => u($href),
        'class' => classesBouton($options['variante'] ?? 'primaire', $options['classe'] ?? ''),
    ] + ($options['attrs'] ?? []));

    return '<a ' . $attrs . '>'
        . (isset($options['icone']) ? icone($options['icone'], 'size-4') : '')
        . e($libelle) . '</a>';
}

/** Sérialise un tableau clé => valeur en attributs HTML (null ignoré). */
function attributs(array $attrs): string
{
    $morceaux = [];
    foreach ($attrs as $cle => $valeur) {
        if ($valeur === null || $valeur === false) continue;
        if ($valeur === true) { $morceaux[] = e($cle); continue; }
        $morceaux[] = e($cle) . '="' . e($valeur) . '"';
    }
    return implode(' ', $morceaux);
}

/* ------------------------------------------------------------------- cartes */

function carteDebut(?string $titre = null, array $options = []): string
{
    $html = '<section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 ' . e($options['classe'] ?? '') . '">';

    if ($titre !== null || !empty($options['actions'])) {
        $html .= '<header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"><div>';
        if ($titre !== null) $html .= '<h2 class="text-base font-semibold text-slate-800">' . e($titre) . '</h2>';
        if (!empty($options['sous_titre'])) {
            $html .= '<p class="mt-0.5 text-sm text-slate-500">' . e($options['sous_titre']) . '</p>';
        }
        $html .= '</div>';
        if (!empty($options['actions'])) {
            $html .= '<div class="flex items-center gap-2">' . $options['actions'] . '</div>';
        }
        $html .= '</header>';
    }

    return $html . '<div class="' . (!empty($options['sans_padding']) ? '' : 'p-5') . '">';
}

function carteFin(): string
{
    return '</div></section>';
}

/* -------------------------------------------------------------- en-tête page */

function enTetePage(string $titre, ?string $sousTitre = null, string $actions = ''): string
{
    $html = '<div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div>'
        . '<h1 class="text-xl font-semibold text-slate-900">' . e($titre) . '</h1>';
    if ($sousTitre) $html .= '<p class="mt-1 text-sm text-slate-500">' . e($sousTitre) . '</p>';
    $html .= '</div>';
    // `items-end` et non `items-center` : un filtre de période est plus haut que
    // les boutons (ses libellés surmontent les champs). Centré, chaque bouton
    // flottait à mi-hauteur au lieu de s'aligner sur la rangée des champs.
    if ($actions !== '') $html .= '<div class="flex flex-wrap items-end gap-2">' . $actions . '</div>';
    return $html . '</div>';
}

/* --------------------------------------------------------------- formulaires */

// `focus:outline-none` : sans lui le navigateur ajoute son propre liseré — un
// rectangle sombre à angles vifs qui se superpose à l'anneau marine et casse
// l'harmonie des champs. L'anneau reste, la focalisation demeure donc visible.
const CLASSES_SAISIE = 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-marine-500 disabled:bg-slate-50 disabled:text-slate-500';

/**
 * Le même champ, mais qui ne prend que la place qu'il lui faut : dans une barre
 * de filtres, le `w-full` ci-dessus étale chaque contrôle sur toute la ligne.
 *
 * Il faut bien le *remplacer* : ajouter « w-auto » par-dessus ne suffit pas.
 * Les deux règles pèsent le même poids, et c'est l'ordre de la feuille Tailwind
 * — pas celui de l'attribut `class` — qui tranche, au profit de `w-full`.
 */
define('CLASSES_SAISIE_AUTO', str_replace('w-full', 'w-auto', CLASSES_SAISIE));

/** Enveloppe libellé + contrôle. `$controle` est du HTML déjà rendu. */
function champBloc(string $label, string $controle, array $options = []): string
{
    $html = '<label class="block ' . e($options['classe'] ?? '') . '">'
        . '<span class="mb-1.5 block text-sm font-medium text-slate-700">' . e($label)
        . (!empty($options['obligatoire']) ? '<span class="ml-0.5 text-rose-500">*</span>' : '')
        . '</span>' . $controle;

    if (!empty($options['erreur'])) {
        $html .= '<span class="mt-1 block text-xs text-rose-600">' . e($options['erreur']) . '</span>';
    } elseif (!empty($options['aide'])) {
        $html .= '<span class="mt-1 block text-xs text-slate-500">' . e($options['aide']) . '</span>';
    }

    return $html . '</label>';
}

function saisie(string $nom, $valeur = '', array $options = []): string
{
    $attrs = attributs([
        'type'  => $options['type'] ?? 'text',
        'name'  => $nom,
        'id'    => $options['id'] ?? null,
        'value' => $valeur === null ? '' : (string) $valeur,
        'class' => CLASSES_SAISIE . ' ' . ($options['classe'] ?? ''),
    ] + ($options['attrs'] ?? []));
    return '<input ' . $attrs . '>';
}

function zoneTexte(string $nom, $valeur = '', array $options = []): string
{
    $attrs = attributs([
        'name'  => $nom,
        'rows'  => $options['lignes'] ?? 3,
        'class' => CLASSES_SAISIE . ' ' . ($options['classe'] ?? ''),
    ] + ($options['attrs'] ?? []));
    return '<textarea ' . $attrs . '>' . e($valeur) . '</textarea>';
}

/** `$choix` : valeur => libellé. */
function listeDeroulante(string $nom, array $choix, $valeur = null, array $options = []): string
{
    $attrs = attributs([
        'name'  => $nom,
        'id'    => $options['id'] ?? null,
        'class' => CLASSES_SAISIE . ' ' . ($options['classe'] ?? ''),
    ] + ($options['attrs'] ?? []));

    $html = '<select ' . $attrs . '>';
    if (isset($options['vide'])) {
        $html .= '<option value="">' . e($options['vide']) . '</option>';
    }
    foreach ($choix as $cle => $libelle) {
        $selection = ((string) $cle === (string) $valeur) ? ' selected' : '';
        $html .= '<option value="' . e($cle) . '"' . $selection . '>' . e($libelle) . '</option>';
    }
    return $html . '</select>';
}

function caseACocher(string $nom, bool $coche, string $label, array $options = []): string
{
    return '<label class="flex items-center gap-2 text-sm text-slate-700 ' . e($options['classe'] ?? '') . '">'
        . '<input type="checkbox" name="' . e($nom) . '" value="1"' . ($coche ? ' checked' : '')
        . ' class="size-4 rounded border-slate-300 text-marine-600 focus:ring-marine-500">'
        . e($label) . '</label>';
}

/* ------------------------------------------------------------ tableaux & vide */

const CLASSES_TH = 'bg-slate-50 px-4 py-3 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase whitespace-nowrap';
const CLASSES_TD = 'px-4 py-3 text-slate-700';

function tableauDebut(array $colonnes = []): string
{
    $html = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm">';
    if ($colonnes) {
        $html .= '<thead><tr>';
        foreach ($colonnes as $colonne) {
            $libelle = is_array($colonne) ? ($colonne['libelle'] ?? '') : $colonne;
            $extra = is_array($colonne) ? ($colonne['classe'] ?? '') : '';
            $html .= '<th scope="col" class="' . CLASSES_TH . ' ' . e($extra) . '">' . e($libelle) . '</th>';
        }
        $html .= '</tr></thead>';
    }
    return $html . '<tbody class="divide-y divide-slate-100">';
}

function tableauFin(): string
{
    return '</tbody></table></div>';
}

function ligneVide(int $colonnes, string $message = 'Aucune donnée disponible'): string
{
    return '<tr><td colspan="' . $colonnes . '" class="' . CLASSES_TD . ' py-10 text-center text-slate-400">'
        . e($message) . '</td></tr>';
}

/* ----------------------------------------------------------------- alertes */

function alerte(string $contenu, string $ton = 'info'): string
{
    $tons = [
        'info'      => 'bg-blue-50 text-blue-800 ring-blue-200',
        'succes'    => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'erreur'    => 'bg-rose-50 text-rose-800 ring-rose-200',
        'attention' => 'bg-amber-50 text-amber-800 ring-amber-200',
    ];
    return '<div class="rounded-lg px-4 py-3 text-sm ring-1 ring-inset ' . ($tons[$ton] ?? $tons['info']) . '">'
        . $contenu . '</div>';
}

/* --------------------------------------------------------------- pagination */

function pagination(string $base, int $total, array $params): string
{
    $taille = $params['taille'];
    $page = $params['page'];
    $pages = max(1, (int) ceil($total / max(1, $taille)));
    $debut = $total === 0 ? 0 : ($page - 1) * $taille + 1;
    $fin = min($total, $page * $taille);

    $html = '<div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-600">'
        // Concaténation explicite : dans une chaîne interpolée, le tiret demi-cadratin
        // serait avalé par le nom de variable qui le précède.
        . '<span>' . e($debut . '–' . $fin . ' sur ' . $total) . '</span>'
        . '<div class="flex items-center gap-2">';

    $html .= '<form method="get" class="flex items-center gap-2">';
    foreach ($_GET as $cle => $valeur) {
        if (in_array($cle, ['taille', 'page'], true) || !is_string($valeur)) continue;
        $html .= '<input type="hidden" name="' . e($cle) . '" value="' . e($valeur) . '">';
    }
    $html .= '<select name="taille" onchange="this.form.submit()" class="rounded-lg border-0 bg-white py-1.5 pl-2 pr-7 text-sm ring-1 ring-inset ring-slate-300">';
    foreach (TAILLES_PAGE as $option) {
        $html .= '<option value="' . $option . '"' . ($option === $taille ? ' selected' : '') . '>'
            . $option . ' / page</option>';
    }
    $html .= '</select></form>';

    $precedente = $page > 1
        ? '<a href="' . e(urlAvec($base, ['page' => $page - 1])) . '" class="' . classesBouton('secondaire', 'px-2 py-1') . '">' . icone('chevron-g', 'size-4') . '</a>'
        : '<span class="' . classesBouton('secondaire', 'px-2 py-1 opacity-40') . '">' . icone('chevron-g', 'size-4') . '</span>';

    $suivante = $page < $pages
        ? '<a href="' . e(urlAvec($base, ['page' => $page + 1])) . '" class="' . classesBouton('secondaire', 'px-2 py-1') . '">' . icone('chevron-d', 'size-4') . '</a>'
        : '<span class="' . classesBouton('secondaire', 'px-2 py-1 opacity-40') . '">' . icone('chevron-d', 'size-4') . '</span>';

    $html .= $precedente . '<span class="px-1">' . e("Page $page / $pages") . '</span>' . $suivante;

    return $html . '</div></div>';
}

/* ------------------------------------------------------- barre d'outils liste */

/** Champ de recherche + filtres additionnels (HTML déjà rendu). */
function barreOutils(string $base, array $params, string $filtres = '', string $placeholder = 'Rechercher…'): string
{
    $html = '<form method="get" action="' . e(u($base)) . '" class="flex flex-wrap items-center gap-2 px-5 py-3 border-b border-slate-100">';

    foreach ($_GET as $cle => $valeur) {
        if (in_array($cle, ['q', 'page'], true) || !is_string($valeur)) continue;
        if (str_contains($filtres, 'name="' . $cle . '"')) continue;
        $html .= '<input type="hidden" name="' . e($cle) . '" value="' . e($valeur) . '">';
    }

    $html .= '<div class="relative flex-1 min-w-[12rem]">'
        . '<span class="pointer-events-none absolute inset-y-0 left-2.5 grid place-items-center text-slate-400">'
        . icone('loupe', 'size-4') . '</span>'
        . '<input type="search" name="q" value="' . e($params['q']) . '" placeholder="' . e($placeholder) . '" '
        . 'class="' . CLASSES_SAISIE . ' pl-9">'
        . '</div>' . $filtres
        . '<button type="submit" class="' . classesBouton('secondaire') . '">Filtrer</button>';

    if ($params['q'] !== '' || count($_GET) > 0) {
        $html .= '<a href="' . e(u($base)) . '" class="' . classesBouton('fantome') . '">Réinitialiser</a>';
    }

    return $html . '</form>';
}

/* ---------------------------------------------------------------- photo */

/**
 * Sélecteur de photo. Port de `src/components/ChampImage.tsx`.
 *
 * L'image est réduite dans le navigateur (640 px, JPEG) avant l'envoi : une
 * photo de téléphone pèse plusieurs mégaoctets, la réduire ici garde la base
 * légère, la caisse rapide et les sauvegardes raisonnables.
 *
 * Publie deux champs cachés : `image` (data URL, vide si rien de neuf) et
 * `image_supprimee` (« 1 » si la photo existante a été retirée).
 */
function champImage(?string $imageUrl, string $nom = 'image', array $options = []): string
{
    $label = $options['label'] ?? "Photo de l'article";
    $aide = $options['aide'] ?? "JPEG ou PNG. L'image est réduite automatiquement avant l'enregistrement.";
    $apercu = urlImage($imageUrl);
    $id = 'img_' . substr(md5($nom . random_int(0, 99999)), 0, 8);

    ob_start(); ?>
    <div class="block" id="<?= $id ?>">
      <span class="mb-1.5 block text-sm font-medium text-slate-700"><?= e($label) ?></span>
      <input type="hidden" name="<?= e($nom) ?>" value="" data-role="donnees">
      <input type="hidden" name="<?= e($nom) ?>_supprimee" value="" data-role="supprimee">

      <div class="flex items-start gap-4">
        <div class="grid size-28 shrink-0 place-items-center overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200" data-role="cadre">
          <?php if ($apercu): ?>
            <img src="<?= e($apercu) ?>" alt="" class="size-full object-cover" data-role="apercu">
          <?php else: ?>
            <span class="text-slate-300" data-role="vide"><?= icone('boite', 'size-7') ?></span>
          <?php endif; ?>
        </div>

        <div class="min-w-0 flex-1 space-y-2">
          <input type="file" accept="image/*" data-role="fichier"
                 class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
          <button type="button" data-role="retirer" class="<?= $apercu ? 'inline-flex' : 'hidden' ?> items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50">
            <?= icone('poubelle', 'size-3.5') ?> Retirer la photo
          </button>
          <p class="text-xs text-slate-500" data-role="aide"><?= e($aide) ?></p>
        </div>
      </div>
    </div>

    <script>
    (function () {
      const bloc = document.getElementById('<?= $id ?>');
      const donnees = bloc.querySelector('[data-role=donnees]');
      const supprimee = bloc.querySelector('[data-role=supprimee]');
      const cadre = bloc.querySelector('[data-role=cadre]');
      const fichier = bloc.querySelector('[data-role=fichier]');
      const retirer = bloc.querySelector('[data-role=retirer]');
      const aide = bloc.querySelector('[data-role=aide]');
      const COTE_MAX = 640, QUALITE = 0.82;

      async function reduire(f) {
        const bitmap = await createImageBitmap(f);
        const echelle = Math.min(1, COTE_MAX / Math.max(bitmap.width, bitmap.height));
        const largeur = Math.max(1, Math.round(bitmap.width * echelle));
        const hauteur = Math.max(1, Math.round(bitmap.height * echelle));
        const toile = document.createElement('canvas');
        toile.width = largeur; toile.height = hauteur;
        const ctx = toile.getContext('2d');
        // Les JPEG et les PNG transparents sont aplatis sur du blanc, pas du noir.
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, largeur, hauteur);
        ctx.drawImage(bitmap, 0, 0, largeur, hauteur);
        bitmap.close();
        return toile.toDataURL('image/jpeg', QUALITE);
      }

      function afficher(src) {
        cadre.innerHTML = src
          ? '<img src="' + src + '" alt="" class="size-full object-cover">'
          : '<span class="text-slate-300">—</span>';
        retirer.className = (src ? 'inline-flex' : 'hidden') + ' items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50';
      }

      fichier.addEventListener('change', async () => {
        const f = fichier.files && fichier.files[0];
        if (!f) return;
        if (!f.type.startsWith('image/')) { aide.textContent = 'Choisissez un fichier image (JPEG, PNG, WebP…).'; return; }
        try {
          const reduite = await reduire(f);
          donnees.value = reduite;
          supprimee.value = '';
          afficher(reduite);
        } catch (e) { aide.textContent = "Cette image n'a pas pu être lue."; }
      });

      retirer.addEventListener('click', () => {
        donnees.value = '';
        supprimee.value = '1';
        fichier.value = '';
        afficher(null);
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/* ------------------------------------------------------------ menu d'actions */

/**
 * Menu d'actions d'une ligne de liste. `$elements` : HTML déjà rendu
 * (voir `lienMenu` et `actionMenu`).
 *
 * Le bouton porte une vraie icône (trois points) plutôt qu'un caractère
 * typographique : la taille et l'alignement ne dépendent plus de la police
 * installée. La classe `menu-actions` sert au script du gabarit, qui referme
 * le menu dès qu'on clique ailleurs.
 */
function menuActions(array $elements): string
{
    if (!$elements) return '';

    // Le panneau est en `fixed`, positionné par le script du gabarit : une
    // liste vit dans un conteneur `overflow-x-auto`, qui découperait un menu
    // simplement `absolute` — c'est exactement ce qu'on voyait.
    return '<details class="menu-actions group inline-block" ontoggle="positionnerMenu(this)">'
        . '<summary class="grid size-8 cursor-pointer list-none place-items-center rounded-lg text-slate-400 '
        . 'transition-colors hover:bg-slate-100 hover:text-slate-700 '
        . 'group-open:bg-slate-100 group-open:text-slate-700" '
        . 'aria-label="Actions" title="Actions">'
        . icone('points', 'size-[18px]') . '</summary>'
        . '<div class="menu-actions-panneau fixed top-0 left-0 z-50 w-56 overflow-hidden rounded-xl '
        . 'bg-white py-1 text-sm shadow-xl ring-1 ring-slate-900/10">'
        . implode('', $elements) . '</div></details>';
}

function lienMenu(string $href, string $libelle, string $icone = '', string $classe = 'text-slate-700'): string
{
    return '<a href="' . e(u($href)) . '" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50 ' . e($classe) . '">'
        . ($icone ? icone($icone, 'size-4') : '') . e($libelle) . '</a>';
}

/**
 * Action POST dans un menu : formulaire minimal avec jeton CSRF, et
 * confirmation navigateur pour les gestes destructifs.
 */
function actionMenu(string $action, array $champs, string $libelle, array $options = []): string
{
    $html = '<form method="post"' . (isset($options['confirmation'])
        ? ' onsubmit="return confirm(' . e(json_encode($options['confirmation'], JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
        . champCsrf() . '<input type="hidden" name="_action" value="' . e($action) . '">';

    foreach ($champs as $nom => $valeur) {
        $html .= '<input type="hidden" name="' . e($nom) . '" value="' . e($valeur) . '">';
    }

    return $html . '<button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-slate-50 '
        . e($options['classe'] ?? 'text-slate-700') . '">'
        . (isset($options['icone']) ? icone($options['icone'], 'size-4') : '') . e($libelle)
        . '</button></form>';
}

/** Bouton d'action isolé (hors menu). */
function actionBouton(string $action, array $champs, string $libelle, array $options = []): string
{
    $html = '<form method="post" class="inline"' . (isset($options['confirmation'])
        ? ' onsubmit="return confirm(' . e(json_encode($options['confirmation'], JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
        . champCsrf() . '<input type="hidden" name="_action" value="' . e($action) . '">';

    foreach ($champs as $nom => $valeur) {
        $html .= '<input type="hidden" name="' . e($nom) . '" value="' . e($valeur) . '">';
    }

    return $html . bouton($libelle, $options) . '</form>';
}

/**
 * Valeur à recopier telle quelle (code société, identifiant, mot de passe),
 * avec son bouton « copier ».
 *
 * Un code société porte 24 chiffres : personne ne devrait avoir à le relire
 * caractère par caractère. Le bouton passe par le presse-papiers du
 * navigateur, avec repli sur une sélection quand celui-ci est refusé (page
 * servie en clair, vieux navigateur).
 */
function champCopiable(string $valeur, array $options = []): string
{
    $id = 'copiable-' . substr(md5($valeur . random_int(0, PHP_INT_MAX)), 0, 8);

    return '<div class="flex items-stretch gap-2 ' . e($options['classe'] ?? '') . '">'
        . '<code id="' . $id . '" class="flex-1 overflow-x-auto rounded-lg bg-slate-50 px-3 py-2 '
        . 'font-mono text-[13px] break-all text-slate-800 ring-1 ring-inset ring-slate-200">'
        . e($valeur) . '</code>'
        . '<button type="button" onclick="copierValeur(this, ' . "'" . $id . "'" . ')" '
        . 'class="shrink-0 rounded-lg px-2.5 text-slate-500 ring-1 ring-inset ring-slate-300 '
        . 'transition-colors hover:bg-slate-50 hover:text-slate-700" title="Copier" aria-label="Copier">'
        . icone('copie', 'size-4') . '</button></div>';
}

/**
 * Script du bouton « copier ». Posé une seule fois par page, à l'endroit où
 * l'on utilise `champCopiable()`.
 */
function scriptCopie(): string
{
    return '<script>
      const ICONE_COCHE = ' . json_encode(icone('coche', 'size-4'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';

      function copierValeur(bouton, id) {
        const texte = document.getElementById(id).textContent.trim();
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(texte)
            .then(() => retour(bouton, "text-emerald-600", "Copié"))
            .catch(() => selectionner(bouton, id));
          return;
        }
        selectionner(bouton, id);
      }

      // Presse-papiers refusé (page en clair, navigateur ancien) : la valeur
      // est sélectionnée, il ne reste plus qu\'un Ctrl+C — et on le dit.
      function selectionner(bouton, id) {
        const plage = document.createRange();
        plage.selectNodeContents(document.getElementById(id));
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(plage);
        retour(bouton, "text-amber-600", "Sélectionné — faites Ctrl+C");
      }

      function retour(bouton, classe, titre) {
        const avant = bouton.innerHTML;
        const titreAvant = bouton.title;
        bouton.innerHTML = ICONE_COCHE;
        bouton.title = titre;
        bouton.classList.add(classe);
        setTimeout(() => {
          bouton.innerHTML = avant;
          bouton.title = titreAvant;
          bouton.classList.remove(classe);
        }, 1800);
      }
    </script>';
}

/** Ligne « libellé / valeur » d'une fiche. `$valeur` vide s'affiche « — ». */
function ligneInfo(string $libelle, ?string $valeur, bool $html = false): string
{
    return '<div class="flex items-baseline justify-between gap-4 border-b border-slate-100 py-2 last:border-0">'
        . '<span class="text-sm text-slate-500">' . e($libelle) . '</span>'
        . '<span class="text-right text-sm font-medium text-slate-800">'
        . ($html ? ($valeur ?? '—') : e($valeur !== null && $valeur !== '' ? $valeur : '—'))
        . '</span></div>';
}

/** Petite tuile d'indicateur (tableau de bord, caisse, rapports). */
function tuile(string $libelle, string $valeur, array $options = []): string
{
    $html = '<div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">'
        . '<div class="flex items-start justify-between gap-2">'
        . '<p class="text-sm text-slate-500">' . e($libelle) . '</p>';
    if (!empty($options['icone'])) {
        $html .= '<span class="grid size-8 place-items-center rounded-lg bg-marine-50 text-marine-600">'
            . icone($options['icone'], 'size-4') . '</span>';
    }
    $html .= '</div><p class="mt-2 text-xl font-semibold ' . e($options['couleur'] ?? 'text-slate-900') . '">'
        . e($valeur) . '</p>';
    if (!empty($options['detail'])) {
        $html .= '<p class="mt-1 text-xs text-slate-500">' . e($options['detail']) . '</p>';
    }
    return $html . '</div>';
}
