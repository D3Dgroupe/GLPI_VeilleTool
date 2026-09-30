<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Cards;

use DBmysql;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Category;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Favorite;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\ReadStatus;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\Settings;

/**
 * Tout ce qui interroge la table des cartes.
 *
 * L'observatoire pose toujours la même question sous des formes
 * différentes - combien, lesquelles, dans quel ordre, sur quelle page - et
 * la réponse se construit à partir d'un jeu de filtres unique.
 * Réunir ici la construction des critères, du tri et de la pagination
 * garantit que le comptage affiché et la liste affichée parlent des mêmes
 * cartes : les faire diverger était jusqu'ici à un copier-coller près.
 *
 * La carte elle-même - ce qu'on en montre - est l'affaire de
 * {@see ArticleDecorator}.
 */
final class ArticleQuery
{
    /**
     * Longueur minimale d'un mot recherché en index plein texte.
     *
     * MySQL et MariaDB n'indexent pas les mots plus courts
     * (`innodb_ft_min_token_size`, trois caractères par défaut) : au-
     * dessous, la recherche retombe sur un `LIKE`, plus lent mais exact.
     */
    private const FULLTEXT_MIN_WORD = 3;

    /**
     * Longueur de contenu ramenée par les listes de cartes.
     *
     * Le contenu est un `longtext` : le charger entier pour chaque carte
     * d'une page transférait plusieurs mégaoctets, pour n'en afficher que
     * deux lignes.
     * Les listes n'en demandent donc que le début - assez pour l'aperçu
     * comme pour le contexte transmis à l'IA.
     */
    private const LIST_CONTENT_LENGTH = 4000;

    /**
     * Nombre de cartes correspondant aux filtres.
     *
     * @param array $filters
     *
     * @return int
     */
    public static function countList(array $filters): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria             = self::buildCriteria($filters);
        $criteria['COUNT']    = 'cpt';
        $criteria['SELECT']   = Article::getTable() . '.id';
        $criteria['DISTINCT'] = true;

        $row = $DB->request($criteria)->current();

        return $row !== null ? (int) $row['cpt'] : 0;
    }

    /**
     * Identifiants des cartes retenues par les filtres, sans décoration.
     *
     * Les traitements de masse n'ont besoin que des identifiants : les
     * décorer - thèmes, technologies, score, question à l'IA - pour les
     * jeter aussitôt coûtait plus cher que le traitement lui-même.
     *
     * @param array $filters
     * @param int   $start
     * @param int   $limit 0 pour ne pas borner
     *
     * @return int[]
     */
    public static function findIds(array $filters, int $start = 0, int $limit = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table    = Article::getTable();
        $criteria = self::buildCriteria($filters);

        $criteria['SELECT']   = $table . '.id';
        $criteria['DISTINCT'] = true;
        $criteria['ORDER']    = [$table . '.id ASC'];

        if ($limit > 0) {
            $criteria['START'] = max(0, $start);
            $criteria['LIMIT'] = $limit;
        }

        $ids = [];
        foreach ($DB->request($criteria) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Liste paginée des cartes, enrichie des informations d'affichage.
     *
     * La page se compose en deux temps.
     * Le premier trie et pagine sur les seuls identifiants : la base n'a
     * alors à lire que l'index `score_order`, et jamais les lignes.
     * Le second charge les colonnes d'affichage - dont le début du contenu
     * - pour la vingtaine d'identifiants retenus.
     *
     * Les faire en une seule requête revenait à demander à la base de
     * trimbaler le contenu de **toutes** les cartes filtrées dans son
     * tampon de tri, pour n'en garder qu'une page : le tri par score, qui
     * dépend de l'heure, ne peut pas s'arrêter avant d'avoir tout vu.
     *
     * @param array $filters
     * @param int   $start
     * @param int   $limit
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getList(array $filters, int $start = 0, int $limit = 24): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = Article::getTable();
        $order = self::buildOrder(
            self::normalizeSort((string) ($filters['sort'] ?? ''))
        );

        // Temps 1 : l'ordre et la page, sur les identifiants seuls.
        $criteria = self::buildCriteria($filters);

        $criteria['SELECT'] = $table . '.id';
        $criteria['ORDER']  = $order;
        $criteria['START']  = max(0, $start);
        $criteria['LIMIT']  = max(1, $limit);

        $ids = [];
        foreach ($DB->request($criteria) as $row) {
            $ids[] = (int) $row['id'];
        }

        if ($ids === []) {
            return [];
        }

        // Temps 2 : le contenu des seules lignes retenues.
        $found    = [];
        $iterator = $DB->request([
            'SELECT' => self::getListColumns(),
            'FROM'   => $table,
            'WHERE'  => [$table . '.id' => $ids],
        ]);

        foreach ($iterator as $row) {
            $found[(int) $row['id']] = $row;
        }

        // L'ordre vient du premier temps : un `IN` ne garantit pas celui
        // dans lequel la base rend les lignes, et le retrier en base
        // ferait réévaluer le score - donc l'heure - une seconde fois.
        $rows = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $rows[$id] = $found[$id];
            }
        }

        return ArticleDecorator::decorate($rows, (int) ($filters['users_id'] ?? 0));
    }

    /**
     * Sous-requête renvoyant les identifiants des cartes retenues par les
     * filtres, pour agréger les statistiques sans les charger en mémoire.
     *
     * @param array $filters
     *
     * @return QuerySubQuery
     */
    public static function buildIdSubQuery(array $filters): QuerySubQuery
    {
        $criteria           = self::buildCriteria($filters);
        $criteria['SELECT'] = Article::getTable() . '.id';

        return new QuerySubQuery($criteria);
    }

    /**
     * Modes de tri proposés dans l'observatoire.
     *
     * @return array<string, string>
     */
    public static function getSortModes(): array
    {
        return [
            Article::SORT_SCORE => __('Score d\'intérêt', 'veilletool'),
            Article::SORT_DATE  => __('Date de publication', 'veilletool'),
        ];
    }

    /**
     * Normalise le tri demandé, en retombant sur le réglage par défaut.
     *
     * @param string $sort
     *
     * @return string
     */
    public static function normalizeSort(string $sort): string
    {
        if (array_key_exists($sort, self::getSortModes())) {
            return $sort;
        }

        $default = (string) Settings::get('default_sort');

        return array_key_exists($default, self::getSortModes()) ? $default : Article::SORT_SCORE;
    }

    /**
     * Valide une date saisie par l'utilisateur et la renvoie au format
     * attendu par la base, ou une chaîne vide si elle est inexploitable.
     *
     * @param string $value
     *
     * @return string
     */
    public static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * Découpe une saisie en mots exploitables par l'index plein texte.
     *
     * Toute ponctuation devient séparateur : les opérateurs du mode booléen
     * (`+ - * " ( ) ~ < >`) ne peuvent donc pas être injectés depuis le
     * champ de recherche.
     *
     * @param string $keyword
     *
     * @return string[]
     */
    private static function extractSearchWords(string $keyword): array
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $keyword) ?? '';

        $words = [];
        foreach (explode(' ', $cleaned) as $word) {
            if ($word !== '' && mb_strlen($word) >= self::FULLTEXT_MIN_WORD) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * Construit les critères de recherche communs à la liste et au
     * comptage.
     *
     * @param array $filters
     *
     * @return array
     */
    private static function buildCriteria(array $filters): array
    {
        $table    = Article::getTable();
        $criteria = [
            'FROM'  => $table,
            'WHERE' => [],
        ];

        // `categories_ids` (tableau) sert à la sélection multi-thèmes.
        // À défaut, `categories_id` porte l'onglet actif de l'observatoire.
        // Le filtrage passe par une sous-requête plutôt qu'une jointure, ce
        // qui évite les doublons quand plusieurs catégories sont retenues.
        $categories_ids = array_values(array_filter(array_map(
            'intval',
            (array) ($filters['categories_ids'] ?? [])
        )));

        if ($categories_ids === [] && (int) ($filters['categories_id'] ?? 0) > 0) {
            $categories_ids = [(int) $filters['categories_id']];
        }

        if ($categories_ids !== []) {
            $criteria['WHERE'][] = [
                $table . '.id' => new QuerySubQuery([
                    'SELECT' => 'plugin_veilletool_articles_id',
                    'FROM'   => Article_Category::getTable(),
                    'WHERE'  => ['plugin_veilletool_categories_id' => $categories_ids],
                ]),
            ];
        }

        if (!empty($filters['favorites_only'])) {
            $criteria['INNER JOIN'][Favorite::getTable()] = [
                'ON' => [
                    Favorite::getTable() => 'plugin_veilletool_articles_id',
                    $table               => 'id',
                ],
            ];
        }

        $users_id = (int) ($filters['users_id'] ?? 0);
        if (!empty($filters['unread_only']) && $users_id > 0) {
            $criteria['LEFT JOIN'][ReadStatus::getTable()] = [
                'ON' => [
                    ReadStatus::getTable() => 'plugin_veilletool_articles_id',
                    $table                 => 'id',
                    [
                        'AND' => [ReadStatus::getTable() . '.users_id' => $users_id],
                    ],
                ],
            ];
            $criteria['WHERE'][] = [ReadStatus::getTable() . '.id' => null];
        }

        $sources_id = (int) ($filters['sources_id'] ?? 0);
        if ($sources_id > 0) {
            $criteria['WHERE'][$table . '.plugin_veilletool_sources_id'] = $sources_id;
        }

        if (!empty($filters['with_comments'])) {
            $criteria['WHERE'][] = [
                $table . '.id' => new QuerySubQuery([
                    'SELECT' => 'plugin_veilletool_articles_id',
                    'FROM'   => Comment::getTable(),
                ]),
            ];
        }

        // Une seule borne renseignée donne « après » ou « avant ».
        // Les deux ensemble donnent une fourchette.
        $date_from = self::normalizeDate((string) ($filters['date_from'] ?? ''));
        if ($date_from !== '') {
            $criteria['WHERE'][] = [
                $table . '.date_published' => ['>=', $date_from . ' 00:00:00'],
            ];
        }

        $date_to = self::normalizeDate((string) ($filters['date_to'] ?? ''));
        if ($date_to !== '') {
            $criteria['WHERE'][] = [
                $table . '.date_published' => ['<=', $date_to . ' 23:59:59'],
            ];
        }

        // Filtre technologique : la classification étant matérialisée, une
        // sous-requête sur la table de liaison suffit.
        $tech_level = (string) ($filters['tech_level'] ?? '');
        $tech_label = (string) ($filters['tech_label'] ?? '');

        if ($tech_label !== '' && in_array($tech_level, ['categorie', 'famille', 'name'], true)) {
            $criteria['WHERE'][] = [
                $table . '.id' => new QuerySubQuery([
                    'SELECT' => 'plugin_veilletool_articles_id',
                    'FROM'   => Article_Technology::getTable(),
                    'WHERE'  => [$tech_level => $tech_label],
                ]),
            ];
        } elseif (!empty($filters['tech_other'])) {
            // « Autre » : aucune technologie reconnue pour cette carte.
            $criteria['WHERE'][] = [
                'NOT' => [
                    $table . '.id' => new QuerySubQuery([
                        'SELECT' => 'plugin_veilletool_articles_id',
                        'FROM'   => Article_Technology::getTable(),
                    ]),
                ],
            ];
        }

        // Référentiel des technologies suivies : ne retient que les cartes
        // rattachées à une famille suivie, ou à une technologie suivie pour
        // elle-même.
        if (!empty($filters['followed_only'])) {
            $conditions = FollowedTechnology::buildLinkConditions();

            $criteria['WHERE'][] = $conditions === []
                // Rien n'est suivi : la sélection est vide, et visiblement
                // vide, plutôt que silencieusement ignorée.
                ? new QueryExpression('0')
                : [
                    $table . '.id' => new QuerySubQuery([
                        'SELECT' => 'plugin_veilletool_articles_id',
                        'FROM'   => Article_Technology::getTable(),
                        'WHERE'  => ['OR' => $conditions],
                    ]),
                ];
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // L'auteur reste cherché en sous-chaîne : c'est un nom propre,
            // souvent partiel (« Dupont » dans « J.
            // Dupont (ANSSI) ») et absent de l'index plein texte.
            $conditions = [[$table . '.author' => ['LIKE', '%' . $keyword . '%']]];

            $fulltext = self::buildFulltextCondition($table, $keyword);

            if ($fulltext !== null) {
                $conditions[] = $fulltext;
            } else {
                // Mots trop courts pour l'index : on retombe sur l'ancien
                // comportement plutôt que de ne rien trouver.
                $like = '%' . $keyword . '%';
                $conditions[] = [$table . '.name' => ['LIKE', $like]];
                $conditions[] = [$table . '.summary' => ['LIKE', $like]];
                $conditions[] = [$table . '.content' => ['LIKE', $like]];
            }

            $criteria['WHERE'][] = ['OR' => $conditions];
        }

        return $criteria;
    }

    /**
     * Construit la condition `MATCH ...
     * AGAINST` de la recherche par mot clé.
     *
     * L'index plein texte porte sur le titre, le résumé et le contenu.
     * Le mode booléen permet d'exiger tous les mots saisis et d'accepter
     * leurs dérivés : « chiffr » retrouve « chiffrement » comme « chiffré
     * ».
     *
     * @param string $table
     * @param string $keyword
     *
     * @return QueryExpression|null Null si aucun mot n'est indexable
     */
    private static function buildFulltextCondition(string $table, string $keyword): ?QueryExpression
    {
        $words = self::extractSearchWords($keyword);

        if ($words === []) {
            return null;
        }

        // Chaque mot est exigé (« + ») et ouvert sur ses dérivés (« * »),
        // pour rester aussi permissif que l'ancien LIKE « %mot% » en fin de
        // mot, tout en profitant de l'index.
        $terms = [];
        foreach ($words as $word) {
            $terms[] = '+' . $word . '*';
        }

        $columns = [];
        foreach (['name', 'summary', 'content'] as $column) {
            $columns[] = DBmysql::quoteName($table . '.' . $column);
        }

        return new QueryExpression(sprintf(
            'MATCH(%s) AGAINST (%s IN BOOLEAN MODE)',
            implode(', ', $columns),
            DBmysql::quoteValue(implode(' ', $terms))
        ));
    }

    /**
     * Clause de tri correspondant au mode retenu.
     *
     * Le score dépend de la fraîcheur, donc de l'heure : il est évalué par
     * la base, ce qui évite d'avoir à recalculer chaque nuit un classement
     * qui se périmerait seul.
     *
     * @param string $sort
     *
     * @return array<int, string|QueryExpression>
     */
    private static function buildOrder(string $sort): array
    {
        $table = Article::getTable();

        if ($sort === Article::SORT_SCORE) {
            return [
                Score::sqlOrder($table),
                $table . '.date_published DESC',
                $table . '.id DESC',
            ];
        }

        return [$table . '.date_published DESC', $table . '.id DESC'];
    }

    /**
     * Colonnes ramenées par les listes de cartes.
     *
     * Tout y est sauf le contenu intégral, remplacé par son début : c'est
     * ce qui sépare une page qui s'affiche d'une page qui se charge.
     *
     * @return array<int, string|QueryExpression>
     */
    private static function getListColumns(): array
    {
        $table = Article::getTable();

        $columns = [];
        foreach (
            [
                'id',
                'plugin_veilletool_sources_id',
                'name',
                'summary',
                'url',
                'guid',
                'author',
                'image_url',
                'origin',
                'date_published',
                'users_id',
                'score_source',
                'score_technology',
                'score_category',
                'is_indexed',
                'date_mod',
                'date_creation',
            ] as $column
        ) {
            $columns[] = $table . '.' . $column;
        }

        $columns[] = new QueryExpression(sprintf(
            'LEFT(%s, %d) AS %s',
            DBmysql::quoteName($table . '.content'),
            self::LIST_CONTENT_LENGTH,
            DBmysql::quoteName('content')
        ));

        return $columns;
    }
}
