<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;

/**
 * Référentiel des technologies suivies.
 *
 * Le référentiel général **qualifie** : il dit de quoi parle une carte.
 * Celui-ci **sélectionne** : il dit lesquelles de ces qualifications nous
 * intéressent.
 *
 * Le suivi porte sur deux niveaux, et deux seulement :
 *
 * - la **famille** - « Linux - Debian », « Normes ISO / IEC ».
 * - la **sous-catégorie**, c'est-à-dire une technologie précise - « GLPI 11
 * ».
 *
 * La **catégorie** n'est pas suivable : « Application » ou « OS »
 * regroupent des centaines d'entrées sans rapport entre elles, et les
 * suivre revenait à ne rien filtrer du tout.
 *
 * Les colonnes reprennent exactement celles de {@see Article_Technology},
 * ce qui réduit le filtrage à une comparaison directe : une ligne dont
 * `name` est vide désigne toute la famille, une ligne renseignée désigne
 * cette entrée seule.
 */
class FollowedTechnology extends CommonDBTM
{
    /**
     * Le suivi relève de l'administration du référentiel, comme le
     * référentiel lui-même.
     */
    public static $rightname = 'plugin_veilletool_source';

    public $dohistory = false;

    /** Suivi d'une famille entière */
    public const LEVEL_FAMILY = 'family';

    /** Suivi d'une technologie précise */
    public const LEVEL_ENTRY = 'entry';

    /**
     * Index des suivis, construit une fois par requête.
     *
     * @var array<string, bool>|null
     */
    private static ?array $index = null;

    /**
     * Lignes de suivi, mises en cache pour la durée de la requête.
     *
     * @var array<int, array{categorie:string, famille:string, name:string}>|null
     */
    private static ?array $cache = null;

    public static function getTypeName($nb = 0)
    {
        return _n('Technologie suivie', 'Technologies suivies', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-target-arrow';
    }

    /**
     * Oublie les suivis mis en cache.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::$cache = null;
        self::$index = null;
    }

    /**
     * Tous les suivis enregistrés.
     *
     * @return array<int, array{categorie:string, famille:string, name:string}>
     */
    public static function getAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows     = [];
        $iterator = $DB->request([
            'SELECT' => ['categorie', 'famille', 'name'],
            'FROM'   => self::getTable(),
            'ORDER'  => ['categorie ASC', 'famille ASC', 'name ASC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = [
                'categorie' => (string) $row['categorie'],
                'famille'   => (string) $row['famille'],
                'name'      => (string) $row['name'],
            ];
        }

        return self::$cache = $rows;
    }

    /**
     * Index de consultation immédiate.
     *
     * Une clé à deux segments désigne une famille, une clé à trois segments
     * une technologie précise.
     *
     * @return array<string, bool>
     */
    private static function getIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (self::getAll() as $row) {
            $key = $row['name'] === ''
                ? $row['categorie'] . '|' . $row['famille']
                : $row['categorie'] . '|' . $row['famille'] . '|' . $row['name'];

            $index[$key] = true;
        }

        return self::$index = $index;
    }

    /**
     * Une famille est-elle suivie ?
     *
     * @param string $categorie
     * @param string $famille
     *
     * @return bool
     */
    public static function isFamilyFollowed(string $categorie, string $famille): bool
    {
        return isset(self::getIndex()[$categorie . '|' . $famille]);
    }

    /**
     * Une technologie précise est-elle suivie pour elle-même ?
     *
     * Répond faux pour une entrée seulement couverte par le suivi de sa
     * famille : les deux niveaux sont indépendants, et l'écran de réglage
     * doit pouvoir les distinguer.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name
     *
     * @return bool
     */
    public static function isEntryFollowed(string $categorie, string $famille, string $name): bool
    {
        return $name !== ''
            && isset(self::getIndex()[$categorie . '|' . $famille . '|' . $name]);
    }

    /**
     * Un rattachement technologique tombe-t-il dans le périmètre suivi ?
     *
     * Une sous-catégorie est retenue si elle est suivie pour elle-même
     * **ou** si sa famille l'est.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name Vide lorsque le rattachement s'arrête à la famille
     *
     * @return bool
     */
    public static function covers(string $categorie, string $famille, string $name): bool
    {
        return self::isFamilyFollowed($categorie, $famille)
            || self::isEntryFollowed($categorie, $famille, $name);
    }

    /**
     * Ajoute un suivi.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name Vide pour suivre toute la famille
     *
     * @return bool
     */
    public static function follow(string $categorie, string $famille, string $name = ''): bool
    {
        $categorie = trim($categorie);
        $famille   = trim($famille);
        $name      = trim($name);

        if ($categorie === '' || $famille === '') {
            return false;
        }

        if (self::exists($categorie, $famille, $name)) {
            return true;
        }

        $follow = new self();
        $added  = $follow->add([
            'categorie'   => mb_substr($categorie, 0, 100),
            'famille'     => mb_substr($famille, 0, 190),
            'name'        => mb_substr($name, 0, 190),
            '_no_message' => true,
        ]);

        self::invalidate();

        return (bool) $added;
    }

    /**
     * Retire un suivi.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name Vide pour cesser de suivre toute la famille
     *
     * @return bool
     */
    public static function unfollow(string $categorie, string $famille, string $name = ''): bool
    {
        $removed = (new self())->deleteByCriteria([
            'categorie' => trim($categorie),
            'famille'   => trim($famille),
            'name'      => trim($name),
        ], true);

        self::invalidate();

        return (bool) $removed;
    }

    /**
     * Bascule un suivi.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name Vide pour la famille entière
     *
     * @return bool Nouvel état : vrai si la cible est désormais suivie
     */
    public static function toggle(string $categorie, string $famille, string $name = ''): bool
    {
        if (self::exists($categorie, $famille, $name)) {
            self::unfollow($categorie, $famille, $name);
            return false;
        }

        self::follow($categorie, $famille, $name);

        return true;
    }

    /**
     * Ce suivi exact est-il déjà enregistré ?
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name
     *
     * @return bool
     */
    private static function exists(string $categorie, string $famille, string $name): bool
    {
        $key = trim($name) === ''
            ? trim($categorie) . '|' . trim($famille)
            : trim($categorie) . '|' . trim($famille) . '|' . trim($name);

        return isset(self::getIndex()[$key]);
    }

    /**
     * Nombre de familles suivies.
     *
     * @return int
     */
    public static function countFamilies(): int
    {
        return countElementsInTable(self::getTable(), ['name' => '']);
    }

    /**
     * Nombre de technologies suivies pour elles-mêmes.
     *
     * @return int
     */
    public static function countEntries(): int
    {
        return countElementsInTable(self::getTable(), ['NOT' => ['name' => '']]);
    }

    /**
     * Conditions de filtrage applicables à la table de classification.
     *
     * Les familles suivies sont regroupées par catégorie, et les sous-
     * catégories par famille : le périmètre tient en quelques conditions,
     * même avec une centaine de suivis.
     *
     * Une entrée dont la famille est déjà suivie n'ajoute aucune condition
     * : elle est déjà couverte.
     *
     * @return array<int, array<string, mixed>> Tableau vide si rien n'est suivi
     */
    public static function buildLinkConditions(): array
    {
        $families = [];
        $entries  = [];

        foreach (self::getAll() as $row) {
            if ($row['name'] === '') {
                $families[$row['categorie']][] = $row['famille'];
                continue;
            }

            $key = $row['categorie'] . '|' . $row['famille'];

            $entries[$key]['categorie'] = $row['categorie'];
            $entries[$key]['famille']   = $row['famille'];
            $entries[$key]['names'][]   = $row['name'];
        }

        $conditions = [];

        foreach ($families as $categorie => $familles) {
            $conditions[] = ['categorie' => $categorie, 'famille' => $familles];
        }

        foreach ($entries as $entry) {
            $covered = isset($families[$entry['categorie']])
                && in_array($entry['famille'], $families[$entry['categorie']], true);

            if ($covered) {
                continue;
            }

            $conditions[] = [
                'categorie' => $entry['categorie'],
                'famille'   => $entry['famille'],
                'name'      => $entry['names'],
            ];
        }

        return $conditions;
    }

    /**
     * Supprime les suivis dont la cible a disparu du référentiel.
     *
     * Un suivi de famille est conservé tant que la famille existe.
     * Un suivi de sous-catégorie disparaît avec elle.
     *
     * @return int Nombre de suivis retirés
     */
    public static function purgeOrphans(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $known_families = [];
        $known_entries  = [];

        $iterator = $DB->request([
            'SELECT' => ['categorie', 'famille', 'name'],
            'FROM'   => Technology::getTable(),
        ]);

        foreach ($iterator as $row) {
            $known_families[$row['categorie'] . '|' . $row['famille']] = true;
            $known_entries[$row['categorie'] . '|' . $row['famille'] . '|' . $row['name']] = true;
        }

        $follow  = new self();
        $removed = 0;

        foreach (self::getAll() as $row) {
            $alive = $row['name'] === ''
                ? isset($known_families[$row['categorie'] . '|' . $row['famille']])
                : isset($known_entries[$row['categorie'] . '|' . $row['famille'] . '|' . $row['name']]);

            if ($alive) {
                continue;
            }

            $follow->deleteByCriteria([
                'categorie' => $row['categorie'],
                'famille'   => $row['famille'],
                'name'      => $row['name'],
            ], true);

            $removed++;
        }

        if ($removed > 0) {
            self::invalidate();
        }

        return $removed;
    }

    /**
     * Résumé du périmètre suivi, prêt à afficher.
     *
     * @return array{families:int, entries:int}
     */
    public static function summarize(): array
    {
        return [
            'families' => self::countFamilies(),
            'entries'  => self::countEntries(),
        ];
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => '1',
            'table'         => static::getTable(),
            'field'         => 'name',
            'name'          => __('Sous-catégorie', 'veilletool'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '2',
            'table'    => static::getTable(),
            'field'    => 'categorie',
            'name'     => __('Catégorie', 'veilletool'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => static::getTable(),
            'field'    => 'famille',
            'name'     => __('Famille', 'veilletool'),
            'datatype' => 'string',
        ];

        return $tab;
    }
}
