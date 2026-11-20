<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

/**
 * Le contenu complet d'un article, repris de sa page.
 *
 * Enchaîne la récupération ({@see FeedFetcher} : adresse examinée par
 * {@see UrlGuard}, taille plafonnée, redirections revalidées une à une) et
 * l'extraction ({@see PageExtractor}). Rien n'est écrit en base ici.
 */
final class PageContent
{
    /** Fragment extrait, vide en cas d'échec */
    public readonly string $html;

    /** Motif de l'échec, null en cas de succès */
    public readonly ?string $error;

    private function __construct(string $html, ?string $error)
    {
        $this->html  = $html;
        $this->error = $error;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Récupère la page d'un article et en extrait le contenu.
     *
     * @param string       $url      Lien de l'article
     * @param string       $selector Sélecteur de la source, vide pour
     *                               l'heuristique
     * @param ?FeedFetcher $fetcher  Client partagé entre plusieurs pages
     *
     * @return self
     */
    public static function fetch(string $url, string $selector = '', ?FeedFetcher $fetcher = null): self
    {
        $url = trim($url);

        if ($url === '' || !UrlGuard::isAcceptable($url)) {
            return new self('', __('La carte n\'a pas de lien exploitable vers sa page.', 'veilletool'));
        }

        $result = ($fetcher ?? new FeedFetcher())->fetch($url, FeedFetcher::ACCEPT_PAGE);

        if (!$result->isSuccess()) {
            return new self('', (string) $result->error);
        }

        $html = PageExtractor::extract($result->content, $result->finalUrl(), $selector);

        if ($html === null) {
            return new self('', __('Aucune zone de contenu n\'a été reconnue dans la page.', 'veilletool'));
        }

        return new self($html, null);
    }
}
