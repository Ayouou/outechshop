<?php
/** Catalogue public : adresse, QR code et options d'affichage. */

$session = exigerSession();
$societeId = $session['societe_id'];

$erreur = null;

if (estPost()) {
    $titre = (string) champ('titre', '');
    $slug = slugifier((string) champ('slug', ''));

    $domaineSaisi = trim((string) champ('domaine', ''));
    $domaine = normaliserDomaine($domaineSaisi);

    if ($titre === '') {
        $erreur = 'Le titre est obligatoire';
    } elseif (mb_strlen($slug) < 3) {
        $erreur = "L'adresse est obligatoire (3 caractères minimum)";
    } elseif (!adresseDisponible($slug, $societeId)) {
        // L'adresse ne porte plus le code de la société : elle est donc
        // partagée par toutes, et se réserve comme un nom de domaine.
        $erreur = 'Cette adresse est déjà prise par un autre commerçant. '
            . 'Essayez par exemple « ' . e($slug) . '-' . slugifier(societe()['ville'] ?? 'boutique') . ' ».';
    } else {
        $donnees = [
            'titre'            => $titre,
            'sous_titre'       => ouNull(champ('sous_titre')),
            'slug'             => $slug,
            'actif'            => champBool('actif') ? 1 : 0,
            'afficher_prix'    => champBool('afficher_prix') ? 1 : 0,
            'whatsapp'         => ouNull(champ('whatsapp')),
            'whatsapp_actif'   => champBool('whatsapp_actif') ? 1 : 0,
            'whatsapp_message' => ouNull(champ('whatsapp_message')),
            'theme'            => themeBoutique(['theme' => champ('theme')]),
            'couleur'          => couleurBoutique(['couleur' => champ('couleur')]),
            'domaine'          => $domaine,
        ];

        // Commande par WhatsApp cochée sans numéro exploitable : autant le
        // dire tout de suite, le bouton ne mènerait nulle part.
        if ($donnees['whatsapp_actif'] && numeroWhatsapp($donnees['whatsapp']) === null) {
            $erreur = 'Renseignez un numéro WhatsApp valide (06 12 34 56 78 ou +212 6 12 34 56 78).';
        } elseif ($domaineSaisi !== '' && $domaine === null) {
            $erreur = "« $domaineSaisi » n'est pas un nom de domaine valide. "
                . 'Attendu : boutique.ma, ou www.boutique.ma.';
        } elseif (!domaineDisponible($domaine, $societeId)) {
            $erreur = 'Ce domaine est déjà rattaché à un autre commerçant.';
        }
    }

    if (!$erreur && isset($donnees)) {
        try {
            $existant = un('SELECT id FROM catalogue WHERE lieu_id = ?', [$session['lieu_id']]);
            if ($existant) modifier('catalogue', $existant['id'], $donnees);
            else inserer('catalogue', $donnees + ['lieu_id' => $session['lieu_id']]);

            reserverAdressePublique($slug, $societeId, $session['lieu_id']);
            reserverDomaine($domaine, $societeId, $slug);
            rediriger('/catalogue', 'Catalogue enregistré.');
        } catch (PDOException $e) {
            $erreur = 'Cette adresse est déjà utilisée par un autre catalogue.';
        }
    }
}

$lieu = lieuCourant();

$catalogue = un('SELECT * FROM catalogue WHERE lieu_id = ?', [$session['lieu_id']]) ?? [
    'titre'            => $lieu['nom'] ?? 'Notre catalogue',
    'sous_titre'       => '',
    'slug'             => slugifier($lieu['nom'] ?? 'catalogue'),
    'actif'            => 1,
    'afficher_prix'    => 1,
    // Le téléphone du lieu est le point de départ le plus probable.
    'whatsapp'         => $lieu['telephone'] ?? '',
    'whatsapp_actif'   => 0,
    'whatsapp_message' => '',
    'theme'            => THEME_BOUTIQUE_DEFAUT,
    'couleur'          => COULEUR_BOUTIQUE_DEFAUT,
    'domaine'          => null,
];

// Catalogue enregistré avant la mise en place des adresses partagées, ou
// adresse restée libre : on la réserve au passage, sans rien demander.
if (!empty($catalogue['id']) && adresseDisponible($catalogue['slug'], $societeId)) {
    reserverAdressePublique($catalogue['slug'], $societeId, $session['lieu_id']);
}

$articles = (int) valeur('SELECT COUNT(*) FROM article WHERE actif = 1');

page('Catalogue', function () use ($catalogue, $erreur, $articles) {
    echo enTetePage('Catalogue public', 'Page publique consultable sans compte, avec QR code');

    // L'adresse est celle que le commerçant a choisie — rien d'autre : ni code
    // société, ni identifiant technique sur une affiche de vitrine.
    $adresse = '/c/' . $catalogue['slug'];
    // URL absolue : c'est elle qu'on partage et qu'encode le QR code.
    $url = adresseCatalogue($catalogue);
    ?>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
      <div class="lg:col-span-2">
        <?= carteDebut('Réglages') ?>
          <form method="post" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <?= champCsrf() ?>
            <?php if ($erreur) echo '<div class="sm:col-span-2">' . alerte(e($erreur), 'erreur') . '</div>'; ?>
            <?= champBloc('Titre', saisie('titre', $catalogue['titre'], ['attrs' => ['required' => true]]), ['obligatoire' => true]) ?>
            <?= champBloc('Sous-titre', saisie('sous_titre', $catalogue['sous_titre'])) ?>
            <div class="sm:col-span-2">
              <?= champBloc('Adresse publique', saisie('slug', $catalogue['slug'], [
                  'id' => 'slug',
                  'attrs' => ['required' => true, 'minlength' => 3, 'oninput' => 'majLien(this.value)'],
              ]), ['obligatoire' => true,
                   'aide' => "Le nom de votre boutique, tel qu'il apparaîtra dans le lien. "
                       . 'Lettres, chiffres et tirets. Une adresse déjà prise par un autre commerçant est refusée.']) ?>
              <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs break-all text-slate-600 ring-1 ring-inset ring-slate-200">
                <?= e(urlAbsolue('/c/')) ?><strong id="apercu-lien" class="text-marine-700"><?= e($catalogue['slug']) ?></strong>
              </p>
            </div>
            <div><?= caseACocher('actif', (bool) $catalogue['actif'], 'Catalogue en ligne') ?></div>
            <div><?= caseACocher('afficher_prix', (bool) $catalogue['afficher_prix'], 'Afficher les prix') ?></div>

            <div class="sm:col-span-2 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
              <?= caseACocher('whatsapp_actif', (bool) $catalogue['whatsapp_actif'],
                  'Recevoir les commandes sur WhatsApp') ?>
              <p class="mt-1 text-xs text-emerald-800/80">
                Un bouton « Commander » apparaît sur chaque article, et le client vous écrit
                directement — avec le nom de l'article déjà dans le message. Aucun compte à créer
                pour lui.
              </p>

              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?= champBloc('Numéro WhatsApp', saisie('whatsapp', $catalogue['whatsapp'], [
                    'type' => 'tel', 'attrs' => ['placeholder' => '06 12 34 56 78'],
                ]), ['aide' => 'Format local ou international : les deux sont acceptés.']) ?>
                <?= champBloc('Début du message', saisie('whatsapp_message', $catalogue['whatsapp_message'], [
                    'attrs' => ['placeholder' => 'Bonjour, je souhaite commander :', 'maxlength' => 255],
                ]), ['aide' => 'Laissé vide, une formule de politesse est utilisée.']) ?>
              </div>
            </div>

            <div class="sm:col-span-2" id="apparence" style="--apercu: <?= e(couleurBoutique($catalogue)) ?>">
              <p class="text-sm font-semibold text-slate-800">Apparence</p>
              <p class="mt-0.5 text-xs text-slate-500">
                La mise en page et la couleur de votre page publique. Vos articles, vos prix et
                vos photos sont les mêmes dans les trois — c'est la présentation qui change.
              </p>

              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <?php foreach (THEMES_BOUTIQUE as $cle => $t): ?>
                  <label class="cursor-pointer">
                    <input type="radio" name="theme" value="<?= e($cle) ?>" class="peer sr-only"
                           <?= themeBoutique($catalogue) === $cle ? 'checked' : '' ?>>
                    <div class="h-full rounded-xl p-3 ring-1 ring-slate-200 transition
                                hover:ring-slate-300 peer-checked:bg-slate-50 peer-checked:ring-2
                                peer-checked:ring-marine-600 peer-focus-visible:ring-2">
                      <!-- Vignette : des proportions, pas une capture — une capture
                           mentirait au premier changement de gabarit. -->
                      <div class="mb-3 overflow-hidden rounded-lg bg-white ring-1 ring-slate-200">
                        <?php if ($cle === 'vitrine'): ?>
                          <div class="h-5" style="background: var(--apercu)"></div>
                          <div class="grid grid-cols-3 gap-1 p-1.5">
                            <?php for ($i = 0; $i < 6; $i++): ?><div class="aspect-square rounded bg-slate-200"></div><?php endfor; ?>
                          </div>
                        <?php elseif ($cle === 'liste'): ?>
                          <div class="h-1" style="background: var(--apercu)"></div>
                          <div class="space-y-1.5 p-2">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                              <div class="flex items-center gap-1.5">
                                <div class="size-4 shrink-0 rounded bg-slate-200"></div>
                                <div class="h-1.5 flex-1 rounded bg-slate-100"></div>
                                <div class="h-1.5 w-5 rounded" style="background: var(--apercu); opacity: .55"></div>
                              </div>
                            <?php endfor; ?>
                          </div>
                        <?php else: ?>
                          <div class="p-2">
                            <div class="mx-auto mb-1.5 h-1 w-5 rounded-full" style="background: var(--apercu)"></div>
                            <div class="mx-auto mb-2 h-1.5 w-14 rounded bg-slate-300"></div>
                            <div class="grid grid-cols-2 gap-1.5">
                              <div class="aspect-[4/5] rounded bg-slate-200"></div>
                              <div class="aspect-[4/5] rounded bg-slate-200"></div>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>

                      <p class="text-sm font-semibold text-slate-800"><?= e($t['nom']) ?></p>
                      <p class="mt-0.5 text-xs text-slate-600"><?= e($t['resume']) ?></p>
                      <p class="mt-1.5 text-xs text-slate-400"><?= e($t['pour']) ?></p>
                    </div>
                  </label>
                <?php endforeach; ?>
              </div>

              <p class="mt-5 text-sm font-medium text-slate-700">Couleur</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <?php foreach (COULEURS_BOUTIQUE as $hex => $nom): ?>
                  <label class="cursor-pointer" title="<?= e($nom) ?>">
                    <input type="radio" name="couleur" value="<?= e($hex) ?>" class="peer sr-only"
                           <?= couleurBoutique($catalogue) === $hex ? 'checked' : '' ?>
                           onchange="document.getElementById('apparence').style.setProperty('--apercu', this.value)">
                    <span class="block size-9 rounded-full ring-1 ring-slate-300 ring-offset-2 transition
                                 peer-checked:ring-2 peer-checked:ring-slate-900 peer-focus-visible:ring-2"
                          style="background: <?= e($hex) ?>"></span>
                    <span class="sr-only"><?= e($nom) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="sm:col-span-2 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
              <p class="text-sm font-semibold text-slate-800">Votre propre nom de domaine <span class="font-normal text-slate-400">— facultatif</span></p>
              <p class="mt-0.5 text-xs text-slate-600">
                Sans domaine, votre catalogue reste à l'adresse <code class="rounded bg-white px-1 py-0.5 ring-1 ring-slate-200"><?= e(urlAbsolue('/c/' . $catalogue['slug'])) ?></code> —
                elle fonctionne très bien. Avec un domaine, la même page répond aussi sur le vôtre.
              </p>

              <div class="mt-3 sm:max-w-sm">
                <?= champBloc('Nom de domaine', saisie('domaine', $catalogue['domaine'] ?? '', [
                    'attrs' => ['placeholder' => 'boutique-atlas.ma', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
                ])) ?>
              </div>

              <details class="mt-3 text-xs text-slate-600">
                <summary class="cursor-pointer font-medium text-slate-700 hover:text-marine-700">
                  Comment le brancher (3 étapes, dont 2 chez votre hébergeur)
                </summary>
                <ol class="mt-2 list-decimal space-y-1.5 pl-4">
                  <li>Achetez le domaine, puis faites pointer son <strong>enregistrement A</strong> vers l'adresse IP de ce serveur.</li>
                  <li>Déclarez le domaine chez l'hébergeur de la plateforme (panneau → domaines) et faites délivrer son <strong>certificat HTTPS</strong>.</li>
                  <li>Écrivez-le ci-dessus et enregistrez. Dès que les deux premières étapes sont faites, le domaine ouvre votre catalogue.</li>
                </ol>
                <p class="mt-2 text-slate-500">
                  Tant que le DNS n'est pas propagé (jusqu'à 24 h) ou le certificat pas délivré, le domaine
                  ne répondra pas — ce n'est pas un réglage à corriger ici, mais une étape à finir là-bas.
                </p>
              </details>
            </div>

            <div class="sm:col-span-2 flex justify-end"><?= bouton('Enregistrer') ?></div>
          </form>
        <?= carteFin() ?>
      </div>

      <div class="space-y-5">
        <?= carteDebut('Partager') ?>
          <p class="mb-3 break-all text-sm text-marine-600"><?= e($url) ?></p>
          <div id="qr" class="mx-auto grid place-items-center"></div>
          <div class="mt-4 space-y-2">
            <?= boutonLien($adresse, 'Ouvrir le catalogue', ['variante' => 'secondaire', 'classe' => 'w-full', 'attrs' => ['target' => '_blank']]) ?>
            <button type="button" onclick="window.print()" class="<?= classesBouton('secondaire', 'w-full') ?>">
              <?= icone('imprimante', 'size-4') ?> Imprimer le QR code
            </button>
          </div>
        <?= carteFin() ?>

        <?= carteDebut('Contenu') ?>
          <p class="text-sm text-slate-600"><?= $articles ?> article(s) actif(s) publiés, avec leur photo et leur disponibilité.</p>
        <?= carteFin() ?>
      </div>
    </div>

    <script src="<?= e(u('/assets/qrcode.js')) ?>"></script>
    <script>
      // QR code tracé côté navigateur, sans service externe.
      const qr = qrcode(0, 'M');
      qr.addData(<?= json_encode($url) ?>);
      qr.make();
      document.getElementById('qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });

      // Le lien se réécrit pendant la frappe, avec la même mise en forme que
      // celle appliquée à l'enregistrement : pas de surprise après coup.
      function majLien(valeur) {
        const propre = valeur.toLowerCase().normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        document.getElementById('apercu-lien').textContent = propre;
      }
    </script>
<?php });
