<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use Session;
use Toolbox;

/**
 * Entrée du référentiel technologique : un triplet Catégorie / Famille /
 * Sous-catégorie.
 *
 * Ce référentiel est indépendant des thèmes portés par les cartes.
 * Il sert à rapprocher automatiquement le texte d'une carte des
 * technologies et des réglementations suivies, afin d'alimenter la vue
 * statistique.
 */
class Technology extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_source';

    public $dohistory = false;

    /** Libellé employé pour les cartes ne correspondant à aucune entrée */
    public const OTHER = 'Autre';

    /** Filtre de suivi : tout le référentiel */
    public const FOLLOW_ANY = '';

    /** Filtre de suivi : les entrées couvertes par le référentiel de suivi */
    public const FOLLOW_YES = 'yes';

    /** Filtre de suivi : les entrées qui ne le sont pas */
    public const FOLLOW_NO = 'no';

    /**
     * Valeurs proposées par le filtre de suivi.
     *
     * « Suivies » retient ce que couvre le référentiel de suivi, qu'une
     * entrée le soit pour elle-même ou par sa famille : c'est le périmètre
     * que retient l'observatoire, et donc celui qu'il faut pouvoir
     * vérifier.
     *
     * @return array<string, string>
     */
    public static function getFollowFilterModes(): array
    {
        return [
            self::FOLLOW_ANY => __('Toutes', 'veilletool'),
            self::FOLLOW_YES => __('Suivies uniquement', 'veilletool'),
            self::FOLLOW_NO  => __('Non suivies', 'veilletool'),
        ];
    }

    /**
     * Familles du référentiel, avec leur volume : c'est la matière du
     * panneau de suivi par famille.
     *
     * @param string $categorie Restreint à une catégorie. Vide = toutes
     * @param string $keyword   Restreint aux familles dont le nom contient
     *                          ce mot.
     * Vide = toutes
     *
     * @return array<int, array{categorie:string, famille:string, entries:int}>
     */
    public static function getFamiliesWithVolume(string $categorie = '', string $keyword = ''): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [];

        if (trim($categorie) !== '') {
            $where['categorie'] = trim($categorie);
        }

        if (trim($keyword) !== '') {
            $where['famille'] = ['LIKE', '%' . trim($keyword) . '%'];
        }

        $rows     = [];
        $iterator = $DB->request([
            'SELECT'  => [
                'categorie',
                'famille',
                'COUNT' => 'id AS entries',
            ],
            'FROM'    => self::getTable(),
            'WHERE'   => $where,
            'GROUPBY' => ['categorie', 'famille'],
            'ORDER'   => ['categorie ASC', 'famille ASC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = [
                'categorie' => (string) $row['categorie'],
                'famille'   => (string) $row['famille'],
                'entries'   => (int) $row['entries'],
            ];
        }

        return $rows;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Technologie', 'Technologies', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-binary-tree';
    }

    public static function getSectorizedDetails(): array
    {
        return ['veille', 'veille_technologies'];
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareCommonInput($input);
    }

    public function post_addItem()
    {
        TechnologyMatcher::invalidate();
    }

    public function post_updateItem($history = true)
    {
        TechnologyMatcher::invalidate();
    }

    public function post_deleteFromDB()
    {
        TechnologyMatcher::invalidate();

        $categorie = (string) ($this->fields['categorie'] ?? '');
        $famille   = (string) ($this->fields['famille'] ?? '');
        $name      = (string) ($this->fields['name'] ?? '');

        // L'entrée disparaît : le suivi qui la visait n'a plus d'objet.
        // Le suivi de sa famille, lui, survit tant que la famille existe.
        FollowedTechnology::unfollow($categorie, $famille, $name);

        // Les cartes qui y étaient rattachées la perdent aussi.
        // Le reclassement s'en chargerait pour les rapprochements
        // automatiques, mais pas pour ceux ajoutés à la main, qui lui
        // survivent.
        Article_Technology::forgetEntry($categorie, $famille, $name);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * Normalise et valide les trois niveaux du référentiel.
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

        foreach (['categorie', 'famille', 'name'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = mb_substr(trim((string) $input[$field]), 0, $field === 'categorie' ? 100 : 190);
            }
        }

        foreach (['categorie', 'famille', 'name'] as $field) {
            $value = $input[$field] ?? ($this->fields[$field] ?? '');
            if (trim((string) $value) === '') {
                Session::addMessageAfterRedirect(
                    __('Catégorie, famille et sous-catégorie sont toutes les trois obligatoires.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        return $input;
    }

    /**
     * Liste des catégories présentes dans le référentiel.
     *
     * @return string[]
     */
    public static function getCategories(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $values   = [];
        $iterator = $DB->request([
            'SELECT'   => ['categorie'],
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
            'ORDER'    => ['categorie ASC'],
        ]);

        foreach ($iterator as $row) {
            $values[] = (string) $row['categorie'];
        }

        return $values;
    }

    /**
     * Liste des familles, éventuellement restreinte à une catégorie.
     *
     * @param string $categorie
     *
     * @return string[]
     */
    public static function getFamilies(string $categorie = ''): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT'   => ['famille'],
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
            'ORDER'    => ['famille ASC'],
        ];

        if ($categorie !== '') {
            $criteria['WHERE'] = ['categorie' => $categorie];
        }

        $values = [];
        foreach ($DB->request($criteria) as $row) {
            $values[] = (string) $row['famille'];
        }

        return $values;
    }

    /**
     * Charge l'intégralité du référentiel actif, pour la construction de
     * l'index de correspondance.
     *
     * @return array<int, array{id:int, categorie:string, famille:string, name:string}>
     */
    public static function getAllActive(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows     = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'categorie', 'famille', 'name'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['is_active' => 1],
        ]);

        foreach ($iterator as $row) {
            $rows[] = [
                'id'        => (int) $row['id'],
                'categorie' => (string) $row['categorie'],
                'famille'   => (string) $row['famille'],
                'name'      => (string) $row['name'],
            ];
        }

        return $rows;
    }

    /** Colonnes du format d'échange du référentiel */
    public const CSV_HEADER = 'Catégorie;Famille;Sous-catégorie';

    /**
     * Exporte le référentiel au format CSV.
     *
     * Le fichier produit est celui que sait relire `importCsv()`, et c'est
     * aussi celui livré avec le plugin.
     *
     * @return string
     */
    public static function toCsv(): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $lines = [self::CSV_HEADER];

        $iterator = $DB->request([
            'SELECT' => ['categorie', 'famille', 'name'],
            'FROM'   => self::getTable(),
            'ORDER'  => ['categorie ASC', 'famille ASC', 'name ASC'],
        ]);

        foreach ($iterator as $row) {
            $lines[] = Csv::line([
                (string) $row['categorie'],
                (string) $row['famille'],
                (string) $row['name'],
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Nombre d'entrées du référentiel répondant à des critères.
     *
     * @param array $where Critères GLPI. Vide = tout le référentiel
     *
     * @return int
     */
    public static function countForPurge(array $where): int
    {
        return countElementsInTable(self::getTable(), $where);
    }

    /**
     * Supprime définitivement un lot d'entrées du référentiel.
     *
     * La suppression se fait en une requête plutôt qu'entrée par entrée :
     * un référentiel complet en compte plusieurs milliers, et passer par
     * `CommonDBTM::delete()` pour chacune déclencherait autant de fois les
     * mêmes nettoyages.
     * Ceux-ci sont donc faits une seule fois, à la fin :
     *
     * - les suivis qui ne visent plus rien sont retirés.
     * - les rattachements manuels orphelins le sont aussi - les
     * rattachements automatiques, eux, se refont d'eux-mêmes.
     * - toutes les cartes repassent dans la file de reclassement.
     *
     * @param array $where Critères GLPI. Vide = tout le référentiel
     *
     * @return array{entries:int, follows:int, links:int, pending:int}
     */
    public static function purgeByCriteria(array $where): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request(['SELECT' => ['id'], 'FROM' => self::getTable(), 'WHERE' => $where]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        if ($ids === []) {
            return ['entries' => 0, 'follows' => 0, 'links' => 0, 'pending' => 0];
        }

        // Un référentiel complet représente plusieurs milliers de lignes :
        // on desserre la limite de temps, comme le fait GLPI pour ses
        // propres traitements longs.
        $max_execution_time = (int) ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 300) {
            Toolbox::safeIniSet('max_execution_time', '300');
        }

        $entries = 0;

        $DB->beginTransaction();

        try {
            // Par lots : une clause IN de plusieurs milliers d'identifiants
            // n'est pas raisonnable.
            foreach (array_chunk($ids, 500) as $chunk) {
                $DB->delete(self::getTable(), ['id' => $chunk]);
                $entries += $DB->affectedRows();
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        TechnologyMatcher::invalidate();

        $follows = FollowedTechnology::purgeOrphans();
        $links   = Article_Technology::forgetOrphanManualLinks();
        $pending = Article_Technology::markForReindex();

        return [
            'entries' => $entries,
            'follows' => $follows,
            'links'   => $links,
            'pending' => $pending,
        ];
    }

    /**
     * Importe un référentiel au format CSV « Catégorie;Famille;Sous-
     * catégorie ».
     *
     * Les lignes déjà connues sont ignorées : l'import est rejouable sans
     * créer de doublon, la clé d'unicité portant sur le triplet complet.
     *
     * @param string $content Contenu brut du fichier
     * @param bool   $replace Vider le référentiel avant l'import
     *
     * @return array{imported:int, skipped:int, errors:string[]}
     */
    public static function importCsv(string $content, bool $replace = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        // BOM et fins de ligne CRLF des tableurs Windows.
        $content = Csv::normalize($content);

        if (trim($content) === '') {
            return ['imported' => 0, 'skipped' => 0, 'errors' => [__('Fichier vide.', 'veilletool')]];
        }

        $technology = new self();

        // Un référentiel complet représente plusieurs milliers de lignes,
        // et le remplacement y ajoute autant de suppressions : on desserre
        // la limite de temps, comme le fait GLPI pour ses traitements
        // longs.
        $max_execution_time = (int) ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 300) {
            Toolbox::safeIniSet('max_execution_time', '300');
        }

        // Chaque ligne passe par CommonDBTM, mais l'ensemble tient dans une
        // seule transaction : sans cela, deux mille validations disque
        // successives feraient durer l'import plusieurs dizaines de
        // secondes.
        $DB->beginTransaction();

        try {
            if ($replace) {
                $technology->deleteByCriteria(['id' => ['>', 0]], true);
            }

            // Le référentiel existant sert de garde contre les doublons,
            // sans interroger la base à chaque ligne.
            $known = [];
            foreach (self::getAllActive() as $row) {
                $known[self::unicityKey($row['categorie'], $row['famille'], $row['name'])] = true;
            }

            $line_no = 0;

            foreach (explode("\n", $content) as $line) {
                $line_no++;

                if (trim($line) === '') {
                    continue;
                }

                $parts = Csv::split($line);

                // En-tête du fichier fourni avec le plugin.
                if ($line_no === 1 && isset($parts[0]) && mb_strtolower(trim($parts[0])) === 'catégorie') {
                    continue;
                }

                if (count($parts) < 3) {
                    $errors[] = sprintf(__('Ligne %d ignorée : trois colonnes attendues.', 'veilletool'), $line_no);
                    continue;
                }

                $categorie = mb_substr(trim((string) $parts[0]), 0, 100);
                $famille   = mb_substr(trim((string) $parts[1]), 0, 190);
                $name      = mb_substr(trim((string) $parts[2]), 0, 190);

                if ($categorie === '' || $famille === '' || $name === '') {
                    $errors[] = sprintf(__('Ligne %d ignorée : une colonne est vide.', 'veilletool'), $line_no);
                    continue;
                }

                $key = self::unicityKey($categorie, $famille, $name);
                if (isset($known[$key])) {
                    $skipped++;
                    continue;
                }
                $known[$key] = true;

                $added = $technology->add([
                    'categorie'   => $categorie,
                    'famille'     => $famille,
                    'name'        => $name,
                    'is_active'   => 1,
                    // Import de masse : pas de message de confirmation par
                    // ligne.
                    '_no_message' => true,
                ]);

                if ($added) {
                    $imported++;
                } else {
                    $errors[] = sprintf(__('Ligne %d : enregistrement refusé.', 'veilletool'), $line_no);
                }
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        // Un référentiel remplacé laisse des suivis qui ne visent plus
        // rien.
        if ($replace) {
            FollowedTechnology::purgeOrphans();
        }

        if (count($errors) > 5) {
            $extra    = count($errors) - 5;
            $errors   = array_slice($errors, 0, 5);
            $errors[] = sprintf(__('... et %d autre(s) ligne(s) ignorée(s).', 'veilletool'), $extra);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Clé d'unicité d'un triplet, insensible à la casse.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name
     *
     * @return string
     */
    private static function unicityKey(string $categorie, string $famille, string $name): string
    {
        return mb_strtolower($categorie . '|' . $famille . '|' . $name);
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
            'datatype'      => 'itemlink',
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

        $tab[] = [
            'id'       => '4',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif'),
            'datatype' => 'bool',
        ];

        return $tab;
    }
}
