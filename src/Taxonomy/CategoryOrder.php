<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Taxonomy;

use GlpiPlugin\Veilletool\Category;

/**
 * L'ordre d'affichage des thèmes.
 *
 * Les thèmes s'affichent dans un ordre choisi, pas alphabétique : le rang
 * est une donnée du référentiel. Il se manipule de deux façons - par
 * glisser-déposer sur la liste entière, ou d'un cran à la fois - et les deux
 * doivent aboutir au même état : des rangs espacés de dix, sans trou ni
 * doublon, quel que soit ce que la base contenait avant.
 */
final class CategoryOrder
{
    /**
     * Rang à attribuer à un nouveau thème : après tous les autres.
     *
     * @return int
     */
    public static function nextRanking(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'ranking AS top'],
            'FROM'   => Category::getTable(),
        ])->current();

        return (int) ($row['top'] ?? 0) + 10;
    }

    /**
     * Applique un ordre d'affichage complet.
     *
     * L'ordre arrive tel que l'écran l'a montré : une liste d'identifiants,
     * du premier au dernier.
     * Les rangs sont réécrits de dix en dix, ce qui laisse de la place pour
     * une insertion manuelle si le besoin s'en présentait un jour.
     *
     * Les thèmes absents de la liste - écartés par une recherche, par
     * exemple - gardent leur place : on ne réordonne que ce qu'on a vu.
     *
     * @param int[] $ordered_ids
     *
     * @return int Nombre de thèmes déplacés
     */
    public static function applyOrder(array $ordered_ids): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ordered_ids = array_values(array_unique(array_filter(array_map('intval', $ordered_ids))));

        if ($ordered_ids === []) {
            return 0;
        }

        // L'ordre complet est recomposé, puis réécrit de dix en dix.
        // Échanger les rangs deux à deux ne suffirait pas : deux thèmes
        // peuvent porter le même - un import, une reprise de version -, et
        // l'échange ne changerait alors rien du tout.
        $current = array_map(
            static fn(array $row): int => $row['id'],
            Category::getAllOrdered()
        );

        // Les thèmes écartés par une recherche gardent leur place : on ne
        // réordonne que ce qu'on a vu.
        $wanted = array_values(array_filter(
            $ordered_ids,
            static fn(int $id): bool => in_array($id, $current, true)
        ));

        if ($wanted === []) {
            return 0;
        }

        $slots  = array_keys(array_intersect($current, $wanted));
        $result = $current;

        foreach ($slots as $rank => $position) {
            $result[$position] = $wanted[$rank];
        }

        if ($result === $current) {
            return 0;
        }

        self::resetRanking($result);

        return count($wanted);
    }

    /**
     * Échange la place d'un thème avec son voisin.
     *
     * C'est le repli des boutons « monter » et « descendre », pour qui n'a
     * pas de JavaScript ou pas de souris : le glisser-déposer ne doit pas
     * être le seul moyen de ranger ses thèmes.
     *
     * @param int  $categories_id
     * @param bool $up Vrai pour remonter, faux pour descendre
     *
     * @return bool
     */
    public static function move(int $categories_id, bool $up): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $all = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'ranking'],
                'FROM'   => Category::getTable(),
                'ORDER'  => ['ranking ASC', 'name ASC'],
            ]) as $row
        ) {
            $all[] = ['id' => (int) $row['id'], 'ranking' => (int) $row['ranking']];
        }

        $position = null;
        foreach ($all as $index => $row) {
            if ($row['id'] === $categories_id) {
                $position = $index;
                break;
            }
        }

        $target = $position === null ? null : $position + ($up ? -1 : 1);

        if ($target === null || !isset($all[$target])) {
            return false;
        }

        // Deux thèmes peuvent porter le même rang - un import, une reprise
        // de version.
        // L'échange ne changerait alors rien : on réécrit l'ordre complet,
        // qui est de toute façon la bonne réponse.
        if ($all[$position]['ranking'] === $all[$target]['ranking']) {
            $ids = array_column($all, 'id');
            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

            self::resetRanking($ids);
            return true;
        }

        $DB->update(Category::getTable(), ['ranking' => $all[$target]['ranking']], ['id' => $all[$position]['id']]);
        $DB->update(Category::getTable(), ['ranking' => $all[$position]['ranking']], ['id' => $all[$target]['id']]);

        Category::invalidate();

        return true;
    }

    /**
     * Réécrit les rangs de dix en dix, dans l'ordre fourni.
     *
     * @param int[] $ordered_ids
     *
     * @return void
     */
    public static function resetRanking(array $ordered_ids): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rank = 0;
        foreach ($ordered_ids as $categories_id) {
            $rank += 10;
            $DB->update(Category::getTable(), ['ranking' => $rank], ['id' => (int) $categories_id]);
        }

        Category::invalidate();
    }
}
