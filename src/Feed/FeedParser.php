<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GlpiPlugin\Veilletool\Article;
use SimpleXMLElement;

/**
 * La lecture d'un document de veille, quel que soit son dialecte.
 *
 * Trois formats cohabitent dans la nature - RSS 2.0, RSS 1.0 (RDF) et Atom -
 * et disent la même chose avec des mots différents : `pubDate` ou `published`,
 * `description` ou `summary`, `guid` ou `id`.
 * Tout ce qui relève de cette traduction est ici, et rien d'autre : cette
 * classe ne touche ni au réseau, ni à la base.
 */
final class FeedParser
{
    /** Espace de noms Dublin Core, utilisé par de nombreux flux RSS */
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    /** Espace de noms Media RSS, pour les visuels d'illustration */
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';

    /** Espace de noms du module « content » de RSS 1.0 / 2.0 */
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * Charge un document XML sans exposer les erreurs libxml ni les entités
     * externes.
     *
     * @param string $content
     *
     * @return ?SimpleXMLElement
     */
    public static function toXml(string $content): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET et l'absence de LIBXML_NOENT empêchent toute
        // résolution d'entité externe (attaques XXE).
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * Nom de l'élément racine, en minuscules et sans préfixe d'espace de
     * noms : c'est lui qui désigne le dialecte.
     *
     * @param SimpleXMLElement $xml
     *
     * @return string
     */
    public static function rootName(SimpleXMLElement $xml): string
    {
        $name = $xml->getName();
        $pos  = strpos($name, ':');

        return strtolower($pos === false ? $name : substr($name, $pos + 1));
    }

    /**
     * Origine à inscrire sur les cartes issues d'un dialecte.
     *
     * @param string $root
     *
     * @return string
     */
    public static function originOf(string $root): string
    {
        return $root === 'feed' ? Article::ORIGIN_ATOM : Article::ORIGIN_RSS;
    }

    /**
     * Entrées d'un document, normalisées dans un vocabulaire unique.
     *
     * @param SimpleXMLElement $xml
     *
     * @return ?array<int, array<string, mixed>> Null si le dialecte est
     *                                           inconnu
     */
    public static function readItems(SimpleXMLElement $xml): ?array
    {
        return match (self::rootName($xml)) {
            'rss'   => self::readRssItems($xml),
            'rdf'   => self::readRdfItems($xml),
            'feed'  => self::readAtomItems($xml),
            default => null,
        };
    }

    /**
     * Lit les entrées d'un flux RSS 2.0.
     *
     * @param SimpleXMLElement $xml
     *
     * @return array<int, array<string, mixed>>
     */
    private static function readRssItems(SimpleXMLElement $xml): array
    {
        $items = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $items[] = self::readRssItem($item);
        }

        return $items;
    }

    /**
     * Lit les entrées d'un flux RSS 1.0 (RDF).
     *
     * @param SimpleXMLElement $xml
     *
     * @return array<int, array<string, mixed>>
     */
    private static function readRdfItems(SimpleXMLElement $xml): array
    {
        $items = [];

        foreach ($xml->item ?? [] as $item) {
            $items[] = self::readRssItem($item);
        }

        return $items;
    }

    /**
     * Normalise une entrée RSS.
     *
     * @param SimpleXMLElement $item
     *
     * @return array<string, mixed>
     */
    private static function readRssItem(SimpleXMLElement $item): array
    {
        $dc      = $item->children(self::NS_DC);
        $media   = $item->children(self::NS_MEDIA);
        $encoded = $item->children(self::NS_CONTENT);

        $content = (string) ($encoded->encoded ?? '');
        if ($content === '') {
            $content = (string) ($item->description ?? '');
        }

        $author = (string) ($item->author ?? '');
        if ($author === '') {
            $author = (string) ($dc->creator ?? '');
        }

        $date = (string) ($item->pubDate ?? '');
        if ($date === '') {
            $date = (string) ($dc->date ?? '');
        }

        $link = (string) ($item->link ?? '');
        if ($link === '' && isset($item->guid) && filter_var((string) $item->guid, FILTER_VALIDATE_URL)) {
            $link = (string) $item->guid;
        }

        return [
            'title'      => (string) ($item->title ?? ''),
            'link'       => $link,
            'guid'       => (string) ($item->guid ?? ''),
            'author'     => $author,
            'date'       => $date,
            'content'    => $content,
            'summary'    => (string) ($item->description ?? ''),
            'image'      => self::readRssImage($item, $media),
            'categories' => self::readRssCategories($item),
        ];
    }

    /**
     * Visuel d'illustration d'une entrée RSS.
     *
     * Trois conventions coexistent, dans cet ordre de préférence : la pièce
     * jointe déclarée comme image, le contenu Media RSS, sa vignette.
     *
     * @param SimpleXMLElement $item
     * @param SimpleXMLElement $media
     *
     * @return string
     */
    private static function readRssImage(SimpleXMLElement $item, SimpleXMLElement $media): string
    {
        foreach ($item->enclosure ?? [] as $enclosure) {
            $type = (string) ($enclosure['type'] ?? '');

            if (str_starts_with($type, 'image/')) {
                return (string) ($enclosure['url'] ?? '');
            }
        }

        if (isset($media->content)) {
            $url = (string) ($media->content->attributes()['url'] ?? '');

            if ($url !== '') {
                return $url;
            }
        }

        if (isset($media->thumbnail)) {
            return (string) ($media->thumbnail->attributes()['url'] ?? '');
        }

        return '';
    }

    /**
     * Catégories déclarées dans une entrée RSS.
     *
     * @param SimpleXMLElement $item
     *
     * @return string[]
     */
    private static function readRssCategories(SimpleXMLElement $item): array
    {
        $categories = [];

        foreach ($item->category ?? [] as $category) {
            $value = trim((string) $category);

            if ($value !== '') {
                $categories[] = $value;
            }
        }

        return $categories;
    }

    /**
     * Lit les entrées d'un flux Atom.
     *
     * @param SimpleXMLElement $xml
     *
     * @return array<int, array<string, mixed>>
     */
    private static function readAtomItems(SimpleXMLElement $xml): array
    {
        $items = [];

        foreach ($xml->entry ?? [] as $entry) {
            $date = (string) ($entry->published ?? '');
            if ($date === '') {
                $date = (string) ($entry->updated ?? '');
            }

            $content = (string) ($entry->content ?? '');
            if ($content === '') {
                $content = (string) ($entry->summary ?? '');
            }

            $categories = [];
            foreach ($entry->category ?? [] as $category) {
                $term = trim((string) ($category['term'] ?? ''));

                if ($term !== '') {
                    $categories[] = $term;
                }
            }

            $items[] = [
                'title'      => (string) ($entry->title ?? ''),
                'link'       => self::readAtomLink($entry),
                'guid'       => (string) ($entry->id ?? ''),
                'author'     => (string) ($entry->author->name ?? ''),
                'date'       => $date,
                'content'    => $content,
                'summary'    => (string) ($entry->summary ?? ''),
                'image'      => '',
                'categories' => $categories,
            ];
        }

        return $items;
    }

    /**
     * Lien d'une entrée Atom.
     *
     * Une entrée déclare plusieurs liens qualifiés par leur relation : la
     * page à lire est celle en `alternate`. Les autres - `enclosure`,
     * `related` - ne servent de secours que faute de mieux.
     *
     * @param SimpleXMLElement $entry
     *
     * @return string
     */
    private static function readAtomLink(SimpleXMLElement $entry): string
    {
        $fallback = '';

        foreach ($entry->link ?? [] as $candidate) {
            $rel  = (string) ($candidate['rel'] ?? 'alternate');
            $href = (string) ($candidate['href'] ?? '');

            if ($href === '') {
                continue;
            }

            if ($rel === 'alternate') {
                return $href;
            }

            if ($fallback === '') {
                $fallback = $href;
            }
        }

        return $fallback;
    }
}
