<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

/**
 * Éprouve la récupération d'une adresse, étape par étape.
 *
 * « Délai dépassé » ne dit pas où : la résolution du nom, l'ouverture de la
 * connexion et le téléchargement échouent du même mot.
 * Les étapes sont donc mesurées séparément - c'est la seule façon de
 * distinguer un flux injoignable d'un serveur DNS défaillant, alors même que
 * l'adresse s'ouvre sans peine depuis un navigateur.
 *
 * Aucune carte n'est créée : ce contrôle ne lit rien et n'écrit rien.
 */
final class FeedDiagnostic
{
    /** Durée au-delà de laquelle une résolution est jugée trop lente (ms) */
    private const SLOW_RESOLUTION = 4000;

    /**
     * @param string $url
     *
     * @return array<int, array{step:string, ok:bool, detail:string}>
     */
    public static function run(string $url): array
    {
        global $CFG_GLPI;

        $url    = trim($url);
        $report = [];

        // --- L'adresse est-elle recevable ? ---------------------------
        $acceptable = UrlGuard::isAcceptable($url);
        $report[]   = [
            'step'   => __('Adresse', 'veilletool'),
            'ok'     => $acceptable,
            'detail' => $acceptable
                ? $url
                : __('Refusée : elle doit être en http(s) et passer la liste blanche de GLPI.', 'veilletool'),
        ];

        if (!$acceptable) {
            return $report;
        }

        // --- Résolution du nom, chronométrée et jugée -----------------
        //
        // Deux choses se vérifient ici. La durée d'abord : la résolution est
        // comprise dans le délai de connexion, et un serveur de noms qui ne
        // répond pas la fait durer à elle seule, quel que soit l'état du flux
        // visé. La destination ensuite : un nom public qui pointe vers le
        // réseau interne est refusé, et il vaut mieux l'apprendre ici que
        // sous la forme d'une collecte muette.
        $report[] = self::resolutionStep($url);

        // --- Mandataire éventuel --------------------------------------
        if (UrlGuard::behindProxy()) {
            $report[] = [
                'step'   => __('Mandataire', 'veilletool'),
                'ok'     => true,
                'detail' => sprintf(
                    __('GLPI passe par %1$s:%2$s. La résolution du nom a lieu chez lui, et les échecs de connexion viendront de lui avant de venir du flux.', 'veilletool'),
                    trim((string) ($CFG_GLPI['proxy_name'] ?? '')),
                    (string) ($CFG_GLPI['proxy_port'] ?? '')
                ),
            ];
        }

        // --- Récupération effective, dans les conditions de la collecte -
        $start   = microtime(true);
        $result  = (new FeedFetcher())->fetch($url);
        $elapsed = (microtime(true) - $start) * 1000;

        $report[] = [
            'step'   => __('Récupération', 'veilletool'),
            'ok'     => $result->isSuccess(),
            'detail' => $result->isSuccess()
                ? sprintf(
                    __('%1$s octets reçus en %2$d ms.', 'veilletool'),
                    number_format(strlen($result->content), 0, ',', ' '),
                    (int) round($elapsed)
                )
                : sprintf(
                    __('Échec au bout de %1$d ms. %2$s', 'veilletool'),
                    (int) round($elapsed),
                    (string) $result->error
                ),
        ];

        if ($result->redirectCount() > 0) {
            $report[] = [
                'step'   => __('Redirections', 'veilletool'),
                'ok'     => $result->isSuccess(),
                'detail' => sprintf(
                    __('%1$d saut(s) : %2$s', 'veilletool'),
                    $result->redirectCount(),
                    implode(' → ', $result->chain)
                ),
            ];
        }

        if (!$result->isSuccess()) {
            return $report;
        }

        // --- Analyse du document ---------------------------------------
        $xml = FeedParser::toXml($result->content);

        $report[] = [
            'step'   => __('Format', 'veilletool'),
            'ok'     => $xml !== null,
            'detail' => $xml !== null
                ? sprintf(__('Document XML valide, racine « %s ».', 'veilletool'), $xml->getName())
                : __('Le document n\'est pas un XML exploitable : ni RSS, ni RDF, ni Atom.', 'veilletool'),
        ];

        return $report;
    }

    /**
     * Étape de résolution du nom.
     *
     * @param string $url
     *
     * @return array{step:string, ok:bool, detail:string}
     */
    private static function resolutionStep(string $url): array
    {
        $step = __('Résolution du nom', 'veilletool');
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if (UrlGuard::behindProxy()) {
            return [
                'step'   => $step,
                'ok'     => true,
                'detail' => __('Déléguée au mandataire : ce serveur ne résout pas le nom lui-même.', 'veilletool'),
            ];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = UrlGuard::isPublicAddress($host);

            return [
                'step'   => $step,
                'ok'     => $public,
                'detail' => $public
                    ? sprintf(__('%s est déjà une adresse numérique.', 'veilletool'), $host)
                    : sprintf(__('%s appartient au réseau interne : le serveur refuse de l\'interroger.', 'veilletool'), $host),
            ];
        }

        UrlGuard::forget();

        $start     = microtime(true);
        $addresses = UrlGuard::resolve($host);
        $elapsed   = (microtime(true) - $start) * 1000;

        if ($addresses === []) {
            return [
                'step'   => $step,
                'ok'     => false,
                'detail' => sprintf(__('%s n\'a pas pu être résolu.', 'veilletool'), $host),
            ];
        }

        $private = array_values(array_filter(
            $addresses,
            static fn(string $ip): bool => !UrlGuard::isPublicAddress($ip)
        ));

        if ($private !== []) {
            return [
                'step'   => $step,
                'ok'     => false,
                'detail' => sprintf(
                    __('%1$s est résolu en %2$s, qui appartient au réseau interne. La collecte est refusée : un flux ne doit pas servir à faire interroger le réseau local par le serveur.', 'veilletool'),
                    $host,
                    implode(', ', $private)
                ),
            ];
        }

        $slow = $elapsed >= self::SLOW_RESOLUTION;

        return [
            'step'   => $step,
            'ok'     => !$slow,
            'detail' => sprintf(
                __('%1$s → %2$s en %3$d ms.', 'veilletool'),
                $host,
                implode(', ', $addresses),
                (int) round($elapsed)
            ) . ($slow
                ? ' ' . __('C\'est trop long : la connexion est abandonnée au bout de 5 secondes, résolution comprise. Vérifiez les serveurs de noms de /etc/resolv.conf - l\'un d\'eux ne répond probablement pas.', 'veilletool')
                : ''),
        ];
    }
}
