<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDropdown;
use GlpiPlugin\Veilletool\Taxonomy\CategoryCode;
use GlpiPlugin\Veilletool\Taxonomy\CategoryIcons;
use GlpiPlugin\Veilletool\Taxonomy\CategoryOrder;
use Session;

/**
 * Catégorie (tag) de veille.
 *
 * Chaque catégorie correspond à un onglet de filtrage dans la liste des
 * cartes (« Cybersécurité », « Infrastructure », ...).
 * L'onglet « Tous » est virtuel et n'est pas stocké en base.
 *
 * Ne subsiste ici que ce qui définit un thème : ses champs, leur validation,
 * son formulaire, et les lectures dont tout le plugin se sert. Le reste vit
 * dans {@see \GlpiPlugin\Veilletool\Taxonomy} :
 *
 * - {@see CategoryCode} dérive et garde unique le code technique ;
 * - {@see CategoryOrder} tient le rang d'affichage ;
 * - {@see CategoryCsv} importe et exporte le référentiel ;
 * - {@see CategoryIcons} fournit la bibliothèque de pictogrammes.
 */
class Category extends CommonDropdown
{
    /**
     * La lecture des catégories suit le droit de veille : tout utilisateur
     * qui consulte les cartes doit pouvoir voir et choisir les catégories.
     * Leur administration reste réservée au droit sur les sources (voir les
     * surcharges ci-dessous).
     */
    public static $rightname = 'plugin_veilletool_veille';

    public $dohistory = true;

    /** Les catégories ne sont pas traduisibles : pas d'onglet « Traductions ». */
    public $can_be_translated = false;

    /**
     * Thèmes actifs chargés une fois pour la durée de la requête.
     *
     * L'affichage d'une liste de cartes les demande au moins deux fois -
     * les onglets de filtrage, puis la décoration de chaque lot.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $active_cache = null;

    /**
     * Oublie les thèmes mis en cache.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::$active_cache = null;
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(Source::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(Source::$rightname, UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(Source::$rightname, DELETE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(Source::$rightname, PURGE);
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie de veille', 'Catégories de veille', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-tags';
    }

    public static function getSectorizedDetails(): array
    {
        return ['veille', 'veille_categories'];
    }

    public function post_getEmpty()
    {
        $this->fields['color']      = '#4b7bec';
        $this->fields['text_color'] = '#ffffff';
        $this->fields['icon']       = 'ti ti-tag';
        $this->fields['is_active'] = 1;
        $this->fields['ranking']   = 0;
        $this->fields['interest']  = Settings::getInt('default_interest');
    }

    public function post_addItem()
    {
        parent::post_addItem();

        self::invalidate();
    }

    public function post_purgeItem()
    {
        parent::post_purgeItem();

        self::invalidate();
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);

        self::invalidate();

        // La note du thème pèse sur le score de toutes les cartes qui le
        // portent : elles repassent dans la file de reclassement.
        if (in_array('interest', $this->updates, true)) {
            Article_Technology::markForReindex([
                'id' => new \Glpi\DBAL\QuerySubQuery([
                    'SELECT' => 'plugin_veilletool_articles_id',
                    'FROM'   => Article_Category::getTable(),
                    'WHERE'  => ['plugin_veilletool_categories_id' => (int) $this->fields['id']],
                ]),
            ]);
        }
    }

    public function getAdditionalFields()
    {
        // Ni `code` ni `ranking` : le premier est calculé à la création et
        // ne change plus, le second se règle en déplaçant les thèmes à la
        // souris sur l'écran des catégories.
        // Deux champs que personne n'avait envie de remplir à la main.
        return [
            [
                // Type non géré par dropdown_form.html.twig : le rendu est
                // délégué à displaySpecificTypeField() ci-dessous, qui
                // présente le fond, le texte, l'aperçu et le contraste d'un
                // seul tenant - les trois se jugent ensemble.
                'name'  => 'color',
                'label' => __('Couleurs de l\'étiquette', 'veilletool'),
                'type'  => 'veillecolors',
                'list'  => true,
            ],
            [
                // Type non géré par dropdown_form.html.twig : le rendu est
                // délégué à displaySpecificTypeField() ci-dessous.
                'name'  => 'icon',
                'label' => __('Icône', 'veilletool'),
                'type'  => 'veilleicon',
                'list'  => true,
            ],
            [
                'name'    => 'interest',
                'label'   => __('Intérêt (0 à 100)', 'veilletool'),
                'type'    => 'integer',
                'list'    => true,
                'min'     => 0,
                'max'     => 100,
                'comment' => __(
                    'Composante « catégorie » du score d\'intérêt des cartes portant ce thème.',
                    'veilletool'
                ),
            ],
            [
                'name'  => 'is_active',
                'label' => __('Actif'),
                'type'  => 'bool',
                'list'  => true,
            ],
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '12',
            'table'    => static::getTable(),
            'field'    => 'color',
            'name'     => __('Couleur', 'veilletool'),
            'datatype' => 'color',
        ];

        $tab[] = [
            'id'       => '17',
            'table'    => static::getTable(),
            'field'    => 'text_color',
            'name'     => __('Couleur du texte', 'veilletool'),
            'datatype' => 'color',
        ];

        $tab[] = [
            'id'       => '13',
            'table'    => static::getTable(),
            'field'    => 'icon',
            'name'     => __('Icône', 'veilletool'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => '15',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '16',
            'table'    => static::getTable(),
            'field'    => 'interest',
            'name'     => __('Intérêt (0 à 100)', 'veilletool'),
            'datatype' => 'number',
        ];

        return $tab;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->normalizeInput($input);

        // Le code est calculé ici, et une seule fois : voir
        // buildUniqueCode().
        $input['code'] = CategoryCode::build(
            (string) ($input['code'] ?? ''),
            (string) ($input['name'] ?? '')
        );

        // Un thème créé se range en fin de liste.
        // Il se déplace ensuite à la souris, sur l'écran des catégories.
        if (!array_key_exists('ranking', $input) || (int) $input['ranking'] <= 0) {
            $input['ranking'] = CategoryOrder::nextRanking();
        }

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->normalizeInput($input);

        // Le code ne bouge plus après la création.
        // Il désigne le thème dans les fichiers importés - flux, cartes XML
        // - et le voir changer parce qu'on a corrigé une faute dans le
        // libellé casserait ces renvois sans prévenir personne.
        unset($input['code']);

        return parent::prepareInputForUpdate($input);
    }

    /**
     * Contrôles communs à la création et à la mise à jour.
     *
     * @param array $input
     *
     * @return array
     */
    private function normalizeInput(array $input): array
    {
        if (array_key_exists('interest', $input)) {
            $input['interest'] = max(0, min(100, (int) $input['interest']));
        }

        // Une couleur inexploitable retombe sur celle livrée plutôt que de
        // produire une étiquette sans fond ou sans texte.
        foreach (['color' => '#4b7bec', 'text_color' => '#ffffff'] as $field => $fallback) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Settings::normalizeColor((string) $input[$field], $fallback);
            }
        }

        return $input;
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = [])
    {
        if (($field['name'] ?? '') === 'color') {
            $this->displayColorFields();
            return;
        }

        if (($field['name'] ?? '') !== 'icon') {
            return;
        }

        $current = (string) ($this->fields['icon'] ?? 'ti ti-tag');
        $rand    = mt_rand();

        echo '<select name="icon" class="form-select" data-veille-icon-picker="1"'
            . ' id="veille-icon-' . $rand . '">';

        $known = false;
        foreach (CategoryIcons::all() as $group => $icons) {
            echo '<optgroup label="' . htmlescape($group) . '">';
            foreach ($icons as $class => $label) {
                $selected = $class === $current;
                $known    = $known || $selected;
                echo '<option value="' . htmlescape($class) . '"'
                    . ($selected ? ' selected' : '') . '>'
                    . htmlescape($label) . '</option>';
            }
            echo '</optgroup>';
        }

        // Une icône saisie avant l'arrivée du sélecteur reste
        // sélectionnable.
        if (!$known && $current !== '') {
            echo '<optgroup label="' . htmlescape(__('Icône actuelle', 'veilletool')) . '">';
            echo '<option value="' . htmlescape($current) . '" selected>'
                . htmlescape($current) . '</option>';
            echo '</optgroup>';
        }

        echo '</select>';
    }

    /**
     * Affiche le couple fond / texte de l'étiquette, son aperçu et le
     * contraste obtenu.
     *
     * Les attributs `data-veille-*` sont ceux de l'écran des niveaux de
     * priorité : le même script anime les deux, sans rien connaître du
     * contexte.
     *
     * @return void
     */
    private function displayColorFields(): void
    {
        $color = Settings::normalizeColor((string) ($this->fields['color'] ?? ''), '#4b7bec');
        $text  = Settings::normalizeColor((string) ($this->fields['text_color'] ?? ''), '#ffffff');
        $name  = trim((string) ($this->fields['name'] ?? ''));
        $icon  = trim((string) ($this->fields['icon'] ?? '')) !== ''
            ? (string) $this->fields['icon']
            : 'ti ti-tag';

        if ($name === '') {
            $name = __('Thème', 'veilletool');
        }

        $contrast = Score::contrastRatio($color, $text);
        $readable = $contrast >= Score::MIN_CONTRAST;

        echo '<div class="d-flex flex-wrap align-items-center gap-2">';

        echo '<span class="badge veille-badge" data-veille-preview="category"'
            . ' style="background-color: ' . htmlescape($color) . '; color: ' . htmlescape($text) . '">'
            . '<i class="' . htmlescape($icon) . '"></i>'
            . '<span data-veille-preview-label>' . htmlescape($name) . '</span>'
            . '</span>';

        echo '<span class="input-group w-auto">';

        echo '<span class="input-group-text" title="' . htmlescape(__('Fond', 'veilletool')) . '">'
            . '<i class="ti ti-paint"></i></span>';
        echo '<input type="color" class="form-control form-control-color" name="color"'
            . ' value="' . htmlescape($color) . '" data-veille-color="category"'
            . ' aria-label="' . htmlescape(__('Couleur de fond', 'veilletool')) . '">';

        echo '<span class="input-group-text" title="' . htmlescape(__('Texte', 'veilletool')) . '">'
            . '<i class="ti ti-typography"></i></span>';
        echo '<input type="color" class="form-control form-control-color" name="text_color"'
            . ' value="' . htmlescape($text) . '" data-veille-text="category"'
            . ' aria-label="' . htmlescape(__('Couleur du texte', 'veilletool')) . '">';

        echo '</span>';

        echo '<span class="veille-contrast ' . ($readable ? 'text-secondary' : 'text-orange') . '"'
            . ' data-veille-contrast="category"'
            . ' data-veille-suggested="' . htmlescape(Score::readableTextColor($color)) . '"'
            . ' title="' . htmlescape(__(
                'Rapport de contraste entre le texte et le fond. 4,5:1 est le seuil de lisibilité recommandé.',
                'veilletool'
            )) . '">'
            . '<i class="ti ' . ($readable ? 'ti-contrast' : 'ti-alert-triangle') . ' me-1"></i>'
            . '<span data-veille-ratio>' . htmlescape((string) round($contrast, 2)) . '</span>:1'
            . '</span>';

        echo '</div>';
    }

    /**
     * Liste ordonnée des catégories actives.
     *
     * @return array<int, array{id:int, name:string, code:string, color:string, text_color:string, icon:string, interest:int}>
     */
    public static function getActiveCategories(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$active_cache !== null) {
            return self::$active_cache;
        }

        $categories = [];
        $iterator   = $DB->request([
            'FROM'   => self::getTable(),
            'WHERE'  => ['is_active' => 1],
            'ORDER'  => ['ranking ASC', 'name ASC'],
        ]);

        foreach ($iterator as $row) {
            $categories[(int) $row['id']] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'code'     => (string) $row['code'],
                'color'      => (string) $row['color'],
                'text_color' => trim((string) $row['text_color']) !== ''
                    ? (string) $row['text_color']
                    : '#ffffff',
                'interest' => (int) $row['interest'],
                // Une catégorie sans icône reçoit l'étiquette générique :
                // les gabarits peuvent alors toujours rendre un <i>.
                'icon'  => trim((string) $row['icon']) !== '' ? (string) $row['icon'] : 'ti ti-tag',
            ];
        }

        return self::$active_cache = $categories;
    }

    /**
     * Nombre de cartes portant chacun des thèmes fournis.
     *
     * @param int[] $categories_ids
     *
     * @return array<int, int>
     */
    public static function countArticles(array $categories_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $categories_ids = array_values(array_filter(array_map('intval', $categories_ids)));

        if ($categories_ids === []) {
            return [];
        }

        $counts   = array_fill_keys($categories_ids, 0);
        $iterator = $DB->request([
            'SELECT'  => [
                'plugin_veilletool_categories_id',
                'COUNT DISTINCT' => 'plugin_veilletool_articles_id AS cpt',
            ],
            'FROM'    => Article_Category::getTable(),
            'WHERE'   => ['plugin_veilletool_categories_id' => $categories_ids],
            'GROUPBY' => ['plugin_veilletool_categories_id'],
        ]);

        foreach ($iterator as $row) {
            $counts[(int) $row['plugin_veilletool_categories_id']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Nombre de flux appliquant chacun des thèmes fournis.
     *
     * @param int[] $categories_ids
     *
     * @return array<int, int>
     */
    public static function countSources(array $categories_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $categories_ids = array_values(array_filter(array_map('intval', $categories_ids)));

        if ($categories_ids === []) {
            return [];
        }

        $counts   = array_fill_keys($categories_ids, 0);
        $iterator = $DB->request([
            'SELECT'  => [
                'plugin_veilletool_categories_id',
                'COUNT DISTINCT' => 'plugin_veilletool_sources_id AS cpt',
            ],
            'FROM'    => Source_Category::getTable(),
            'WHERE'   => ['plugin_veilletool_categories_id' => $categories_ids],
            'GROUPBY' => ['plugin_veilletool_categories_id'],
        ]);

        foreach ($iterator as $row) {
            $counts[(int) $row['plugin_veilletool_categories_id']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Tous les thèmes, actifs ou non, dans l'ordre d'affichage.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAllOrdered(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'ORDER' => ['ranking ASC', 'name ASC'],
            ]) as $row
        ) {
            $row['id'] = (int) $row['id'];
            $rows[]    = $row;
        }

        return $rows;
    }

    /**
     * Retrouve l'identifiant d'une catégorie à partir de son code ou de son
     * nom.
     *
     * @param string $needle
     *
     * @return int 0 si aucune correspondance
     */
    public static function findIdByCodeOrName(string $needle): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $needle = trim($needle);
        if ($needle === '') {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'OR' => [
                    ['code' => CategoryCode::slugify($needle)],
                    ['name' => $needle],
                ],
            ],
            'LIMIT'  => 1,
        ])->current();

        return $row !== null ? (int) $row['id'] : 0;
    }
}
