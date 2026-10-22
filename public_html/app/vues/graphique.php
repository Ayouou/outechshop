<?php
/**
 * Courbe SVG simple, rendue côté serveur (aucune dépendance JS).
 * Port de `src/components/Graphique.tsx`.
 *
 * `$points` : liste de `['etiquette' => string, 'valeur' => float]`.
 */
function courbeVentes(array $points): string
{
    if (!$points) {
        return '<p class="py-16 text-center text-sm text-slate-400">Aucune donnée sur la période.</p>';
    }

    $L = 720; $H = 240;
    $mHaut = 16; $mDroite = 12; $mBas = 28; $mGauche = 56;
    $largeur = $L - $mGauche - $mDroite;
    $hauteur = $H - $mHaut - $mBas;

    $max = max(array_map(fn($p) => $p['valeur'], $points) + [1]);
    $max = max($max, 1);
    $n = count($points);
    $pas = $n > 1 ? $largeur / ($n - 1) : 0;

    $x = fn(int $i) => $mGauche + $i * $pas;
    $y = fn(float $v) => $mHaut + $hauteur - ($v / $max) * $hauteur;

    $chemin = '';
    foreach ($points as $i => $p) {
        $chemin .= ($i === 0 ? 'M' : ' L') . number_format($x($i), 1, '.', '')
            . ',' . number_format($y($p['valeur']), 1, '.', '');
    }
    $aire = $chemin . ' L' . number_format($x($n - 1), 1, '.', '') . ',' . ($mHaut + $hauteur)
        . ' L' . $mGauche . ',' . ($mHaut + $hauteur) . ' Z';

    $svg = '<div class="overflow-x-auto"><svg viewBox="0 0 ' . $L . ' ' . $H . '" class="h-60 w-full min-w-[560px]" role="img">'
        . '<title>Ventes sur la période</title>';

    foreach ([0, 0.25, 0.5, 0.75, 1] as $g) {
        $yg = $mHaut + $hauteur - $g * $hauteur;
        $svg .= '<line x1="' . $mGauche . '" x2="' . ($L - $mDroite) . '" y1="' . $yg . '" y2="' . $yg . '" stroke="#e2e8f0" stroke-width="1"/>'
            . '<text x="' . ($mGauche - 8) . '" y="' . ($yg + 4) . '" text-anchor="end" font-size="10" fill="#94a3b8">'
            . e(number_format(round($max * $g), 0, ',', ESPACE_FINE)) . '</text>';
    }

    $svg .= '<path d="' . $aire . '" fill="url(#degradeVentes)"/>'
        . '<path d="' . $chemin . '" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linejoin="round"/>'
        . '<defs><linearGradient id="degradeVentes" x1="0" x2="0" y1="0" y2="1">'
        . '<stop offset="0%" stop-color="#4f46e5" stop-opacity="0.22"/>'
        . '<stop offset="100%" stop-color="#4f46e5" stop-opacity="0"/></linearGradient></defs>';

    foreach ($points as $i => $p) {
        $svg .= '<circle cx="' . number_format($x($i), 1, '.', '') . '" cy="' . number_format($y($p['valeur']), 1, '.', '')
            . '" r="2.5" fill="#4f46e5"><title>' . e($p['etiquette'] . ' — ' . montant($p['valeur'])) . '</title></circle>';
    }

    $tous = max(1, (int) ceil($n / 8));
    foreach ($points as $i => $p) {
        if ($i % $tous !== 0) continue;
        $svg .= '<text x="' . number_format($x($i), 1, '.', '') . '" y="' . ($H - 8)
            . '" text-anchor="middle" font-size="10" fill="#94a3b8">' . e($p['etiquette']) . '</text>';
    }

    return $svg . '</svg></div>';
}
