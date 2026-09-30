<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;

/**
 * Catégories (tags) affectées à une carte de veille.
 * Ce sont elles qui déterminent l'onglet dans lequel la carte apparaît.
 */
class Article_Category extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_veille';

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie de carte', 'Catégories de carte', $nb, 'veilletool');
    }

    /**
     * Identifiants des catégories d'une carte.
     *
     * @param int $articles_id
     *
     * @return int[]
     */
    public static function getCategoryIds(int $articles_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return [];
        }

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_veilletool_categories_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_veilletool_articles_id' => $articles_id],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row['plugin_veilletool_categories_id'];
        }

        return $ids;
    }

    /**
     * Catégories de plusieurs cartes, en une seule requête.
     *
     * @param int[] $articles_ids
     *
     * @return array<int, int[]> identifiant de carte => identifiants de catégories
     */
    public static function getCategoryIdsForArticles(array $articles_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_filter(array_map('intval', $articles_ids)));
        if ($articles_ids === []) {
            return [];
        }

        $result   = array_fill_keys($articles_ids, []);
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_veilletool_articles_id' => $articles_ids],
        ]);

        foreach ($iterator as $row) {
            $result[(int) $row['plugin_veilletool_articles_id']][] =
                (int) $row['plugin_veilletool_categories_id'];
        }

        return $result;
    }

    /**
     * Meilleure note d'intérêt parmi les thèmes d'une carte.
     *
     * C'est la composante « catégorie » de son score d'intérêt : une carte
     * sans thème ne vaut rien de ce point de vue, une carte portant
     * plusieurs thèmes vaut celui qui compte le plus.
     *
     * @param int $articles_id
     *
     * @return int Note de 0 à 100
     */
    public static function getBestInterest(int $articles_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return 0;
        }

        $link       = self::getTable();
        $categories = Category::getTable();

        $row = $DB->request([
            'SELECT'     => ['MAX' => $categories . '.interest AS best'],
            'FROM'       => $link,
            'INNER JOIN' => [
                $categories => [
                    'ON' => [
                        $categories => 'id',
                        $link       => 'plugin_veilletool_categories_id',
                    ],
                ],
            ],
            'WHERE'      => [
                $link . '.plugin_veilletool_articles_id' => $articles_id,
                $categories . '.is_active'               => 1,
            ],
        ])->current();

        if ($row === null || $row['best'] === null) {
            return 0;
        }

        return max(0, min(100, (int) $row['best']));
    }

    /**
     * Remplace l'ensemble des catégories d'une carte.
     *
     * @param int   $articles_id
     * @param int[] $categories_ids
     * @param bool  $may_exist
     *        Faux lorsque l'appelant sait que la carte vient d'être créée :
     *        la relecture et la suppression préalables sont alors inutiles.
     *
     * @return int Meilleure note d'intérêt des thèmes retenus, qui est la
     *             composante « catégorie » du score de la carte
     */
    public static function setCategories(
        int $articles_id,
        array $categories_ids,
        bool $may_exist = true
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return 0;
        }

        $wanted  = array_values(array_unique(array_filter(array_map('intval', $categories_ids))));
        $current = $may_exist ? self::getCategoryIds($articles_id) : [];

        // Lignes de liaison nues : ni historique, ni traitement après
        // enregistrement, ni contrôle de saisie.
        // Passer par `CommonDBTM` instanciait un objet complet et relisait
        // la table pour chaque retrait - sur une collecte de flux, cela se
        // comptait en milliers de requêtes pour deux étiquettes par carte.
        $to_remove = array_values(array_diff($current, $wanted));
        if ($to_remove !== []) {
            $DB->delete(self::getTable(), [
                'plugin_veilletool_articles_id'   => $articles_id,
                'plugin_veilletool_categories_id' => $to_remove,
            ]);
        }

        foreach (array_diff($wanted, $current) as $to_add) {
            $DB->insert(self::getTable(), [
                'plugin_veilletool_articles_id'   => $articles_id,
                'plugin_veilletool_categories_id' => $to_add,
            ]);
        }

        return self::bestInterestOf($wanted);
    }

    /**
     * Meilleure note d'intérêt parmi une liste de thèmes.
     *
     * Les thèmes actifs sont déjà chargés pour la durée de la requête : la
     * composante « catégorie » du score se lit donc en mémoire, là où elle
     * demandait une jointure par carte enregistrée.
     *
     * @param int[] $categories_ids
     *
     * @return int Note de 0 à 100
     */
    public static function bestInterestOf(array $categories_ids): int
    {
        $active = Category::getActiveCategories();
        $best   = 0;

        foreach ($categories_ids as $categories_id) {
            if (!isset($active[(int) $categories_id])) {
                continue;
            }

            $best = max($best, (int) $active[(int) $categories_id]['interest']);
        }

        return max(0, min(100, $best));
    }
}
