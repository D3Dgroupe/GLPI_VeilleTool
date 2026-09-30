<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

/**
 * Les deux gestes que tout échange CSV du plugin refait.
 *
 * Quatre référentiels s'importent et s'exportent - flux, thèmes,
 * technologies, réglages - et tous butaient sur les mêmes détails : un
 * tableur Windows laisse un BOM et des fins de ligne CRLF, et une valeur
 * contenant un point-virgule doit être protégée sous peine de décaler
 * toutes les colonnes suivantes.
 */
final class Csv
{
    /** Séparateur de colonnes retenu par tous les fichiers du plugin */
    public const SEPARATOR = ';';

    /**
     * Prépare un contenu reçu pour la lecture ligne à ligne.
     *
     * @param string $content
     *
     * @return string
     */
    public static function normalize(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Protège une valeur destinée à une colonne.
     *
     * Les retours à la ligne sont remplacés plutôt qu'échappés : une
     * description sur trois lignes casserait la lecture ligne à ligne, et
     * le gain de fidélité ne vaut pas ce risque.
     *
     * @param string $value
     *
     * @return string
     */
    public static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));

        return str_contains($value, self::SEPARATOR) || str_contains($value, '"')
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }

    /**
     * Découpe une ligne en colonnes.
     *
     * @param string $line
     *
     * @return string[]
     */
    public static function split(string $line): array
    {
        return str_getcsv($line, self::SEPARATOR, '"', '\\');
    }

    /**
     * Assemble une ligne à partir de ses colonnes.
     *
     * @param string[] $values
     *
     * @return string
     */
    public static function line(array $values): string
    {
        return implode(self::SEPARATOR, array_map(
            static fn(string $value): string => self::escape($value),
            $values
        ));
    }
}
