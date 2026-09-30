<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Écran « Résumer par IA » : sélection d'un lot de cartes par thèmes, puis
 * redirection vers ChatGPT avec une demande de synthèse, de risques et de
 * correctifs portant sur l'ensemble du lot.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Veilletool\AiPrompt;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Cards\ArticleQuery;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\Source;
use Glpi\Kernel\Kernel;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

/** @var DBmysql $DB */
global $DB;

Session::checkRight(Article::$rightname, READ);

// Borne de sécurité : au-delà, la sélection n'a plus de sens pour une
// synthèse et la page deviendrait lourde à afficher.
$max_candidates = 200;

$users_id = (int) Session::getLoginUserID();

// Les critères sont lus dans $_REQUEST : l'écran est en lecture seule, et
// cela permet d'arriver depuis l'observatoire avec le thème déjà coché.
$selection = [
    'categories_ids' => array_values(array_filter(array_map('intval', (array) ($_REQUEST['categories'] ?? [])))),
    'sources_ids'    => array_values(array_filter(array_map('intval', (array) ($_REQUEST['sources'] ?? [])))),
    'date_from'      => ArticleQuery::normalizeDate((string) ($_REQUEST['from'] ?? '')),
    'date_to'        => ArticleQuery::normalizeDate((string) ($_REQUEST['to'] ?? '')),
    'favorites_only' => (int) ($_REQUEST['favorites'] ?? 0) === 1,
    'unread_only'    => (int) ($_REQUEST['unread'] ?? 0) === 1,
];

// --------------------------------------------------------------------------
// Cartes candidates au regard des thèmes et des autres critères
// --------------------------------------------------------------------------
$filters = [
    'categories_ids' => $selection['categories_ids'],
    'date_from'      => $selection['date_from'],
    'date_to'        => $selection['date_to'],
    'favorites_only' => $selection['favorites_only'],
    'unread_only'    => $selection['unread_only'],
    // Le lot candidat est borné : il est pris dans l'ordre chronologique,
    // et non par score d'intérêt.
    // Une synthèse porte sur une période, pas sur une sélection éditoriale
    // - les cartes les moins bien notées de la période y ont leur place, et
    // l'utilisateur écarte ensuite ce qu'il veut, carte par carte.
    'sort'           => Article::SORT_DATE,
    'users_id'       => $users_id,
];

// getList() ne filtre que sur une source.
// Au-delà d'une, le tri est fait après coup sur le lot déjà borné.
if (count($selection['sources_ids']) === 1) {
    $filters['sources_id'] = $selection['sources_ids'][0];
}

$candidates = ArticleQuery::getList($filters, 0, $max_candidates);

if (count($selection['sources_ids']) > 1) {
    $candidates = array_values(array_filter(
        $candidates,
        static fn(array $card): bool => in_array(
            (int) $card['plugin_veilletool_sources_id'],
            $selection['sources_ids'],
            true
        )
    ));
}

$total_matching = ArticleQuery::countList($filters);

// --------------------------------------------------------------------------
// Cartes effectivement retenues pour la requête
// --------------------------------------------------------------------------
$has_run = isset($_POST['refresh']) || isset($_POST['rebuild']);

if (isset($_POST['rebuild'])) {
    // L'utilisateur a décoché certaines cartes dans la liste.
    $kept = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
} else {
    // Premier affichage ou nouvelle sélection : tout est retenu.
    $kept = array_map(static fn(array $card): int => (int) $card['id'], $candidates);
}

$kept_index = array_flip($kept);
$selected   = array_values(array_filter(
    $candidates,
    static fn(array $card): bool => isset($kept_index[(int) $card['id']])
));

$level = AiPrompt::normalizeLevel((string) ($_REQUEST['level'] ?? AiPrompt::LEVEL_SUMMARY));

// --------------------------------------------------------------------------
// Téléchargement du document Markdown
//
// Contrairement à la redirection, ce fichier n'est pas borné par la
// longueur d'une adresse : toutes les cartes retenues y figurent, avec un
// contexte nettement plus large.
// --------------------------------------------------------------------------
if (isset($_POST['download'])) {
    if ($selected === []) {
        Session::addMessageAfterRedirect(
            __('Aucune carte retenue : il n\'y a rien à exporter.', 'veilletool'),
            false,
            ERROR
        );

        Navigation::back(Navigation::page('summary.php'));
    }

    return new Response(
        AiPrompt::toMarkdown($selected, $level),
        200,
        [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . AiPrompt::getMarkdownFilename() . '"',
        ]
    );
}

// Les quatre variantes sont calculées d'avance : l'interface bascule de
// l'une à l'autre sans recharger la page.
$requests = [];
$prompts  = [];
foreach (array_keys(AiPrompt::getLevels()) as $candidate) {
    $requests[$candidate] = AiPrompt::forCards($selected, $candidate);
    $prompts[$candidate]  = $requests[$candidate]['prompt'];
}
$request = $requests[$level];

Html::header(
    __('Résumer par IA', 'veilletool'),
    '',
    Menu::SECTOR,
    Menu::ITEM_ARTICLES
);

$sources  = [];
$iterator = $DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => Source::getTable(),
    'ORDER'  => ['name ASC'],
]);
foreach ($iterator as $row) {
    $sources[(int) $row['id']] = (string) $row['name'];
}

TemplateRenderer::getInstance()->display('@veilletool/summary.html.twig', [
    'ai_levels'      => AiPrompt::getLevels(),
    'level'          => $level,
    'requests'       => $requests,
    'ai_prompts'     => $prompts,
    'categories'     => Category::getActiveCategories(),
    'sources'        => $sources,
    'selection'      => $selection,
    'candidates'     => $candidates,
    'kept'           => $kept,
    'request'        => $request,
    'has_run'        => $has_run,
    'total_matching' => $total_matching,
    'max_candidates' => $max_candidates,
]);

Html::footer();
