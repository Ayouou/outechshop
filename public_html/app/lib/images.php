<?php
/**
 * Photos stockées en base (table `image`), servies par `/api/images/<id>`.
 * Port de `src/lib/images.ts`.
 *
 * Le disque n'est pas utilisé : une image en base part avec la sauvegarde JSON,
 * donc restaurer un fichier restitue aussi les photos — et rien à re-téléverser
 * après un changement d'hébergement.
 */

/** Garde-fou serveur : le navigateur réduit déjà l'image avant l'envoi. */
const IMAGE_TAILLE_MAX = 2 * 1024 * 1024;

const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

const IMAGE_PREFIXE = '/api/images/';

/** Extrait l'identifiant d'une URL produite par `enregistrerImage`. */
function idImage(?string $url): ?string
{
    if (!$url || !str_starts_with($url, IMAGE_PREFIXE)) return null;
    return substr($url, strlen(IMAGE_PREFIXE)) ?: null;
}

/**
 * URL affichable d'une photo (préfixée par le chemin d'installation).
 *
 * Sur une page publique il n'y a pas de session pour désigner la société :
 * l'image passe alors par `/p/<adresse-du-catalogue>/images/<id>`, la même
 * adresse que la page qui l'affiche. Partout ailleurs, `/api/images/<id>`
 * suffit — la session dit déjà de quelle société il s'agit.
 */
function urlImage(?string $url): ?string
{
    if (!$url) return null;
    if (!str_starts_with($url, IMAGE_PREFIXE)) return $url;

    $adresse = adressePublique();
    if ($adresse !== null) {
        return u('/p/' . rawurlencode($adresse) . '/images/' . substr($url, strlen(IMAGE_PREFIXE)));
    }
    return u($url);
}

/**
 * Enregistre une data URL (`data:image/jpeg;base64,…`) et renvoie l'URL
 * publique de l'image. Renvoie null si l'entrée est vide.
 *
 * @throws RuntimeException si le format ou le poids ne conviennent pas.
 */
function enregistrerImage(?string $dataUrl, ?string $nom = null): ?string
{
    if (!$dataUrl) return null;

    if (!preg_match('#^data:(image/[a-z+]+);base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
        throw new RuntimeException("Format d'image non reconnu.");
    }

    [, $mime, $base64] = $m;
    if (!in_array($mime, IMAGE_MIMES, true)) {
        throw new RuntimeException("Format d'image non pris en charge.");
    }

    $donnees = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);
    if ($donnees === false || $donnees === '') throw new RuntimeException('Image vide.');
    if (strlen($donnees) > IMAGE_TAILLE_MAX) {
        throw new RuntimeException('Image trop lourde (2 Mo maximum).');
    }

    $id = cuid();
    $st = bd()->prepare('INSERT INTO image (id, nom, mime, taille, donnees, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)');
    $st->bindValue(1, $id);
    $st->bindValue(2, $nom);
    $st->bindValue(3, $mime);
    $st->bindValue(4, strlen($donnees), PDO::PARAM_INT);
    $st->bindValue(5, $donnees, PDO::PARAM_LOB);
    $st->bindValue(6, maintenant());
    $st->execute();

    return IMAGE_PREFIXE . $id;
}

/** Supprime l'image pointée par une URL, si elle est bien hébergée ici. */
function supprimerImage(?string $url): void
{
    $id = idImage($url);
    if (!$id) return;
    q('DELETE FROM image WHERE id = ?', [$id]);
}

/**
 * Applique le champ photo d'un formulaire et renvoie la valeur à écrire dans
 * `image_url` — ou `false` pour ne pas toucher à la colonne.
 *
 * Trois cas : une nouvelle image (on la stocke et on efface l'ancienne), un
 * retrait explicite (on efface), ou rien du tout (on ne change rien).
 */
function appliquerImage(?string $ancienneUrl, ?string $nom = null, string $champ = 'image')
{
    $nouvelle = trim((string) ($_POST[$champ] ?? ''));
    $supprimee = ($_POST[$champ . '_supprimee'] ?? '') === '1';

    if ($nouvelle !== '') {
        $url = enregistrerImage($nouvelle, $nom);
        supprimerImage($ancienneUrl);
        return $url;
    }

    if ($supprimee) {
        supprimerImage($ancienneUrl);
        return null;
    }

    return false;
}
