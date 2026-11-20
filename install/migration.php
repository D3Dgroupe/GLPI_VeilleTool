<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Migration : rattrapage d'une installation déjà en place.
 *
 * Ce fichier ne crée jamais le schéma de zéro - c'est le rôle de
 * `install/install.php`.
 * Il ne contient que les écarts entre une version antérieure et la version
 * courante, appliqués dans l'ordre et conditionnés à la version réellement
 * installée.
 *
 * Chaque étape est **idempotente** : la rejouer ne casse rien.
 * C'est ce qui permet de rattraper une installation dont la version
 * enregistrée s'est perdue, en repartant du plus ancien palier connu.
 * -------------------------------------------------------------------------
 */

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Taxonomy\CategoryCode;
use GlpiPlugin\Veilletool\Technology;

/**
 * Applique les migrations nécessaires pour amener une installation
 * existante à la version courante.
 *
 * @param Migration $migration
 * @param string    $from_version Version du schéma trouvée en place
 *
 * @return void
 */
function plugin_veilletool_doMigration(Migration $migration, string $from_version)
{
    // Reprise d'une installation portant l'ancienne clé « veilletechno » :
    // à faire avant tout, les tables doivent porter leur nom définitif.
    plugin_veilletool_takeoverLegacyPlugin($migration);

    // Migration::renameItemtype() renomme les tables immédiatement, mais se
    // contente d'**empiler** le renommage des colonnes de clé étrangère :
    // il n'a lieu qu'au vidage de la file.
    // Tant que celle-ci n'est pas vidée, les tables portent leur nouveau
    // nom et leurs anciennes colonnes - et la moindre requête sur ces
    // colonnes échoue.
    plugin_veilletool_flushSchemaChanges($migration);

    // Une reprise interrompue laisse des colonnes à l'ancien nom que plus
    // aucune file ne viendra corriger : on les rattrape explicitement.
    plugin_veilletool_repairLegacyColumns($migration);

    // Une table peut manquer - plugin partiellement installé, table
    // supprimée à la main : la création étant conditionnelle, on repasse
    // dessus sans rien écraser.
    plugin_veilletool_createSchema();

    if (version_compare($from_version, '1.1.0', '<')) {
        plugin_veilletool_migrateTo110();
    }

    if (version_compare($from_version, '1.4.1', '<')) {
        plugin_veilletool_migrateTo141();
    }

    if (version_compare($from_version, '1.5.0', '<')) {
        plugin_veilletool_migrateTo150($migration);
    }

    if (version_compare($from_version, '1.5.1', '<')) {
        plugin_veilletool_migrateTo151($migration);
    }

    if (version_compare($from_version, '1.5.2', '<')) {
        plugin_veilletool_migrateTo152($migration);
    }

    if (version_compare($from_version, '1.6.0', '<')) {
        plugin_veilletool_migrateTo160($migration);
    }

    if (version_compare($from_version, '1.7.0', '<')) {
        plugin_veilletool_migrateTo170($migration);
    }

    if (version_compare($from_version, '1.7.1', '<')) {
        plugin_veilletool_migrateTo171($migration);
    }

    // La 1.8.0 n'ajoute qu'une table, créée par
    // plugin_veilletool_createSchema() plus haut : aucun écart de données à
    // rattraper.

    // La 1.9.0 n'ajoute elle aussi qu'une table, celle des filtres
    // enregistrés, déjà créée plus haut.

    if (version_compare($from_version, '1.11.0', '<')) {
        plugin_veilletool_migrateTo1110($migration);
    }

    // L'index de tri de l'observatoire, introduit en 1.9.1.
    // Sans garde de version, volontairement : l'étape compare l'index en
    // place au calcul du score, et n'agit que s'ils divergent.
    // C'est ce qui la rend rejouable, et ce qui fera suivre l'index le jour
    // où le calcul changera.
    plugin_veilletool_syncScoreIndex($migration);

    $migration->displayMessage(sprintf(
        __('Migration depuis la version %s terminée.', 'veilletool'),
        $from_version
    ));
}

/**
 * Applique immédiatement les modifications de structure en attente.
 *
 * Les méthodes de `Migration` empilent leurs `ALTER TABLE` et ne les
 * exécutent qu'au vidage de la file.
 * Toute étape de migration qui lit ou écrit des **données** doit donc vider
 * la file d'abord, faute de quoi elle travaille sur un schéma qui n'existe
 * pas encore.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_flushSchemaChanges(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (plugin_veilletool_getTables() as $table) {
        if ($DB->tableExists($table)) {
            $migration->migrationOneTable($table);
        }
    }
}

/**
 * Renomme les colonnes restées au nom de l'ancien plugin.
 *
 * Si la reprise de « veilletechno » a été interrompue entre le renommage
 * des tables et celui de leurs colonnes, la file de `Migration` est perdue
 * avec la requête : la tentative suivante croit la reprise faite - les
 * anciennes tables n'existent plus - et laisse des colonnes
 * `plugin_veilletechno_*` dans des tables `glpi_plugin_veilletool_*`.
 *
 * La recherche est volontairement bornée aux tables du plugin : un «
 * veilletechno » encore installé, avec ses propres tables, n'est pas
 * touché.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_repairLegacyColumns(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $legacy_prefix = 'plugin_veilletechno_';

    $iterator = $DB->request([
        'SELECT' => [
            'table_name AS TABLE_NAME',
            'column_name AS COLUMN_NAME',
        ],
        'FROM'   => 'information_schema.columns',
        'WHERE'  => [
            'table_schema' => $DB->dbdefault,
            'table_name'   => ['LIKE', 'glpi\_plugin\_veilletool\_%'],
            'column_name'  => ['LIKE', 'plugin\_veilletechno\_%'],
        ],
        'ORDER'  => 'TABLE_NAME',
    ]);

    $touched = [];

    foreach ($iterator as $row) {
        $table = (string) $row['TABLE_NAME'];
        $old   = (string) $row['COLUMN_NAME'];
        $new   = 'plugin_veilletool_' . substr($old, strlen($legacy_prefix));

        // La colonne cible existe déjà : renommer créerait un doublon.
        // Ce cas ne relève plus d'une reprise interrompue et demande un
        // arbitrage humain.
        // On laisse l'ancienne colonne en place.
        if ($DB->fieldExists($table, $new)) {
            continue;
        }

        $migration->changeField($table, $old, $new, 'fkey');
        $touched[$table] = true;
    }

    if ($touched === []) {
        return;
    }

    foreach (array_keys($touched) as $table) {
        $migration->migrationOneTable($table);
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('Reprise incomplète rattrapée : colonnes renommées dans %d table(s).', 'veilletool'),
            count($touched)
        ),
        true,
        INFO
    );
}

/**
 * Détermine la version du schéma effectivement en place.
 *
 * Trois cas se présentent :
 *
 * - une version est enregistrée dans la configuration : c'est elle.
 * - aucune version, mais les tables existent : l'installation est
 * antérieure à l'enregistrement de la version, donc au plus égale à la
 * 1.0.0.
 * - rien du tout : première installation.
 *
 * @return string Chaîne vide s'il n'y a rien à migrer
 */
function plugin_veilletool_detectInstalledVersion(): string
{
    /** @var DBmysql $DB */
    global $DB;

    $stored = \GlpiPlugin\Veilletool\Settings::getSchemaVersion();
    if ($stored !== '') {
        return $stored;
    }

    // Installation veilletool antérieure à l'enregistrement de la version.
    if ($DB->tableExists(Source::getTable())) {
        return '1.0.0';
    }

    // Installation portant encore l'ancienne clé technique.
    if ($DB->tableExists(getTableForItemType('GlpiPlugin\\Veilletechno\\Source'))) {
        return '1.0.0';
    }

    return '';
}

/**
 * Récupère les données d'une installation portant l'ancienne clé technique.
 *
 * Le plugin s'est d'abord appelé « veilletechno ».
 * Renommer le dossier revient, pour GLPI, à installer un plugin différent :
 * les tables de l'ancien nom resteraient orphelines et l'observatoire
 * repartirait vide.
 *
 * Migration::renameItemtype() fait l'essentiel du travail - renommage de la
 * table, des colonnes de clé étrangère dans toutes les tables, et des
 * valeurs de la colonne `itemtype` partout où elles apparaissent, y compris
 * dans les actions automatiques et l'historique.
 * Restent les noms de droits, qui ne suivent aucune convention d'itemtype
 * et sont repris à part.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_takeoverLegacyPlugin(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $classes = [
        'Category',
        'Source',
        'Source_Category',
        'Article',
        'Article_Category',
        'ReadStatus',
        'Favorite',
        'Comment',
        'Technology',
        'Article_Technology',
    ];

    $renamed = 0;

    foreach ($classes as $class) {
        $old_itemtype = 'GlpiPlugin\\Veilletechno\\' . $class;
        $new_itemtype = 'GlpiPlugin\\Veilletool\\' . $class;

        $old_table = getTableForItemType($old_itemtype);
        $new_table = getTableForItemType($new_itemtype);

        // Rien à reprendre, ou reprise déjà faite.
        if (!$DB->tableExists($old_table) || $DB->tableExists($new_table)) {
            continue;
        }

        $migration->renameItemtype($old_itemtype, $new_itemtype);
        $renamed++;
    }

    // Les droits ne portent pas de nom d'itemtype : ils sont repris ici.
    $rights = 0;
    foreach (['veille', 'source'] as $suffix) {
        $rights += (int) $DB->update(
            'glpi_profilerights',
            ['name' => 'plugin_veilletool_' . $suffix],
            ['name' => 'plugin_veilletechno_' . $suffix]
        );
    }

    if ($rights > 0) {
        ProfileRight::cleanAllPossibleRights();
    }

    if ($renamed === 0) {
        return;
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('Reprise de l\'installation précédente : %d table(s) récupérée(s), droits et actions automatiques reportés.', 'veilletool'),
            $renamed
        ),
        true,
        INFO
    );
}

/**
 * Vers la 1.1.0 : disparition des imports XML et des sources manuelles.
 *
 * Les sources de type « xml » ou « manual » n'existent plus, et les cartes
 * issues d'un import XML sont désormais des saisies manuelles.
 *
 * @return void
 */
function plugin_veilletool_migrateTo110()
{
    /** @var DBmysql $DB */
    global $DB;

    $sources = Source::getTable();

    // Une source obsolète dépourvue d'URL ne peut plus rien collecter : on
    // la désactive pour la rendre visiblement inerte plutôt que
    // silencieusement cassée.
    // L'administrateur choisit ensuite de la compléter ou de la supprimer.
    $DB->update(
        $sources,
        ['is_active' => 0],
        [
            'NOT'  => ['sourcetype' => [Source::TYPE_RSS, Source::TYPE_ATOM]],
            'OR'   => [['url' => ''], ['url' => null]],
        ]
    );

    $DB->update(
        $sources,
        ['sourcetype' => Source::TYPE_RSS],
        ['NOT' => ['sourcetype' => [Source::TYPE_RSS, Source::TYPE_ATOM]]]
    );

    $DB->update(
        Article::getTable(),
        ['origin' => Article::ORIGIN_MANUAL],
        ['NOT' => ['origin' => [Article::ORIGIN_RSS, Article::ORIGIN_ATOM, Article::ORIGIN_MANUAL]]]
    );
}

/**
 * Vers la 1.4.1 : rétablit un mode d'exécution exploitable pour la
 * collecte.
 *
 * Les versions antérieures enregistraient la tâche en mode externe.
 * Seul un cron système déclenche ce mode : sur une installation qui n'en a
 * pas, la tâche restait indéfiniment en attente sans jamais s'exécuter.
 * On la ramène en mode interne, sauf si l'intégrateur a effectivement
 * déclaré un cron système.
 *
 * @return void
 */
function plugin_veilletool_migrateTo141()
{
    if (GLPI_SYSTEM_CRON) {
        // Un cron système est en place : le mode externe est le bon.
        return;
    }

    $crontask = new CronTask();

    if (!$crontask->getFromDBbyName(Source::class, Source::CRON_TASK)) {
        return;
    }

    if ((int) $crontask->fields['mode'] !== CronTask::MODE_EXTERNAL) {
        return;
    }

    $crontask->update([
        'id'   => $crontask->fields['id'],
        'mode' => CronTask::MODE_INTERNAL,
    ]);

    Session::addMessageAfterRedirect(
        __(
            'La collecte automatique des flux est repassée en mode « GLPI » : en mode « CLI », elle ne pouvait pas se déclencher sans cron système.',
            'veilletool'
        ),
        true,
        INFO
    );
}

/**
 * Vers la 1.5.0 : score d'intérêt, confiance des rapprochements, catégories
 * suivies, recherche plein texte et file de reclassement.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo150(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $articles     = Article::getTable();
    $categories   = Category::getTable();
    $sources      = Source::getTable();
    $technologies = Technology::getTable();
    $links        = Article_Technology::getTable();

    // ------------------------------------------------------------------
    // Notes d'intérêt des flux et des thèmes
    // ------------------------------------------------------------------
    $migration->addField($sources, 'interest', 'integer', [
        'value' => 50,
        'after' => 'max_items',
    ]);
    $migration->addField($categories, 'interest', 'integer', [
        'value' => 50,
        'after' => 'ranking',
    ]);

    // ------------------------------------------------------------------
    // Composantes stockées du score, et file de reclassement
    //
    // `is_indexed` naît à 0 : toutes les cartes existantes entrent dans la
    // file, que la tâche automatique videra par lots.
    // C'est ce qui remplace le reclassement synchrone que faisait
    // l'installation.
    // ------------------------------------------------------------------
    $migration->addField($articles, 'score_source', 'integer', [
        'value' => 0,
        'after' => 'users_id',
    ]);
    $migration->addField($articles, 'score_technology', 'integer', [
        'value' => 0,
        'after' => 'score_source',
    ]);
    $migration->addField($articles, 'score_category', 'integer', [
        'value' => 0,
        'after' => 'score_technology',
    ]);
    $migration->addField($articles, 'is_indexed', 'bool', [
        'value' => 0,
        'after' => 'score_category',
    ]);
    $migration->addKey($articles, 'is_indexed');

    // ------------------------------------------------------------------
    // Recherche par mot clé en index plein texte
    // ------------------------------------------------------------------
    $migration->addKey($articles, ['name', 'summary', 'content'], 'fulltext', 'FULLTEXT');

    // ------------------------------------------------------------------
    // Confiance des rapprochements technologiques
    //
    // Les rattachements existants ne portent pas de confiance : elle sera
    // établie par le reclassement, qui est déjà programmé puisque toutes
    // les cartes entrent dans la file.
    // ------------------------------------------------------------------
    $migration->addField($links, 'confidence', 'integer', [
        'value' => 0,
        'after' => 'name',
    ]);
    $migration->addKey($links, 'confidence');

    // ------------------------------------------------------------------
    // Référentiel des catégories suivies
    // ------------------------------------------------------------------
    $migration->addField($technologies, 'is_followed', 'bool', [
        'value' => 0,
        'after' => 'is_active',
    ]);
    $migration->addKey($technologies, 'is_followed');

    // Les ajouts de colonnes doivent être en base avant les lectures et les
    // écritures qui suivent.
    // Le vidage porte sur **toutes** les tables du plugin, et pas seulement
    // sur celles que cette étape a modifiées : les autres peuvent encore
    // porter le renommage de colonnes empilé par la reprise de «
    // veilletechno ».
    plugin_veilletool_flushSchemaChanges($migration);

    // ------------------------------------------------------------------
    // Doublon de « Réglementation »
    //
    // Le thème livré par défaut faisait double emploi avec la catégorie «
    // Réglementation » du référentiel technologique : la même information
    // était qualifiée deux fois, par deux mécanismes différents.
    // Seul le thème livré est retiré, et uniquement s'il n'a jamais servi -
    // un thème effectivement utilisé relève d'un choix de l'exploitant.
    // ------------------------------------------------------------------
    plugin_veilletool_removeLegacyReglementation();

    // ------------------------------------------------------------------
    // Toutes les cartes entrent dans la file de reclassement : leurs
    // rattachements n'ont pas de confiance, et leur score n'a jamais été
    // calculé.
    // ------------------------------------------------------------------
    $DB->update($articles, ['is_indexed' => 0], [new \Glpi\DBAL\QueryExpression('1')]);

    $pending = countElementsInTable($articles);

    if ($pending > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                __(
                    '%d carte(s) en attente de reclassement : la tâche automatique « Reclassement technologique » s\'en charge par lots.',
                    'veilletool'
                ),
                $pending
            ),
            true,
            INFO
        );
    }
}

/**
 * Vers la 1.5.1 : le suivi passe de la catégorie à la famille et à la
 * technologie.
 *
 * Suivre « Application » ou « OS » revenait à retenir des centaines
 * d'entrées sans rapport entre elles - donc à ne rien filtrer.
 * Le suivi descend d'un cran : il porte désormais sur une **famille** ou
 * sur une **technologie** précise, et il est stocké dans sa propre table
 * plutôt que dans une colonne du référentiel général.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo151(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $technologies = Technology::getTable();

    // La table des suivis est créée par plugin_veilletool_createSchema(),
    // appelée en amont : il ne reste qu'à retirer l'ancienne colonne.
    if (!$DB->fieldExists($technologies, 'is_followed')) {
        return;
    }

    // Les catégories suivies sont relevées avant suppression, pour pouvoir
    // dire à l'exploitant ce qu'il doit re-sélectionner.
    // Elles ne sont pas converties : reporter « OS » sur ses familles
    // produirait des dizaines de suivis que personne n'a demandés.
    $lost     = [];
    $iterator = $DB->request([
        'SELECT'   => ['categorie'],
        'DISTINCT' => true,
        'FROM'     => $technologies,
        'WHERE'    => ['is_followed' => 1],
        'ORDER'    => ['categorie ASC'],
    ]);

    foreach ($iterator as $row) {
        $lost[] = (string) $row['categorie'];
    }

    $migration->dropKey($technologies, 'is_followed');
    $migration->dropField($technologies, 'is_followed');
    $migration->migrationOneTable($technologies);

    if ($lost === []) {
        return;
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __(
                'Le suivi par catégorie a été retiré (%s) : il portait sur des ensembles trop larges. Choisissez les familles et les technologies à suivre dans Veille → Technologies.',
                'veilletool'
            ),
            htmlescape(implode(', ', $lost))
        ),
        true,
        WARNING
    );
}

/**
 * Vers la 1.5.2 : couleur du texte des thèmes de veille.
 *
 * Le thème ne portait qu'une couleur de fond, et son étiquette imposait du
 * blanc - illisible dès qu'un fond clair était retenu.
 * Le texte devient un réglage à part entière, comme pour les niveaux de
 * priorité.
 *
 * La valeur livrée est le blanc : l'apparence des thèmes existants ne
 * change pas, et chacun l'ajuste ensuite.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo152(Migration $migration)
{
    $migration->addField(Category::getTable(), 'text_color', 'string', [
        'value' => '#ffffff',
        'after' => 'color',
    ]);

    $migration->migrationOneTable(Category::getTable());
}

/**
 * Vers la 1.6.0 : droit de commentaire distinct, et rattachements
 * technologiques décidés à la main.
 *
 * Deux écarts de schéma, et une reprise de droits :
 *
 * - `is_manual` sur la table de classification, pour qu'un rattachement
 * ajouté par un administrateur survive au reclassement automatique.
 * - le bit « Commenter » ajouté aux profils qui portaient jusqu'ici le
 * droit d'écriture.
 * Sans cette reprise, tous ceux qui commentaient par ce biais ne le
 * pourraient plus : le droit d'écriture ouvrait les commentaires, il ne les
 * ouvre plus à lui seul.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo160(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $links = Article_Technology::getTable();

    // Les rattachements déjà en place viennent tous du rapprochement
    // automatique : ils naissent donc à 0.
    $migration->addField($links, 'is_manual', 'bool', [
        'value' => 0,
        'after' => 'confidence',
    ]);
    $migration->addKey($links, 'is_manual');
    $migration->migrationOneTable($links);

    // Un profil qui pouvait modifier les cartes pouvait commenter : il doit
    // continuer de le pouvoir.
    // Les profils en simple lecture, eux, gagnent le droit de commenter -
    // c'est précisément l'objet de cette version.
    // Un profil sans aucun accès à la veille, lui, n'en gagne aucun.
    $rights = DBmysql::quoteName('rights');

    $DB->update(
        'glpi_profilerights',
        ['rights' => new QueryExpression($rights . ' | ' . Article::RIGHT_COMMENT)],
        [
            'name' => Article::$rightname,
            new QueryExpression($rights . ' & ' . READ . ' = ' . READ),
            new QueryExpression($rights . ' & ' . Article::RIGHT_COMMENT . ' = 0'),
        ]
    );

    $granted = $DB->affectedRows();

    ProfileRight::cleanAllPossibleRights();

    $migration->displayMessage(sprintf(
        __('Droit « Commenter » ajouté à %d profil(s) de veille.', 'veilletool'),
        $granted
    ));
}

/**
 * Vers la 1.7.1 : empreinte du contenu brut des cartes.
 *
 * La purification HTML d'un contenu de quelques kilo-octets coûte plusieurs
 * millisecondes, contre une fraction de milliseconde pour tout le reste de
 * l'enregistrement.
 * Une collecte repassant sur les mêmes entrées à chaque relevé, le plugin
 * repurifiait chaque heure des contenus rigoureusement identiques.
 *
 * La colonne retient l'empreinte du contenu **avant** purification : quand
 * elle n'a pas changé, le contenu stocké est déjà ce que la purification
 * produirait.
 *
 * Elle naît vide : la première collecte suivant la mise à jour repurifie
 * donc chaque carte une dernière fois, puis pose son empreinte.
 * Les relevés suivants n'y reviennent plus.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo171(Migration $migration)
{
    // Type donné en toutes lettres : `string` produirait un VARCHAR(255),
    // là où une empreinte SHA-1 tient dans quarante caractères.
    // Le schéma d'une installation neuve et celui d'une installation migrée
    // doivent concorder.
    $migration->addField(
        Article::getTable(),
        'content_hash',
        "varchar(40) NOT NULL DEFAULT ''",
        ['after' => 'content']
    );

    $migration->migrationOneTable(Article::getTable());
}

/**
 * Vers la 1.11.0 : contenu complet repris de la page de l'article.
 *
 * - deux colonnes sur les flux : l'option elle-même, et le sélecteur de la
 * zone de contenu propre au site ;
 * - une colonne sur les cartes, qui date la reprise : une carte complétée
 * n'est plus écrasée par le résumé du flux aux collectes suivantes ;
 * - les flux du CERT-FR suivent le déménagement du site
 * (`www.cert.ssi.gouv.fr` → `cert.cyber.gouv.fr`) et reçoivent le
 * sélecteur de leur zone de contenu. L'option elle-même reste désactivée :
 * c'est à l'administrateur de décider qu'une collecte va chercher, en plus
 * du flux, la page de chaque article.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo1110(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $sources  = Source::getTable();
    $articles = Article::getTable();

    $migration->addField($sources, 'fetch_full_content', 'bool', [
        'value' => 0,
        'after' => 'max_items',
    ]);
    $migration->addField($sources, 'content_selector', "varchar(255) NOT NULL DEFAULT ''", [
        'after' => 'fetch_full_content',
    ]);
    $migration->addField($articles, 'date_content_fetched', 'timestamp NULL DEFAULT NULL', [
        'after' => 'content_hash',
    ]);

    $migration->migrationOneTable($sources);
    $migration->migrationOneTable($articles);

    // Les trois flux du CERT-FR, reconnus à leur ancienne adresse, avec ou
    // sans `www`, en http comme en https.
    foreach (['alerte', 'avis', 'actualite'] as $section) {
        $new = 'https://cert.cyber.gouv.fr/' . $section . '/feed/';

        foreach ($DB->request(['SELECT' => ['id', 'url'], 'FROM' => $sources]) as $row) {
            $url = strtolower(rtrim(trim((string) $row['url']), '/'));

            if (preg_match('#^https?://(www\.)?cert\.ssi\.gouv\.fr/' . $section . '/feed$#', $url) !== 1) {
                continue;
            }

            $DB->update($sources, [
                'url'              => $new,
                'content_selector' => PLUGIN_VEILLETOOL_CERTFR_SELECTOR,
            ], ['id' => (int) $row['id']]);
        }
    }
}

/**
 * Vers la 1.7.0 : l'ordre des thèmes se déplace à la souris.
 *
 * Le rang n'est plus saisi.
 * Il faut donc qu'il soit **distinct d'un thème à l'autre** : jusqu'ici
 * rien ne l'imposait, et une base pouvait porter plusieurs thèmes à zéro -
 * le classement ne bougeait alors plus, quoi qu'on déplace.
 * Les rangs sont réécrits de dix en dix, dans l'ordre d'affichage courant :
 * personne ne voit sa liste changer.
 *
 * Aucun changement de schéma : les colonnes `ranking` et `code` existaient
 * déjà, seule leur façon d'être renseignées change.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_migrateTo170(Migration $migration)
{
    /** @var DBmysql $DB */
    global $DB;

    $table = Category::getTable();

    if (!$DB->tableExists($table)) {
        return;
    }

    $rank = 0;
    $iterator = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => $table,
        'ORDER'  => ['ranking ASC', 'name ASC'],
    ]);

    foreach ($iterator as $row) {
        $rank += 10;
        $DB->update($table, ['ranking' => $rank], ['id' => (int) $row['id']]);
    }

    // Un thème sans code - importé de travers, ou créé avant que le code ne
    // soit calculé - n'aurait jamais pu être désigné dans un fichier.
    $orphans = 0;
    foreach (
        $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => $table,
            'WHERE'  => ['OR' => [['code' => ''], ['code' => null]]],
        ]) as $row
    ) {
        $DB->update(
            $table,
            ['code' => CategoryCode::build('', (string) $row['name'])],
            ['id' => (int) $row['id']]
        );
        $orphans++;
    }

    Category::invalidate();

    $migration->displayMessage(sprintf(
        __('Ordre des thèmes normalisé (%1$d thème(s), %2$d code(s) rétabli(s)).', 'veilletool'),
        (int) ($rank / 10),
        $orphans
    ));
}

/**
 * Retire le thème « Réglementation » livré par les versions antérieures.
 *
 * Il faisait doublon avec la catégorie « Réglementation » du référentiel
 * technologique.
 * La suppression est prudente : le thème n'est retiré que s'il a conservé
 * son aspect d'origine - preuve qu'il n'a pas été repris à son compte par
 * l'exploitant - et qu'aucune carte ne le porte.
 *
 * @return void
 */
function plugin_veilletool_removeLegacyReglementation()
{
    /** @var DBmysql $DB */
    global $DB;

    $category = new Category();

    if (!$category->getFromDBByCrit(['code' => 'reglementation'])) {
        return;
    }

    $untouched = (string) $category->fields['color'] === '#9b59b6'
        && (string) $category->fields['icon'] === 'ti ti-gavel';

    $used = countElementsInTable(
        \GlpiPlugin\Veilletool\Article_Category::getTable(),
        ['plugin_veilletool_categories_id' => (int) $category->fields['id']]
    ) > 0;

    if (!$untouched || $used) {
        Session::addMessageAfterRedirect(
            __(
                'Le thème « Réglementation » a été conservé : il est utilisé ou a été personnalisé. Il fait doublon avec la catégorie « Réglementation » du référentiel technologique.',
                'veilletool'
            ),
            true,
            WARNING
        );
        return;
    }

    $category->delete(['id' => (int) $category->fields['id']], true);

    Session::addMessageAfterRedirect(
        __(
            'Thème « Réglementation » retiré : il faisait doublon avec la catégorie du référentiel technologique.',
            'veilletool'
        ),
        true,
        INFO
    );
}

/**
 * Met l'index de tri de l'observatoire en correspondance avec le calcul du
 * score.
 *
 * Introduit en 1.9.1, rejoué à chaque mise à jour.
 *
 * Le score d'intérêt se calcule à la volée et dépend de l'heure : la base
 * ne peut donc pas en tirer l'ordre d'un index, et doit noter chaque carte
 * retenue par les filtres avant de rendre la première page.
 * Sur une base qui a collecté pendant des mois, cela revenait à parcourir
 * la table entière - contenu des cartes compris - pour afficher quinze
 * vignettes.
 *
 * L'index ajouté ici porte les quatre **entrées** du calcul : les trois
 * composantes stockées et la date de publication.
 * La base peut alors faire son tri en ne lisant que lui, sans toucher aux
 * lignes.
 * Il ne dépend d'aucun réglage : changer les poids, l'horizon de fraîcheur
 * ou les seuils de priorité n'oblige jamais à le reconstruire, puisque
 * c'est la requête, et non l'index, qui porte le barème.
 *
 * La pagination des cartes va avec, côté code : le tri porte désormais sur
 * les seuls identifiants, et le contenu n'est chargé que pour la page
 * affichée.
 *
 * L'étape n'est gardée par aucune version : elle lit les colonnes de
 * l'index en place, les compare à celles qu'annonce Score, et ne touche à
 * rien tant qu'elles concordent.
 * Le jour où le calcul du score lira d'autres colonnes, la mise à jour
 * suivante reconstruira l'index sans qu'on ait à y penser.
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_veilletool_syncScoreIndex(Migration $migration)
{
    $articles = Article::getTable();
    $wanted   = Score::orderColumns();
    $current  = plugin_veilletool_getIndexColumns($articles, Article::SCORE_INDEX);

    if ($current === $wanted) {
        // Déjà en place et en correspondance : rien à faire.
        // C'est ce qui rend l'étape rejouable.
        return;
    }

    if ($current !== []) {
        // L'index existe, mais sur d'autres colonnes : le calcul du score a
        // changé depuis sa pose.
        // `Migration::addKey` ne regarde que le **nom** de la clé et
        // laisserait l'ancienne en place : il faut donc la retirer nous-
        // mêmes, et vider la file avant de reposer la nouvelle - sinon
        // `addKey` voit encore l'ancienne en base et renonce.
        $migration->displayMessage(sprintf(
            __('Index de tri « %s » reconstruit : le calcul du score a changé.', 'veilletool'),
            Article::SCORE_INDEX
        ));

        $migration->dropKey($articles, Article::SCORE_INDEX);
        $migration->migrationOneTable($articles);
    }

    $migration->addKey($articles, $wanted, Article::SCORE_INDEX);
    $migration->migrationOneTable($articles);
}

/**
 * Colonnes portées par un index, dans leur ordre.
 *
 * @param string $table
 * @param string $index
 *
 * @return string[] Vide si l'index n'existe pas
 */
function plugin_veilletool_getIndexColumns(string $table, string $index): array
{
    /** @var DBmysql $DB */
    global $DB;

    // Colonnes qualifiées, et base nommée plutôt que `DATABASE()` : c'est
    // la forme qu'emploie GLPI lui-même pour interroger le catalogue.
    $columns  = [];
    $iterator = $DB->request([
        'SELECT' => ['information_schema.statistics.column_name as COLUMN_NAME'],
        'FROM'   => 'information_schema.statistics',
        'WHERE'  => [
            'information_schema.statistics.table_schema' => $DB->dbdefault,
            'information_schema.statistics.table_name'   => $table,
            'information_schema.statistics.index_name'   => $index,
        ],
        'ORDER'  => ['information_schema.statistics.seq_in_index ASC'],
    ]);

    foreach ($iterator as $row) {
        $columns[] = (string) $row['COLUMN_NAME'];
    }

    return $columns;
}
