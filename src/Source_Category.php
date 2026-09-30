<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;

/**
 * Catégories (tags) affectées à une source.
 * Les cartes collectées héritent de ces catégories, ce qui alimente les
 * onglets de filtrage.
 */
class Source_Category extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_source';

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie de source', 'Catégories de source', $nb, 'veilletool');
    }

    /**
     * Identifiants des catégories d'une source.
     *
     * @param int $sources_id
     *
     * @return int[]
     */
    public static function getCategoryIds(int $sources_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($sources_id <= 0) {
            return [];
        }

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_veilletool_categories_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_veilletool_sources_id' => $sources_id],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row['plugin_veilletool_categories_id'];
        }

        return $ids;
    }

    /**
     * Catégories de plusieurs flux, en une seule requête.
     *
     * L'écran des flux en affiche autant qu'il y a de lignes : les demander
     * flux par flux faisait une requête par ligne.
     *
     * @param int[] $sources_ids
     *
     * @return array<int, int[]> Identifiant de flux => identifiants de thèmes
     */
    public static function getCategoryIdsForSources(array $sources_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sources_ids = array_values(array_filter(array_map('intval', $sources_ids)));

        if ($sources_ids === []) {
            return [];
        }

        $result   = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_veilletool_sources_id', 'plugin_veilletool_categories_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_veilletool_sources_id' => $sources_ids],
        ]);

        foreach ($iterator as $row) {
            $result[(int) $row['plugin_veilletool_sources_id']][] =
                (int) $row['plugin_veilletool_categories_id'];
        }

        return $result;
    }

    /**
     * Remplace l'ensemble des catégories d'une source.
     *
     * @param int   $sources_id
     * @param int[] $categories_ids
     *
     * @return void
     */
    public static function setCategories(int $sources_id, array $categories_ids): void
    {
        if ($sources_id <= 0) {
            return;
        }

        $wanted  = array_values(array_unique(array_filter(array_map('intval', $categories_ids))));
        $current = self::getCategoryIds($sources_id);
        $link    = new self();

        foreach (array_diff($current, $wanted) as $to_remove) {
            $link->deleteByCriteria([
                'plugin_veilletool_sources_id'    => $sources_id,
                'plugin_veilletool_categories_id' => $to_remove,
            ], true);
        }

        foreach (array_diff($wanted, $current) as $to_add) {
            $link->add([
                'plugin_veilletool_sources_id'    => $sources_id,
                'plugin_veilletool_categories_id' => $to_add,
            ]);
        }
    }
}
