<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonGLPI;
use Session;

/**
 * Porte-entrée du secteur de menu « Veille ».
 *
 * Cette classe n'a pas de table : elle sert uniquement à déclarer le
 * nouveau secteur de premier niveau et ses sous-onglets, au même titre que
 * « Parc », « Assistance », « Gestion », « Outils », « Administration » et
 * « Configuration ».
 */
class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_veilletool_veille';

    /** Clé du secteur de menu de premier niveau */
    public const SECTOR = 'veille';

    /** Clés des sous-onglets du secteur */
    public const ITEM_ARTICLES   = 'veille_articles';
    public const ITEM_FAVORITES  = 'veille_favorites';
    public const ITEM_SOURCES    = 'veille_sources';
    public const ITEM_CATEGORIES   = 'veille_categories';
    public const ITEM_TECHNOLOGIES = 'veille_technologies';
    public const ITEM_SCORING      = 'veille_scoring';
    public const ITEM_BACKUP       = 'veille_backup';

    public static function getTypeName($nb = 0)
    {
        return __('Veille', 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-radar';
    }

    /**
     * Construction du secteur « Veille » et de ses sous-onglets.
     *
     * La clé `title` du premier niveau est indispensable : sans elle,
     * Html::generateMenuSession() ne sait pas nommer un secteur créé par un
     * plugin.
     * La clé `is_multi_entries` permet de déclarer plusieurs sous-onglets
     * pour une seule classe.
     *
     * @return array|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        $menu = [
            'title'            => self::getTypeName(),
            'is_multi_entries' => true,
        ];

        $menu[self::ITEM_ARTICLES] = [
            'title' => __('Observatoire', 'veilletool'),
            'page'  => '/plugins/veilletool/front/article.php',
            'icon'  => Article::getIcon(),
            'links' => [
                'search' => '/plugins/veilletool/front/article.php',
            ],
        ];

        if (Session::haveRight(self::$rightname, UPDATE)) {
            $menu[self::ITEM_ARTICLES]['links']['add'] =
                '/plugins/veilletool/front/article.form.php';
        }

        $menu[self::ITEM_FAVORITES] = [
            'title' => __('Favoris', 'veilletool'),
            'page'  => '/plugins/veilletool/front/article.php?favorites=1',
            'icon'  => 'ti ti-star',
        ];

        if (Source::canView()) {
            $menu[self::ITEM_SOURCES] = [
                'title' => __('Flux RSS / ATOM', 'veilletool'),
                'page'  => '/plugins/veilletool/front/source.php',
                'icon'  => Source::getIcon(),
                'links' => [
                    'search' => '/plugins/veilletool/front/source.php',
                ],
            ];

            if (Source::canCreate()) {
                $menu[self::ITEM_SOURCES]['links']['add'] =
                    '/plugins/veilletool/front/source.form.php';
            }

            $menu[self::ITEM_CATEGORIES] = [
                'title' => __('Catégories', 'veilletool'),
                'page'  => '/plugins/veilletool/front/category.php',
                'icon'  => Category::getIcon(),
                'links' => [
                    'search' => '/plugins/veilletool/front/category.php',
                ],
            ];

            if (Category::canCreate()) {
                $menu[self::ITEM_CATEGORIES]['links']['add'] =
                    '/plugins/veilletool/front/category.form.php';
            }

            $menu[self::ITEM_TECHNOLOGIES] = [
                'title' => __('Technologies', 'veilletool'),
                'page'  => '/plugins/veilletool/front/technology.php',
                'icon'  => Technology::getIcon(),
                'links' => [
                    'search' => '/plugins/veilletool/front/technology.php',
                ],
            ];

            // Réglages du score d'intérêt : poids des composantes et seuils
            // de priorité.
            // Ils pèsent sur le classement de toutes les cartes, d'où une
            // entrée qui leur est propre.
            $menu[self::ITEM_SCORING] = [
                'title' => __('Score d\'intérêt', 'veilletool'),
                'page'  => '/plugins/veilletool/front/scoring.php',
                'icon'  => 'ti ti-adjustments-star',
            ];

            // Export et import de la configuration.
            // Sa place est en dernier : on y vient une fois tout le reste
            // réglé, pour l'emporter.
            $menu[self::ITEM_BACKUP] = [
                'title' => __('Sauvegarde', 'veilletool'),
                'page'  => '/plugins/veilletool/front/backup.php',
                'icon'  => 'ti ti-archive',
            ];
        }

        return $menu;
    }
}
