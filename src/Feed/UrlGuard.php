<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use Toolbox;

/**
 * Le portier des adresses distantes.
 *
 * Un flux est une adresse fournie par un utilisateur, que le serveur va
 * ouvrir lui-même : c'est la définition d'une falsification de requête côté
 * serveur (SSRF).
 * La liste blanche de GLPI ne suffit pas à s'en prémunir - son expression
 * régulière accepte explicitement les adresses numériques, `127.0.0.1` et
 * `192.168.1.1` comprises.
 *
 * Trois contrôles s'ajoutent donc ici :
 *
 * - **le schéma**, restreint à http et https ; GLPI accepte aussi `feed://`,
 *   dont cURL ne sait rien faire ;
 * - **la destination réelle**, résolue ici et comparée aux plages privées,
 *   réservées et de bouclage - un nom public résolvant vers `169.254.169.254`
 *   ouvrirait le service de métadonnées de l'hébergeur ;
 * - **l'épinglage** du résultat, pour que la machine jointe soit celle qui a
 *   été examinée, et pas celle qu'une seconde résolution renverrait.
 *
 * Ce dernier point est ce qui distingue un contrôle d'une formalité. Vérifier
 * puis laisser cURL résoudre à nouveau laisse une fenêtre entre les deux
 * résolutions : un serveur de noms complice y répond une adresse publique à
 * la première, une adresse interne à la seconde. C'est la réattribution de
 * nom (« DNS rebinding »), et l'épinglage la referme.
 */
final class UrlGuard
{
    /** Longueur maximale acceptée pour une adresse */
    private const MAX_URL_LENGTH = 2048;

    /**
     * Adresses déjà résolues, pour la durée de la requête.
     *
     * Une collecte relève plusieurs flux d'un même domaine : les résoudre une
     * fois suffit, et la résolution est ce qui coûte le plus cher avant même
     * qu'une connexion soit tentée.
     *
     * @var array<string, string[]>
     */
    private static array $resolved = [];

    /**
     * Une adresse est-elle exploitable et autorisée par la configuration
     * GLPI ?
     *
     * Contrôle de forme uniquement : il ne dit rien de la machine visée.
     * C'est ce qu'on demande aux liens trouvés dans un flux, qui ne seront
     * pas ouverts par le serveur mais affichés à l'utilisateur.
     *
     * @param string $url
     *
     * @return bool
     */
    public static function isAcceptable(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return Toolbox::isUrlSafe($url);
    }

    /**
     * Examen complet d'une adresse que le serveur s'apprête à ouvrir.
     *
     * @param string $url
     *
     * @return Destination
     */
    public static function inspect(string $url): Destination
    {
        $url = trim($url);

        if (!self::isAcceptable($url)) {
            return Destination::refused(
                $url,
                __('Adresse refusée : elle doit être en http(s) et passer la liste blanche de GLPI.', 'veilletool')
            );
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return Destination::refused($url, __('Adresse sans nom d\'hôte.', 'veilletool'));
        }

        // Les crochets de la forme IPv6 littérale ne font pas partie de
        // l'adresse elle-même.
        $host   = trim($host, '[]');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port   = (int) (parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));

        // --- Adresse déjà numérique : rien à résoudre, tout à vérifier ---
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicAddress($host)
                ? Destination::direct($url, $host, $port)
                : Destination::refused($url, self::privateRefusal($host));
        }

        // --- Collecte passant par un mandataire ---------------------------
        //
        // La résolution n'a alors pas lieu sur ce serveur : c'est le
        // mandataire qui joint le flux, et lui seul sait vers quelle adresse.
        // Épingler une résolution faite ici n'aurait aucun effet, et refuser
        // un nom que ce serveur ne sait pas résoudre couperait toute collecte
        // sur un réseau où c'est précisément le rôle du mandataire.
        if (self::behindProxy()) {
            return Destination::direct($url, $host, $port);
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            return Destination::refused(
                $url,
                sprintf(__('%s n\'a pas pu être résolu.', 'veilletool'), $host)
            );
        }

        // Une seule adresse privée dans la réponse suffit à écarter le nom.
        // Un jeu mêlant public et interne n'est pas une configuration
        // ordinaire : c'est la forme que prend une tentative de
        // réattribution de nom.
        foreach ($addresses as $address) {
            if (!self::isPublicAddress($address)) {
                return Destination::refused($url, self::privateRefusal($address, $host));
            }
        }

        return Destination::pinned($url, $host, $port, $addresses);
    }

    /**
     * Une adresse IP est-elle sur l'Internet public ?
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` écarte les plages privées (10/8, 172.16/12,
     * 192.168/16, fc00::/7), `FILTER_FLAG_NO_RES_RANGE` les plages réservées
     * (0/8, 127/8, 169.254/16, 240/4, ::1, ::, fe80::/10, et les adresses
     * IPv4 déguisées en IPv6 de ::ffff:0:0/96).
     *
     * Reste la plage 100.64/10 des opérateurs, que PHP ne classe ni privée ni
     * réservée alors qu'elle ne désigne rien de joignable publiquement.
     *
     * @param string $ip
     *
     * @return bool
     */
    public static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long !== false && ($long & 0xFFC00000) === (100 << 24 | 64 << 16)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adresses IP d'un hôte, mémorisées pour la durée de la requête.
     *
     * L'IPv4 passe par `gethostbynamel()`, qui emprunte le résolveur du
     * système - fichier `hosts` et chaînes d'alias compris.
     * L'IPv6 n'y a pas d'équivalent : il est demandé à part, et seulement si
     * le nom n'a aucune adresse IPv4, pour ne pas payer une seconde
     * interrogation dans le cas courant.
     *
     * @param string $host
     *
     * @return string[] Vide si le nom n'a pas pu être résolu
     */
    public static function resolve(string $host): array
    {
        if (array_key_exists($host, self::$resolved)) {
            return self::$resolved[$host];
        }

        $addresses = @gethostbynamel($host);
        $addresses = is_array($addresses) ? array_values($addresses) : [];

        if ($addresses === [] && function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);

            foreach (is_array($records) ? $records : [] as $record) {
                $ipv6 = (string) ($record['ipv6'] ?? '');

                if ($ipv6 !== '') {
                    $addresses[] = $ipv6;
                }
            }
        }

        return self::$resolved[$host] = $addresses;
    }

    /**
     * Oublie les résolutions mémorisées.
     *
     * Une collecte longue ne doit pas s'appuyer indéfiniment sur un cliché
     * pris à son début.
     *
     * @return void
     */
    public static function forget(): void
    {
        self::$resolved = [];
    }

    /**
     * GLPI est-il configuré pour sortir par un mandataire ?
     *
     * @return bool
     */
    public static function behindProxy(): bool
    {
        global $CFG_GLPI;

        return trim((string) ($CFG_GLPI['proxy_name'] ?? '')) !== '';
    }

    /**
     * Motif de refus d'une adresse qui n'est pas publique.
     *
     * @param string $ip
     * @param string $host Nom qui a mené à cette adresse, vide si l'adresse
     *                     était déjà numérique
     *
     * @return string
     */
    private static function privateRefusal(string $ip, string $host = ''): string
    {
        if ($host === '') {
            return sprintf(
                __('Adresse refusée : %s appartient au réseau interne, que le serveur ne doit pas interroger pour le compte d\'un flux.', 'veilletool'),
                $ip
            );
        }

        return sprintf(
            __('Adresse refusée : %1$s est résolu en %2$s, qui appartient au réseau interne.', 'veilletool'),
            $host,
            $ip
        );
    }
}
