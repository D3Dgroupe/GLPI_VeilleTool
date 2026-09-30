<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;

/**
 * Favori sur une carte.
 * Contrairement au marquage « lu », un favori est partagé : toute carte
 * mise en favori par un utilisateur apparaît dans l'onglet « Favoris » de
 * tous les utilisateurs.
 */
class Favorite extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_veille';

    public static function getTypeName($nb = 0)
    {
        return _n('Favori', 'Favoris', $nb, 'veilletool');
    }

    /**
     * La carte est-elle en favori ?
     *
     * @param int $articles_id
     *
     * @return bool
     */
    public static function isFavorite(int $articles_id): bool
    {
        return countElementsInTable(self::getTable(), [
            'plugin_veilletool_articles_id' => $articles_id,
        ]) > 0;
    }

    /**
     * Ajoute ou retire une carte des favoris.
     *
     * @param int $articles_id
     * @param int $users_id Utilisateur à l'origine de l'ajout
     *
     * @return bool état après bascule (true = en favori)
     */
    public static function toggle(int $articles_id, int $users_id): bool
    {
        if ($articles_id <= 0) {
            return false;
        }

        $favorite = new self();

        if (self::isFavorite($articles_id)) {
            $favorite->deleteByCriteria(['plugin_veilletool_articles_id' => $articles_id], true);
            return false;
        }

        $favorite->add([
            'plugin_veilletool_articles_id' => $articles_id,
            'users_id'                        => $users_id,
        ]);

        return true;
    }

    /**
     * Identifiants des cartes en favori, parmi une liste.
     *
     * @param int[] $articles_ids
     *
     * @return int[]
     */
    public static function getFavoriteArticleIds(array $articles_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_filter(array_map('intval', $articles_ids)));
        if ($articles_ids === []) {
            return [];
        }

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_veilletool_articles_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_veilletool_articles_id' => $articles_ids],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row['plugin_veilletool_articles_id'];
        }

        return $ids;
    }
}
