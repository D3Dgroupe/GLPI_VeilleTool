<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Écran « Sauvegarde » : export et import de la configuration du plugin,
 * bloc par bloc, au format CSV.
 *
 * Chaque bloc produit exactement le fichier que son import sait relire :
 * une sauvegarde se rejoue telle quelle, sur la même instance comme sur une
 * autre.
 * Rien n'y est propre à une installation - ni identifiant interne, ni
 * version de schéma - de sorte qu'un export reste lisible et modifiable au
 * tableur.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Feed\SourceCatalog;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\ScorePreset;
use GlpiPlugin\Veilletool\Settings;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Taxonomy\CategoryCsv;
use GlpiPlugin\Veilletool\Technology;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Source::$rightname, READ);

/** Taille maximale acceptée pour un fichier importé (5 Mio) */
$max_size = 5242880;

/**
 * Blocs de configuration proposés à l'export et à l'import.
 *
 * `right` est le droit exigé pour **importer**.
 * L'export se contente de la lecture, puisqu'il ne fait que relire ce que
 * l'écran affiche déjà.
 */
$blocks = [
    'general' => [
        'label'  => __('Général', 'veilletool'),
        'icon'   => 'ti ti-settings',
        'file'   => 'veille-reglages.csv',
        'right'  => UPDATE,
        'header' => Settings::CSV_HEADER,
        'hint'   => __(
            'L\'ensemble des réglages du plugin : barème du score, seuils et couleurs de priorité, note par défaut et tri de l\'observatoire. C\'est la sauvegarde complète des options.',
            'veilletool'
        ),
    ],
    'scoring' => [
        'label'  => __('Score d\'intérêt', 'veilletool'),
        'icon'   => 'ti ti-adjustments-star',
        'file'   => 'veille-score.csv',
        'right'  => UPDATE,
        'header' => ScorePreset::CSV_HEADER,
        'hint'   => __(
            'Le barème en vigueur **et tous les préréglages enregistrés**, un bloc chacun. À l\'import, le plugin demande lequel appliquer - ou les enregistre tous comme préréglages sans toucher au barème en vigueur.',
            'veilletool'
        ),
    ],
    'sources' => [
        'label'  => __('Flux RSS / ATOM', 'veilletool'),
        'icon'   => 'ti ti-rss',
        'file'   => 'veille-flux.csv',
        'right'  => CREATE,
        'header' => SourceCatalog::CSV_HEADER,
        'hint'   => __(
            'Tous les flux, avec leurs thèmes, leur note d\'intérêt, leur état d\'activité et leur description. À l\'import, un flux déjà connu - comparaison sur l\'adresse - est ignoré plutôt que dupliqué.',
            'veilletool'
        ),
    ],
    'categories' => [
        'label'  => __('Thèmes de veille', 'veilletool'),
        'icon'   => 'ti ti-tags',
        'file'   => 'veille-themes.csv',
        'right'  => CREATE,
        'header' => CategoryCsv::CSV_HEADER,
        'hint'   => __(
            'Les thèmes, dans leur ordre d\'affichage, avec leurs couleurs, leur icône et leur note d\'intérêt. À l\'import, le nom fait foi : un thème déjà présent est mis à jour, et son identifiant technique ne change pas.',
            'veilletool'
        ),
    ],
    'technologies' => [
        'label'  => __('Référentiel technologique', 'veilletool'),
        'icon'   => 'ti ti-binary-tree',
        'file'   => 'veille-technologies.csv',
        'right'  => CREATE,
        'header' => Technology::CSV_HEADER,
        'hint'   => __(
            'Le triplet Catégorie / Famille / Sous-catégorie qui sert à rattacher les cartes. À l\'import, les entrées déjà connues sont ignorées. Toutes les cartes repassent ensuite dans la file de reclassement.',
            'veilletool'
        ),
    ],
];

$block = (string) ($_POST['block'] ?? '');

if ($block !== '' && !array_key_exists($block, $blocks)) {
    $block = '';
}

// --------------------------------------------------------------------------
// Export
// --------------------------------------------------------------------------
if (isset($_POST['export']) && $block !== '') {
    $content = match ($block) {
        'scoring'      => ScorePreset::toCsv(),
        'sources'      => SourceCatalog::toCsv(),
        'categories'   => CategoryCsv::toCsv(),
        'technologies' => Technology::toCsv(),
        default        => Settings::toCsv(),
    };

    return new Response(
        $content,
        200,
        [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $blocks[$block]['file'] . '"',
        ]
    );
}

// --------------------------------------------------------------------------
// Barème : application du bloc choisi
//
// Un fichier de barème peut en porter plusieurs.
// Le premier temps a listé ce qu'il contient.
// Celui-ci applique ce que l'utilisateur a retenu.
// Le contenu revient par le formulaire : il est court, et cela évite de le
// déposer quelque part entre les deux requêtes.
// --------------------------------------------------------------------------
if (isset($_POST['scoring_choice'])) {
    Session::checkRight(Source::$rightname, UPDATE);

    $parsed = ScorePreset::parseCsv((string) ($_POST['scoring_content'] ?? ''));
    $blocks = $parsed['blocks'];
    $choice = (string) $_POST['scoring_choice'];

    foreach ($parsed['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
    }

    if ($choice === '__all__') {
        // Tout ranger comme préréglages, sans rien appliquer : le barème en
        // vigueur reste celui qu'on avait.
        $stored  = 0;
        $refused = 0;

        foreach ($blocks as $name => $values) {
            if (trim((string) $name) === '') {
                continue;
            }

            ScorePreset::store((string) $name, $values) ? $stored++ : $refused++;
        }

        Session::addMessageAfterRedirect(
            sprintf(
                __('%d préréglage(s) enregistré(s). Le barème en vigueur n\'a pas changé.', 'veilletool'),
                $stored
            ),
            true,
            INFO
        );

        if ($refused > 0) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('%d préréglage(s) refusé(s) : la limite de %d est atteinte.', 'veilletool'),
                    $refused,
                    ScorePreset::MAX_PRESETS
                ),
                false,
                WARNING
            );
        }
    } elseif (array_key_exists($choice, $blocks)) {
        Settings::importCsvValues($blocks[$choice], Settings::getScoringKeys());

        Session::addMessageAfterRedirect(
            sprintf(
                __('Barème « %s » appliqué.', 'veilletool'),
                htmlescape(ScorePreset::blockLabel($choice))
            ),
            true,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(
            __('Ce barème ne figure pas dans le fichier.', 'veilletool'),
            false,
            ERROR
        );
    }

    Navigation::back(Navigation::page('backup.php'));
}

// --------------------------------------------------------------------------
// Import
// --------------------------------------------------------------------------
if (isset($_POST['import']) && $block !== '') {
    Session::checkRight(Source::$rightname, $blocks[$block]['right']);

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

        Navigation::back(Navigation::page('backup.php'));
    }

    if ((int) $file['size'] > $max_size) {
        Session::addMessageAfterRedirect(
            __('Fichier trop volumineux (5 Mio maximum).', 'veilletool'),
            false,
            ERROR
        );

        Navigation::back(Navigation::page('backup.php'));
    }

    $content         = (string) file_get_contents($file['tmp_name']);
    $errors          = [];
    $pending_scoring = null;

    switch ($block) {
        case 'scoring':
            // Un fichier de barème peut porter le réglage en vigueur et
            // plusieurs préréglages : il faut savoir lequel appliquer avant
            // de toucher à quoi que ce soit.
            $parsed = ScorePreset::parseCsv($content);

            foreach ($parsed['errors'] as $error) {
                Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
            }

            if ($parsed['blocks'] === []) {
                Navigation::back(Navigation::page('backup.php'));
            }

            // Un seul bloc sans titre : c'est l'ancien format, il n'y a
            // rien à choisir.
            if (array_keys($parsed['blocks']) === [ScorePreset::CSV_CURRENT]) {
                Settings::importCsvValues(
                    $parsed['blocks'][ScorePreset::CSV_CURRENT],
                    Settings::getScoringKeys()
                );

                Session::addMessageAfterRedirect(
                    __('Barème appliqué.', 'veilletool'),
                    true,
                    INFO
                );

                Navigation::back(Navigation::page('backup.php'));
            }

            $pending_scoring = [
                'content' => $content,
                'blocks'  => $parsed['blocks'],
            ];
            break;

        case 'general':
            $done   = Settings::importCsv($content);
            $errors = $done['errors'];

            Session::addMessageAfterRedirect(
                sprintf(
                    __('%1$d réglage(s) appliqué(s), %2$d ignoré(s).', 'veilletool'),
                    $done['applied'],
                    $done['skipped']
                ),
                true,
                INFO
            );
            break;

        case 'sources':
            $done   = SourceCatalog::importCsv($content);
            $errors = $done['errors'];

            Session::addMessageAfterRedirect(
                sprintf(
                    __('%1$d flux ajouté(s). %2$d étaient déjà connus.', 'veilletool'),
                    $done['imported'],
                    $done['skipped']
                ),
                true,
                INFO
            );
            break;

        case 'categories':
            $done   = CategoryCsv::importCsv($content);
            $errors = $done['errors'];

            Session::addMessageAfterRedirect(
                sprintf(
                    __('%1$d thème(s) ajouté(s), %2$d mis à jour.', 'veilletool'),
                    $done['imported'],
                    $done['updated']
                ),
                true,
                INFO
            );
            break;

        default:
            $done   = Technology::importCsv($content);
            $errors = $done['errors'];

            Session::addMessageAfterRedirect(
                sprintf(
                    __('Référentiel : %1$d entrée(s) ajoutée(s), %2$d déjà connue(s).', 'veilletool'),
                    $done['imported'],
                    $done['skipped']
                ),
                true,
                INFO
            );

            // Le référentiel a changé : la classification existante est
            // périmée, la tâche automatique la refera par lots.
            Article_Technology::markForReindex();
            break;
    }

    foreach ($errors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
    }

    // Le barème attend un choix : l'écran se réaffiche avec la question,
    // plutôt que de rediriger et de perdre le fichier.
    if ($pending_scoring === null) {
        Navigation::back(Navigation::page('backup.php'));
    }
}

// --------------------------------------------------------------------------
// Affichage
// --------------------------------------------------------------------------
Html::header(
    __('Sauvegarde', 'veilletool'),
    '',
    Menu::SECTOR,
    Menu::ITEM_BACKUP
);

// Volume de chaque bloc, pour que l'écran dise ce qu'un export emporterait.
$volumes = [
    'general'      => count(Settings::getExportableKeys()),
    // Le barème en vigueur, plus un bloc par préréglage.
    'scoring'      => count(Settings::getScoringKeys()) * (1 + ScorePreset::countAll()),
    'sources'      => countElementsInTable(Source::getTable()),
    'categories'   => countElementsInTable(Category::getTable()),
    'technologies' => countElementsInTable(Technology::getTable()),
];

TemplateRenderer::getInstance()->display('@veilletool/backup.html.twig', [
    'blocks'     => $blocks,
    'volumes'    => $volumes,
    'pending_scoring' => $pending_scoring ?? null,
    'preset_labels'   => array_map(
        static fn(string $name): string => ScorePreset::blockLabel($name),
        array_combine(
            array_keys($pending_scoring['blocks'] ?? []),
            array_keys($pending_scoring['blocks'] ?? [])
        )
    ),
    'can_update' => Session::haveRight(Source::$rightname, UPDATE),
    'can_create' => Session::haveRight(Source::$rightname, CREATE),
]);

Html::footer();
