<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Onglet « Technologies » : gestion du référentiel Catégorie / Famille /
 * Sous-catégorie qui alimente la vue statistique de l'observatoire.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\CronStatus;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\Technology;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

/** @var DBmysql $DB */
global $DB;

Session::checkRight(Technology::$rightname, READ);

/**
 * Place toutes les cartes dans la file de reclassement.
 *
 * Toute modification du référentiel rend la classification existante
 * obsolète.
 * Le recalcul n'est pas fait ici : il coûte plusieurs dizaines de secondes
 * sur une base fournie, et ferait attendre l'utilisateur pour une simple
 * ligne ajoutée.
 * La tâche automatique videra la file par lots.
 *
 * @return void
 */
$schedule = static function (): void {
    $marked = Article_Technology::markForReindex();

    Session::addMessageAfterRedirect(
        sprintf(
            __('%d carte(s) placée(s) dans la file de reclassement : l\'action automatique « Reclassement technologique » s\'en charge.', 'veilletool'),
            $marked
        ),
        true,
        INFO
    );
};

/**
 * Reclasse immédiatement toutes les cartes.
 *
 * Réservé au bouton « Reclasser maintenant » : l'utilisateur qui le demande
 * accepte d'attendre.
 *
 * @return void
 */
$reindex = static function (): void {
    $indexed = Article_Technology::reindexAll();

    Session::addMessageAfterRedirect(
        sprintf(
            __('Cartes reclassées : %1$d analysée(s), %2$d rattachement(s).', 'veilletool'),
            $indexed['articles'],
            $indexed['links']
        ),
        true,
        INFO
    );
};

// Taille maximale acceptée pour un référentiel importé (5 Mio).
$max_csv_size = 5242880;

$technology = new Technology();

// --------------------------------------------------------------------------
// Ajout d'une entrée
// --------------------------------------------------------------------------
if (isset($_POST['add'])) {
    Session::checkRight(Technology::$rightname, CREATE);

    if (
        $technology->add([
            'categorie' => (string) ($_POST['categorie'] ?? ''),
            'famille'   => (string) ($_POST['famille'] ?? ''),
            'name'      => (string) ($_POST['name'] ?? ''),
            'is_active' => 1,
        ])
    ) {
        Session::addMessageAfterRedirect(__('Entrée ajoutée au référentiel.', 'veilletool'), true, INFO);
        $schedule();
    }

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Suppression d'une ou plusieurs entrées
// --------------------------------------------------------------------------
if (isset($_POST['purge'])) {
    Session::checkRight(Technology::$rightname, PURGE);

    $ids     = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $deleted = 0;

    foreach ($ids as $id) {
        if ($technology->delete(['id' => $id], true)) {
            $deleted++;
        }
    }

    Session::addMessageAfterRedirect(
        sprintf(
            _n('%d entrée supprimée.', '%d entrées supprimées.', $deleted, 'veilletool'),
            $deleted
        ),
        true,
        INFO
    );

    if ($deleted > 0) {
        $schedule();
    }

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Import d'un référentiel au format CSV
// --------------------------------------------------------------------------
if (isset($_POST['import'])) {
    Session::checkRight(Technology::$rightname, CREATE);

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
    } elseif ((int) $file['size'] > $max_csv_size) {
        Session::addMessageAfterRedirect(
            __('Fichier trop volumineux (5 Mio maximum).', 'veilletool'),
            false,
            ERROR
        );
    } else {
        $replace = (int) ($_POST['replace'] ?? 0) === 1;

        if ($replace) {
            Session::checkRight(Technology::$rightname, PURGE);
        }

        $result = Technology::importCsv((string) file_get_contents($file['tmp_name']), $replace);

        Session::addMessageAfterRedirect(
            sprintf(
                __('Référentiel : %1$d entrée(s) ajoutée(s), %2$d déjà connue(s).', 'veilletool'),
                $result['imported'],
                $result['skipped']
            ),
            true,
            INFO
        );

        foreach ($result['errors'] as $error) {
            Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
        }

        $schedule();
    }

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Reclassement manuel
// --------------------------------------------------------------------------
if (isset($_POST['reindex'])) {
    Session::checkRight(Technology::$rightname, UPDATE);
    $reindex();

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Référentiel des technologies suivies
//
// Le référentiel général dit de quoi parle une carte.
// Celui-ci dit lesquelles de ces qualifications nous intéressent.
// Les deux se règlent depuis le même écran, puisque le second se pose sur
// le premier.
//
// Le suivi porte sur une famille ou sur une technologie précise - jamais
// sur une catégorie, qui recouvrirait des centaines d'entrées sans rapport.
// --------------------------------------------------------------------------
if (isset($_POST['follow_family'])) {
    Session::checkRight(Technology::$rightname, UPDATE);

    $categorie = trim((string) ($_POST['categorie'] ?? ''));
    $famille   = trim((string) ($_POST['famille'] ?? ''));

    if ($categorie !== '' && $famille !== '') {
        $now_followed = FollowedTechnology::toggle($categorie, $famille);

        Session::addMessageAfterRedirect(
            sprintf(
                $now_followed
                    ? __('La famille « %s » est désormais suivie.', 'veilletool')
                    : __('La famille « %s » n\'est plus suivie.', 'veilletool'),
                htmlescape($famille)
            ),
            true,
            INFO
        );
    }

    Navigation::back(Technology::getSearchURL());
}

if (isset($_POST['follow_entry'])) {
    Session::checkRight(Technology::$rightname, UPDATE);

    // Le bouton porte l'identifiant dans sa valeur : une ligne de tableau
    // ne peut pas ouvrir son propre formulaire.
    if ($technology->getFromDB((int) $_POST['follow_entry'])) {
        $now_followed = FollowedTechnology::toggle(
            (string) $technology->fields['categorie'],
            (string) $technology->fields['famille'],
            (string) $technology->fields['name']
        );

        Session::addMessageAfterRedirect(
            sprintf(
                $now_followed
                    ? __('« %s » est désormais suivie.', 'veilletool')
                    : __('« %s » n\'est plus suivie.', 'veilletool'),
                htmlescape((string) $technology->fields['name'])
            ),
            true,
            INFO
        );
    }

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Filtres
//
// Ils sont lus dans $_REQUEST et construits **avant** les traitements : la
// suppression de masse porte exactement sur ce que l'écran affiche, et se
// fait donc sur les mêmes critères.
// --------------------------------------------------------------------------
$filter_categorie = trim((string) ($_REQUEST['categorie'] ?? ''));
$filter_famille   = trim((string) ($_REQUEST['famille'] ?? ''));
$filter_keyword   = trim((string) ($_REQUEST['q'] ?? ''));

// Filtre de suivi : « suivies » retient ce que couvre le référentiel de
// suivi, directement ou par la famille.
$filter_followed = (string) ($_REQUEST['followed'] ?? '');
if (!in_array($filter_followed, [Technology::FOLLOW_ANY, Technology::FOLLOW_YES, Technology::FOLLOW_NO], true)) {
    $filter_followed = Technology::FOLLOW_ANY;
}

$has_filter = $filter_categorie !== ''
    || $filter_famille !== ''
    || $filter_keyword !== ''
    || $filter_followed !== Technology::FOLLOW_ANY;

$where = [];
if ($filter_categorie !== '') {
    $where['categorie'] = $filter_categorie;
}
if ($filter_famille !== '') {
    $where['famille'] = $filter_famille;
}
if ($filter_keyword !== '') {
    $like    = '%' . $filter_keyword . '%';
    $where[] = [
        'OR' => [
            ['name' => ['LIKE', $like]],
            ['famille' => ['LIKE', $like]],
            ['categorie' => ['LIKE', $like]],
        ],
    ];
}

// Les conditions de suivi portent sur le triplet catégorie / famille /
// sous-catégorie : elles s'appliquent donc telles quelles au référentiel,
// comme elles s'appliquent à la table de classification.
if ($filter_followed !== Technology::FOLLOW_ANY) {
    $followed_conditions = FollowedTechnology::buildLinkConditions();

    if ($followed_conditions === []) {
        // Rien n'est suivi : « suivies » ne ramène rien, « non suivies »
        // ramène tout.
        if ($filter_followed === Technology::FOLLOW_YES) {
            $where[] = new QueryExpression('0');
        }
    } elseif ($filter_followed === Technology::FOLLOW_YES) {
        $where[] = ['OR' => $followed_conditions];
    } else {
        $where[] = ['NOT' => ['OR' => $followed_conditions]];
    }
}

// --------------------------------------------------------------------------
// Suppression de masse du référentiel
// --------------------------------------------------------------------------
if (isset($_POST['count_entries']) || isset($_POST['purge_filtered']) || isset($_POST['purge_all'])) {
    Session::checkRight(Technology::$rightname, PURGE);

    // « Tout supprimer » ignore délibérément les filtres en cours : c'est
    // une action distincte, pas un raccourci de la précédente.
    $scope = isset($_POST['purge_all']) ? [] : $where;

    if (isset($_POST['count_entries'])) {
        $count = Technology::countForPurge($scope);

        Session::addMessageAfterRedirect(
            $count === 0
                ? __('Aucune entrée ne correspond à ces critères.', 'veilletool')
                : sprintf(
                    _n(
                        '%d entrée serait supprimée du référentiel.',
                        '%d entrées seraient supprimées du référentiel.',
                        $count,
                        'veilletool'
                    ),
                    $count
                ),
            true,
            $count === 0 ? INFO : WARNING
        );
    } else {
        $done = Technology::purgeByCriteria($scope);

        if ($done['entries'] === 0) {
            Session::addMessageAfterRedirect(
                __('Aucune entrée ne correspond à ces critères : rien n\'a été supprimé.', 'veilletool'),
                false,
                ERROR
            );
        } else {
            Session::addMessageAfterRedirect(
                sprintf(
                    __(
                        '%1$d entrée(s) supprimée(s) du référentiel, %2$d suivi(s) et %3$d rattachement(s) manuel(s) devenus sans objet retirés.',
                        'veilletool'
                    ),
                    $done['entries'],
                    $done['follows'],
                    $done['links']
                ),
                true,
                INFO
            );

            Session::addMessageAfterRedirect(
                sprintf(
                    __(
                        '%d carte(s) placée(s) dans la file de reclassement : l\'action automatique « Reclassement technologique » s\'en charge.',
                        'veilletool'
                    ),
                    $done['pending']
                ),
                true,
                INFO
            );
        }
    }

    Navigation::back(Technology::getSearchURL());
}

// --------------------------------------------------------------------------
// Pagination
// --------------------------------------------------------------------------

$limit = max(20, (int) ($_SESSION['glpilist_limit'] ?? 50));
$start = max(0, (int) ($_GET['start'] ?? 0));

$total_row = $DB->request([
    'COUNT' => 'cpt',
    'FROM'  => Technology::getTable(),
    'WHERE' => $where,
])->current();
$total = $total_row !== null ? (int) $total_row['cpt'] : 0;

if ($start >= $total) {
    $start = max(0, ((int) ceil(max(1, $total) / $limit) - 1) * $limit);
}

$rows     = [];
$iterator = $DB->request([
    'FROM'  => Technology::getTable(),
    'WHERE' => $where,
    'ORDER' => ['categorie ASC', 'famille ASC', 'name ASC'],
    'START' => $start,
    'LIMIT' => $limit,
]);
foreach ($iterator as $row) {
    $row['is_followed']     = FollowedTechnology::isEntryFollowed(
        (string) $row['categorie'],
        (string) $row['famille'],
        (string) $row['name']
    );
    // Une entrée déjà couverte par le suivi de sa famille n'a pas à être
    // suivie séparément : l'écran le dit plutôt que de proposer un bouton
    // qui n'ajouterait rien.
    $row['family_followed'] = FollowedTechnology::isFamilyFollowed(
        (string) $row['categorie'],
        (string) $row['famille']
    );

    $rows[] = $row;
}

// Panneau de suivi par famille : il suit les mêmes filtres que le tableau,
// pour qu'une recherche ramène les familles comme les entrées.
$families = [];
foreach (Technology::getFamiliesWithVolume($filter_categorie, $filter_keyword) as $family) {
    $family['is_followed'] = FollowedTechnology::isFamilyFollowed(
        $family['categorie'],
        $family['famille']
    );

    // Le filtre de suivi vaut aussi pour le panneau : filtrer sur « suivies
    // » et continuer d'afficher les quatre-vingt-treize familles serait
    // contradictoire.
    // Le tri se fait en mémoire - le référentiel en compte moins d'une
    // centaine.
    if (
        ($filter_followed === Technology::FOLLOW_YES && !$family['is_followed'])
        || ($filter_followed === Technology::FOLLOW_NO && $family['is_followed'])
    ) {
        continue;
    }

    $families[] = $family;
}

Html::header(
    Technology::getTypeName(Session::getPluralNumber()),
    '',
    Menu::SECTOR,
    Menu::ITEM_TECHNOLOGIES
);

TemplateRenderer::getInstance()->display('@veilletool/technologies.html.twig', [
    'rows'          => $rows,
    'categories'    => Technology::getCategories(),
    'familles'      => Technology::getFamilies($filter_categorie),
    'filters'       => [
        'categorie' => $filter_categorie,
        'famille'   => $filter_famille,
        'keyword'   => $filter_keyword,
        'followed'  => $filter_followed,
    ],
    'follow_modes'  => Technology::getFollowFilterModes(),
    'has_filter'    => $has_filter,
    'total'         => $total,
    'start'         => $start,
    'limit'         => $limit,
    'total_all'     => countElementsInTable(Technology::getTable()),
    'can_create'    => Session::haveRight(Technology::$rightname, CREATE),
    'can_update'    => Session::haveRight(Technology::$rightname, UPDATE),
    'classified'    => countElementsInTable(Article_Technology::getTable()),
    'can_purge'     => Session::haveRight(Technology::$rightname, PURGE),
    'families'      => $families,
    'followed'      => FollowedTechnology::summarize(),
    'pending'       => Article_Technology::countPending(),
    'cron'          => CronStatus::describe(Article_Technology::class, Article_Technology::CRON_TASK),
    'can_config'    => Session::haveRight('config', READ),
]);

Html::footer();
