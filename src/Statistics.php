<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use GlpiPlugin\Veilletool\Cards\ArticleQuery;

/**
 * Répartition des cartes par technologie ou par réglementation.
 *
 * Les chiffres sont obtenus par agrégation SQL sur la classification
 * matérialisée dans Article_Technology : aucune carte n'est chargée en
 * mémoire, et l'analyse porte sur l'intégralité des cartes filtrées.
 *
 * Une carte relevant de plusieurs catégories est comptée une fois dans
 * chacune.
 * Une carte sans technologie reconnue est comptée dans « Autre ».
 */
class Statistics
{
    /** Découpage par catégorie du référentiel */
    public const LEVEL_CATEGORY = 'categorie';

    /** Découpage par famille du référentiel */
    public const LEVEL_FAMILY = 'famille';

    /** Découpage par sous-catégorie, c'est-à-dire par technologie précise */
    public const LEVEL_TECHNOLOGY = 'name';

    /** Valeur de filtre désignant les cartes hors référentiel */
    public const FILTER_OTHER = 'other';

    /** Nombre de sous-catégories détaillées sous le graphique */
    private const TOP_SUBCATEGORIES = 20;

    /**
     * Niveaux de découpage proposés.
     *
     * @return array<string, string>
     */
    public static function getLevels(): array
    {
        return [
            self::LEVEL_CATEGORY   => __('Catégorie', 'veilletool'),
            self::LEVEL_FAMILY     => __('Famille', 'veilletool'),
            self::LEVEL_TECHNOLOGY => __('Technologie', 'veilletool'),
        ];
    }

    /**
     * Libellé lisible d'une valeur de filtre technologique.
     *
     * « famille:Linux - Debian » se lit « Linux - Debian » : le niveau est
     * une mécanique interne, il n'a pas à s'afficher.
     *
     * @param string $value
     *
     * @return string
     */
    public static function filterLabel(string $value): string
    {
        $filter = self::parseTechnologyFilter($value);

        if ($filter === null) {
            return '';
        }

        return $filter['other'] ? Technology::OTHER : $filter['label'];
    }

    /**
     * Décode la valeur du filtre technologique.
     *
     * Format attendu : « categorie:OS », « famille:Linux kernel », ou le
     * jeton « other ».
     * Un jeton dédié évite toute collision avec une catégorie réellement
     * nommée « Autre ».
     *
     * @param string $value
     *
     * @return array{level:string, label:string, other:bool}|null
     */
    public static function parseTechnologyFilter(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === self::FILTER_OTHER) {
            return ['level' => '', 'label' => '', 'other' => true];
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2 || !array_key_exists($parts[0], self::getLevels()) || trim($parts[1]) === '') {
            return null;
        }

        return ['level' => $parts[0], 'label' => trim($parts[1]), 'other' => false];
    }

    /**
     * Traduit le filtre décodé en critères exploitables par Article.
     *
     * @param array{level:string, label:string, other:bool}|null $filter
     *
     * @return array<string, mixed>
     */
    public static function toArticleFilters(?array $filter): array
    {
        if ($filter === null) {
            return [];
        }

        if ($filter['other']) {
            return ['tech_other' => true];
        }

        return ['tech_level' => $filter['level'], 'tech_label' => $filter['label']];
    }

    /**
     * Options du filtre technologique, groupées par niveau.
     *
     * Seules les valeurs effectivement rencontrées sur au moins une carte
     * sont proposées : inutile d'offrir quatre-vingt-treize familles dont
     * la plupart ne ramèneraient rien.
     *
     * @return array<string, array<string, string>> groupe => [valeur => libellé]
     */
    public static function getFilterOptions(): array
    {
        $options = [
            __('Hors référentiel', 'veilletool') => [
                self::FILTER_OTHER => Technology::OTHER,
            ],
        ];

        $categories = [];
        foreach (Article_Technology::getUsedValues(self::LEVEL_CATEGORY) as $categorie) {
            $categories[self::LEVEL_CATEGORY . ':' . $categorie] = $categorie;
        }
        if ($categories !== []) {
            $options[__('Catégories', 'veilletool')] = $categories;
        }

        $familles = [];
        foreach (Article_Technology::getUsedValues(self::LEVEL_FAMILY) as $famille) {
            $familles[self::LEVEL_FAMILY . ':' . $famille] = $famille;
        }
        if ($familles !== []) {
            $options[__('Familles', 'veilletool')] = $familles;
        }

        return $options;
    }

    /**
     * Calcule la répartition des cartes retenues par les filtres.
     *
     * @param array  $filters Filtres de l'observatoire
     * @param string $level
     *
     * @return array{
     *     slices: array<int, array{label:string, count:int, share:float}>,
     *     total: int,
     *     matched: int,
     *     unmatched: int,
     *     subcategories: array<int, array{categorie:string, famille:string, name:string, count:int}>,
     *     level: string
     * }
     */
    public static function compute(array $filters, string $level = self::LEVEL_CATEGORY): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!array_key_exists($level, self::getLevels())) {
            $level = self::LEVEL_CATEGORY;
        }

        $total = ArticleQuery::countList($filters);
        $link  = Article_Technology::getTable();

        if ($total === 0) {
            return [
                'slices'        => [],
                'total'         => 0,
                'matched'       => 0,
                'unmatched'     => 0,
                'subcategories' => [],
                'level'         => $level,
            ];
        }

        // Les cartes retenues sont désignées par une sous-requête : le
        // décompte se fait intégralement côté base.
        $scope = ArticleQuery::buildIdSubQuery($filters);

        // Au niveau technologie, les rattachements arrêtés à la famille
        // n'ont pas de sous-catégorie : ils ne désignent aucune technologie
        // et relèvent donc d'« Autre », au même titre qu'une carte hors
        // référentiel.
        $where = ['plugin_veilletool_articles_id' => $scope];

        if ($level === self::LEVEL_TECHNOLOGY) {
            $where[] = ['NOT' => ['name' => '']];
        }

        $counts   = [];
        $iterator = $DB->request([
            'SELECT'   => [
                $level,
                'COUNT DISTINCT' => 'plugin_veilletool_articles_id AS cpt',
            ],
            'FROM'     => $link,
            'WHERE'    => $where,
            'GROUPBY'  => [$level],
            'ORDER'    => ['cpt DESC'],
        ]);

        foreach ($iterator as $row) {
            $counts[(string) $row[$level]] = (int) $row['cpt'];
        }

        // Nombre de cartes que le découpage retient effectivement.
        $matched_row = $DB->request([
            'SELECT' => ['COUNT DISTINCT' => 'plugin_veilletool_articles_id AS cpt'],
            'FROM'   => $link,
            'WHERE'  => $where,
        ])->current();
        $matched = $matched_row !== null ? (int) $matched_row['cpt'] : 0;

        $unmatched = max(0, $total - $matched);

        $slices = [];
        foreach ($counts as $label => $count) {
            $slices[] = [
                'label' => $label,
                'count' => $count,
                'share' => round($count * 100 / $total, 1),
            ];
        }

        // « Autre » reste en dernière position, quelle que soit sa taille.
        if ($unmatched > 0) {
            $slices[] = [
                'label' => Technology::OTHER,
                'count' => $unmatched,
                'share' => round($unmatched * 100 / $total, 1),
            ];
        }

        return [
            'slices'        => $slices,
            'total'         => $total,
            'matched'       => $matched,
            'unmatched'     => $unmatched,
            'subcategories' => self::computeSubcategories($scope),
            'level'         => $level,
        ];
    }

    /**
     * Sous-catégories les plus citées parmi les cartes retenues.
     *
     * @param \Glpi\DBAL\QuerySubQuery $scope
     *
     * @return array<int, array{categorie:string, famille:string, name:string, count:int}>
     */
    private static function computeSubcategories($scope): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows     = [];
        $iterator = $DB->request([
            'SELECT'  => [
                'categorie',
                'famille',
                'name',
                'COUNT DISTINCT' => 'plugin_veilletool_articles_id AS cpt',
            ],
            'FROM'    => Article_Technology::getTable(),
            'WHERE'   => [
                'plugin_veilletool_articles_id' => $scope,
                ['NOT' => ['name' => '']],
            ],
            'GROUPBY' => ['categorie', 'famille', 'name'],
            'ORDER'   => ['cpt DESC', 'name ASC'],
            'LIMIT'   => self::TOP_SUBCATEGORIES,
        ]);

        foreach ($iterator as $row) {
            $rows[] = [
                'categorie' => (string) $row['categorie'],
                'famille'   => (string) $row['famille'],
                'name'      => (string) $row['name'],
                'count'     => (int) $row['cpt'],
            ];
        }

        return $rows;
    }
}
