<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Onglet « Flux RSS / ATOM » : gestion des flux d'alimentation et collecte
 * manuelle.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Collector;
use GlpiPlugin\Veilletool\Feed\SourceCatalog;
use GlpiPlugin\Veilletool\Feed\SourceCron;
use GlpiPlugin\Veilletool\Feed\SourceFilter;
use GlpiPlugin\Veilletool\Feed\SourcePurge;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Source_Category;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

/** @var DBmysql $DB */
global $DB;

Session::checkRight(Source::$rightname, READ);

$source = new Source();

// Critères de recherche, lus dans $_REQUEST : la barre de filtres les
// envoie en GET, la suppression de masse les renvoie en POST pour que
// l'action porte exactement sur ce que l'écran montrait.
$search = SourceFilter::readSearch($_REQUEST);

/**
 * Restitue à l'utilisateur le résultat d'une collecte.
 *
 * @param Source $source
 * @param array{imported:int, updated:int, errors:string[]} $result
 *
 * @return void
 */
$report = static function (Source $source, array $result): void {
    if ($result['errors'] !== []) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('%1$s : %2$s', 'veilletool'),
                htmlescape($source->fields['name']),
                htmlescape(implode(' | ', $result['errors']))
            ),
            false,
            ERROR
        );
        return;
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('%1$s : %2$d nouvelle(s) carte(s), %3$d mise(s) à jour.', 'veilletool'),
            htmlescape($source->fields['name']),
            $result['imported'],
            $result['updated']
        ),
        true,
        INFO
    );
};

/**
 * Restitue à l'utilisateur le résultat d'un import de flux.
 *
 * @param array{imported:int, skipped:int, errors:string[]} $result
 *
 * @return void
 */
$report_import = static function (array $result): void {
    if ($result['imported'] > 0 || $result['skipped'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __(
                    '%1$d flux ajouté(s), désactivé(s) par défaut. %2$d étaient déjà connus.',
                    'veilletool'
                ),
                $result['imported'],
                $result['skipped']
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
// Collecte d'une source
// --------------------------------------------------------------------------
if (isset($_POST['refresh'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    if ($source->getFromDB((int) $_POST['id'])) {
        $report($source, $source->collect());
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Collecte de toutes les sources distantes actives
// --------------------------------------------------------------------------
if (isset($_POST['refresh_all'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $iterator = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => Source::getTable(),
        'WHERE'  => [
            'is_active'  => 1,
            'sourcetype' => [Source::TYPE_RSS, Source::TYPE_ATOM],
            ['NOT' => ['url' => '']],
            ['NOT' => ['url' => null]],
        ],
    ]);

    $deadline = SourceCron::batchDeadline();
    $skipped  = 0;

    foreach ($iterator as $row) {
        // Relever quarante flux, c'est quarante attentes réseau bout à
        // bout.
        // Passé le budget, on s'arrête en rendant compte, plutôt que de
        // laisser PHP interrompre le script au milieu d'un flux.
        if ($deadline !== null && microtime(true) >= $deadline) {
            $skipped++;
            continue;
        }

        if ($source->getFromDB($row['id'])) {
            $report($source, $source->collect());
        }
    }

    if ($skipped > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('%d flux n\'ont pas été relevés faute de temps. Relancez la collecte, ou laissez faire l\'action automatique.', 'veilletool'),
                $skipped
            ),
            true,
            WARNING
        );
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Diagnostic d'un flux
//
// « Délai dépassé » ne dit pas où : la résolution du nom, l'ouverture de la
// connexion et le téléchargement échouent du même mot.
// Ce contrôle les sépare, sans rien lire ni rien écrire.
// --------------------------------------------------------------------------
$diagnosis = null;

if (isset($_POST['diagnose'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    if ($source->getFromDB((int) $_POST['diagnose'])) {
        $diagnosis = [
            'source' => (string) $source->fields['name'],
            'steps'  => Collector::diagnose((string) $source->fields['url']),
        ];
    }
}

// --------------------------------------------------------------------------
// Activation / désactivation d'un flux
//
// Le catalogue livré arrive entièrement désactivé : l'activation se fait
// donc depuis la liste, sans ouvrir une fiche par flux.
// --------------------------------------------------------------------------
if (isset($_POST['toggle_active'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $now = Source::toggleActive((int) $_POST['toggle_active']);

    if ($now !== null) {
        Session::addMessageAfterRedirect(
            $now
                ? __('Flux activé : il sera relevé à la prochaine collecte.', 'veilletool')
                : __('Flux désactivé : il n\'est plus relevé.', 'veilletool'),
            true,
            INFO
        );
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Chargement du catalogue livré avec le plugin
// --------------------------------------------------------------------------
if (isset($_POST['load_catalog'])) {
    Session::checkRight(Source::$rightname, CREATE);

    $report_import(SourceCatalog::importCatalog());

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Import d'une liste de flux au format CSV
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
    } elseif ((int) $file['size'] > SourceCatalog::CSV_MAX_SIZE) {
        Session::addMessageAfterRedirect(
            __('Fichier trop volumineux (1 Mio maximum).', 'veilletool'),
            false,
            ERROR
        );
    } else {
        $report_import(SourceCatalog::importCsv((string) file_get_contents($file['tmp_name'])));
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Suppression de masse
//
// Supprimer un flux emporte toutes ses cartes : le décompte préalable porte
// donc sur les deux, et rien ne s'exécute sans confirmation explicite.
// --------------------------------------------------------------------------
if (isset($_POST['count_sources']) || isset($_POST['purge_sources']) || isset($_POST['purge_all_sources'])) {
    Session::checkRight(Source::$rightname, PURGE);

    // « Tout supprimer » ignore délibérément la recherche en cours : c'est
    // une action distincte, pas un raccourci de la précédente.
    $scope = isset($_POST['purge_all_sources']) ? [] : $search;
    $ids   = SourcePurge::findIdsForPurge($scope);

    if (isset($_POST['count_sources'])) {
        $articles = array_sum(SourceFilter::countArticles($ids));

        Session::addMessageAfterRedirect(
            $ids === []
                ? __('Aucun flux ne correspond à ces critères.', 'veilletool')
                : sprintf(
                    __('%1$d flux et %2$d carte(s) seraient supprimés.', 'veilletool'),
                    count($ids),
                    $articles
                ),
            true,
            $ids === [] ? INFO : WARNING
        );
    } elseif ($ids === []) {
        Session::addMessageAfterRedirect(
            __('Aucun flux ne correspond à ces critères : rien n\'a été supprimé.', 'veilletool'),
            false,
            ERROR
        );
    } else {
        $done = SourcePurge::purgeByIds($ids);

        Session::addMessageAfterRedirect(
            sprintf(
                __('%1$d flux supprimé(s), ainsi que %2$d carte(s).', 'veilletool'),
                $done['sources'],
                $done['articles']
            ),
            true,
            INFO
        );
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Suppression d'une source
// --------------------------------------------------------------------------
if (isset($_POST['purge'])) {
    Session::checkRight(Source::$rightname, PURGE);

    if ($source->getFromDB((int) $_POST['id'])) {
        $source->delete(['id' => (int) $_POST['id']], true);
    }

    Navigation::back(Source::getSearchURL());
}

// --------------------------------------------------------------------------
// Affichage de la liste
// --------------------------------------------------------------------------
$all_categories = Category::getActiveCategories();
$rows           = [];

foreach (
    $DB->request(['FROM' => Source::getTable(), 'ORDER' => ['name ASC']]) as $row
) {
    $rows[(int) $row['id']] = $row;
}

// Décomptes et thèmes : une requête pour tout le tableau, et non une par
// ligne affichée.
$article_counts = SourceFilter::countArticles(array_keys($rows));
$category_links = Source_Category::getCategoryIdsForSources(array_keys($rows));

$sources = [];
$total   = 0;
$actives = 0;

foreach ($rows as $sources_id => $row) {
    $row['id']            = $sources_id;
    $row['type_label']    = Source::getTypeLabel((string) $row['sourcetype']);
    $row['type_icon']     = Source::getTypeIcon((string) $row['sourcetype']);
    $row['can_collect']   = trim((string) $row['url']) !== '';
    $row['article_count'] = $article_counts[$sources_id] ?? 0;

    $row['categories'] = [];
    foreach ($category_links[$sources_id] ?? [] as $categories_id) {
        if (isset($all_categories[$categories_id])) {
            $row['categories'][] = $all_categories[$categories_id];
        }
    }

    // Les compteurs d'en-tête portent sur l'ensemble des flux, pas sur le
    // résultat de la recherche : ils disent où l'on en est, pas ce qu'on
    // regarde.
    $total++;
    if ((int) $row['is_active'] === 1) {
        $actives++;
    }

    if (!SourceFilter::matches($row, $search, $category_links[$sources_id] ?? [])) {
        continue;
    }

    $sources[] = $row;
}

Html::header(
    __('Flux RSS / ATOM', 'veilletool'),
    '',
    Menu::SECTOR,
    Menu::ITEM_SOURCES
);

TemplateRenderer::getInstance()->display('@veilletool/sources.html.twig', [
    'sources'        => $sources,
    'diagnosis'      => $diagnosis,
    'search'         => $search,
    'has_search'     => SourceFilter::hasSearch($search),
    'categories'     => $all_categories,
    'volume_filters' => SourceFilter::getVolumeFilters(),
    'total_count'    => $total,
    'active_count'   => $actives,
    'catalog_count'  => SourceCatalog::countCatalog(),
    'cron'           => SourceCron::status(),
    'can_config'     => Session::haveRight('config', READ),
    'can_update'     => Session::haveRight(Source::$rightname, UPDATE),
    'can_create'     => Session::haveRight(Source::$rightname, CREATE),
    'can_purge'      => Session::haveRight(Source::$rightname, PURGE),
]);

Html::footer();
