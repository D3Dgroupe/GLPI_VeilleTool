<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * L'extraction de la zone de contenu d'une page d'article.
 *
 * Certains flux ne publient qu'un résumé d'une ou deux phrases : c'est le
 * cas du CERT-FR, dont la page d'un avis porte seule les risques, les
 * systèmes affectés, les solutions et les liens vers les bulletins. La
 * carte doit alors reprendre le corps de la page.
 *
 * Cette classe ne touche ni au réseau, ni à la base : elle reçoit un
 * document HTML et rend le fragment utile, débarrassé de ce qui n'a rien à
 * faire dans une carte. Le fragment est ensuite purifié à l'enregistrement
 * ({@see \GlpiPlugin\Veilletool\Article}) puis de nouveau à l'affichage :
 * le nettoyage fait ici sert la lisibilité, pas la sécurité.
 */
final class PageExtractor
{
    /** Taille maximale du fragment retenu (1 Mio) */
    public const MAX_FRAGMENT_SIZE = 1048576;

    /** Longueur maximale d'un sélecteur, en caractères */
    public const MAX_SELECTOR_LENGTH = 255;

    /**
     * Éléments retirés du fragment : navigation, habillage, scripts,
     * formulaires. Ils encombrent une carte sans rien lui apprendre.
     */
    private const REMOVED_TAGS = [
        'script', 'style', 'noscript', 'template', 'iframe', 'frame', 'object',
        'embed', 'form', 'button', 'input', 'select', 'textarea', 'nav',
        'aside', 'footer', 'svg', 'canvas', 'link', 'meta',
    ];

    /** Attributs porteurs d'une adresse, rendus absolus */
    private const URL_ATTRIBUTES = ['href', 'src'];

    /**
     * Zones essayées, dans l'ordre, quand aucun sélecteur n'est donné ou
     * que le sélecteur ne désigne rien.
     */
    private const HEURISTIC_QUERIES = [
        '//article',
        '//main',
        '//*[@role="main"]',
    ];

    /**
     * Forme d'un sélecteur simple : un nom d'élément facultatif, suivi de
     * classes (`.nom`) et d'identifiants (`#nom`).
     */
    private const SIMPLE_SELECTOR = '/^([A-Za-z][A-Za-z0-9-]*)?((?:[.#][A-Za-z0-9_-]+)*)$/';

    /**
     * Le sélecteur est-il de la forme acceptée ?
     *
     * Un sous-ensemble volontairement réduit de CSS : `article`,
     * `.publication-content`, `div#contenu`, `main .corps`, et plusieurs
     * variantes séparées par des virgules, essayées dans l'ordre.
     *
     * @param string $selector
     *
     * @return bool
     */
    public static function isValidSelector(string $selector): bool
    {
        $selector = trim($selector);

        if ($selector === '') {
            return true;
        }

        return mb_strlen($selector) <= self::MAX_SELECTOR_LENGTH
            && self::toXPaths($selector) !== null;
    }

    /**
     * Extrait la zone de contenu d'une page.
     *
     * @param string $html     Document reçu
     * @param string $base_url Adresse qui a répondu : les liens relatifs
     *                         sont résolus contre elle
     * @param string $selector Sélecteur propre à la source, vide pour
     *                         l'heuristique seule
     *
     * @return ?string Fragment HTML, null si rien d'exploitable
     */
    public static function extract(string $html, string $base_url, string $selector = ''): ?string
    {
        $dom = self::load($html);

        if ($dom === null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $node  = self::locate($xpath, trim($selector));

        if ($node === null) {
            return null;
        }

        self::clean($node, $base_url);

        $fragment = '';
        foreach ($node->childNodes as $child) {
            $fragment .= (string) $dom->saveHTML($child);
        }

        $fragment = trim($fragment);

        if ($fragment === '' || strlen($fragment) > self::MAX_FRAGMENT_SIZE) {
            return null;
        }

        return self::textLength($fragment) > 0 ? $fragment : null;
    }

    /**
     * Longueur du texte lisible d'un fragment HTML.
     *
     * Sert à comparer le contenu de la page à celui du flux : on ne
     * remplace pas un texte par un plus court.
     *
     * @param string $html
     *
     * @return int
     */
    public static function textLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen(trim($text));
    }

    /**
     * Charge un document HTML sans exposer les erreurs libxml.
     *
     * Le jeu de caractères déclaré par la page est respecté : sans
     * indication, libxml lit l'ISO-8859-1 et défigure tout accent.
     *
     * @param string $html
     *
     * @return ?DOMDocument
     */
    private static function load(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $charset = 'UTF-8';
        if (preg_match('/<meta[^>]+charset=["\']?\s*([A-Za-z0-9_-]+)/i', substr($html, 0, 4096), $match) === 1) {
            $charset = strtoupper($match[1]);
        }

        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            try {
                $converted = mb_convert_encoding($html, 'UTF-8', $charset);
                if (is_string($converted)) {
                    $html = $converted;
                }
            } catch (\ValueError) {
                // Jeu de caractères inconnu de mbstring : on lit tel quel.
            }
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument();
        // L'en-tête XML force la lecture en UTF-8. Le parseur HTML ne
        // résout aucune entité externe, et LIBXML_NONET lui interdit le
        // réseau.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    /**
     * Zone de contenu désignée par le sélecteur, ou trouvée par
     * l'heuristique.
     *
     * Un sélecteur qui ne trouve rien n'est pas une impasse : la page a pu
     * changer de gabarit, et l'heuristique vaut mieux qu'un résumé.
     *
     * @param DOMXPath $xpath
     * @param string   $selector
     *
     * @return ?DOMElement
     */
    private static function locate(DOMXPath $xpath, string $selector): ?DOMElement
    {
        if ($selector !== '') {
            foreach (self::toXPaths($selector) ?? [] as $query) {
                $found = self::richest($xpath, $query);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        foreach (self::HEURISTIC_QUERIES as $query) {
            $found = self::richest($xpath, $query);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Parmi les éléments d'une requête, celui qui porte le plus de texte.
     *
     * Une page compte souvent plusieurs `<article>` - l'article lui-même et
     * les encarts « à lire aussi » : le plus long est le bon.
     *
     * @param DOMXPath $xpath
     * @param string   $query
     *
     * @return ?DOMElement
     */
    private static function richest(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = @$xpath->query($query);

        if ($nodes === false) {
            return null;
        }

        $best        = null;
        $best_length = 0;

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $length = mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $node->textContent)));

            if ($length > $best_length) {
                $best        = $node;
                $best_length = $length;
            }
        }

        return $best;
    }

    /**
     * Traduit le sélecteur en requêtes XPath, une par variante.
     *
     * @param string $selector
     *
     * @return ?string[] Null si le sélecteur n'est pas de la forme acceptée
     */
    private static function toXPaths(string $selector): ?array
    {
        $queries = [];

        foreach (explode(',', $selector) as $variant) {
            $steps = preg_split('/\s+/', trim($variant), -1, PREG_SPLIT_NO_EMPTY);

            if ($steps === false || $steps === []) {
                return null;
            }

            $query = '';

            foreach ($steps as $step) {
                if (preg_match(self::SIMPLE_SELECTOR, $step, $match) !== 1) {
                    return null;
                }

                // Le parseur HTML range les noms d'éléments en minuscules.
                $tag        = $match[1] !== '' ? strtolower($match[1]) : '*';
                $conditions = [];

                preg_match_all('/([.#])([A-Za-z0-9_-]+)/', $match[2], $parts, PREG_SET_ORDER);

                foreach ($parts as $part) {
                    $conditions[] = $part[1] === '#'
                        ? sprintf('@id="%s"', $part[2])
                        : sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $part[2]);
                }

                $query .= '//' . $tag . ($conditions === [] ? '' : '[' . implode(' and ', $conditions) . ']');
            }

            $queries[] = $query;
        }

        return $queries;
    }

    /**
     * Retire l'habillage du fragment et rend ses adresses absolues.
     *
     * Une adresse relative copiée telle quelle dans GLPI pointerait vers
     * GLPI lui-même : `/avis/CERTFR-...` deviendrait un lien mort.
     *
     * @param DOMElement $root
     * @param string     $base_url
     *
     * @return void
     */
    private static function clean(DOMElement $root, string $base_url): void
    {
        $xpath = new DOMXPath($root->ownerDocument);

        $doomed = [];
        foreach (self::REMOVED_TAGS as $tag) {
            foreach ($xpath->query('.//' . $tag, $root) ?: [] as $node) {
                $doomed[] = $node;
            }
        }
        foreach ($xpath->query('.//comment()', $root) ?: [] as $node) {
            $doomed[] = $node;
        }

        foreach ($doomed as $node) {
            // Un élément déjà emporté avec son parent n'a plus de parent.
            if ($node instanceof DOMNode && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        $base = null;
        try {
            $base = $base_url !== '' ? new Uri($base_url) : null;
        } catch (\Throwable) {
            $base = null;
        }

        foreach ($xpath->query('.//*[@href or @src]', $root) ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            foreach (self::URL_ATTRIBUTES as $attribute) {
                if (!$element->hasAttribute($attribute)) {
                    continue;
                }

                $value = trim($element->getAttribute($attribute));

                if ($value === '' || str_starts_with($value, '#') || $base === null) {
                    continue;
                }

                try {
                    $absolute = (string) UriResolver::resolve($base, new Uri($value));
                } catch (\Throwable) {
                    $element->removeAttribute($attribute);
                    continue;
                }

                if (UrlGuard::isAcceptable($absolute)) {
                    $element->setAttribute($attribute, $absolute);
                } else {
                    // `javascript:`, `data:` et consorts : rien à garder.
                    $element->removeAttribute($attribute);
                }
            }
        }
    }
}
