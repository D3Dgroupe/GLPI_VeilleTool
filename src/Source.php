<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Veilletool\Feed\FeedImporter;
use GlpiPlugin\Veilletool\Feed\PageExtractor;
use GlpiPlugin\Veilletool\Feed\SourceCron;
use GlpiPlugin\Veilletool\Feed\UrlGuard;
use Session;

/**
 * Flux d'alimentation de la veille : RSS ou Atom.
 *
 * Ne subsiste ici que ce qui définit un flux : ses champs, leur validation,
 * ses thèmes, et le déclenchement d'une collecte. Ce qu'on fait d'un
 * ensemble de flux vit dans {@see \GlpiPlugin\Veilletool\Feed} :
 *
 * - {@see SourceCatalog} les importe et les exporte ;
 * - {@see SourceFilter} les cherche dans la liste ;
 * - {@see SourcePurge} les supprime, cartes comprises ;
 * - {@see SourceCron} les relève automatiquement.
 */
class Source extends CommonDBTM
{
    public const TYPE_RSS  = 'rss';
    public const TYPE_ATOM = 'atom';

    /**
     * Notes d'intérêt déjà lues, pour la durée de la requête.
     *
     * @var array<int, int>
     */
    private static array $interests = [];

    public static $rightname = 'plugin_veilletool_source';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Flux RSS / Atom', 'Flux RSS / Atom', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-rss';
    }

    public static function getSectorizedDetails(): array
    {
        return ['veille', 'veille_sources'];
    }

    /**
     * Libellés des types de source.
     *
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_RSS  => __('Flux RSS', 'veilletool'),
            self::TYPE_ATOM => __('Flux Atom', 'veilletool'),
        ];
    }

    /**
     * Libellé d'un type de source.
     *
     * @param string $type
     *
     * @return string
     */
    public static function getTypeLabel(string $type): string
    {
        return self::getTypes()[$type] ?? $type;
    }

    /**
     * Icône associée à un type de source.
     *
     * @param string $type
     *
     * @return string
     */
    public static function getTypeIcon(string $type): string
    {
        return match ($type) {
            self::TYPE_RSS  => 'ti ti-rss',
            self::TYPE_ATOM => 'ti ti-broadcast',
            default         => 'ti ti-world',
        };
    }

    public function post_getEmpty()
    {
        $this->fields['sourcetype'] = self::TYPE_RSS;
        $this->fields['is_active']  = 1;
        $this->fields['max_items']  = 50;
        $this->fields['fetch_full_content'] = 0;
        $this->fields['content_selector']   = '';
        $this->fields['interest']   = Settings::getInt('default_interest');
    }

    /**
     * Note d'intérêt d'un flux, composante « source » du score des cartes.
     *
     * Une carte saisie à la main, ou issue d'un flux effacé depuis, reçoit
     * la note neutre réglée par l'utilisateur.
     *
     * @param int $sources_id
     *
     * @return int Note de 0 à 100
     */
    public static function getInterest(int $sources_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($sources_id <= 0) {
            return Settings::getInt('default_interest');
        }

        // Une collecte enregistre des dizaines de cartes venant toutes du
        // même flux : sans cette mémoire, chacune redemandait la même note
        // à la base.
        if (isset(self::$interests[$sources_id])) {
            return self::$interests[$sources_id];
        }

        $row = $DB->request([
            'SELECT' => ['interest'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => $sources_id],
            'LIMIT'  => 1,
        ])->current();

        return self::$interests[$sources_id] = $row === null
            ? Settings::getInt('default_interest')
            : max(0, min(100, (int) $row['interest']));
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareCommonInput($input);

        if (!isset($input['users_id']) || (int) $input['users_id'] === 0) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * Contrôles communs à la création et à la mise à jour.
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function prepareCommonInput($input)
    {
        if (!is_array($input)) {
            return $input;
        }

        if (isset($input['sourcetype']) && !array_key_exists($input['sourcetype'], self::getTypes())) {
            Session::addMessageAfterRedirect(
                __('Type de source invalide.', 'veilletool'),
                false,
                ERROR
            );
            return false;
        }

        if (array_key_exists('url', $input)) {
            $input['url'] = trim((string) $input['url']);

            if ($input['url'] !== '' && !UrlGuard::isAcceptable($input['url'])) {
                Session::addMessageAfterRedirect(
                    __('L\'URL doit être une adresse http(s) valide.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }

            if ($input['url'] === '') {
                Session::addMessageAfterRedirect(
                    __('L\'URL du flux est obligatoire.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (array_key_exists('content_selector', $input)) {
            $input['content_selector'] = trim((string) $input['content_selector']);

            if (!PageExtractor::isValidSelector($input['content_selector'])) {
                Session::addMessageAfterRedirect(
                    __('Sélecteur de contenu invalide : utilisez un nom d\'élément, une classe (.nom) ou un identifiant (#nom), éventuellement imbriqués (main .corps) ou en variantes séparées par des virgules.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (array_key_exists('fetch_full_content', $input)) {
            $input['fetch_full_content'] = (int) (bool) $input['fetch_full_content'];
        }

        if (isset($input['max_items'])) {
            $input['max_items'] = max(1, min(500, (int) $input['max_items']));
        }

        if (array_key_exists('interest', $input)) {
            $input['interest'] = max(0, min(100, (int) $input['interest']));
        }

        return $input;
    }

    public function post_addItem()
    {
        $this->updateCategoriesFromInput();
    }

    public function post_updateItem($history = true)
    {
        $this->updateCategoriesFromInput();

        // La note du flux est une composante du score de ses cartes : elles
        // sont remises dans la file de reclassement, que la tâche
        // automatique videra par lots.
        if (in_array('interest', $this->updates, true)) {
            unset(self::$interests[(int) $this->fields['id']]);

            Article_Technology::markForReindex([
                'plugin_veilletool_sources_id' => (int) $this->fields['id'],
            ]);
        }
    }

    /**
     * Enregistre les catégories transmises par le formulaire.
     *
     * @return void
     */
    private function updateCategoriesFromInput(): void
    {
        if (!isset($this->input['_categories_defined']) && !isset($this->input['categories'])) {
            return;
        }

        $categories = $this->input['categories'] ?? [];
        if (!is_array($categories)) {
            $categories = [$categories];
        }

        Source_Category::setCategories((int) $this->fields['id'], array_map('intval', $categories));
    }

    public function cleanDBonPurge()
    {
        $sources_id = (int) $this->fields['id'];

        (new Source_Category())->deleteByCriteria(
            ['plugin_veilletool_sources_id' => $sources_id],
            true
        );

        // Les cartes issues de ce flux sont supprimées avec lui.
        // Leur propre cleanDBonPurge se charge des commentaires, favoris et
        // lectures.
        (new Article())->deleteByCriteria(
            ['plugin_veilletool_sources_id' => $sources_id],
            true
        );
    }

    /**
     * Bascule l'activité d'un flux.
     *
     * Le catalogue livré arrive entièrement désactivé : l'activation doit
     * pouvoir se faire depuis la liste, sans ouvrir quarante fiches.
     *
     * @param int $sources_id
     *
     * @return bool|null Nouvel état, ou null si le flux est introuvable
     */
    public static function toggleActive(int $sources_id): ?bool
    {
        $source = new self();

        if ($sources_id <= 0 || !$source->getFromDB($sources_id)) {
            return null;
        }

        $now = (int) $source->fields['is_active'] === 1 ? 0 : 1;

        $source->update([
            'id'          => $sources_id,
            'is_active'   => $now,
            '_no_message' => true,
        ]);

        return $now === 1;
    }

    /**
     * Identifiants des catégories affectées à la source.
     *
     * @return int[]
     */
    public function getCategoryIds(): array
    {
        return Source_Category::getCategoryIds((int) $this->fields['id']);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@veilletool/source_form.html.twig', [
            'item'       => $this,
            'categories' => $ID > 0 ? $this->getCategoryIds() : [],
            'types'      => self::getTypes(),
            'max_pages'  => FeedImporter::MAX_PAGES_PER_COLLECT,
            'params'     => $options + ['canedit' => $this->canUpdateItem()],
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(2),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => static::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'    => '2',
            'table' => static::getTable(),
            'field' => 'id',
            'name'  => __('ID'),
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => static::getTable(),
            'field'    => 'sourcetype',
            'name'     => _n('Type', 'Types', 1),
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => static::getTable(),
            'field'    => 'url',
            'name'     => __('URL'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '6',
            'table'    => static::getTable(),
            'field'    => 'last_import',
            'name'     => __('Dernière collecte', 'veilletool'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '7',
            'table'    => static::getTable(),
            'field'    => 'imported_count',
            'name'     => __('Cartes collectées', 'veilletool'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => '8',
            'table'    => static::getTable(),
            'field'    => 'interest',
            'name'     => __('Intérêt de la source', 'veilletool'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => '16',
            'table'    => static::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => '19',
            'table'    => static::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '121',
            'table'    => static::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Creation date'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'sourcetype') {
            return self::getTypeLabel((string) $values[$field]);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'sourcetype') {
            $options['value'] = $values[$field];
            return \Dropdown::showFromArray($name, self::getTypes(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Déclenche la collecte de cette source.
     *
     * @return array{imported:int, updated:int, errors:string[]}
     */
    public function collect(): array
    {
        $collector = new Collector();
        $result    = $collector->collectSource($this);

        $this->update([
            'id'             => $this->fields['id'],
            'last_import'    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'last_status'    => $result['errors'] === [] ? 'ok' : 'error',
            'last_message'   => $result['errors'] === []
                ? sprintf(
                    __('%1$d nouvelle(s) carte(s), %2$d mise(s) à jour', 'veilletool'),
                    $result['imported'],
                    $result['updated']
                ) . (($result['pages_failed'] ?? 0) > 0
                    ? ' - ' . sprintf(
                        __('%d page(s) d\'article non récupérée(s), résumé du flux conservé', 'veilletool'),
                        $result['pages_failed']
                    )
                    : '')
                : implode(' | ', $result['errors']),
            'imported_count' => (int) $this->fields['imported_count'] + $result['imported'],
        ]);

        return $result;
    }

    /**
     * Nom de la tâche automatique de collecte.
     */
    public const CRON_TASK = 'CollectFeeds';

    /**
     * Informations affichées dans la configuration des actions
     * automatiques.
     *
     * GLPI appelle `<itemtype>::cron<NomDeLaTâche>()` : ces deux méthodes
     * sont une convention de nommage, pas un choix de conception. Elles
     * restent donc ici, et passent la main à {@see SourceCron}.
     *
     * @param string $name
     *
     * @return array<string, string>
     */
    public static function cronInfo($name)
    {
        return SourceCron::describe((string) $name);
    }

    /**
     * Tâche automatique : relève tous les flux actifs.
     *
     * @param CronTask $task
     *
     * @return int 1 si des cartes ont été créées, 0 sinon, -1 en erreur
     */
    public static function cronCollectFeeds(CronTask $task)
    {
        return SourceCron::collectAll($task);
    }

    /**
     * Libellé de la source utilisable dans une carte (« Import manuel » si
     * la carte n'est rattachée à aucune source).
     *
     * @param int $sources_id
     *
     * @return string
     */
    public static function getDisplayName(int $sources_id): string
    {
        return self::getDisplayNames([$sources_id])[$sources_id]
            ?? __('Source supprimée', 'veilletool');
    }

    /**
     * Noms d'affichage de plusieurs flux, en une seule requête.
     *
     * Les listes de cartes en demandent un par carte : les résoudre un à un
     * faisait une requête par ligne affichée.
     *
     * @param int[] $sources_ids
     *
     * @return array<int, string>
     */
    public static function getDisplayNames(array $sources_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        $wanted = [];

        foreach ($sources_ids as $sources_id) {
            $sources_id = (int) $sources_id;

            // Une carte saisie à la main ne vient d'aucun flux.
            if ($sources_id <= 0) {
                $names[$sources_id] = __('Saisie manuelle', 'veilletool');
                continue;
            }

            $wanted[$sources_id] = true;
        }

        if ($wanted === []) {
            return $names;
        }

        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['id' => array_keys($wanted)],
        ]);

        foreach ($iterator as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        // Un flux supprimé laisse ses cartes derrière lui : elles doivent
        // rester lisibles.
        foreach (array_keys($wanted) as $sources_id) {
            $names[$sources_id] ??= __('Source supprimée', 'veilletool');
        }

        return $names;
    }
}
