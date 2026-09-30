<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use GlpiPlugin\Veilletool\Feed\FeedDiagnostic;
use GlpiPlugin\Veilletool\Feed\FeedFetcher;
use GlpiPlugin\Veilletool\Feed\FeedImporter;
use GlpiPlugin\Veilletool\Feed\FeedParser;
use GlpiPlugin\Veilletool\Feed\UrlGuard;

/**
 * Collecteur d'informations : enchaîne la récupération d'un flux, sa lecture
 * et l'enregistrement des cartes.
 *
 * Il ne fait plus rien lui-même. Chacune des trois étapes a son métier, ses
 * pièges et ses raisons, et vit dans {@see \GlpiPlugin\Veilletool\Feed} :
 *
 * - {@see UrlGuard} décide si une adresse peut être ouverte par le serveur ;
 * - {@see FeedFetcher} la récupère, plafonnée et redirections revalidées ;
 * - {@see FeedParser} traduit RSS, RDF et Atom dans un vocabulaire unique ;
 * - {@see FeedImporter} en tire des cartes.
 *
 * Ce qui reste ici est le seul enchaînement, et la façon d'en rendre compte.
 */
class Collector
{
    /**
     * Collecte une source distante.
     *
     * @param Source $source
     *
     * @return array{imported:int, updated:int, errors:string[]}
     */
    public function collectSource(Source $source): array
    {
        $url = trim((string) ($source->fields['url'] ?? ''));

        if ($url === '') {
            return self::nothing(__('Aucune URL n\'est définie pour cette source.', 'veilletool'));
        }

        $result = (new FeedFetcher())->fetch($url);

        if (!$result->isSuccess()) {
            return self::nothing((string) $result->error);
        }

        return $this->importContent($result->content, $source);
    }

    /**
     * Éprouve la récupération d'une adresse, étape par étape.
     *
     * @param string $url
     *
     * @return array<int, array{step:string, ok:bool, detail:string}>
     */
    public static function diagnose(string $url): array
    {
        return FeedDiagnostic::run($url);
    }

    /**
     * Analyse un flux RSS ou Atom et crée les cartes.
     *
     * @param string $content
     * @param Source $source
     *
     * @return array{imported:int, updated:int, errors:string[]}
     */
    private function importContent(string $content, Source $source): array
    {
        $xml = FeedParser::toXml($content);

        if ($xml === null) {
            return self::nothing(__('Le document n\'est pas un XML valide.', 'veilletool'));
        }

        $items = FeedParser::readItems($xml);

        if ($items === null) {
            return self::nothing(sprintf(
                __('Flux non reconnu (élément racine « %s »). Formats acceptés : RSS 2.0, RSS 1.0 (RDF) et Atom.', 'veilletool'),
                FeedParser::rootName($xml)
            ));
        }

        return (new FeedImporter())->import(
            $items,
            $source,
            FeedParser::originOf(FeedParser::rootName($xml))
        );
    }

    /**
     * Collecte sans résultat, avec son motif.
     *
     * @param string $error
     *
     * @return array{imported:int, updated:int, errors:string[]}
     */
    private static function nothing(string $error): array
    {
        return [
            'imported' => 0,
            'updated'  => 0,
            'errors'   => [$error],
        ];
    }
}
