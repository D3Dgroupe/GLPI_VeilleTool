<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

/**
 * Verdict rendu sur une adresse à joindre.
 *
 * Trois questions se posent avant d'ouvrir une connexion - l'adresse est-elle
 * recevable, quelle machine désigne-t-elle, cette machine est-elle sur
 * l'Internet public - et une seule réponse en sort : on y va, ou on n'y va
 * pas, et dans ce cas pour un motif présentable à l'utilisateur.
 *
 * Réunir les trois dans un même objet évite au récupérateur de refaire le
 * raisonnement à chaque saut de redirection : il demande le verdict, et s'y
 * tient.
 */
final class Destination
{
    /**
     * @param string   $url       Adresse examinée
     * @param string   $host      Hôte extrait de l'adresse
     * @param int      $port      Port effectif, déduit du schéma si absent
     * @param string[] $addresses Adresses IP retenues, vides si non épinglé
     * @param ?string  $refusal   Motif du refus, null si l'adresse est admise
     */
    private function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
        public readonly ?string $refusal,
    ) {
    }

    /**
     * Adresse écartée, avec son motif.
     *
     * @param string $url
     * @param string $reason
     *
     * @return self
     */
    public static function refused(string $url, string $reason): self
    {
        return new self($url, '', 0, [], $reason);
    }

    /**
     * Adresse admise, que cURL résoudra lui-même.
     *
     * C'est le cas de l'adresse déjà numérique - il n'y a rien à résoudre -
     * et celui d'une collecte passant par un mandataire, où la résolution
     * n'a pas lieu sur ce serveur.
     *
     * @param string $url
     * @param string $host
     * @param int    $port
     *
     * @return self
     */
    public static function direct(string $url, string $host, int $port): self
    {
        return new self($url, $host, $port, [], null);
    }

    /**
     * Adresse admise, dont les adresses IP sont imposées à cURL.
     *
     * @param string   $url
     * @param string   $host
     * @param int      $port
     * @param string[] $addresses
     *
     * @return self
     */
    public static function pinned(string $url, string $host, int $port, array $addresses): self
    {
        return new self($url, $host, $port, array_values($addresses), null);
    }

    /**
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->refusal === null;
    }

    /**
     * Valeur `CURLOPT_RESOLVE` correspondante, ou null s'il n'y a rien à
     * épingler.
     *
     * L'épinglage ne vaut que pour le couple hôte / port examiné : une
     * redirection vers un autre domaine ne s'en trouve pas affectée, elle
     * repassera par le même examen.
     *
     * @return ?string
     */
    public function curlResolveEntry(): ?string
    {
        if ($this->addresses === []) {
            return null;
        }

        return $this->host . ':' . $this->port . ':' . implode(',', $this->addresses);
    }
}
