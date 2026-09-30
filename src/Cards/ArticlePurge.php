<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Cards;

use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Category;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Favorite;
use GlpiPlugin\Veilletool\ReadStatus;

/**
 * La suppression définitive de cartes, par critères ou par flux.
 *
 * Une purge n'est pas une recherche : elle ne pagine pas, ne décore pas, et
 * se trompe une seule fois. Elle a donc ses propres critères, plus stricts
 * que ceux de l'observatoire - les favoris y sont préservés par défaut, et
 * les trois niveaux technologiques s'y combinent par « ou ».
 *
 * La tenir à l'écart de {@see ArticleQuery} évite qu'un ajout de filtre
 * d'affichage change ce qui s'efface.
 */
final class ArticlePurge
{
    /**
     * Identifiants des cartes visées par une purge.
     *
     * @param array{
     *     categories?: int[],
     *     sources?: int[],
     *     technologies?: array{categorie?: string[], famille?: string[], name?: string[]},
     *     before?: string,
     *     favorites?: string
     * } $params `favorites` vaut `exclude` (défaut), `include` ou `only`.
     *
     * Les critères se cumulent : une carte doit satisfaire chacun d'eux. Les
     * trois niveaux technologiques, eux, se combinent **entre eux par « ou »**
     * - cocher la catégorie « OS » et la technologie « GLPI 11 » vise les
     * cartes qui relèvent de l'une ou de l'autre.
     *
     * @return int[]
     */
    public static function findIdsForPurge(array $params): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table    = Article::getTable();
        $criteria = [
            'SELECT'   => [$table . '.id'],
            'DISTINCT' => true,
            'FROM'     => $table,
            'WHERE'    => [],
        ];

        $categories = array_values(array_filter(array_map('intval', $params['categories'] ?? [])));
        if ($categories !== []) {
            $criteria['INNER JOIN'][Article_Category::getTable()] = [
                'ON' => [
                    Article_Category::getTable() => 'plugin_veilletool_articles_id',
                    $table                       => 'id',
                    [
                        'AND' => [
                            Article_Category::getTable() . '.plugin_veilletool_categories_id'
                                => $categories,
                        ],
                    ],
                ],
            ];
        }

        $sources = array_values(array_filter(array_map('intval', $params['sources'] ?? [])));
        if ($sources !== []) {
            $criteria['WHERE'][$table . '.plugin_veilletool_sources_id'] = $sources;
        }

        $technologies = self::normalizeTechnologySelection($params['technologies'] ?? []);
        if ($technologies !== []) {
            $criteria['WHERE'][] = [
                $table . '.id' => new QuerySubQuery([
                    'SELECT' => 'plugin_veilletool_articles_id',
                    'FROM'   => Article_Technology::getTable(),
                    'WHERE'  => ['OR' => $technologies],
                ]),
            ];
        }

        $before = ArticleQuery::normalizeDate((string) ($params['before'] ?? ''));
        if ($before !== '') {
            $criteria['WHERE'][] = [
                $table . '.date_published' => ['<', $before . ' 00:00:00'],
            ];
        }

        $favorites_subquery = new QuerySubQuery([
            'SELECT' => 'plugin_veilletool_articles_id',
            'FROM'   => Favorite::getTable(),
        ]);

        switch ((string) ($params['favorites'] ?? 'exclude')) {
            case 'only':
                $criteria['WHERE'][] = [$table . '.id' => $favorites_subquery];
                break;

            case 'include':
                // Aucune restriction : les favoris sont purgés comme le
                // reste.
                break;

            default:
                // Comportement par défaut : une carte en favori est
                // préservée, même si elle correspond aux autres critères.
                $criteria['WHERE'][] = ['NOT' => [$table . '.id' => $favorites_subquery]];
                break;
        }

        $ids = [];
        foreach ($DB->request($criteria) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Supprime définitivement toutes les cartes d'un lot de flux.
     *
     * La suppression est **ensembliste** : cinq requêtes pour les tables
     * liées, une pour les cartes.
     * Passer par `CommonDBTM::delete()` carte par carte, comme le fait la
     * purge par critères, coûterait six opérations par carte - la
     * suppression d'un catalogue entier de flux, qui en porte des dizaines
     * de milliers, n'y survivrait pas.
     *
     * C'est admissible ici parce qu'une carte n'a pas d'historique
     * (`$dohistory = false`) et que son seul effet de bord à la suppression
     * est précisément le nettoyage de ces cinq tables.
     *
     * @param int[] $sources_ids
     *
     * @return int Nombre de cartes supprimées
     */
    public static function purgeBySources(array $sources_ids): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sources_ids = array_values(array_filter(array_map('intval', $sources_ids)));

        if ($sources_ids === []) {
            return 0;
        }

        $scope = new QuerySubQuery([
            'SELECT' => 'id',
            'FROM'   => Article::getTable(),
            'WHERE'  => ['plugin_veilletool_sources_id' => $sources_ids],
        ]);

        foreach (
            [
                Article_Category::class,
                ReadStatus::class,
                Favorite::class,
                Comment::class,
                Article_Technology::class,
            ] as $itemtype
        ) {
            $DB->delete($itemtype::getTable(), ['plugin_veilletool_articles_id' => $scope]);
        }

        $DB->delete(Article::getTable(), ['plugin_veilletool_sources_id' => $sources_ids]);

        return $DB->affectedRows();
    }

    /**
     * Supprime définitivement les cartes visées par une purge.
     *
     * @param array $params voir findIdsForPurge()
     *
     * @return int nombre de cartes supprimées
     */
    public static function purgeByCriteria(array $params): int
    {
        $article = new Article();
        $deleted = 0;

        foreach (self::findIdsForPurge($params) as $id) {
            if ($article->delete(['id' => $id], true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Ramène une sélection technologique à des conditions SQL.
     *
     * Les trois niveaux du référentiel sont des colonnes de la table de
     * classification : une sélection s'y traduit directement.
     *
     * @param array<string, string[]> $selection
     *
     * @return array<int, array<string, string[]>>
     */
    private static function normalizeTechnologySelection(array $selection): array
    {
        $conditions = [];

        foreach (['categorie', 'famille', 'name'] as $level) {
            $values = array_values(array_unique(array_filter(
                array_map(static fn($value): string => trim((string) $value), (array) ($selection[$level] ?? [])),
                static fn(string $value): bool => $value !== ''
            )));

            if ($values !== []) {
                $conditions[] = [$level => $values];
            }
        }

        return $conditions;
    }
}
