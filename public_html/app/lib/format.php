<?php
/**
 * Mise en forme : nombres, montants, dates — au format français, comme
 * l'application d'origine (`src/lib/format.ts`).
 */

const ESPACE_FINE = "\u{202F}"; // séparateur de milliers français

/** Échappement HTML — à utiliser sur toute donnée affichée. */
function e($valeur): string
{
    return htmlspecialchars((string) ($valeur ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Convertit une valeur de base (DECIMAL renvoyé en chaîne) en float. */
function num($v): float
{
    if ($v === null || $v === '' || $v === false) return 0.0;
    if (is_float($v) || is_int($v)) return (float) $v;
    return (float) str_replace(',', '.', (string) $v);
}

/** Arrondi comptable au centime. */
function arrondir(float $valeur): float
{
    return round($valeur, 2);
}

function montant($v, string $devise = 'dhs'): string
{
    return trim(number_format(num($v), 2, ',', ESPACE_FINE) . ' ' . $devise);
}

/** Quantité : jusqu'à 3 décimales, sans zéros inutiles. */
function nombre($v): string
{
    $valeur = num($v);
    $texte = number_format($valeur, 3, ',', ESPACE_FINE);
    $texte = rtrim(rtrim($texte, '0'), ',');
    return $texte === '' || $texte === '-' ? '0' : $texte;
}

/** Pourcentage court : « 20 % », « 7,5 % ». */
function pourcent($v): string
{
    return nombre($v) . ' %';
}

function dateFr($d): string
{
    $date = enDate($d);
    return $date ? $date->format('d/m/Y') : '—';
}

function dateHeureFr($d): string
{
    $date = enDate($d);
    return $date ? $date->format('d/m/Y H:i') : '—';
}

function heureFr($d): string
{
    $date = enDate($d);
    return $date ? $date->format('H:i') : '—';
}

/** Format `yyyy-MM-dd` pour les `<input type="date">`. */
function dateInput($d): string
{
    $date = enDate($d);
    return $date ? $date->format('Y-m-d') : '';
}

function enDate($d): ?DateTime
{
    if ($d === null || $d === '' || $d === '0000-00-00 00:00:00') return null;
    if ($d instanceof DateTime) return $d;
    if ($d instanceof DateTimeInterface) return DateTime::createFromFormat('U.u', $d->format('U.u')) ?: null;
    try {
        return new DateTime((string) $d);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * `Négoce d'Atlas` → `negoce-d-atlas`.
 *
 * Les accents sont convertis avant tout appel à `iconv` : selon la
 * bibliothèque installée (Windows notamment), `ASCII//TRANSLIT` supprime le
 * caractère accentué au lieu de le translittérer, et « négoce » devenait
 * « ngoce ».
 */
function slugifier(string $texte): string
{
    $texte = strtr($texte, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C', 'Œ' => 'OE', 'Æ' => 'AE',
    ]);

    $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
    if ($sans === false) $sans = $texte;
    $sans = strtolower($sans);
    $sans = preg_replace('/[^a-z0-9]+/', '-', $sans) ?? '';
    return trim($sans, '-');
}

/* -------------------------------------------------------------- WhatsApp */

/** Indicatif utilisé quand le numéro est saisi au format local (06…). */
const INDICATIF_PAYS = '212';

/**
 * Numéro au format attendu par WhatsApp : que des chiffres, indicatif compris.
 * `06 12 34 56 78` et `+212 612-345-678` donnent tous deux `212612345678`.
 * Renvoie null si le numéro ne ressemble à rien d'utilisable.
 */
function numeroWhatsapp(?string $numero): ?string
{
    $chiffres = preg_replace('/\D+/', '', (string) $numero) ?? '';
    if ($chiffres === '') return null;

    // 00212… → 212…
    if (str_starts_with($chiffres, '00')) $chiffres = substr($chiffres, 2);
    // 0612345678 → 212612345678
    if (str_starts_with($chiffres, '0')) $chiffres = INDICATIF_PAYS . substr($chiffres, 1);

    return strlen($chiffres) >= 9 ? $chiffres : null;
}

/** Lien de conversation WhatsApp, message pré-écrit. */
function lienWhatsapp(?string $numero, string $message = ''): ?string
{
    $numero = numeroWhatsapp($numero);
    if ($numero === null) return null;

    return 'https://wa.me/' . $numero . ($message === '' ? '' : '?text=' . rawurlencode($message));
}

/** Début de journée locale (00:00:00.000). */
function debutJour($d = 'now'): string
{
    $date = enDate($d) ?? new DateTime();
    return $date->format('Y-m-d') . ' 00:00:00.000';
}

/** Fin de journée locale (23:59:59.999). */
function finJour($d = 'now'): string
{
    $date = enDate($d) ?? new DateTime();
    return $date->format('Y-m-d') . ' 23:59:59.999';
}

/** Nombre lu depuis un formulaire : virgule décimale acceptée. */
function nombreSaisi($valeur, float $defaut = 0): float
{
    if ($valeur === null || $valeur === '') return $defaut;
    $texte = str_replace([' ', ESPACE_FINE, "\u{00A0}"], '', (string) $valeur);
    $texte = str_replace(',', '.', $texte);
    return is_numeric($texte) ? (float) $texte : $defaut;
}
