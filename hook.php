<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Aiguillage de l'installation et de la désinstallation.
 *
 * GLPI appelle la même fonction pour installer un plugin et pour le mettre
 * à jour.
 * Le travail réel est donc réparti en deux fichiers, selon ce qu'on trouve
 * en place :
 *
 * - `install/install.php` - l'état cible du schéma, sans aucune notion des
 * versions antérieures.
 * - `install/migration.php` - les seuls écarts entre une version connue et
 * la version courante.
 *
 * Ce fichier ne fait que décider lequel des deux s'applique, puis exécuter
 * ce qui vaut dans les deux cas : droits, tâches automatiques et réglages.
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Settings;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Technology;

require_once __DIR__ . '/install/install.php';
require_once __DIR__ . '/install/migration.php';

/**
 * Installation ou mise à jour du plugin.
 *
 * @return bool
 */
function plugin_veilletool_install()
{
    $migration = new Migration(PLUGIN_VEILLETOOL_VERSION);

    // La version trouvée en place décide de la suite.
    // Elle est relevée avant toute écriture : une fois le schéma touché,
    // plus rien ne la distingue d'une installation neuve.
    $installed = plugin_veilletool_detectInstalledVersion();

    if ($installed === '') {
        plugin_veilletool_doInstall($migration);
    } else {
        plugin_veilletool_doMigration($migration, $installed);
    }

    // ------------------------------------------------------------------
    // Commun aux deux chemins
    // ------------------------------------------------------------------
    plugin_veilletool_installDefaultSettings();
    plugin_veilletool_installRights($migration);
    plugin_veilletool_registerCronTasks();

    $migration->executeMigration();

    // La version n'est enregistrée qu'une fois tout le reste passé : une
    // installation interrompue sera reprise au prochain essai, au lieu de
    // se croire à jour.
    Settings::setSchemaVersion(PLUGIN_VEILLETOOL_VERSION);

    // Les cartes déjà présentes sont classées par la tâche automatique «
    // Reclassement technologique », par lots : l'installation ne bloque
    // plus sur plusieurs milliers de cartes.
    $pending = Article_Technology::countPending();

    if ($pending > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __(
                    'Classification technologique : %d carte(s) en attente, traitées par l\'action automatique « Reclassement technologique ».',
                    'veilletool'
                ),
                $pending
            ),
            true,
            INFO
        );
    }

    return true;
}

/**
 * Désinstallation : suppression du schéma, des droits, des réglages et des
 * tâches automatiques.
 *
 * @return bool
 */
function plugin_veilletool_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (plugin_veilletool_getTables() as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Suppression des tâches automatiques du plugin.
    $crontask = new CronTask();
    $crontask->deleteByCriteria(['itemtype' => [Source::class, Article_Technology::class]]);

    // Suppression des droits.
    ProfileRight::deleteProfileRights([Article::$rightname, Source::$rightname]);
    ProfileRight::cleanAllPossibleRights();

    // Réglages du score d'intérêt et version du schéma.
    Settings::purge();

    // Nettoyage des préférences d'affichage éventuelles.
    $pref = new DisplayPreference();
    $pref->deleteByCriteria(['itemtype' => [Article::class, Source::class, Category::class, Technology::class]]);

    // Nettoyage des journaux liés aux itemtypes du plugin.
    $log = new Log();
    $log->deleteByCriteria(['itemtype' => [Article::class, Source::class, Category::class, Comment::class]]);

    return true;
}
