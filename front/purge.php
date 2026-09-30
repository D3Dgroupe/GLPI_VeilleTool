<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Écran « Vider les cartes » : suppression définitive d'un lot de cartes
 * choisi par catégorie, par flux, par date et selon le statut de favori.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Cards\ArticlePurge;
use GlpiPlugin\Veilletool\Cards\ArticleQuery;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Source;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

/** @var DBmysql $DB */
global $DB;

Session::checkRight(Article::$rightname, PURGE);

/**
 * Récupère les critères depuis la requête.
 *
 * @param array $request
 *
 * @return array{
 *     categories:int[],
 *     sources:int[],
 *     technologies:array{categorie:string[], famille:string[], name:string[]},
 *     before:string,
 *     favorites:string
 * }
 */
$read_criteria = static function (array $request): array {
    $favorites = (string) ($request['favorites'] ?? 'exclude');
    if (!in_array($favorites, ['exclude', 'include', 'only'], true)) {
        $favorites = 'exclude';
    }

    // Les trois niveaux du référentiel arrivent dans trois listes
    // distinctes, chacune à choix multiple.
    $technologies = [];
    foreach (['categorie', 'famille', 'name'] as $level) {
        $technologies[$level] = array_values(array_unique(array_filter(
            array_map(
                static fn($value): string => trim((string) $value),
                (array) ($request['tech_' . $level] ?? [])
            ),
            static fn(string $value): bool => $value !== ''
        )));
    }

    return [
        'categories'   => array_values(array_filter(array_map('intval', (array) ($request['categories'] ?? [])))),
        'sources'      => array_values(array_filter(array_map('intval', (array) ($request['sources'] ?? [])))),
        'technologies' => $technologies,
        'before'       => ArticleQuery::normalizeDate((string) ($request['before'] ?? '')),
        'favorites'    => $favorites,
    ];
};

$criteria = $read_criteria($_REQUEST);
$matched  = null;

// --------------------------------------------------------------------------
// Décompte préalable
// --------------------------------------------------------------------------
if (isset($_POST['preview'])) {
    $matched = count(ArticlePurge::findIdsForPurge($criteria));

    Session::addMessageAfterRedirect(
        sprintf(
            _n(
                '%d carte correspond à ces critères.',
                '%d cartes correspondent à ces critères.',
                $matched,
                'veilletool'
            ),
            $matched
        ),
        true,
        INFO
    );
}

// --------------------------------------------------------------------------
// Suppression définitive
// --------------------------------------------------------------------------
if (isset($_POST['purge'])) {
    $deleted = ArticlePurge::purgeByCriteria($criteria);

    Session::addMessageAfterRedirect(
        sprintf(
            _n(
                '%d carte supprimée définitivement.',
                '%d cartes supprimées définitivement.',
                $deleted,
                'veilletool'
            ),
            $deleted
        ),
        true,
        INFO
    );

    Html::redirect(Article::getSearchURL());
}

// --------------------------------------------------------------------------
// Affichage
// --------------------------------------------------------------------------
$sources  = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => Source::getTable(),
    'ORDER'  => ['name ASC'],
]);
foreach ($iterator as $row) {
    $sources[(int) $row['id']] = (string) $row['name'];
}

Html::header(
    __('Vider les cartes', 'veilletool'),
    '',
    Menu::SECTOR,
    Menu::ITEM_ARTICLES
);

TemplateRenderer::getInstance()->display('@veilletool/purge.html.twig', [
    'categories'   => Category::getActiveCategories(),
    'sources'      => $sources,
    // Seules les valeurs réellement portées par au moins une carte sont
    // proposées : purger sur une technologie qu'aucune carte ne porte ne
    // viderait rien, et allonger la liste de deux mille entrées inutiles la
    // rendrait impraticable.
    'tech_levels'  => [
        'categorie' => [
            'label'  => __('Catégories technologiques', 'veilletool'),
            'icon'   => 'ti ti-category',
            'values' => Article_Technology::getUsedValues('categorie'),
        ],
        'famille'   => [
            'label'  => __('Familles', 'veilletool'),
            'icon'   => 'ti ti-sitemap',
            'values' => Article_Technology::getUsedValues('famille'),
        ],
        'name'      => [
            'label'  => __('Technologies', 'veilletool'),
            'icon'   => 'ti ti-binary-tree',
            'values' => array_values(array_filter(
                Article_Technology::getUsedValues('name'),
                static fn(string $value): bool => $value !== ''
            )),
        ],
    ],
    'criteria'     => $criteria,
    'matched'      => $matched,
    'total'        => countElementsInTable(Article::getTable()),
]);

Html::footer();
