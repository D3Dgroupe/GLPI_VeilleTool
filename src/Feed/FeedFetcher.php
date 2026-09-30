<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Toolbox;

/**
 * La récupération d'un flux distant, et rien d'autre.
 *
 * Trois choses que le simple appel à `Toolbox::getURLContent()` ne permettait
 * pas, et dont chacune a sa raison d'être ici :
 *
 * **Un plafond appliqué pendant le transfert.** Le contrôle de taille venait
 * après coup, sur un document déjà entièrement chargé en mémoire : il
 * constatait l'excès sans l'empêcher. Voir {@see CappedStream}.
 *
 * **Les redirections suivies une par une.** GLPI les suit lui-même, en
 * rappelant sa propre fonction sans compteur : deux adresses qui se renvoient
 * l'une à l'autre font une récursion sans fin. Et le contrôle qu'il refait à
 * chaque saut est celui de sa liste blanche, qui accepte les adresses
 * numériques - une redirection vers `http://127.0.0.1:8080/` la traverse sans
 * encombre.
 *
 * **Un délai total.** GLPI ne borne que l'*établissement* de la connexion.
 * Un serveur qui accepte la connexion puis n'envoie rien immobiliserait la
 * collecte jusqu'à `max_execution_time`, emportant avec lui tous les flux
 * restant à relever.
 *
 * Le client HTTP reste celui de GLPI ({@see Toolbox::getGuzzleClient()}) :
 * le mandataire éventuel et ses identifiants sont ceux de la configuration
 * générale, et il n'est pas question de les redéclarer ici.
 */
final class FeedFetcher
{
    /** Taille maximale acceptée pour un document récupéré (10 Mio) */
    public const MAX_DOCUMENT_SIZE = 10485760;

    /**
     * Durée totale accordée à la récupération d'un flux, en secondes.
     */
    private const FETCH_TIMEOUT = 30;

    /** Délai d'établissement de la connexion, en secondes */
    private const CONNECT_TIMEOUT = 5;

    /**
     * Nombre de redirections suivies avant abandon.
     *
     * Cinq sauts couvrent largement les usages légitimes - changement de
     * domaine, passage en HTTPS, ajout d'une barre oblique finale.
     * Au-delà, c'est une boucle ou un serveur qui se moque de nous.
     */
    private const MAX_REDIRECTS = 5;

    /** Codes de réponse qui désignent une redirection */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** Client HTTP de GLPI, construit une fois pour toute la chaîne */
    private ?Client $client = null;

    /**
     * Récupère un document, en revalidant chaque étape du chemin.
     *
     * @param string $url
     *
     * @return FetchResult
     */
    public function fetch(string $url): FetchResult
    {
        $current = trim($url);
        $chain   = [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Chaque saut est examiné comme l'était le premier : schéma,
            // liste blanche, et surtout adresse réelle de la machine visée.
            // Une redirection n'hérite d'aucune confiance de celle qui
            // l'a précédée.
            $destination = UrlGuard::inspect($current);
            $chain[]     = $current;

            if (!$destination->isAllowed()) {
                return FetchResult::failure(
                    $hop === 0
                        ? (string) $destination->refusal
                        : sprintf(
                            __('Redirection refusée vers %1$s : %2$s', 'veilletool'),
                            $current,
                            (string) $destination->refusal
                        ),
                    $chain
                );
            }

            $sink = new CappedStream(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')), self::MAX_DOCUMENT_SIZE);

            try {
                $response = $this->request($destination, $sink);
            } catch (\Throwable $e) {
                return FetchResult::failure($this->describeFailure($e, $sink), $chain);
            }

            $status = $response->getStatusCode();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $next = $this->nextHop($current, $response, $status);

                if ($next->refusal !== null) {
                    return FetchResult::failure($next->refusal, $chain);
                }

                if (in_array($next->url, $chain, true)) {
                    return FetchResult::failure(
                        __('Les redirections tournent en rond.', 'veilletool'),
                        $chain
                    );
                }

                $current = $next->url;
                continue;
            }

            if ($status < 200 || $status >= 300) {
                return FetchResult::failure(
                    sprintf(__('Le serveur a répondu %d.', 'veilletool'), $status),
                    $chain
                );
            }

            $content = (string) $response->getBody();

            if (trim($content) === '') {
                return FetchResult::failure(__('Contenu vide.', 'veilletool'), $chain);
            }

            return FetchResult::success($content, $chain);
        }

        return FetchResult::failure(
            sprintf(__('Plus de %d redirections successives.', 'veilletool'), self::MAX_REDIRECTS),
            $chain
        );
    }

    /**
     * Exécute une requête vers une destination déjà validée.
     *
     * @param Destination  $destination
     * @param CappedStream $sink
     *
     * @return ResponseInterface
     */
    private function request(Destination $destination, CappedStream $sink): ResponseInterface
    {
        global $CFG_GLPI;

        $curl = [
            // Plafond annoncé : quand le serveur déclare la taille du
            // document, cURL renonce avant d'ouvrir le robinet. Le
            // récipient plafonné couvre les autres cas - taille tue,
            // découpage en morceaux, compression.
            CURLOPT_MAXFILESIZE => self::MAX_DOCUMENT_SIZE,
        ];

        $resolve = $destination->curlResolveEntry();

        if ($resolve !== null) {
            $curl[CURLOPT_RESOLVE] = [$resolve];
        }

        return $this->client()->request('GET', $destination->url, [
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout'         => self::FETCH_TIMEOUT,
            // Les redirections sont notre affaire, pas celle du client.
            'allow_redirects' => false,
            // Un 404 est une réponse à interpréter, pas une exception à
            // rattraper.
            'http_errors'     => false,
            'sink'            => $sink,
            'curl'            => $curl,
            'headers'         => [
                'User-Agent' => 'GLPI/' . trim((string) ($CFG_GLPI['version'] ?? '')) . ' (veilletool)',
                'Accept'     => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.8',
            ],
        ]);
    }

    /**
     * Client HTTP de GLPI, mandataire compris.
     *
     * @return Client
     */
    private function client(): Client
    {
        return $this->client ??= Toolbox::getGuzzleClient();
    }

    /**
     * Adresse de la prochaine étape, ou motif de refus préfixé de `refus:`.
     *
     * Deux règles s'ajoutent à l'examen ordinaire fait au tour suivant.
     *
     * L'en-tête `Location` peut être relatif : il est résolu contre l'adresse
     * courante, faute de quoi une redirection parfaitement légitime vers
     * `/feed/atom` serait rejetée comme illisible.
     *
     * Une redirection de HTTPS vers HTTP est refusée. Le chiffrement d'un
     * échange est une propriété du premier saut ; laisser le serveur distant
     * décider de s'en passer en cours de route revient à ne jamais l'avoir
     * exigé. L'inverse - HTTP vers HTTPS - est au contraire la bonne pratique,
     * et reste accepté.
     *
     * @param string            $current
     * @param ResponseInterface $response
     * @param int               $status
     *
     * @return Redirection
     */
    private function nextHop(string $current, ResponseInterface $response, int $status): Redirection
    {
        $location = trim($response->getHeaderLine('Location'));

        if ($location === '') {
            return Redirection::refused(
                sprintf(__('Redirection %d sans destination exploitable.', 'veilletool'), $status)
            );
        }

        try {
            $target = (string) UriResolver::resolve(new Uri($current), new Uri($location));
        } catch (\Throwable) {
            return Redirection::refused(
                sprintf(__('Redirection vers une adresse illisible : %s', 'veilletool'), $location)
            );
        }

        $from = strtolower((string) parse_url($current, PHP_URL_SCHEME));
        $to   = strtolower((string) parse_url($target, PHP_URL_SCHEME));

        if ($from === 'https' && $to === 'http') {
            return Redirection::refused(sprintf(
                __('Redirection refusée : %s abandonne le chiffrement en cours de route.', 'veilletool'),
                $target
            ));
        }

        return Redirection::to($target);
    }

    /**
     * Met un mot sur un transfert qui s'est interrompu.
     *
     * @param \Throwable   $e
     * @param CappedStream $sink
     *
     * @return string
     */
    private function describeFailure(\Throwable $e, CappedStream $sink): string
    {
        if ($sink->hasExceeded() || str_contains($e->getMessage(), 'Maximum file size exceeded')
            || str_contains($e->getMessage(), 'cURL error 63')) {
            return sprintf(
                __('Document trop volumineux : le téléchargement s\'arrête à %d Mio.', 'veilletool'),
                (int) (self::MAX_DOCUMENT_SIZE / 1048576)
            );
        }

        return $e->getMessage();
    }
}
