<?php
/**
 * Journal des écritures — table `evenement`, en ajout seul.
 *
 * Une pièce comptable sans historique de ses modifications n'est pas opposable :
 * il faut pouvoir dire qui a changé quoi, quand, et depuis où. Le registre de la
 * plateforme (`saas_journal`) ne suit que les gestes de l'exploitant ; celui-ci
 * vit dans la base de la société et suit ses documents et ses règlements.
 *
 * Rien n'y est jamais modifié ni supprimé : `journaliser()` écrit, personne ne
 * réécrit. C'est aussi la base de l'archivage exigé sur dix ans.
 */

/**
 * L'écriture est sans effet tant que la base n'a pas reçu la table.
 *
 * Une société installée avant cette version n'a pas encore `evenement` : la
 * journalisation doit alors se taire, surtout pas faire échouer la transaction
 * de la facture qui l'entoure. `bases.php --migrer` pose la table, et le
 * journal démarre de lui-même.
 */
function journalDisponible(): bool
{
    static $cache = [];
    $base = baseActive();
    if (isset($cache[$base])) return $cache[$base];

    try {
        $existe = tableExiste('evenement');
    } catch (Throwable $e) {
        $existe = false;
    }

    return $cache[$base] = $existe;
}

/**
 * Inscrit un événement.
 *
 * `$avant` et `$apres` sont les lignes concernées, telles quelles : l'une des
 * deux est nulle à la création et à la suppression. À appeler **dans** la
 * transaction de l'opération, pour qu'un incident emporte les deux ensemble.
 */
function journaliserEvenement(
    string $objet,
    string $objetId,
    string $action,
    ?array $avant = null,
    ?array $apres = null,
    ?array $session = null,
): void {
    if (!journalDisponible()) return;

    q('INSERT INTO evenement (objet, objet_id, action, avant, apres, user_id, ip, horodatage)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $objet,
        $objetId,
        $action,
        $avant === null ? null : json_encode($avant, JSON_UNESCAPED_UNICODE),
        $apres === null ? null : json_encode($apres, JSON_UNESCAPED_UNICODE),
        $session['user_id'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        maintenant(),
    ]);
}

/** Historique d'un objet, du plus récent au plus ancien. */
function historiqueEvenements(string $objet, string $objetId, int $limite = 100): array
{
    if (!journalDisponible()) return [];

    return tous('SELECT e.*, u.nom AS auteur
                   FROM evenement e
                   LEFT JOIN utilisateur u ON u.id = e.user_id
                  WHERE e.objet = ? AND e.objet_id = ?
                  ORDER BY e.id DESC
                  LIMIT ' . max(1, $limite), [$objet, $objetId]);
}
