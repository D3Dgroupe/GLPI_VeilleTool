<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 *
 * Centralise la veille technologique : flux RSS / Atom, cartes
 * d'information filtrables par catégorie, marquage lu/non lu par
 * utilisateur, favoris partagés et commentaires collaboratifs.
 *
 * @copyright 2026 Kevin D3D
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\FilterPreset;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Profile as VeilleProfile;
use GlpiPlugin\Veilletool\ScorePreset;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Technology;

define('PLUGIN_VEILLETOOL_VERSION', '1.10.0');

/** Version minimale de GLPI supportée (incluse) */
define('PLUGIN_VEILLETOOL_MIN_GLPI', '11.0.0');
/** Version maximale de GLPI supportée (exclue) */
define('PLUGIN_VEILLETOOL_MAX_GLPI', '12.0.0');

/**
 * Initialisation du plugin : déclaration des hooks et du menu.
 *
 * @return void
 */
function plugin_init_veilletool()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['veilletool'] = true;

    // Feuille de style et script des cartes de veille.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['veilletool']        = 'css/veilletool.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['veilletool'] = 'js/veilletool.js';

    // Page de configuration accessible depuis Configuration > Plugins.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['veilletool'] = 'front/source.php';

    if (!Session::getLoginUserID()) {
        return;
    }

    Plugin::registerClass(Category::class);
    Plugin::registerClass(Source::class);
    Plugin::registerClass(Article::class);
    Plugin::registerClass(Technology::class);
    Plugin::registerClass(ScorePreset::class);
    Plugin::registerClass(FilterPreset::class);

    // Porte la tâche automatique de reclassement : la configuration des
    // actions automatiques a besoin de la classe pour la nommer et en
    // afficher la description.
    Plugin::registerClass(Article_Technology::class);
    Plugin::registerClass(FollowedTechnology::class);

    // Onglet « Veille » dans le formulaire des profils, pour régler les
    // droits.
    Plugin::registerClass(VeilleProfile::class, ['addtabon' => 'Profile']);

    // Nouveau secteur de premier niveau « Veille », au même titre que Parc
    // / Assistance / Gestion / Outils / Administration / Configuration.
    // Le tableau imbriqué est indispensable : c'est la seule forme acceptée
    // par Html::generateMenuSession() pour créer une entrée inexistante.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['veilletool'] = [
        Menu::SECTOR => [Menu::class],
    ];
}

/**
 * Informations affichées dans la liste des plugins.
 *
 * @return array
 */
function plugin_version_veilletool()
{
    return [
        'name'         => __('Outils de Veille', 'veilletool'),
        'version'      => PLUGIN_VEILLETOOL_VERSION,
        'author'       => 'Kevin D3D',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_VEILLETOOL_MIN_GLPI,
                'max' => PLUGIN_VEILLETOOL_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => [
                    'simplexml' => ['required' => true],
                    'libxml'    => ['required' => true, 'function' => 'libxml_use_internal_errors'],
                    'curl'      => ['required' => true],
                    'mbstring'  => ['required' => true],
                ],
            ],
        ],
    ];
}

/**
 * Pré-requis fonctionnels vérifiés avant installation.
 *
 * @return bool
 */
function plugin_veilletool_check_prerequisites()
{
    return true;
}

/**
 * Vérification de la configuration après installation.
 *
 * @param bool $verbose
 *
 * @return bool
 */
function plugin_veilletool_check_config($verbose = false)
{
    return true;
}
