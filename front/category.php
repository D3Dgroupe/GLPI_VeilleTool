<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Écran des thèmes de veille : les étiquettes qui alimentent les onglets de
 * filtrage de l'observatoire.
 *
 * L'écran ne passe plus par le moteur de recherche générique de GLPI : un
 * thème se juge à son apparence - sa couleur, son icône, le contraste de
 * son étiquette - et se range par rapport aux autres.
 * Un tableau de colonnes ne montrait ni l'un ni l'autre.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Taxonomy\CategoryCsv;
use GlpiPlugin\Veilletool\Taxonomy\CategoryOrder;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Category::$rightname, READ);

$category = new Category();

/**
 * Restitue à l'utilisateur le résultat d'un import.
 *
 * @param array{imported:int, updated:int, removed:int, errors:string[]} $result
 *
 * @return void
 */
$report_import = static function (array $result): void {
    if ($result['imported'] > 0 || $result['updated'] > 0 || $result['removed'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('%1$d thème(s) ajouté(s), %2$d mis à jour, %3$d retiré(s).', 'veilletool'),
                $result['imported'],
                $result['updated'],
                $result['removed']
            ),
            true,
            INFO
        );
    }

    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
    }
};

// --------------------------------------------------------------------------
// Ordre d'affichage
//
// L'ordre arrive tel que l'écran l'a montré : la liste des identifiants, du
// premier au dernier.
// Les boutons monter / descendre font la même chose, une place à la fois,
// pour qui n'a pas de souris ou pas de JavaScript.
// --------------------------------------------------------------------------
if (isset($_POST['reorder'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $moved = CategoryOrder::applyOrder((array) ($_POST['order'] ?? []));

    Session::addMessageAfterRedirect(
        $moved > 0
            ? __('Ordre d\'affichage enregistré.', 'veilletool')
            : __('L\'ordre n\'a pas changé.', 'veilletool'),
        true,
        INFO
    );

    Navigation::back(Category::getSearchURL());
}

if (isset($_POST['move_up']) || isset($_POST['move_down'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $up = isset($_POST['move_up']);

    CategoryOrder::move((int) ($up ? $_POST['move_up'] : $_POST['move_down']), $up);

    Navigation::back(Category::getSearchURL());
}

// --------------------------------------------------------------------------
// Activation / désactivation
// --------------------------------------------------------------------------
if (isset($_POST['toggle_active'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $categories_id = (int) $_POST['toggle_active'];

    if ($category->getFromDB($categories_id)) {
        $now = (int) $category->fields['is_active'] === 1 ? 0 : 1;

        $category->update([
            'id'          => $categories_id,
            'is_active'   => $now,
            '_no_message' => true,
        ]);

        Session::addMessageAfterRedirect(
            $now === 1
                ? __('Thème activé : il reparaît dans les onglets de l\'observatoire.', 'veilletool')
                : __('Thème désactivé : il disparaît des onglets, sans perdre ses cartes.', 'veilletool'),
            true,
            INFO
        );
    }

    Navigation::back(Category::getSearchURL());
}

// --------------------------------------------------------------------------
// Export du référentiel
// --------------------------------------------------------------------------
if (isset($_POST['export'])) {
    return new Response(
        CategoryCsv::toCsv(),
        200,
        [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="veille-themes.csv"',
        ]
    );
}

// --------------------------------------------------------------------------
// Import d'un référentiel de thèmes
// --------------------------------------------------------------------------
if (isset($_POST['import'])) {
    Session::checkRight(Source::$rightname, CREATE);

    $file = $_FILES['csvfile'] ?? null;

    if (
        !is_array($file)
        || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'])
    ) {
        Session::addMessageAfterRedirect(
            __('Aucun fichier CSV n\'a été reçu (vérifiez la taille maximale autorisée par PHP).', 'veilletool'),
            false,
            ERROR
        );
    } elseif ((int) $file['size'] > CategoryCsv::CSV_MAX_SIZE) {
        Session::addMessageAfterRedirect(
            __('Fichier trop volumineux (256 Kio maximum).', 'veilletool'),
            false,
            ERROR
        );
    } else {
        $replace = (int) ($_POST['replace'] ?? 0) === 1;

        if ($replace) {
            Session::checkRight(Source::$rightname, PURGE);
        }

        $report_import(
            CategoryCsv::importCsv((string) file_get_contents($file['tmp_name']), $replace)
        );
    }

    Navigation::back(Category::getSearchURL());
}

// --------------------------------------------------------------------------
// Suppression d'un thème
// --------------------------------------------------------------------------
if (isset($_POST['purge'])) {
    Session::checkRight(Source::$rightname, PURGE);

    $categories_id = (int) $_POST['purge'];

    if ($category->getFromDB($categories_id)) {
        $category->delete(['id' => $categories_id], true);

        Session::addMessageAfterRedirect(
            __('Thème supprimé. Les cartes qui le portaient restent en place, sans lui.', 'veilletool'),
            true,
            INFO
        );
    }

    Navigation::back(Category::getSearchURL());
}

// --------------------------------------------------------------------------
// Recherche
// --------------------------------------------------------------------------
$search = [
    'keyword' => trim((string) ($_GET['q'] ?? '')),
    // '' = tous, '1' = actifs, '0' = inactifs.
    'active'  => (string) ($_GET['active'] ?? ''),
];

if (!in_array($search['active'], ['', '0', '1'], true)) {
    $search['active'] = '';
}

$has_search = $search['keyword'] !== '' || $search['active'] !== '';

// --------------------------------------------------------------------------
// Liste
// --------------------------------------------------------------------------
$all = Category::getAllOrdered();
$ids = array_map(static fn(array $row): int => $row['id'], $all);

$article_counts = Category::countArticles($ids);
$source_counts  = Category::countSources($ids);

$categories = [];
$actives    = 0;

foreach ($all as $row) {
    $categories_id = $row['id'];

    $row['article_count'] = $article_counts[$categories_id] ?? 0;
    $row['source_count']  = $source_counts[$categories_id] ?? 0;

    if ((int) $row['is_active'] === 1) {
        $actives++;
    }

    if ($search['active'] !== '' && (string) (int) $row['is_active'] !== $search['active']) {
        continue;
    }

    if (
        $search['keyword'] !== ''
        && mb_stripos((string) $row['name'], $search['keyword']) === false
        && mb_stripos((string) ($row['comment'] ?? ''), $search['keyword']) === false
    ) {
        continue;
    }

    $categories[] = $row;
}

Html::header(
    Category::getTypeName(Session::getPluralNumber()),
    '',
    Menu::SECTOR,
    Menu::ITEM_CATEGORIES
);

TemplateRenderer::getInstance()->display('@veilletool/categories.html.twig', [
    'categories'   => $categories,
    'total_count'  => count($all),
    'active_count' => $actives,
    'search'       => $search,
    'has_search'   => $has_search,
    'csv_header'   => CategoryCsv::CSV_HEADER,
    'can_update'   => Session::haveRight(Source::$rightname, UPDATE),
    'can_create'   => Session::haveRight(Source::$rightname, CREATE),
    'can_purge'    => Session::haveRight(Source::$rightname, PURGE),
]);

Html::footer();
