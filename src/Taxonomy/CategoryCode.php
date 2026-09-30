<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Taxonomy;

use GlpiPlugin\Veilletool\Category;

/**
 * Le code technique d'un thème.
 *
 * Un thème porte un libellé lisible (« Cybersécurité ») et un code stable
 * (`cybersecurite`). Le second est ce qui permet à un import CSV, à un flux
 * annonçant ses catégories et à une mise à jour du plugin de désigner le
 * même thème sans dépendre de son orthographe du jour.
 *
 * Il doit donc être dérivable d'un libellé, et rester unique quoi qu'il
 * arrive - deux thèmes nommés « Réseau » et « Réseaux » se réduisent au même
 * code une fois les accents et la ponctuation retirés.
 */
final class CategoryCode
{
    /**
     * Code technique d'un thème, calculé à partir de son nom.
     *
     * Ce code n'est plus saisi : il est déduit du libellé, rendu unique par
     * un suffixe numérique si besoin, et figé.
     * La colonne porte une clé d'unicité - sans ce contrôle, deux thèmes
     * dont les libellés donnent le même identifiant feraient échouer
     * l'enregistrement, sans que l'utilisateur puisse comprendre pourquoi
     * ni comment s'en sortir.
     *
     * @param string $wanted Code souhaité. Vide, il est tiré du nom
     * @param string $name
     *
     * @return string
     */
    public static function build(string $wanted, string $name): string
    {
        $base = self::slugify($wanted !== '' ? $wanted : $name);

        if ($base === '') {
            $base = 'theme';
        }

        $taken = self::getUsedCodes();

        if (!isset($taken[$base])) {
            return $base;
        }

        $suffix = 2;
        while (isset($taken[$base . '-' . $suffix])) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /**
     * Codes déjà attribués, indexés pour consultation immédiate.
     *
     * @return array<string, true>
     */
    private static function getUsedCodes(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $codes = [];
        foreach (
            $DB->request(['SELECT' => ['code'], 'FROM' => Category::getTable()]) as $row
        ) {
            $codes[(string) $row['code']] = true;
        }

        return $codes;
    }

    /**
     * Normalise une chaîne en code technique (minuscules, sans accent).
     *
     * @param string $value
     *
     * @return string
     */
    public static function slugify(string $value): string
    {
        $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($slug === false) {
            $slug = $value;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 64) : 'categorie';
    }
}
