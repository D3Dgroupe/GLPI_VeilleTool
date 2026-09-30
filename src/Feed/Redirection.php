<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

/**
 * Ce qu'une réponse de redirection nous demande de faire.
 *
 * Soit une adresse à suivre, soit un motif de ne pas la suivre. Le récupérateur
 * n'a pas à démêler un en-tête absent d'un en-tête illisible ou d'un
 * déclassement refusé : la lecture de l'en-tête rend un verdict, il l'applique.
 */
final class Redirection
{
    /**
     * @param string  $url     Adresse de la prochaine étape, vide si refusée
     * @param ?string $refusal Motif du refus, null si l'étape est à suivre
     */
    private function __construct(
        public readonly string $url,
        public readonly ?string $refusal,
    ) {
    }

    /**
     * @param string $url
     *
     * @return self
     */
    public static function to(string $url): self
    {
        return new self($url, null);
    }

    /**
     * @param string $reason
     *
     * @return self
     */
    public static function refused(string $reason): self
    {
        return new self('', $reason);
    }
}
