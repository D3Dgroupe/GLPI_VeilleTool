<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Liste des cartes de veille (onglets « Veille technologique » et « Favoris
 * »), avec filtrage par catégorie, par mot clé et par état de lecture.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Cards\ArticleQuery;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\FilterPreset;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\ReadStatus;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Statistics;

if (!class_exists(Kernel::class, autoload: false)) {
    // Le script doit être servi par le routeur de GLPI (/public/index.php).
    header('HTTP/1.1 404 Not Found');
    exit();
}

/** @var DBmysql $DB */
global $DB;

Session::checkRight(Article::$rightname, READ);

$users_id       = (int) Session::getLoginUserID();
$favorites_only = (int) ($_REQUEST['favorites'] ?? 0) === 1;

// --------------------------------------------------------------------------
// Actions (marquage lu / non lu de masse)
// --------------------------------------------------------------------------
// L'état de lecture est propre à chaque utilisateur : il ne modifie pas la
// carte, et n'a donc pas à dépendre du droit d'écriture.
if (isset($_POST['mark_all_read'])) {
    $filters = [
        'categories_id'  => (int) ($_POST['cat'] ?? 0),
        'keyword'        => (string) ($_POST['q'] ?? ''),
        'sources_id'     => (int) ($_POST['src'] ?? 0),
        'with_comments'  => (int) ($_POST['commented'] ?? 0) === 1,
        'date_from'      => (string) ($_POST['from'] ?? ''),
        'date_to'        => (string) ($_POST['to'] ?? ''),
        'followed_only'  => (int) ($_POST['followed'] ?? 0) === 1,
        'favorites_only' => $favorites_only,
        'unread_only'    => true,
        'users_id'       => $users_id,
    ] + Statistics::toArticleFilters(
        Statistics::parseTechnologyFilter((string) ($_POST['tech'] ?? ''))
    );

    // Les cartes marquées sortent du filtre « non lues » : on repart donc
    // toujours du premier lot, avec une borne de sécurité.
    // Seuls les identifiants sont chargés - décorer les cartes pour les
    // jeter aussitôt coûtait bien plus cher que le marquage lui-même.
    $count = 0;
    do {
        $batch = ArticleQuery::findIds($filters, 0, 500);
        $count += ReadStatus::markManyAsRead($batch, $users_id);
    } while (count($batch) === 500 && $count < 5000);

    Session::addMessageAfterRedirect(
        sprintf(_n('%d carte marquée comme lue.', '%d cartes marquées comme lues.', $count, 'veilletool'), $count),
        true,
        INFO
    );

    Navigation::back(Article::getSearchURL());
}

// --------------------------------------------------------------------------
// Filtres enregistrés
//
// Un filtre de veille se reconstitue plusieurs fois par jour.
// Le recomposer chaque fois use, et on finit par ne plus filtrer du tout.
// Les préréglages sont propres à chaque utilisateur : ranger sa propre vue
// ne demande aucun droit d'écriture.
// --------------------------------------------------------------------------
if (isset($_POST['filter_save'])) {
    $done = FilterPreset::save((string) ($_POST['filter_name'] ?? ''), $_POST);

    [$message, $succes] = match ($done['status']) {
        'added'    => [
            sprintf(__('Filtre « %s » enregistré.', 'veilletool'), htmlescape($done['name'])),
            true,
        ],
        'replaced' => [
            sprintf(__('Filtre « %s » remplacé.', 'veilletool'), htmlescape($done['name'])),
            true,
        ],
        'full'     => [
            sprintf(
                __('%d filtres au plus : supprimez-en un avant d\'en ajouter.', 'veilletool'),
                FilterPreset::MAX_PRESETS
            ),
            false,
        ],
        default    => [
            __('Rien à enregistrer : posez au moins un critère, et donnez-lui un nom.', 'veilletool'),
            false,
        ],
    };

    Session::addMessageAfterRedirect($message, true, $succes ? INFO : ERROR);

    // On revient sur la vue qu'on vient de nommer, pas sur une page vierge.
    Html::redirect(
        Article::getSearchURL() . '?' . http_build_query(FilterPreset::extract($_POST))
    );
}

if (isset($_POST['filter_delete'])) {
    $removed = FilterPreset::remove((int) ($_POST['preset_id'] ?? 0));

    Session::addMessageAfterRedirect(
        $removed !== ''
            ? sprintf(__('Filtre « %s » supprimé.', 'veilletool'), htmlescape($removed))
            : __('Choisissez le filtre à supprimer dans la liste.', 'veilletool'),
        true,
        $removed !== '' ? INFO : ERROR
    );

    Html::redirect(
        Article::getSearchURL() . '?' . http_build_query(FilterPreset::extract($_POST))
    );
}

// Un préréglage choisi dans la liste : ses paramètres remplacent l'adresse,
// de sorte que la barre de filtres les relit comme n'importe quels autres.
// Rien n'est donc contrôlé deux fois, ni contourné.
if (isset($_POST['filter_apply'])) {
    $preset = FilterPreset::load((int) ($_POST['preset_id'] ?? 0));

    if ($preset === null) {
        Session::addMessageAfterRedirect(
            __('Choisissez un filtre dans la liste.', 'veilletool'),
            true,
            ERROR
        );

        // On reste sur la vue courante plutôt que de la perdre.
        Html::redirect(
            Article::getSearchURL() . '?' . http_build_query(FilterPreset::extract($_POST))
        );
    }

    $target = $preset['params'];

    if ($favorites_only) {
        $target['favorites'] = 1;
    }

    Html::redirect(Article::getSearchURL() . '?' . http_build_query($target));
}

// --------------------------------------------------------------------------
// Filtres
// --------------------------------------------------------------------------
$categories    = Category::getActiveCategories();
$categories_id = (int) ($_GET['cat'] ?? 0);
if ($categories_id > 0 && !isset($categories[$categories_id])) {
    $categories_id = 0;
}

// Tri des cartes : par score d'intérêt par défaut - réglage de
// l'administrateur -, ou par date si l'utilisateur préfère s'en passer.
// Son choix est mémorisé dans sa session, comme le mode d'affichage.
if (array_key_exists('sort', $_GET)) {
    $_SESSION['plugin_veilletool_sort'] = ArticleQuery::normalizeSort((string) $_GET['sort']);
}
$sort = ArticleQuery::normalizeSort((string) ($_SESSION['plugin_veilletool_sort'] ?? ''));
$_SESSION['plugin_veilletool_sort'] = $sort;

$filters = [
    'categories_id'  => $categories_id,
    'keyword'        => trim((string) ($_GET['q'] ?? '')),
    'sources_id'     => (int) ($_GET['src'] ?? 0),
    'unread_only'    => (int) ($_GET['unread'] ?? 0) === 1,
    'with_comments'  => (int) ($_GET['commented'] ?? 0) === 1,
    'followed_only'  => (int) ($_GET['followed'] ?? 0) === 1,
    'date_from'      => ArticleQuery::normalizeDate((string) ($_GET['from'] ?? '')),
    'date_to'        => ArticleQuery::normalizeDate((string) ($_GET['to'] ?? '')),
    'favorites_only' => $favorites_only,
    'sort'           => $sort,
    'users_id'       => $users_id,
];

// Vue demandée : cartes, liste tabulaire ou statistiques.
// Le choix est mémorisé dans la session pour être retrouvé d'une visite à
// l'autre.
$views = ['cards', 'list', 'stats'];
$view  = (string) ($_GET['view'] ?? ($_SESSION['plugin_veilletool_view'] ?? 'cards'));
if (!in_array($view, $views, true)) {
    $view = 'cards';
}
$_SESSION['plugin_veilletool_view'] = $view;

// Filtre technologique : la classification des cartes étant matérialisée en
// base, le filtre se traduit par une sous-requête SQL ordinaire, sans
// plafond ni traitement en mémoire.
$tech_raw    = (string) ($_REQUEST['tech'] ?? '');
$tech_filter = Statistics::parseTechnologyFilter($tech_raw);
if ($tech_filter === null) {
    $tech_raw = '';
}

$filters += Statistics::toArticleFilters($tech_filter);

$limit = max(5, (int) ($_SESSION['glpilist_limit'] ?? 15));
$start = max(0, (int) ($_GET['start'] ?? 0));
$total = ArticleQuery::countList($filters);

if ($start >= $total) {
    $start = max(0, ((int) ceil(max(1, $total) / $limit) - 1) * $limit);
}

// La vue statistique agrège en base l'intégralité des cartes filtrées. les
// autres vues ne chargent qu'une page de résultats.
$stats = null;

if ($view === 'stats') {
    $articles = [];
    $stats    = Statistics::compute($filters, (string) ($_GET['level'] ?? Statistics::LEVEL_CATEGORY));
} else {
    $articles = ArticleQuery::getList($filters, $start, $limit);
}


// Liste des sources utilisables dans le filtre déroulant.
$sources  = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => Source::getTable(),
    'ORDER'  => ['name ASC'],
]);
foreach ($iterator as $row) {
    $sources[(int) $row['id']] = (string) $row['name'];
}

if ($view === 'stats') {
    // Bibliothèque de graphiques fournie par GLPI (ECharts).
    Html::requireJs('charts');
}

$page_title = $favorites_only
    ? __('Favoris', 'veilletool')
    : __('Observatoire', 'veilletool');

Html::header(
    $page_title,
    '',
    Menu::SECTOR,
    $favorites_only ? Menu::ITEM_FAVORITES : Menu::ITEM_ARTICLES
);

TemplateRenderer::getInstance()->display('@veilletool/articles.html.twig', [
    'page_title'     => $page_title,
    'view'           => $view,
    'sort'           => $sort,
    'sort_modes'     => ArticleQuery::getSortModes(),
    'followed_count' => count(FollowedTechnology::getAll()),
    'stats'          => $stats,
    'stats_levels'      => Statistics::getLevels(),
    'stats_level_icons' => [
        Statistics::LEVEL_CATEGORY   => 'ti ti-category',
        Statistics::LEVEL_FAMILY     => 'ti ti-sitemap',
        Statistics::LEVEL_TECHNOLOGY => 'ti ti-binary-tree',
    ],
    'tech'           => $tech_raw,
    'tech_label'     => Statistics::filterLabel($tech_raw),
    'tech_options'   => Statistics::getFilterOptions(),
    'articles'       => $articles,
    'categories'     => $categories,
    'sources'        => $sources,
    'filters'        => $filters,
    'favorites_only' => $favorites_only,
    'total'          => $total,
    'start'          => $start,
    'limit'          => $limit,
    'filter_presets' => FilterPreset::getMine(),
    'filter_max'     => FilterPreset::MAX_PRESETS,
    'can_update'     => Session::haveRight(Article::$rightname, UPDATE),
    'can_create'     => Session::haveRight(Article::$rightname, CREATE),
    'can_purge'      => Session::haveRight(Article::$rightname, PURGE),
]);

Html::footer();
