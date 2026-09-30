<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use Session;

/**
 * Position de réglage nommée du score d'intérêt.
 *
 * Le barème n'a pas une bonne réponse mais plusieurs, selon ce qu'on
 * cherche : une veille de crise privilégie la fraîcheur et la note du flux,
 * une veille produit privilégie la technologie.
 * Passer de l'une à l'autre demandait jusqu'ici de régler une quinzaine de
 * curseurs à la main, et de se souvenir des valeurs précédentes.
 *
 * Un préréglage fige l'ensemble du barème sous un titre.
 * Il ne s'applique pas tout seul : il est posé quand on le demande, et les
 * réglages courants restent la seule vérité - un préréglage est une copie
 * qu'on rappelle, pas un mode dans lequel on entre.
 */
class ScorePreset extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_source';

    public $dohistory = false;

    /** Nombre maximum de préréglages conservés */
    public const MAX_PRESETS = 20;

    /**
     * Colonnes du format d'échange du barème.
     *
     * Une colonne de plus que les autres réglages : sans elle, un fichier
     * ne saurait porter qu'un seul barème, et la question « lequel
     * appliquer ? » ne se poserait jamais.
     */
    public const CSV_HEADER = 'Préréglage;Réglage;Valeur';

    /**
     * Nom de bloc désignant les réglages en vigueur, par opposition à un
     * préréglage enregistré.
     */
    public const CSV_CURRENT = '';

    public static function getTypeName($nb = 0)
    {
        return _n('Préréglage du score', 'Préréglages du score', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-bookmarks';
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareCommonInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * Contrôles communs : un préréglage sans titre ne se rappelle pas.
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

        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);

            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(
                    __('Le titre du préréglage est obligatoire.', 'veilletool'),
                    false,
                    ERROR
                );

                return false;
            }

            $input['name'] = mb_substr($input['name'], 0, 255);
        }

        if (array_key_exists('comment', $input)) {
            $input['comment'] = mb_substr(trim((string) $input['comment']), 0, 1000);
        }

        return $input;
    }

    /**
     * Valeurs du barème portées par ce préréglage.
     *
     * Les clés inconnues sont écartées et les manquantes reprennent la
     * valeur livrée : un préréglage enregistré par une version antérieure
     * reste applicable après une mise à jour qui ajoute un réglage.
     *
     * @return array<string, string|int>
     */
    public function getValues(): array
    {
        $stored = json_decode((string) ($this->fields['settings'] ?? ''), true);

        if (!is_array($stored)) {
            $stored = [];
        }

        $values = [];
        foreach (Settings::getScoringKeys() as $key) {
            $values[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : Settings::DEFAULTS[$key];
        }

        return $values;
    }

    /**
     * Enregistre les réglages courants sous un titre.
     *
     * @param string $name
     * @param string $comment
     *
     * @return int Identifiant créé, ou 0 en cas de refus
     */
    public static function captureCurrent(string $name, string $comment = ''): int
    {
        if (self::countAll() >= self::MAX_PRESETS) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Vingt préréglages au plus : supprimez-en un avant d\'en ajouter (%d enregistrés).', 'veilletool'),
                    self::countAll()
                ),
                false,
                ERROR
            );

            return 0;
        }

        return (int) (new self())->add([
            'name'        => $name,
            'comment'     => $comment,
            'settings'    => self::encodeCurrent(),
            '_no_message' => true,
        ]);
    }

    /**
     * Remplace le contenu d'un préréglage par les réglages courants.
     *
     * @param int $presets_id
     *
     * @return bool
     */
    public static function captureInto(int $presets_id): bool
    {
        $preset = new self();

        if ($presets_id <= 0 || !$preset->getFromDB($presets_id)) {
            return false;
        }

        return (bool) $preset->update([
            'id'          => $presets_id,
            'settings'    => self::encodeCurrent(),
            '_no_message' => true,
        ]);
    }

    /**
     * Applique un préréglage aux réglages courants.
     *
     * @param int $presets_id
     *
     * @return string Titre appliqué, chaîne vide si le préréglage est
     *                introuvable
     */
    public static function apply(int $presets_id): string
    {
        $preset = new self();

        if ($presets_id <= 0 || !$preset->getFromDB($presets_id)) {
            return '';
        }

        // set() borne et contrôle chaque valeur : un préréglage trafiqué en
        // base ne peut pas introduire de barème incohérent.
        Settings::set($preset->getValues());

        return (string) $preset->fields['name'];
    }

    /**
     * Barème courant, prêt à être stocké.
     *
     * @return string
     */
    private static function encodeCurrent(): string
    {
        $values  = Settings::getAll();
        $capture = [];

        foreach (Settings::getScoringKeys() as $key) {
            $capture[$key] = $values[$key] ?? Settings::DEFAULTS[$key];
        }

        return (string) json_encode($capture, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tous les préréglages, du plus récemment modifié au plus ancien.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request(['FROM' => self::getTable(), 'ORDER' => ['name ASC']]) as $row
        ) {
            $row['id'] = (int) $row['id'];
            $rows[]    = $row;
        }

        return $rows;
    }

    /**
     * Nombre de préréglages enregistrés.
     *
     * @return int
     */
    public static function countAll(): int
    {
        return countElementsInTable(self::getTable());
    }

    /**
     * Préréglage dont le barème correspond exactement aux réglages
     * courants.
     *
     * Sert à dire à l'écran « vous êtes sur ce préréglage » plutôt que de
     * laisser deviner.
     * Rien ne l'impose : les réglages peuvent parfaitement ne correspondre
     * à aucun.
     *
     * @return int Identifiant, ou 0
     */
    public static function findCurrent(): int
    {
        $current = json_decode(self::encodeCurrent(), true);
        $preset  = new self();

        foreach (self::getAll() as $row) {
            $preset->fields = $row;

            if ($preset->getValues() == $current) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * Retrouve un préréglage par son titre, insensible à la casse.
     *
     * @param string $name
     *
     * @return int Identifiant, ou 0
     */
    public static function findByName(string $name): int
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return 0;
        }

        foreach (self::getAll() as $row) {
            if (mb_strtolower(trim((string) $row['name'])) === $name) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * Exporte le barème courant et tous les préréglages.
     *
     * Le premier bloc, sans titre, porte les réglages en vigueur.
     * Viennent ensuite les préréglages, un bloc chacun.
     * Le fichier se relit tel quel, et l'import demande alors lequel
     * appliquer.
     *
     * @return string
     */
    public static function toCsv(): string
    {
        $lines = [self::CSV_HEADER];

        $current = Settings::getAll();

        foreach (Settings::getScoringKeys() as $key) {
            $lines[] = Csv::line([
                self::CSV_CURRENT,
                $key,
                (string) ($current[$key] ?? Settings::DEFAULTS[$key]),
            ]);
        }

        $preset = new self();

        foreach (self::getAll() as $row) {
            $preset->fields = $row;

            foreach ($preset->getValues() as $key => $value) {
                $lines[] = Csv::line([(string) $row['name'], $key, (string) $value]);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Décode un fichier de barème.
     *
     * Les deux formats sont acceptés : trois colonnes avec le titre du
     * préréglage, ou les deux colonnes `Réglage;Valeur` des versions
     * antérieures - lesquelles ne décrivaient que le barème courant.
     *
     * @param string $content
     *
     * @return array{blocks: array<string, array<string, string>>, errors: string[]}
     */
    public static function parseCsv(string $content): array
    {
        $content = Csv::normalize($content);
        $blocks  = [];
        $errors  = [];
        $unknown = [];
        $allowed = array_flip(Settings::getScoringKeys());
        $line_no = 0;

        if (trim($content) === '') {
            return ['blocks' => [], 'errors' => [__('Fichier vide.', 'veilletool')]];
        }

        foreach (explode("\n", $content) as $line) {
            $line_no++;

            if (trim($line) === '') {
                continue;
            }

            $parts = Csv::split($line);
            $first = trim((string) ($parts[0] ?? ''));

            // En-tête, dans l'un ou l'autre format.
            if ($line_no === 1 && in_array(mb_strtolower($first), ['préréglage', 'réglage'], true)) {
                continue;
            }

            // Deux colonnes : format d'avant, qui ne portait que le
            // courant.
            if (count($parts) < 3) {
                $key   = $first;
                $value = trim((string) ($parts[1] ?? ''));
                $name  = self::CSV_CURRENT;
            } else {
                $name  = $first;
                $key   = trim((string) ($parts[1] ?? ''));
                $value = trim((string) ($parts[2] ?? ''));
            }

            if ($key === '') {
                continue;
            }

            if (!isset($allowed[$key])) {
                $unknown[$key] = true;
                continue;
            }

            $blocks[$name][$key] = $value;
        }

        if ($unknown !== []) {
            $errors[] = sprintf(
                __('Réglage(s) hors barème, ignoré(s) : %s.', 'veilletool'),
                implode(', ', array_slice(array_keys($unknown), 0, 8))
            );
        }

        if ($blocks === []) {
            $errors[] = __('Aucun réglage de barème exploitable dans ce fichier.', 'veilletool');
        }

        return ['blocks' => $blocks, 'errors' => $errors];
    }

    /**
     * Libellé d'affichage d'un bloc du fichier.
     *
     * @param string $name
     *
     * @return string
     */
    public static function blockLabel(string $name): string
    {
        return $name === self::CSV_CURRENT
            ? __('Réglages en vigueur dans le fichier', 'veilletool')
            : $name;
    }

    /**
     * Enregistre un jeu de valeurs sous un titre, en remplaçant le
     * préréglage de même titre s'il existe.
     *
     * @param string                    $name
     * @param array<string, string|int> $values
     * @param string                    $comment
     *
     * @return bool
     */
    public static function store(string $name, array $values, string $comment = ''): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        // Seules les clés du barème sont retenues : un fichier importé ne
        // peut pas glisser de réglage étranger dans un préréglage.
        $capture = [];
        foreach (Settings::getScoringKeys() as $key) {
            if (array_key_exists($key, $values)) {
                $capture[$key] = $values[$key];
            }
        }

        if ($capture === []) {
            return false;
        }

        $preset     = new self();
        $presets_id = self::findByName($name);

        $input = [
            'settings'    => (string) json_encode($capture, JSON_UNESCAPED_UNICODE),
            '_no_message' => true,
        ];

        if ($presets_id > 0) {
            return (bool) $preset->update($input + ['id' => $presets_id]);
        }

        if (self::countAll() >= self::MAX_PRESETS) {
            return false;
        }

        return (bool) $preset->add($input + ['name' => $name, 'comment' => $comment]);
    }
}
