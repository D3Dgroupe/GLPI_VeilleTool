<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;

/**
 * Marquage « lu » d'une carte, propre à chaque utilisateur : une carte lue
 * par un utilisateur reste non lue pour les autres.
 */
class ReadStatus extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_veille';

    public static function getTypeName($nb = 0)
    {
        return _n('Statut de lecture', 'Statuts de lecture', $nb, 'veilletool');
    }

    /**
     * Marque une carte comme lue pour un utilisateur.
     *
     * @param int $articles_id
     * @param int $users_id
     *
     * @return void
     */
    public static function markAsRead(int $articles_id, int $users_id): void
    {
        if ($articles_id <= 0 || $users_id <= 0 || self::isRead($articles_id, $users_id)) {
            return;
        }

        (new self())->add([
            'plugin_veilletool_articles_id' => $articles_id,
            'users_id'                        => $users_id,
        ]);
    }

    /**
     * Marque un lot de cartes comme lues pour un utilisateur.
     *
     * Une seule requête établit ce qui est déjà lu, puis les seules lignes
     * manquantes sont insérées.
     * Le marquage de masse passait jusqu'ici par `markAsRead()`, donc par
     * deux requêtes et un objet GLPI complet par carte.
     *
     * @param int[] $articles_ids
     * @param int   $users_id
     *
     * @return int Nombre de cartes nouvellement marquées
     */
    public static function markManyAsRead(array $articles_ids, int $users_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_unique(array_filter(array_map('intval', $articles_ids))));

        if ($users_id <= 0 || $articles_ids === []) {
            return 0;
        }

        $already = array_flip(self::getReadArticleIds($users_id, $articles_ids));
        $now     = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $marked  = 0;

        foreach ($articles_ids as $articles_id) {
            if (isset($already[$articles_id])) {
                continue;
            }

            $DB->insert(self::getTable(), [
                'plugin_veilletool_articles_id' => $articles_id,
                'users_id'                        => $users_id,
                'date_creation'                   => $now,
            ]);

            $marked++;
        }

        return $marked;
    }

    /**
     * Retire le marquage « lu » d'une carte pour un utilisateur.
     *
     * @param int $articles_id
     * @param int $users_id
     *
     * @return void
     */
    public static function markAsUnread(int $articles_id, int $users_id): void
    {
        if ($articles_id <= 0 || $users_id <= 0) {
            return;
        }

        (new self())->deleteByCriteria([
            'plugin_veilletool_articles_id' => $articles_id,
            'users_id'                        => $users_id,
        ], true);
    }

    /**
     * La carte est-elle lue par cet utilisateur ?
     *
     * @param int $articles_id
     * @param int $users_id
     *
     * @return bool
     */
    public static function isRead(int $articles_id, int $users_id): bool
    {
        return countElementsInTable(self::getTable(), [
            'plugin_veilletool_articles_id' => $articles_id,
            'users_id'                        => $users_id,
        ]) > 0;
    }

    /**
     * Identifiants des cartes déjà lues par un utilisateur, parmi une
     * liste.
     *
     * @param int   $users_id
     * @param int[] $articles_ids
     *
     * @return int[]
     */
    public static function getReadArticleIds(int $users_id, array $articles_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_filter(array_map('intval', $articles_ids)));
        if ($users_id <= 0 || $articles_ids === []) {
            return [];
        }

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_veilletool_articles_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'users_id'                        => $users_id,
                'plugin_veilletool_articles_id' => $articles_ids,
            ],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row['plugin_veilletool_articles_id'];
        }

        return $ids;
    }
}
