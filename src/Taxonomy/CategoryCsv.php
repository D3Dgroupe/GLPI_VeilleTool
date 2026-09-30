<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Taxonomy;

use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Csv;
use GlpiPlugin\Veilletool\Settings;

/**
 * Le format d'échange des thèmes.
 *
 * Exporter puis réimporter doit reconstituer le même référentiel : mêmes
 * thèmes, mêmes codes, mêmes couleurs, même ordre. C'est ce qui permet de
 * transporter une taxonomie d'une instance à l'autre, et de la versionner
 * ailleurs que dans la base.
 *
 * L'import a deux modes, et c'est le seul choix qui compte : compléter, ou
 * remplacer. Le second efface les thèmes absents du fichier - et donc les
 * rattachements des cartes qui s'y trouvaient.
 */
final class CategoryCsv
{
    /** Colonnes du référentiel de thèmes au format CSV */
    public const CSV_HEADER = 'Nom;Couleur de fond;Couleur du texte;Icône;Intérêt;Actif;Description';

    /** Taille maximale acceptée pour un référentiel de thèmes importé (256 Kio) */
    public const CSV_MAX_SIZE = 262144;

    /**
     * Exporte les thèmes au format CSV.
     *
     * L'ordre d'affichage est celui du fichier : le réimporter ailleurs
     * reconstitue le même classement, sans colonne de rang à tenir à jour.
     *
     * @return string
     */
    public static function toCsv(): string
    {
        $lines = [self::CSV_HEADER];

        foreach (Category::getAllOrdered() as $row) {
            $lines[] = Csv::line([
                    (string) $row['name'],
                    (string) $row['color'],
                    (string) $row['text_color'],
                    (string) $row['icon'],
                    (string) (int) $row['interest'],
                    (string) (int) $row['is_active'],
                    (string) ($row['comment'] ?? ''),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Importe un référentiel de thèmes au format CSV.
     *
     * Colonnes : `Nom ; Couleur de fond ; Couleur du texte ; Icône ;
     * Intérêt.
     * Actif.
     * Description`.
     * Seul le nom est obligatoire.
     *
     * Le **nom fait foi** : un thème déjà présent est mis à jour plutôt que
     * dupliqué.
     * C'est ce qui permet de rejouer un import pour corriger des couleurs
     * sans repartir de zéro - et le code technique, lui, ne bouge pas, donc
     * les renvois faits ailleurs restent valides.
     *
     * L'ordre des lignes donne l'ordre d'affichage.
     *
     * @param string $content
     * @param bool   $replace Retirer les thèmes absents du fichier
     *
     * @return array{imported:int, updated:int, removed:int, errors:string[]}
     */
    public static function importCsv(string $content, bool $replace = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $imported = 0;
        $updated  = 0;
        $removed  = 0;
        $errors   = [];

        // BOM et fins de ligne CRLF des tableurs Windows.
        $content = Csv::normalize($content);

        if (trim($content) === '') {
            return [
                'imported' => 0,
                'updated'  => 0,
                'removed'  => 0,
                'errors'   => [__('Fichier vide.', 'veilletool')],
            ];
        }

        // Index par nom, insensible à la casse : c'est ainsi qu'un réimport
        // reconnaît ce qu'il a déjà posé.
        $known = [];
        foreach (Category::getAllOrdered() as $row) {
            $known[mb_strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }

        $category = new Category();
        $seen     = [];
        $order    = [];
        $line_no  = 0;

        $DB->beginTransaction();

        try {
            foreach (explode("\n", $content) as $line) {
                $line_no++;

                if (trim($line) === '') {
                    continue;
                }

                $parts = Csv::split($line);

                // En-tête du fichier exporté par le plugin.
                if ($line_no === 1 && mb_strtolower(trim((string) $parts[0])) === 'nom') {
                    continue;
                }

                $name = mb_substr(trim((string) ($parts[0] ?? '')), 0, 255);

                if ($name === '') {
                    $errors[] = sprintf(__('Ligne %d ignorée : le nom est obligatoire.', 'veilletool'), $line_no);
                    continue;
                }

                $key = mb_strtolower($name);

                if (isset($seen[$key])) {
                    $errors[] = sprintf(__('Ligne %d ignorée : « %s » figure déjà dans le fichier.', 'veilletool'), $line_no, $name);
                    continue;
                }
                $seen[$key] = true;

                $interest = trim((string) ($parts[4] ?? ''));
                $icon     = trim((string) ($parts[3] ?? ''));

                $input = [
                    'name'        => $name,
                    'color'       => Settings::normalizeColor((string) ($parts[1] ?? ''), '#4b7bec'),
                    'text_color'  => Settings::normalizeColor((string) ($parts[2] ?? ''), '#ffffff'),
                    'icon'        => $icon !== '' ? mb_substr($icon, 0, 100) : 'ti ti-tag',
                    'interest'    => $interest === ''
                        ? Settings::getInt('default_interest')
                        : max(0, min(100, (int) $interest)),
                    // Un thème absent de la colonne « Actif » est actif :
                    // contrairement à un flux, il ne déclenche aucune
                    // collecte - il ne fait que classer.
                    'is_active'   => trim((string) ($parts[5] ?? '')) === '0' ? 0 : 1,
                    'comment'     => trim((string) ($parts[6] ?? '')),
                    '_no_message' => true,
                ];

                if (isset($known[$key])) {
                    $input['id'] = $known[$key];

                    if ($category->update($input)) {
                        $updated++;
                    }

                    $order[] = $known[$key];
                    continue;
                }

                $newID = $category->add($input);

                if ($newID) {
                    $imported++;
                    $known[$key] = (int) $newID;
                    $order[]     = (int) $newID;
                } else {
                    $errors[] = sprintf(__('Ligne %d : enregistrement refusé.', 'veilletool'), $line_no);
                }
            }

            if ($replace) {
                $removed = self::purgeAbsent(array_values($known), $seen);
            }

            // L'ordre des lignes fait l'ordre d'affichage.
            // Les thèmes absents du fichier le suivent, dans leur ordre
            // précédent : sans cela, ils garderaient des rangs qui
            // entreraient en collision avec ceux qu'on vient d'attribuer.
            $tail = [];
            foreach (Category::getAllOrdered() as $row) {
                if (!in_array((int) $row['id'], $order, true)) {
                    $tail[] = (int) $row['id'];
                }
            }

            CategoryOrder::resetRanking(array_merge($order, $tail));

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        Category::invalidate();

        if (count($errors) > 5) {
            $extra    = count($errors) - 5;
            $errors   = array_slice($errors, 0, 5);
            $errors[] = sprintf(__('... et %d autre(s) ligne(s) ignorée(s).', 'veilletool'), $extra);
        }

        return ['imported' => $imported, 'updated' => $updated, 'removed' => $removed, 'errors' => $errors];
    }

    /**
     * Retire les thèmes que le fichier importé ne mentionne pas.
     *
     * Un thème encore porté par une carte ou par un flux est **conservé** :
     * le supprimer retirerait silencieusement sa qualification à des cartes
     * qui n'ont rien à voir avec l'import.
     *
     * @param int[]               $all_ids
     * @param array<string, true> $seen Noms présents dans le fichier
     *
     * @return int
     */
    private static function purgeAbsent(array $all_ids, array $seen): int
    {
        $category = new Category();
        $removed  = 0;

        $articles = Category::countArticles($all_ids);
        $sources  = Category::countSources($all_ids);

        foreach (Category::getAllOrdered() as $row) {
            $key = mb_strtolower(trim((string) $row['name']));

            if (isset($seen[$key])) {
                continue;
            }

            $categories_id = (int) $row['id'];

            if (($articles[$categories_id] ?? 0) > 0 || ($sources[$categories_id] ?? 0) > 0) {
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('Thème « %s » conservé : il est encore porté par des cartes ou des flux.', 'veilletool'),
                        htmlescape((string) $row['name'])
                    ),
                    true,
                    WARNING
                );
                continue;
            }

            if ($category->delete(['id' => $categories_id], true)) {
                $removed++;
            }
        }

        return $removed;
    }
}
