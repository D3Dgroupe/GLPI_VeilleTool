<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Csv;
use GlpiPlugin\Veilletool\Settings;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Source_Category;

/**
 * Le format d'échange des flux : catalogue livré, export et import CSV.
 *
 * Trois chemins mènent à un flux enregistré - le catalogue livré avec le
 * plugin, un fichier déposé par l'administrateur, l'export d'une autre
 * instance - et les trois lisent le même format. Ils partagent donc tout :
 * la reconnaissance d'un flux déjà connu par son adresse, la traduction des
 * thèmes, la lecture des drapeaux d'activité.
 *
 * C'est aussi ici que se tient la règle qui compte : **un flux importé
 * arrive désactivé**. Ce qu'une organisation suit lui appartient, et rien ne
 * doit se mettre à collecter sans qu'on l'ait décidé.
 */
final class SourceCatalog
{
    /**
     * Catalogue de flux livré avec le plugin.
     *
     * Une installation neuve part sinon d'un écran vide, et il faut aller
     * chercher une à une les adresses des CERT, des éditeurs et de la
     * presse spécialisée.
     * Le catalogue les rassemble - mais **tous ses flux arrivent
     * désactivés** : ce qu'une organisation suit lui appartient, et rien ne
     * doit se mettre à collecter sans qu'on l'ait décidé.
     */
    public const CATALOG_FILE = __DIR__ . '/../install/data/sources_veille.csv';

    /** Séparateur des thèmes dans une ligne de catalogue ou de CSV importé */
    private const CSV_CATEGORY_SEPARATOR = '|';

    /** Taille maximale acceptée pour un fichier de flux importé (1 Mio) */
    public const CSV_MAX_SIZE = 1048576;

    /** Colonnes du format d'échange des flux */
    public const CSV_HEADER = 'Nom;URL;Type;Thèmes;Intérêt;Actif;Description';

    /**
     * Exporte les flux au format CSV.
     *
     * Le fichier produit est celui que sait relire `importCsv()` : exporter
     * puis réimporter sur une autre instance reconstitue les mêmes flux,
     * avec leurs thèmes, leur note d'intérêt et leur état d'activité.
     *
     * @return string
     */
    public static function toCsv(): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request(['FROM' => Source::getTable(), 'ORDER' => ['name ASC']]) as $row
        ) {
            $rows[(int) $row['id']] = $row;
        }

        // Tous les thèmes, actifs ou non : un thème désactivé reste
        // appliqué aux flux qui le portent, et l'export doit le dire.
        $codes = [];
        foreach (Category::getAllOrdered() as $category) {
            $codes[(int) $category['id']] = (string) $category['code'];
        }

        $links = Source_Category::getCategoryIdsForSources(array_keys($rows));

        $lines = [self::CSV_HEADER];

        foreach ($rows as $sources_id => $row) {
            // Les thèmes sont exportés par leur **code**, pas par leur
            // libellé : c'est lui qui ne bouge pas, et l'import sait lire
            // les deux.
            $themes = [];
            foreach ($links[$sources_id] ?? [] as $categories_id) {
                if (isset($codes[$categories_id])) {
                    $themes[] = $codes[$categories_id];
                }
            }

            $lines[] = Csv::line([
                    (string) $row['name'],
                    (string) $row['url'],
                    (string) $row['sourcetype'],
                    implode(self::CSV_CATEGORY_SEPARATOR, $themes),
                    (string) (int) $row['interest'],
                    (string) (int) $row['is_active'],
                    (string) ($row['comment'] ?? ''),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Charge le catalogue de flux livré avec le plugin.
     *
     * @return array{imported:int, skipped:int, errors:string[]}
     */
    public static function importCatalog(): array
    {
        $path = self::CATALOG_FILE;

        if (!is_file($path) || !is_readable($path)) {
            return [
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => [__('Catalogue de flux introuvable.', 'veilletool')],
            ];
        }

        return self::importCsv((string) file_get_contents($path));
    }

    /**
     * Nombre de flux du catalogue livré.
     *
     * Sert à annoncer ce qu'un chargement apporterait, sans l'exécuter.
     *
     * @return int
     */
    public static function countCatalog(): int
    {
        $path = self::CATALOG_FILE;

        if (!is_file($path) || !is_readable($path)) {
            return 0;
        }

        // Un tableur ayant réenregistré le fichier y laisse un BOM, qui
        // collerait à l'en-tête et le rendrait méconnaissable.
        $content = Csv::normalize((string) file_get_contents($path));

        $lines = 0;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            // Ligne vide, ou en-tête du fichier : ni l'une ni l'autre n'est
            // un flux.
            if ($line === '' || mb_stripos($line, 'Nom;') === 0) {
                continue;
            }

            $lines++;
        }

        return $lines;
    }

    /**
     * Importe des flux depuis un CSV.
     *
     * Colonnes attendues, séparées par un point-virgule : `Nom ; URL ; Type
     * ; Thèmes ; Intérêt ; Actif ; Description`.
     * Seules les deux premières sont obligatoires.
     *
     * - **Type** - `rss` ou `atom`.
     * Vide, le type est déduit de l'adresse, et `rss` à défaut.
     * Il ne sert qu'à l'affichage : l'analyseur reconnaît seul le format
     * réellement servi.
     * - **Thèmes** - codes ou noms de catégories de veille, séparés par une
     * barre verticale.
     * Un thème inconnu est signalé, la ligne reste importée.
     * - **Intérêt** - 0 à 100.
     * Vide, la note par défaut des réglages.
     * - **Actif** - `1` / `oui` pour activer. **Vide vaut désactivé** : un
     * import ne doit pas mettre en route une collecte que personne n'a
     * demandée.
     * - **Description** - ce que le flux publie, et ce qu'il vaut.
     * C'est ce qui permet de choisir quoi activer sans avoir à ouvrir
     * chaque adresse.
     *
     * L'URL fait foi pour la déduplication : réimporter le même fichier
     * n'ajoute rien.
     *
     * @param string $content Contenu brut du fichier
     *
     * @return array{imported:int, skipped:int, errors:string[]}
     */
    public static function importCsv(string $content): array
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

        // Les flux déjà connus servent de garde contre les doublons, sans
        // interroger la base à chaque ligne.
        $known = [];
        foreach (
            $DB->request(['SELECT' => ['url'], 'FROM' => Source::getTable()]) as $row
        ) {
            $known[self::urlKey((string) $row['url'])] = true;
        }

        $source           = new Source();
        $default_interest = Settings::getInt('default_interest');
        $unknown_themes   = [];
        $line_no          = 0;

        $DB->beginTransaction();

        try {
            foreach (explode("\n", $content) as $line) {
                $line_no++;

                if (trim($line) === '') {
                    continue;
                }

                $parts = Csv::split($line);

                // En-tête du fichier livré avec le plugin.
                if ($line_no === 1 && isset($parts[0]) && mb_strtolower(trim($parts[0])) === 'nom') {
                    continue;
                }

                $name = mb_substr(trim((string) ($parts[0] ?? '')), 0, 255);
                $url  = trim((string) ($parts[1] ?? ''));

                if ($url === '') {
                    $errors[] = sprintf(
                        __('Ligne %d ignorée : nom et URL sont attendus.', 'veilletool'),
                        $line_no
                    );
                    continue;
                }

                if (!UrlGuard::isAcceptable($url)) {
                    $errors[] = sprintf(
                        __('Ligne %d ignorée : « %s » n\'est pas une adresse http(s) exploitable.', 'veilletool'),
                        $line_no,
                        $url
                    );
                    continue;
                }

                $key = self::urlKey($url);
                if (isset($known[$key])) {
                    $skipped++;
                    continue;
                }
                $known[$key] = true;

                // Un flux sans nom reste identifiable par son domaine.
                if ($name === '') {
                    $name = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
                }

                $interest = trim((string) ($parts[4] ?? ''));

                $input = [
                    'name'        => $name,
                    'url'         => $url,
                    'sourcetype'  => self::normalizeType((string) ($parts[2] ?? ''), $url),
                    'interest'    => $interest === '' ? $default_interest : (int) $interest,
                    'max_items'   => 50,
                    'is_active'   => self::parseFlag((string) ($parts[5] ?? '')) ? 1 : 0,
                    // La description explique ce que le flux publie : elle
                    // est ce qui permet d'activer en connaissance de cause.
                    'comment'     => trim((string) ($parts[6] ?? '')),
                    '_no_message' => true,
                ];

                [$categories, $unknown] = self::resolveCategories((string) ($parts[3] ?? ''));

                if ($categories !== []) {
                    $input['categories'] = $categories;
                }

                foreach ($unknown as $theme) {
                    $unknown_themes[$theme] = true;
                }

                if ($source->add($input)) {
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

        if ($unknown_themes !== []) {
            $errors[] = sprintf(
                __('Thème(s) inconnu(s), ignoré(s) : %s. Créez-les dans Veille → Catégories, puis réimportez.', 'veilletool'),
                implode(', ', array_keys($unknown_themes))
            );
        }

        if (count($errors) > 5) {
            $extra    = count($errors) - 5;
            $errors   = array_slice($errors, 0, 5);
            $errors[] = sprintf(__('... et %d autre(s) ligne(s) ignorée(s).', 'veilletool'), $extra);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Clé de déduplication d'une adresse de flux.
     *
     * Le protocole, la casse du domaine et la barre oblique finale ne font
     * pas deux flux différents.
     *
     * @param string $url
     *
     * @return string
     */
    private static function urlKey(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $url = preg_replace('#^https?://#i', '', $url) ?? $url;

        return mb_strtolower(rtrim($url, '/'));
    }

    /**
     * Ramène une valeur de type à `rss` ou `atom`.
     *
     * Le type n'est qu'une étiquette d'affichage : l'analyseur reconnaît de
     * lui-même le format réellement servi.
     * On se contente donc d'un indice tiré de l'adresse quand la colonne
     * est vide.
     *
     * @param string $type
     * @param string $url
     *
     * @return string
     */
    private static function normalizeType(string $type, string $url): string
    {
        $type = mb_strtolower(trim($type));

        if (array_key_exists($type, Source::getTypes())) {
            return $type;
        }

        return str_contains(mb_strtolower($url), 'atom') ? Source::TYPE_ATOM : Source::TYPE_RSS;
    }

    /**
     * Lit une colonne booléenne écrite en toutes lettres ou en chiffre.
     *
     * @param string $value
     *
     * @return bool
     */
    private static function parseFlag(string $value): bool
    {
        return in_array(
            mb_strtolower(trim($value)),
            ['1', 'oui', 'yes', 'true', 'vrai', 'actif', 'x'],
            true
        );
    }

    /**
     * Traduit une liste de thèmes en identifiants de catégories.
     *
     * @param string $raw Codes ou noms séparés par une barre verticale
     *
     * @return array{0: int[], 1: string[]} Identifiants trouvés, puis libellés inconnus
     */
    private static function resolveCategories(string $raw): array
    {
        $found   = [];
        $unknown = [];

        foreach (explode(self::CSV_CATEGORY_SEPARATOR, $raw) as $theme) {
            $theme = trim($theme);

            if ($theme === '') {
                continue;
            }

            $categories_id = Category::findIdByCodeOrName($theme);

            if ($categories_id > 0) {
                $found[] = $categories_id;
            } else {
                $unknown[] = $theme;
            }
        }

        return [array_values(array_unique($found)), $unknown];
    }
}
