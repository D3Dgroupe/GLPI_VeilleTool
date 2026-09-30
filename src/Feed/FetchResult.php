<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

/**
 * Ce qu'une récupération rapporte.
 *
 * Le contenu ne suffit pas à rendre compte : le diagnostic a besoin de savoir
 * par où le document est passé, et la collecte de savoir pourquoi elle n'a
 * rien reçu. Les trois voyagent ensemble.
 */
final class FetchResult
{
    /**
     * @param string   $content Document reçu, vide en cas d'échec
     * @param ?string  $error   Motif de l'échec, null en cas de succès
     * @param string[] $chain   Adresses traversées, la première étant celle
     *                          demandée et la dernière celle qui a répondu
     */
    private function __construct(
        public readonly string $content,
        public readonly ?string $error,
        public readonly array $chain,
    ) {
    }

    /**
     * @param string   $content
     * @param string[] $chain
     *
     * @return self
     */
    public static function success(string $content, array $chain): self
    {
        return new self($content, null, $chain);
    }

    /**
     * @param string   $error
     * @param string[] $chain
     *
     * @return self
     */
    public static function failure(string $error, array $chain): self
    {
        return new self('', $error, $chain);
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Adresse qui a effectivement répondu.
     *
     * @return string
     */
    public function finalUrl(): string
    {
        return $this->chain === [] ? '' : (string) end($this->chain);
    }

    /**
     * Nombre de redirections suivies.
     *
     * @return int
     */
    public function redirectCount(): int
    {
        return max(0, count($this->chain) - 1);
    }
}
