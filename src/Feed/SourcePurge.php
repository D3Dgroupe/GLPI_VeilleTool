<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GlpiPlugin\Veilletool\Cards\ArticlePurge;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Source_Category;
use Toolbox;

/**
 * La suppression définitive de flux.
 *
 * Supprimer un flux, c'est supprimer toutes ses cartes, et avec elles leurs
 * commentaires, favoris et marquages de lecture. Le décompte rendu compte
 * donc deux nombres : on ne supprime jamais seulement des flux.
 */
final class SourcePurge
{
    /**
     * Identifiants des flux visés par une suppression de masse.
     *
     * @param array<string, mixed> $search Critères normalisés. Vides, tous
     *                                     les flux sont visés
     *
     * @return int[]
     */
    public static function findIdsForPurge(array $search): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'url', 'comment', 'is_active'],
                'FROM'   => Source::getTable(),
            ]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }

        if ($rows === []) {
            return [];
        }

        $counts = SourceFilter::countArticles(array_keys($rows));
        $themes = Source_Category::getCategoryIdsForSources(array_keys($rows));

        $ids = [];
        foreach ($rows as $sources_id => $row) {
            $row['article_count'] = $counts[$sources_id] ?? 0;

            if (SourceFilter::matches($row, $search, $themes[$sources_id] ?? [])) {
                $ids[] = $sources_id;
            }
        }

        return $ids;
    }

    /**
     * Supprime définitivement un lot de flux.
     *
     * La suppression d'un flux emporte **toutes ses cartes**, et avec elles
     * leurs commentaires, favoris et marquages de lecture : c'est
     * `cleanDBonPurge()` qui s'en charge, flux par flux.
     * D'où le décompte rendu en deux nombres - on ne supprime jamais
     * seulement des flux.
     *
     * @param int[] $sources_ids
     *
     * @return array{sources:int, articles:int}
     */
    public static function purgeByIds(array $sources_ids): array
    {
        $sources_ids = array_values(array_filter(array_map('intval', $sources_ids)));

        if ($sources_ids === []) {
            return ['sources' => 0, 'articles' => 0];
        }

        // Un flux fourni peut porter des milliers de cartes : on desserre
        // la limite de temps, comme le fait GLPI pour ses propres
        // traitements longs.
        $max_execution_time = (int) ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 300) {
            Toolbox::safeIniSet('max_execution_time', '300');
        }

        // Les cartes partent d'abord, en quelques requêtes pour l'ensemble
        // du lot. `cleanDBonPurge()` les supprimerait une à une, flux par
        // flux : sur un catalogue entier, l'écran n'y survivrait pas.
        // Quand vient le tour des flux, il ne leur reste donc que leurs
        // thèmes à nettoyer.
        $articles = ArticlePurge::purgeBySources($sources_ids);

        $source  = new Source();
        $deleted = 0;

        foreach ($sources_ids as $sources_id) {
            if ($source->delete(['id' => $sources_id], true)) {
                $deleted++;
            }
        }

        return ['sources' => $deleted, 'articles' => $articles];
    }
}
