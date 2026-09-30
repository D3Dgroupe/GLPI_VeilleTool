<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Installation : création du schéma tel qu'il doit être dans la version
 * courante, et chargement des données livrées avec le plugin.
 *
 * Ce fichier ne connaît **que** l'état cible.
 * Il n'a aucune notion des versions antérieures : tout ce qui consiste à
 * rattraper un schéma déjà en place relève de `install/migration.php`.
 * La séparation est volontaire - elle évite que le code d'installation ne
 * devienne, au fil des versions, un empilement de correctifs conditionnels.
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Category;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Favorite;
use GlpiPlugin\Veilletool\Feed\SourceCatalog;
use GlpiPlugin\Veilletool\FilterPreset;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\Profile as VeilleProfile;
use GlpiPlugin\Veilletool\ReadStatus;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\ScorePreset;
use GlpiPlugin\Veilletool\Settings;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Source_Category;
use GlpiPlugin\Veilletool\Technology;

/**
 * Première installation : crée le schéma et charge les données livrées.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_doInstall(Migration $migration)
{
    plugin_veilletool_createSchema();

    // Le référentiel livré avec le plugin n'est chargé qu'une fois : une
    // réinstallation ne doit pas écraser les ajustements de l'exploitant.
    if (countElementsInTable(Technology::getTable()) === 0) {
        plugin_veilletool_installDefaultTechnologies();
    }

    plugin_veilletool_installDefaultCategories();

    // Le catalogue de flux suit les catégories : il s'y rattache.
    // Comme le référentiel technologique, il n'est chargé que sur un plugin
    // vierge - une réinstallation ne doit pas réintroduire des flux
    // écartés.
    if (countElementsInTable(Source::getTable()) === 0) {
        plugin_veilletool_installCatalogSources();
    }

    $migration->displayMessage(__('Schéma et données livrées en place.', 'veilletool'));
}

/**
 * Charge le catalogue de flux livré avec le plugin.
 *
 * Tous ses flux arrivent **désactivés** : aucune collecte ne démarre tant
 * que l'administrateur n'a pas choisi ce qu'il suit.
 *
 * @return void
 */
function plugin_veilletool_installCatalogSources()
{
    $result = SourceCatalog::importCatalog();

    if ($result['imported'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __(
                    'Catalogue de veille : %d flux proposés, tous désactivés. Activez ceux qui vous intéressent depuis Veille → Flux RSS / ATOM.',
                    'veilletool'
                ),
                $result['imported']
            ),
            true,
            INFO
        );
    }

    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, WARNING);
    }
}

/**
 * Tables du plugin, des dépendances vers les porteuses.
 *
 * L'ordre est celui de la suppression : une table de liaison disparaît
 * avant celle qu'elle référence.
 *
 * @return string[]
 */
function plugin_veilletool_getTables(): array
{
    return [
        FilterPreset::getTable(),
        ScorePreset::getTable(),
        Article_Technology::getTable(),
        FollowedTechnology::getTable(),
        Technology::getTable(),
        Comment::getTable(),
        Favorite::getTable(),
        ReadStatus::getTable(),
        Article_Category::getTable(),
        Article::getTable(),
        Source_Category::getTable(),
        Source::getTable(),
        Category::getTable(),
    ];
}

/**
 * Création des tables du plugin, dans leur forme courante.
 *
 * Chaque création est conditionnée à l'absence de la table : la fonction
 * est rejouable sans risque, ce qui permet de la réutiliser telle quelle
 * lorsqu'une migration constate qu'une table manque.
 *
 * @return void
 */
function plugin_veilletool_createSchema()
{
    /** @var DBmysql $DB */
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    // ------------------------------------------------------------------
    // Catégories (les « tags » de filtrage des onglets)
    //
    // `interest` est la composante « catégorie » du score d'intérêt des
    // cartes portant ce thème.
    // ------------------------------------------------------------------
    $table = Category::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name`          varchar(255) DEFAULT NULL,
            `code`          varchar(64) DEFAULT NULL,
            `color`         varchar(20) NOT NULL DEFAULT '#4b7bec',
            `text_color`    varchar(20) NOT NULL DEFAULT '#ffffff',
            `icon`          varchar(100) NOT NULL DEFAULT 'ti ti-tag',
            `ranking`       int NOT NULL DEFAULT '0',
            `interest`      int NOT NULL DEFAULT '50',
            `is_active`     tinyint NOT NULL DEFAULT '1',
            `comment`       text,
            `date_mod`      timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`),
            KEY `ranking` (`ranking`),
            KEY `date_mod` (`date_mod`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Sources : flux RSS / Atom
    //
    // `interest` est la composante « source » du score d'intérêt des cartes
    // collectées par ce flux.
    // ------------------------------------------------------------------
    $table = Source::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`              int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name`            varchar(255) DEFAULT NULL,
            `sourcetype`      varchar(20) NOT NULL DEFAULT 'rss',
            `url`             text,
            `is_active`       tinyint NOT NULL DEFAULT '1',
            `max_items`       int NOT NULL DEFAULT '50',
            `interest`        int NOT NULL DEFAULT '50',
            `users_id`        int {$default_key_sign} NOT NULL DEFAULT '0',
            `last_import`     timestamp NULL DEFAULT NULL,
            `last_status`     varchar(20) NOT NULL DEFAULT '',
            `last_message`    text,
            `imported_count`  int NOT NULL DEFAULT '0',
            `comment`         text,
            `date_mod`        timestamp NULL DEFAULT NULL,
            `date_creation`   timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `sourcetype` (`sourcetype`),
            KEY `is_active` (`is_active`),
            KEY `users_id` (`users_id`),
            KEY `date_mod` (`date_mod`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Catégories affectées à une source (héritées par les cartes)
    // ------------------------------------------------------------------
    $table = Source_Category::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                                 int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_sources_id`     int {$default_key_sign} NOT NULL DEFAULT '0',
            `plugin_veilletool_categories_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_sources_id`,`plugin_veilletool_categories_id`),
            KEY `plugin_veilletool_categories_id` (`plugin_veilletool_categories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Cartes d'information
    //
    // Les trois colonnes `score_*` figent les composantes stables du score
    // d'intérêt.
    // La fraîcheur, qui change tous les jours, est recalculée à la volée.
    // `is_indexed` matérialise la file de reclassement traitée par la tâche
    // automatique.
    //
    // L'index plein texte porte la recherche par mot clé sur le titre, le
    // résumé et le contenu.
    // L'auteur en est exclu et reste cherché en sous-chaîne.
    //
    // `content_hash` retient l'empreinte du contenu **brut**, avant
    // purification : elle permet à une collecte de reconnaître une entrée
    // inchangée et de ne pas repurifier pour rien.
    //
    // `score_order` porte le tri par score d'intérêt de l'observatoire.
    // Le score se calcule à la volée, et dépend de l'heure : aucun index ne
    // peut en donner l'ordre.
    // Celui-ci indexe les quatre **entrées** du calcul, et permet donc à la
    // base de trier en ne lisant que cet index, sans toucher aux lignes -
    // qui portent le contenu intégral des cartes.
    // Il est indépendant du barème : les poids, l'horizon de fraîcheur et
    // les seuils de priorité se règlent librement sans jamais avoir à le
    // reconstruire.
    // ------------------------------------------------------------------
    $table = Article::getTable();
    if (!$DB->tableExists($table)) {
        // Les colonnes de `score_order` sont celles qu'annonce Score : le
        // schéma posé ici et celui que la mise à jour remet en
        // correspondance viennent de la même source.
        $score_index   = Article::SCORE_INDEX;
        $score_columns = '`' . implode('`,`', Score::orderColumns()) . '`';

        $query = "CREATE TABLE `$table` (
            `id`                              int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_sources_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            `name`                            varchar(255) DEFAULT NULL,
            `summary`                         text,
            `content`                         longtext,
            `content_hash`                    varchar(40) NOT NULL DEFAULT '',
            `url`                             text,
            `guid`                            varchar(191) NOT NULL DEFAULT '',
            `author`                          varchar(255) DEFAULT NULL,
            `image_url`                       text,
            `origin`                          varchar(20) NOT NULL DEFAULT 'rss',
            `date_published`                  timestamp NULL DEFAULT NULL,
            `users_id`                        int {$default_key_sign} NOT NULL DEFAULT '0',
            `score_source`                    int NOT NULL DEFAULT '0',
            `score_technology`                int NOT NULL DEFAULT '0',
            `score_category`                  int NOT NULL DEFAULT '0',
            `is_indexed`                      tinyint NOT NULL DEFAULT '0',
            `date_mod`                        timestamp NULL DEFAULT NULL,
            `date_creation`                   timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_sources_id`,`guid`),
            KEY `name` (`name`),
            KEY `origin` (`origin`),
            KEY `date_published` (`date_published`),
            KEY `users_id` (`users_id`),
            KEY `is_indexed` (`is_indexed`),
            KEY `date_mod` (`date_mod`),
            KEY `date_creation` (`date_creation`),
            KEY `{$score_index}` ({$score_columns}),
            FULLTEXT KEY `fulltext` (`name`,`summary`,`content`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Catégories d'une carte
    // ------------------------------------------------------------------
    $table = Article_Category::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                                 int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_articles_id`    int {$default_key_sign} NOT NULL DEFAULT '0',
            `plugin_veilletool_categories_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_articles_id`,`plugin_veilletool_categories_id`),
            KEY `plugin_veilletool_categories_id` (`plugin_veilletool_categories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Marquage « lu » : propre à chaque utilisateur
    // ------------------------------------------------------------------
    $table = ReadStatus::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                               int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_articles_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id`                         int {$default_key_sign} NOT NULL DEFAULT '0',
            `date_creation`                    timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_articles_id`,`users_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Favoris : partagés, visibles par tous les utilisateurs
    // ------------------------------------------------------------------
    $table = Favorite::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                               int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_articles_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id`                         int {$default_key_sign} NOT NULL DEFAULT '0',
            `date_creation`                    timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_articles_id`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Commentaires
    // ------------------------------------------------------------------
    $table = Comment::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                               int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_articles_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id`                         int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id_editor`                  int {$default_key_sign} NOT NULL DEFAULT '0',
            `content`                          text,
            `date_mod`                         timestamp NULL DEFAULT NULL,
            `date_creation`                    timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_veilletool_articles_id` (`plugin_veilletool_articles_id`),
            KEY `users_id` (`users_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Classification technologique des cartes
    //
    // Le rapprochement entre le texte d'une carte et le référentiel est
    // matérialisé ici : le filtrage et les statistiques deviennent de
    // simples requêtes SQL sur ces colonnes. `confidence` dit sur quoi
    // repose le rattachement - libellé exact, version tronquée, alias,
    // famille ou racine de produit.
    //
    // `is_manual` distingue le rattachement décidé par un administrateur de
    // celui trouvé par le rapprochement automatique.
    // Le premier vaut 100 et survit au reclassement : c'est une décision,
    // pas une déduction.
    // ------------------------------------------------------------------
    $table = Article_Technology::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`                              int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_veilletool_articles_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `categorie`                       varchar(100) NOT NULL DEFAULT '',
            `famille`                         varchar(190) NOT NULL DEFAULT '',
            `name`                            varchar(190) NOT NULL DEFAULT '',
            `confidence`                      int NOT NULL DEFAULT '0',
            `is_manual`                       tinyint NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_veilletool_articles_id`,`categorie`,`famille`,`name`),
            KEY `categorie` (`categorie`),
            KEY `famille` (`famille`),
            KEY `name` (`name`),
            KEY `confidence` (`confidence`),
            KEY `is_manual` (`is_manual`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Référentiel technologique (catégorie / famille / sous-catégorie)
    // ------------------------------------------------------------------
    $table = Technology::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `categorie`     varchar(100) NOT NULL DEFAULT '',
            `famille`       varchar(190) NOT NULL DEFAULT '',
            `name`          varchar(190) NOT NULL DEFAULT '',
            `is_active`     tinyint NOT NULL DEFAULT '1',
            `date_mod`      timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`categorie`,`famille`,`name`),
            KEY `categorie` (`categorie`),
            KEY `famille` (`famille`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Filtres enregistrés de l'observatoire
    //
    // Propres à chaque utilisateur : un filtre est une façon de travailler,
    // pas un réglage d'administration.
    // La clé d'unicité porte donc sur le couple utilisateur / titre, et deux
    // personnes peuvent nommer leurs vues de la même façon.
    // ------------------------------------------------------------------
    $table = FilterPreset::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `users_id`      int {$default_key_sign} NOT NULL DEFAULT '0',
            `name`          varchar(255) NOT NULL DEFAULT '',
            `params`        text,
            `date_mod`      timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`users_id`,`name`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Positions de réglage du score d'intérêt
    //
    // Le barème n'a pas une bonne réponse mais plusieurs, selon ce qu'on
    // cherche. `settings` porte la copie JSON des seules clés du barème :
    // une clé ajoutée par une version ultérieure reprend sa valeur livrée,
    // et un préréglage ancien reste donc applicable.
    // ------------------------------------------------------------------
    $table = ScorePreset::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name`          varchar(255) NOT NULL DEFAULT '',
            `comment`       text,
            `settings`      text,
            `date_mod`      timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }

    // ------------------------------------------------------------------
    // Référentiel des technologies suivies
    //
    // Le référentiel général qualifie.
    // Celui-ci sélectionne.
    // Ses colonnes sont celles de la table de classification, ce qui ramène
    // le filtrage à une comparaison directe : `name` vide désigne toute la
    // famille, `name` renseigné désigne cette technologie seule.
    //
    // La catégorie n'est pas suivable : « Application » ou « OS »
    // regroupent des centaines d'entrées sans rapport, et les suivre
    // revenait à ne rien filtrer.
    // ------------------------------------------------------------------
    $table = FollowedTechnology::getTable();
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `categorie`     varchar(100) NOT NULL DEFAULT '',
            `famille`       varchar(190) NOT NULL DEFAULT '',
            `name`          varchar(190) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`categorie`,`famille`,`name`),
            KEY `famille` (`famille`),
            KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);
    }
}

/**
 * Droits du plugin, et attribution au profil courant.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_installRights(Migration $migration)
{
    // Droit livré à tous les profils : consulter et commenter.
    // Modifier, créer et supprimer une carte restent à cocher explicitement
    // - un lecteur participe aux échanges sans pouvoir altérer la veille.
    $migration->addRight(Article::$rightname, READ | Article::RIGHT_COMMENT, []);
    $migration->addRight(Source::$rightname, ALLSTANDARDRIGHT, ['config' => READ | UPDATE]);

    VeilleProfile::initDefaultProfile();
}

/**
 * Enregistrement des tâches automatiques du plugin.
 *
 * Le mode d'exécution n'est volontairement pas imposé : GLPI retient le
 * mode interne (déclenché par la navigation), sauf si l'intégrateur a
 * activé GLPI_SYSTEM_CRON, auquel cas il bascule seul en mode externe.
 * Forcer le mode externe rendrait les tâches inertes sur toute installation
 * dépourvue de cron système.
 *
 * @return void
 */
function plugin_veilletool_registerCronTasks()
{
    CronTask::register(
        Source::class,
        Source::CRON_TASK,
        HOUR_TIMESTAMP,
        [
            'comment'       => 'Collecte automatique des flux RSS / Atom de la veille technologique',
            'state'         => CronTask::STATE_WAITING,
            'allowmode'     => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
        ]
    );

    // Le reclassement technologique est une opération longue : il ne se
    // fait plus pendant l'installation, mais par lots, ici. `param` porte
    // la taille du lot, réglable depuis l'écran des actions automatiques.
    CronTask::register(
        Article_Technology::class,
        Article_Technology::CRON_TASK,
        HOUR_TIMESTAMP,
        [
            'comment'       => 'Reclassement technologique des cartes en attente, par lots',
            'state'         => CronTask::STATE_WAITING,
            'allowmode'     => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            'param'         => Article_Technology::CRON_BATCH,
            'logs_lifetime' => 30,
        ]
    );
}

/**
 * Charge le référentiel technologique livré avec le plugin.
 *
 * @return void
 */
function plugin_veilletool_installDefaultTechnologies()
{
    $path = __DIR__ . '/data/technologies_IT.csv';

    if (!is_file($path) || !is_readable($path)) {
        Session::addMessageAfterRedirect(
            __('Référentiel technologique introuvable : il pourra être importé depuis l\'onglet Technologies.', 'veilletool'),
            false,
            WARNING
        );
        return;
    }

    $content = (string) file_get_contents($path);
    $result  = Technology::importCsv($content);

    Session::addMessageAfterRedirect(
        sprintf(
            __('Référentiel technologique : %d entrée(s) chargée(s).', 'veilletool'),
            $result['imported']
        ),
        true,
        INFO
    );
}

/**
 * Crée les catégories fournies par défaut si elles n'existent pas déjà.
 *
 * La dernière valeur est la note d'intérêt du thème, composante « catégorie
 * » du score des cartes qui le portent.
 *
 * @return void
 */
function plugin_veilletool_installDefaultCategories()
{
    $defaults = [
        ['cybersecurite',  'Cybersécurité',    '#e74c3c', 'ti ti-shield-lock', 90],
        ['infrastructure', 'Infrastructure',   '#3498db', 'ti ti-server-2',    70],
        ['technologie-d3d', 'Technologie D3D', '#16a085', 'ti ti-cpu',         80],
        ['innovation',     'Innovation',       '#f39c12', 'ti ti-bulb',        50],
    ];

    $category = new Category();
    $ranking  = 0;
    foreach ($defaults as [$code, $name, $color, $icon, $interest]) {
        $ranking += 10;
        if (countElementsInTable(Category::getTable(), ['code' => $code]) > 0) {
            continue;
        }
        $category->add([
            'name'      => $name,
            'code'      => $code,
            'color'     => $color,
            'icon'      => $icon,
            'ranking'   => $ranking,
            'interest'  => $interest,
            'is_active' => 1,
        ]);
    }
}

/**
 * Réglages livrés par défaut.
 *
 * Seules les clés absentes sont écrites : une réinstallation ne réécrit pas
 * les poids et les seuils ajustés par l'exploitant.
 *
 * @return void
 */
function plugin_veilletool_installDefaultSettings()
{
    // Les réglages d'anciennes versions ne servent plus et encombreraient
    // la configuration de GLPI : ils partent d'abord.
    Settings::purgeObsolete();

    $existing = Config::getConfigurationValues(Settings::CONTEXT);

    $missing = [];
    foreach (Settings::DEFAULTS as $key => $value) {
        if (!array_key_exists($key, $existing)) {
            $missing[$key] = $value;
        }
    }

    if ($missing !== []) {
        Settings::set($missing);
    }
}
