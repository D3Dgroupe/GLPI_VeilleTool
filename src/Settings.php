<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use Config;

/**
 * Réglages du plugin, stockés dans la configuration de GLPI.
 *
 * Tout est rangé dans le contexte « plugin:veilletool » de `glpi_configs` :
 * aucune table supplémentaire, et la désinstallation se contente d'effacer
 * le contexte.
 *
 * Deux familles de réglages y cohabitent :
 *
 * - le **score d'intérêt** : poids des quatre composantes, horizon de
 * fraîcheur et seuils de priorité, tous réglables depuis l'écran « Veille →
 * Score d'intérêt ».
 * - l'**état interne** : version du schéma effectivement installée, qui
 * permet de distinguer une installation d'une migration.
 */
class Settings
{
    /** Contexte de configuration utilisé dans glpi_configs */
    public const CONTEXT = 'plugin:veilletool';

    /**
     * Valeurs livrées par défaut.
     *
     * Les quatre poids sont volontairement égaux : le barème est ramené sur
     * 100 quelles que soient les valeurs retenues, seule leur proportion
     * compte.
     *
     * @var array<string, string|int>
     */
    public const DEFAULTS = [
        // Version du schéma installée.
        // Vide tant que rien n'est installé.
        'schema_version'     => '',

        // Poids des composantes du score d'intérêt.
        'weight_source'      => 25,
        'weight_technology'  => 25,
        'weight_category'    => 25,
        'weight_freshness'   => 25,

        // Fraîcheur : une carte garde 100 pendant ce délai, puis perd le
        // nombre de points indiqué par jour supplémentaire.
        'freshness_grace_days'   => 7,
        'freshness_loss_per_day' => 5,

        // Seuils bas de chaque niveau de priorité.
        'priority_high'      => 80,
        'priority_important' => 60,
        'priority_toread'    => 50,
        'priority_info'      => 20,

        // Fond de l'étiquette de chaque niveau.
        // Les valeurs livrées reprennent la palette Tabler.
        // L'exploitant les remplace librement.
        'color_high'         => '#d63939',
        'color_important'    => '#f76707',
        'color_toread'       => '#f59f00',
        'color_info'         => '#4299e1',
        'color_low'          => '#667382',

        // Texte de l'étiquette.
        // Les valeurs livrées sont celles qui contrastent le mieux avec les
        // fonds ci-dessus.
        // Elles se règlent elles aussi librement.
        'text_high'          => '#ffffff',
        'text_important'     => '#000000',
        'text_toread'        => '#000000',
        'text_info'          => '#000000',
        'text_low'           => '#ffffff',

        // Libellé de chaque niveau.
        // Vide = le libellé livré, qui a l'avantage de suivre la langue de
        // l'utilisateur.
        // Toute autre valeur le remplace tel quel.
        'label_high'         => '',
        'label_important'    => '',
        'label_toread'       => '',
        'label_info'         => '',
        'label_low'          => '',

        // Note attribuée à un flux ou à un thème qui n'en porte pas.
        'default_interest'   => 50,

        // Tri appliqué à l'ouverture de l'observatoire.
        'default_sort'       => 'score',
    ];

    /**
     * Réglages portant une couleur, contrôlés comme telle.
     *
     * @var string[]
     */
    public const COLOR_KEYS = [
        'color_high',
        'color_important',
        'color_toread',
        'color_info',
        'color_low',
        'text_high',
        'text_important',
        'text_toread',
        'text_info',
        'text_low',
    ];

    /**
     * Réglages portant un libellé de niveau, bornés en longueur.
     *
     * @var string[]
     */
    public const LABEL_KEYS = [
        'label_high',
        'label_important',
        'label_toread',
        'label_info',
        'label_low',
    ];

    /** Longueur maximale d'un libellé de niveau */
    public const LABEL_MAX_LENGTH = 40;

    /** Réglages chargés une fois pour la durée de la requête */
    private static ?array $cache = null;

    /**
     * Tous les réglages, complétés par les valeurs par défaut.
     *
     * @return array<string, string|int>
     */
    public static function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = Config::getConfigurationValues(self::CONTEXT);
        $values = self::DEFAULTS;

        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $values[$key] = is_int($default) ? (int) $stored[$key] : (string) $stored[$key];
        }

        return self::$cache = self::sanitize($values);
    }

    /**
     * Valeur d'un réglage.
     *
     * @param string $key
     *
     * @return string|int
     */
    public static function get(string $key)
    {
        return self::getAll()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /**
     * Valeur entière d'un réglage.
     *
     * @param string $key
     *
     * @return int
     */
    public static function getInt(string $key): int
    {
        return (int) self::get($key);
    }

    /**
     * Réglages composant le barème du score d'intérêt.
     *
     * L'export « Score d'intérêt » ne transporte que ceux-là : c'est le
     * réglage qu'on met au point une fois et qu'on rejoue d'une instance à
     * l'autre, sans emporter le reste de la configuration.
     *
     * @return string[]
     */
    public static function getScoringKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::DEFAULTS),
            static fn(string $key): bool => $key !== 'schema_version'
                && $key !== 'default_sort'
                && $key !== 'default_interest'
        ));
    }

    /**
     * Réglages exportables, hors état interne.
     *
     * @return string[]
     */
    public static function getExportableKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::DEFAULTS),
            // La version du schéma décrit l'installation, pas un choix de
            // l'exploitant : la transporter ferait croire à une mise à
            // jour.
            static fn(string $key): bool => $key !== 'schema_version'
        ));
    }

    /** Colonnes du format d'échange des réglages */
    public const CSV_HEADER = 'Réglage;Valeur';

    /**
     * Exporte des réglages au format CSV.
     *
     * @param string[] $keys Clés retenues. Vide = tous les exportables
     *
     * @return string
     */
    public static function toCsv(array $keys = []): string
    {
        $keys   = $keys === [] ? self::getExportableKeys() : $keys;
        $values = self::getAll();

        $lines = [self::CSV_HEADER];

        foreach ($keys as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $value = (string) ($values[$key] ?? self::DEFAULTS[$key]);

            $lines[] = Csv::line([$key, $value]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Importe des réglages au format CSV.
     *
     * Les clés inconnues sont signalées et ignorées : un fichier venu d'une
     * autre version ne doit pas introduire de valeur parasite dans la
     * configuration de GLPI, ni faire échouer l'import en entier.
     *
     * @param string   $content
     * @param string[] $allowed Clés acceptées. Vide = toutes les exportables
     *
     * @return array{applied:int, skipped:int, errors:string[]}
     */
    public static function importCsv(string $content, array $allowed = []): array
    {
        $allowed = array_flip($allowed === [] ? self::getExportableKeys() : $allowed);

        // BOM et fins de ligne CRLF des tableurs Windows.
        $content = Csv::normalize($content);

        if (trim($content) === '') {
            return ['applied' => 0, 'skipped' => 0, 'errors' => [__('Fichier vide.', 'veilletool')]];
        }

        $values  = [];
        $skipped = 0;
        $unknown = [];
        $line_no = 0;

        foreach (explode("\n", $content) as $line) {
            $line_no++;

            if (trim($line) === '') {
                continue;
            }

            $parts = Csv::split($line);
            $key   = trim((string) ($parts[0] ?? ''));

            // En-tête du fichier exporté par le plugin.
            if ($line_no === 1 && mb_strtolower($key) === 'réglage') {
                continue;
            }

            if ($key === '') {
                continue;
            }

            if (!isset($allowed[$key])) {
                $skipped++;
                $unknown[$key] = true;
                continue;
            }

            $values[$key] = trim((string) ($parts[1] ?? ''));
        }

        $errors = [];

        if ($unknown !== []) {
            $errors[] = sprintf(
                __('Réglage(s) inconnu(s), ignoré(s) : %s.', 'veilletool'),
                implode(', ', array_slice(array_keys($unknown), 0, 8))
            );
        }

        if ($values === []) {
            $errors[] = __('Aucun réglage exploitable dans ce fichier.', 'veilletool');

            return ['applied' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        // set() borne et contrôle chaque valeur avant écriture : un fichier
        // trafiqué ne peut pas poser de réglage incohérent.
        self::set($values);

        return ['applied' => count($values), 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Applique un jeu de valeurs déjà décodé.
     *
     * Sert à l'import du barème, dont le fichier est lu une fois puis
     * présenté à l'utilisateur avant d'être appliqué : le contenu n'a pas à
     * être redécodé pour l'écriture.
     *
     * @param array<string, string|int> $values
     * @param string[]                  $allowed Clés acceptées. Vide =
     *                                           toutes les exportables
     *
     * @return int Nombre de réglages appliqués
     */
    public static function importCsvValues(array $values, array $allowed = []): int
    {
        $allowed = array_flip($allowed === [] ? self::getExportableKeys() : $allowed);
        $clean   = [];

        foreach ($values as $key => $value) {
            if (isset($allowed[$key])) {
                $clean[$key] = $value;
            }
        }

        if ($clean === []) {
            return 0;
        }

        self::set($clean);

        return count($clean);
    }

    /**
     * Enregistre tout ou partie des réglages.
     *
     * Les clés inconnues sont ignorées : l'écran de réglage ne peut pas
     * introduire de valeur parasite dans la configuration de GLPI.
     *
     * @param array<string, mixed> $values
     *
     * @return void
     */
    public static function set(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $clean[$key] = is_int(self::DEFAULTS[$key]) ? (string) (int) $value : (string) $value;
        }

        if ($clean === []) {
            return;
        }

        // Les bornes sont appliquées avant écriture : la base ne contient
        // jamais de réglage incohérent, même si l'écran est contourné.
        $merged = self::sanitize(array_merge(self::getAll(), array_map(
            static fn($v) => is_numeric($v) ? (int) $v : $v,
            $clean
        )));


        $to_store = [];
        foreach (array_keys($clean) as $key) {
            $to_store[$key] = (string) $merged[$key];
        }

        Config::setConfigurationValues(self::CONTEXT, $to_store);

        self::invalidate();
    }

    /**
     * Oublie les réglages mis en cache.
     *
     * Le score en dérive entièrement et en garde sa propre copie : les deux
     * caches s'oublient ensemble, sans quoi l'écran de réglage afficherait
     * encore l'ancien barème juste après l'avoir changé.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::$cache = null;

        Score::invalidate();
    }

    /**
     * Réglages d'anciennes versions, effacés à chaque installation.
     *
     * Ils ne servent plus à rien mais survivraient à la désinstallation,
     * qui ne connaît que les clés courantes.
     *
     * @var string[]
     */
    public const OBSOLETE_KEYS = [
        // Remplacé en 1.5.2 par freshness_grace_days +
        // freshness_loss_per_day.
        'freshness_days',
    ];

    /**
     * Efface l'intégralité des réglages du plugin, obsolètes compris.
     *
     * @return void
     */
    public static function purge(): void
    {
        Config::deleteConfigurationValues(
            self::CONTEXT,
            array_merge(array_keys(self::DEFAULTS), self::OBSOLETE_KEYS)
        );
        self::invalidate();
    }

    /**
     * Efface les réglages laissés par une version antérieure.
     *
     * @return int Nombre de clés retirées
     */
    public static function purgeObsolete(): int
    {
        $stored  = Config::getConfigurationValues(self::CONTEXT);
        $present = array_values(array_intersect(self::OBSOLETE_KEYS, array_keys($stored)));

        if ($present === []) {
            return 0;
        }

        Config::deleteConfigurationValues(self::CONTEXT, $present);
        self::invalidate();

        return count($present);
    }

    /**
     * Version du schéma effectivement installée.
     *
     * Chaîne vide tant qu'aucune installation n'a eu lieu : c'est ce qui
     * distingue une première installation d'une migration.
     *
     * @return string
     */
    public static function getSchemaVersion(): string
    {
        return (string) self::get('schema_version');
    }

    /**
     * Enregistre la version du schéma installée.
     *
     * @param string $version
     *
     * @return void
     */
    public static function setSchemaVersion(string $version): void
    {
        self::set(['schema_version' => $version]);
    }

    /**
     * Contrôle une couleur saisie et la ramène en notation `#rrggbb`.
     *
     * Le sélecteur de couleur du navigateur produit toujours six chiffres,
     * mais le réglage reste atteignable autrement : une valeur
     * inexploitable retombe sur celle livrée plutôt que de produire une
     * étiquette sans fond.
     *
     * @param string $value
     * @param string $fallback
     *
     * @return string
     */
    public static function normalizeColor(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));

        // Notation courte : #abc vaut #aabbcc.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m) === 1) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * Ramène chaque réglage dans un intervalle exploitable.
     *
     * Les seuils de priorité sont en outre remis en ordre décroissant : un
     * seuil « Important » supérieur au seuil « Prioritaire » rendrait un
     * niveau inatteignable.
     *
     * @param array<string, string|int> $values
     *
     * @return array<string, string|int>
     */
    private static function sanitize(array $values): array
    {
        foreach (['weight_source', 'weight_technology', 'weight_category', 'weight_freshness'] as $key) {
            $values[$key] = max(0, min(100, (int) $values[$key]));
        }

        // Quatre poids nuls rendraient le score indéfini : on rétablit le
        // barème équilibré livré par défaut.
        $total = (int) $values['weight_source']
            + (int) $values['weight_technology']
            + (int) $values['weight_category']
            + (int) $values['weight_freshness'];

        if ($total === 0) {
            $values['weight_source']     = self::DEFAULTS['weight_source'];
            $values['weight_technology'] = self::DEFAULTS['weight_technology'];
            $values['weight_category']   = self::DEFAULTS['weight_category'];
            $values['weight_freshness']  = self::DEFAULTS['weight_freshness'];
        }

        $values['freshness_grace_days']   = max(0, min(3650, (int) $values['freshness_grace_days']));
        // Zéro point par jour est un réglage valable : la fraîcheur cesse
        // alors de peser, sans qu'il faille mettre son poids à zéro.
        $values['freshness_loss_per_day'] = max(0, min(100, (int) $values['freshness_loss_per_day']));
        $values['default_interest']       = max(0, min(100, (int) $values['default_interest']));

        $high      = max(0, min(100, (int) $values['priority_high']));
        $important = max(0, min($high, (int) $values['priority_important']));
        $toread    = max(0, min($important, (int) $values['priority_toread']));
        $info      = max(0, min($toread, (int) $values['priority_info']));

        $values['priority_high']      = $high;
        $values['priority_important'] = $important;
        $values['priority_toread']    = $toread;
        $values['priority_info']      = $info;

        if (!in_array((string) $values['default_sort'], ['score', 'date'], true)) {
            $values['default_sort'] = 'score';
        }

        foreach (self::COLOR_KEYS as $key) {
            $values[$key] = self::normalizeColor(
                (string) $values[$key],
                (string) self::DEFAULTS[$key]
            );
        }

        foreach (self::LABEL_KEYS as $key) {
            // Le libellé est rendu tel quel dans une étiquette : il ne doit
            // porter ni balise, ni retour à la ligne.
            $label = trim(strip_tags(str_replace(["\r", "\n", "\t"], ' ', (string) $values[$key])));
            $label = (string) preg_replace('/\s+/u', ' ', $label);

            $values[$key] = mb_substr($label, 0, self::LABEL_MAX_LENGTH);
        }

        return $values;
    }
}
