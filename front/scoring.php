<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Onglet « Score d'intérêt » : poids des quatre composantes du score,
 * horizon de fraîcheur et seuils des niveaux de priorité.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Cards\ArticleQuery;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\ScorePreset;
use GlpiPlugin\Veilletool\Settings;
use GlpiPlugin\Veilletool\Source;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Source::$rightname, READ);

// --------------------------------------------------------------------------
// Préréglages du barème
//
// Le barème n'a pas une bonne réponse mais plusieurs, selon ce qu'on
// cherche.
// Un préréglage en fige une sous un titre.
// Il ne s'applique que lorsqu'on le demande.
// --------------------------------------------------------------------------
if (isset($_POST['preset_apply'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $applied = ScorePreset::apply((int) $_POST['preset_apply']);

    Session::addMessageAfterRedirect(
        $applied !== ''
            ? sprintf(__('Barème « %s » appliqué.', 'veilletool'), htmlescape($applied))
            : __('Ce préréglage est introuvable.', 'veilletool'),
        true,
        $applied !== '' ? INFO : ERROR
    );

    Navigation::back(Navigation::page('scoring.php'));
}

if (isset($_POST['preset_save'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $name = trim((string) ($_POST['preset_name'] ?? ''));

    if ($name === '') {
        Session::addMessageAfterRedirect(
            __('Donnez un titre au préréglage : c\'est par lui qu\'on le retrouvera.', 'veilletool'),
            false,
            ERROR
        );
    } elseif (ScorePreset::findByName($name) > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('Un préréglage s\'appelle déjà « %s ». Employez « Remplacer » sur sa ligne, ou choisissez un autre titre.', 'veilletool'),
                htmlescape($name)
            ),
            false,
            ERROR
        );
    } elseif (ScorePreset::captureCurrent($name, (string) ($_POST['preset_comment'] ?? '')) > 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('Barème courant enregistré sous « %s ».', 'veilletool'), htmlescape($name)),
            true,
            INFO
        );
    }

    Navigation::back(Navigation::page('scoring.php'));
}

if (isset($_POST['preset_capture'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    Session::addMessageAfterRedirect(
        ScorePreset::captureInto((int) $_POST['preset_capture'])
            ? __('Préréglage mis à jour avec le barème courant.', 'veilletool')
            : __('Ce préréglage est introuvable.', 'veilletool'),
        true,
        INFO
    );

    Navigation::back(Navigation::page('scoring.php'));
}

if (isset($_POST['preset_purge'])) {
    Session::checkRight(Source::$rightname, PURGE);

    $preset = new ScorePreset();

    if ($preset->getFromDB((int) $_POST['preset_purge'])) {
        $preset->delete(['id' => (int) $_POST['preset_purge']], true);

        Session::addMessageAfterRedirect(
            __('Préréglage supprimé. Le barème en vigueur n\'a pas changé.', 'veilletool'),
            true,
            INFO
        );
    }

    Navigation::back(Navigation::page('scoring.php'));
}

// --------------------------------------------------------------------------
// Enregistrement des réglages
// --------------------------------------------------------------------------
if (isset($_POST['update'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $before = Settings::getInt('default_interest');

    Settings::set([
        'weight_source'      => (int) ($_POST['weight_source'] ?? 0),
        'weight_technology'  => (int) ($_POST['weight_technology'] ?? 0),
        'weight_category'    => (int) ($_POST['weight_category'] ?? 0),
        'weight_freshness'   => (int) ($_POST['weight_freshness'] ?? 0),
        'freshness_grace_days'   => (int) ($_POST['freshness_grace_days'] ?? 7),
        'freshness_loss_per_day' => (int) ($_POST['freshness_loss_per_day'] ?? 5),
        'priority_high'      => (int) ($_POST['priority_high'] ?? 80),
        'priority_important' => (int) ($_POST['priority_important'] ?? 60),
        'priority_toread'    => (int) ($_POST['priority_toread'] ?? 50),
        'priority_info'      => (int) ($_POST['priority_info'] ?? 20),
        'default_interest'   => (int) ($_POST['default_interest'] ?? 50),
        'default_sort'       => (string) ($_POST['default_sort'] ?? Article::SORT_SCORE),
        // Les couleurs sont contrôlées par Settings : une valeur
        // inexploitable retombe sur celle livrée.
        // Fond et texte sont libres, y compris dans des combinaisons peu
        // lisibles : l'écran le signale, il ne l'interdit pas.
        'color_high'         => (string) ($_POST['color_high'] ?? ''),
        'color_important'    => (string) ($_POST['color_important'] ?? ''),
        'color_toread'       => (string) ($_POST['color_toread'] ?? ''),
        'color_info'         => (string) ($_POST['color_info'] ?? ''),
        'color_low'          => (string) ($_POST['color_low'] ?? ''),
        'text_high'          => (string) ($_POST['text_high'] ?? ''),
        'text_important'     => (string) ($_POST['text_important'] ?? ''),
        'text_toread'        => (string) ($_POST['text_toread'] ?? ''),
        'text_info'          => (string) ($_POST['text_info'] ?? ''),
        'text_low'           => (string) ($_POST['text_low'] ?? ''),
        // Libellé vide = celui livré, qui suit la langue de l'utilisateur.
        'label_high'         => (string) ($_POST['label_high'] ?? ''),
        'label_important'    => (string) ($_POST['label_important'] ?? ''),
        'label_toread'       => (string) ($_POST['label_toread'] ?? ''),
        'label_info'         => (string) ($_POST['label_info'] ?? ''),
        'label_low'          => (string) ($_POST['label_low'] ?? ''),
    ]);

    Session::addMessageAfterRedirect(__('Réglages du score enregistrés.', 'veilletool'), true, INFO);

    // Les poids et les seuils sont appliqués à la volée : ils ne touchent
    // pas aux composantes stockées.
    // La note attribuée aux flux et aux thèmes qui n'en portent pas, si :
    // les cartes concernées repassent dans la file de reclassement.
    if ($before !== Settings::getInt('default_interest')) {
        $marked = Article_Technology::markForReindex();

        Session::addMessageAfterRedirect(
            sprintf(
                __('%d carte(s) replacée(s) dans la file de reclassement.', 'veilletool'),
                $marked
            ),
            true,
            INFO
        );
    }

    Navigation::back(Navigation::page('scoring.php'));
}

// --------------------------------------------------------------------------
// Rétablissement des valeurs livrées
// --------------------------------------------------------------------------
if (isset($_POST['reset'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $defaults = Settings::DEFAULTS;
    // La version du schéma n'est pas un réglage : elle ne se rétablit pas.
    unset($defaults['schema_version']);

    Settings::set($defaults);

    Session::addMessageAfterRedirect(__('Réglages rétablis aux valeurs livrées.', 'veilletool'), true, INFO);

    Navigation::back(Navigation::page('scoring.php'));
}

// --------------------------------------------------------------------------
// Affichage
// --------------------------------------------------------------------------
$weights = Score::getWeights();
$total   = array_sum($weights);

// Part effective de chaque composante dans la note finale : c'est elle qui
// parle à l'utilisateur, pas le poids brut.
$shares = [];
foreach ($weights as $name => $weight) {
    $shares[$name] = $total > 0 ? round($weight * 100 / $total, 1) : 0.0;
}

Html::header(
    __('Score d\'intérêt', 'veilletool'),
    '',
    Menu::SECTOR,
    Menu::ITEM_SCORING
);

TemplateRenderer::getInstance()->display('@veilletool/scoring.html.twig', [
    'settings'   => Settings::getAll(),
    'weights'    => $weights,
    'shares'     => $shares,
    'priorities' => Score::getPriorities(),
    'thresholds' => Score::getThresholds(),
    'sort_modes' => ArticleQuery::getSortModes(),
    'zero_day'   => Score::freshnessZeroDay(),
    'pending'    => Article_Technology::countPending(),
    'presets'        => ScorePreset::getAll(),
    'current_preset' => ScorePreset::findCurrent(),
    'preset_max'     => ScorePreset::MAX_PRESETS,
    'can_update' => Session::haveRight(Source::$rightname, UPDATE),
    'can_purge'  => Session::haveRight(Source::$rightname, PURGE),
]);

Html::footer();
