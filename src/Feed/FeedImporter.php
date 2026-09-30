<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use Glpi\Error\ErrorHandler;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Source_Category;

/**
 * L'enregistrement des entrées d'un flux.
 *
 * Lire un flux et en garder trace sont deux métiers : le premier interprète
 * un document, le second tient une base à jour - dédoublonnage, transactions,
 * héritage des thèmes. Les séparer permet de relire l'un sans traverser
 * l'autre.
 */
final class FeedImporter
{
    /** Nombre d'erreurs détaillées avant de résumer */
    private const REPORTED_ERRORS = 3;

    /**
     * Crée ou met à jour les cartes issues d'une collecte.
     *
     * @param array<int, array<string, mixed>> $items
     * @param Source                           $source
     * @param string                           $origin
     *
     * @return array{imported:int, updated:int, errors:string[]}
     */
    public function import(array $items, Source $source, string $origin): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $imported   = 0;
        $updated    = 0;
        $errors     = [];
        $sources_id = (int) $source->fields['id'];
        $max_items  = max(1, (int) ($source->fields['max_items'] ?? 50));
        $inherited  = Source_Category::getCategoryIds($sources_id);
        $article    = new Article();

        // Les entrées sont normalisées d'abord, toutes ensemble : c'est là
        // que se calcule l'identifiant de dédoublonnage, et il doit être le
        // même que celui servant à interroger la base.
        // Le dériver deux fois exposait à ce qu'une entrée sans identifiant
        // ni lien - dont la graine est l'heure courante - en obtienne deux
        // différents à une seconde d'intervalle.
        $prepared = [];

        foreach (array_slice($items, 0, $max_items) as $item) {
            $entry = self::normalizeItem($item);

            if ($entry === null) {
                $errors[] = __('Une entrée sans titre a été ignorée.', 'veilletool');
                continue;
            }

            $prepared[] = $entry;
        }

        // Les cartes déjà connues du flux sont relevées en une seule
        // requête.
        // Les chercher une par une faisait, sur un flux de cent entrées,
        // cent allers-retours pour n'apprendre à chaque fois qu'un seul
        // identifiant.
        $existing = self::findExistingGuids($sources_id, array_column($prepared, 'guid'));

        foreach ($prepared as $entry) {
            $title       = $entry['title'];
            $articles_id = $existing[$entry['guid']] ?? 0;
            $input       = $this->buildInput($entry, $source, $origin, $inherited);

            if ($articles_id > 0) {
                $input['id'] = $articles_id;
                unset($input['users_id'], $input['guid'], $input['plugin_veilletool_sources_id']);
            }

            // Une entrée, une transaction.
            //
            // Enregistrer une carte, ce n'est pas une écriture mais cinq :
            // la carte, ses thèmes, ses technologies et son score.
            // Valider le flux d'un bloc laisserait passer une carte dont
            // les relations auraient échoué en cours de route.
            // Valider entrée par entrée garantit qu'une carte enregistrée
            // est une carte complète, et qu'une entrée fautive n'emporte
            // pas les autres.
            $DB->beginTransaction();

            try {
                $saved = $articles_id > 0 ? $article->update($input) : $article->add($input);

                if (!$saved) {
                    $DB->rollBack();

                    $errors[] = $articles_id > 0
                        ? sprintf(__('Mise à jour impossible pour « %s ».', 'veilletool'), $title)
                        : sprintf(__('Création impossible pour « %s ».', 'veilletool'), $title);

                    continue;
                }

                $DB->commit();

                if ($articles_id > 0) {
                    $updated++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                $DB->rollBack();

                ErrorHandler::logCaughtException($e);

                $errors[] = sprintf(
                    __('Entrée « %1$s » ignorée : %2$s', 'veilletool'),
                    $title,
                    $e->getMessage()
                );
            }
        }

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'errors'   => self::summarize($errors),
        ];
    }

    /**
     * Champs à écrire pour une entrée normalisée.
     *
     * @param array<string, mixed> $entry
     * @param Source               $source
     * @param string               $origin
     * @param int[]                $inherited
     *
     * @return array<string, mixed>
     */
    private function buildInput(array $entry, Source $source, string $origin, array $inherited): array
    {
        $item  = $entry['item'];
        $image = trim((string) $item['image']);

        if ($image !== '' && !UrlGuard::isAcceptable($image)) {
            $image = '';
        }

        return [
            'plugin_veilletool_sources_id' => (int) $source->fields['id'],
            'name'                         => mb_substr($entry['title'], 0, 255),
            'summary'                      => (string) $item['summary'],
            'content'                      => (string) $item['content'],
            'url'                          => $entry['link'],
            'guid'                         => $entry['guid'],
            'author'                       => mb_substr(trim(strip_tags((string) $item['author'])), 0, 255),
            'image_url'                    => $image,
            'origin'                       => $origin,
            'date_published'               => $entry['published'],
            'users_id'                     => (int) ($source->fields['users_id'] ?? 0),
            'categories'                   => $this->resolveCategories((array) $item['categories'], $inherited),
            '_categories_defined'          => 1,
        ];
    }

    /**
     * Ramène une entrée de flux à ce dont l'enregistrement a besoin.
     *
     * Le titre, le lien, la date et l'identifiant de dédoublonnage sont
     * dérivés **une seule fois** : c'est le même identifiant qui sert à
     * interroger la base et à écrire la carte.
     *
     * @param array<string, mixed> $item
     *
     * @return array{item:array, title:string, link:string, published:string, guid:string}|null
     *         Null pour une entrée sans titre, qui n'est pas exploitable
     */
    private static function normalizeItem(array $item): ?array
    {
        $title = trim(strip_tags((string) $item['title']));

        if ($title === '') {
            return null;
        }

        $link = trim((string) $item['link']);
        if ($link !== '' && !UrlGuard::isAcceptable($link)) {
            $link = '';
        }

        $timestamp = $item['date'] !== '' ? strtotime((string) $item['date']) : false;
        $published = $timestamp !== false
            ? date('Y-m-d H:i:s', $timestamp)
            : ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));

        $seed = (string) $item['guid'];
        if ($seed === '') {
            $seed = $link !== '' ? $link : $title . '|' . $published;
        }

        return [
            'item'      => $item,
            'title'     => $title,
            'link'      => $link,
            'published' => $published,
            'guid'      => substr(sha1($seed), 0, 40),
        ];
    }

    /**
     * Identifiants déjà connus du flux, parmi les entrées de cette
     * collecte.
     *
     * @param int      $sources_id
     * @param string[] $guids
     *
     * @return array<string, int> GUID => identifiant de carte
     */
    private static function findExistingGuids(int $sources_id, array $guids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $guids = array_values(array_unique(array_filter($guids)));

        if ($guids === []) {
            return [];
        }

        $found    = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'guid'],
            'FROM'   => Article::getTable(),
            'WHERE'  => [
                'plugin_veilletool_sources_id' => $sources_id,
                'guid'                         => $guids,
            ],
        ]);

        foreach ($iterator as $row) {
            $found[(string) $row['guid']] = (int) $row['id'];
        }

        return $found;
    }

    /**
     * Détermine les catégories d'une carte.
     *
     * Les catégories déclarées dans le document priment : une entrée qui
     * annonce explicitement « cybersecurite » ne doit pas hériter en plus
     * de toutes les catégories de sa source.
     * Les libellés non reconnus sont ignorés - c'est le cas courant des
     * <category> libres des flux RSS - et la carte reçoit alors les
     * catégories de la source.
     *
     * @param string[] $declared
     * @param int[]    $inherited
     *
     * @return int[]
     */
    private function resolveCategories(array $declared, array $inherited): array
    {
        $matched = [];

        foreach ($declared as $label) {
            $categories_id = Category::findIdByCodeOrName((string) $label);

            if ($categories_id > 0) {
                $matched[] = $categories_id;
            }
        }

        $ids = $matched !== [] ? $matched : $inherited;

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Ne remonte que les premières erreurs, pour garder un message lisible.
     *
     * @param string[] $errors
     *
     * @return string[]
     */
    private static function summarize(array $errors): array
    {
        if (count($errors) <= self::REPORTED_ERRORS) {
            return $errors;
        }

        $extra  = count($errors) - self::REPORTED_ERRORS;
        $kept   = array_slice($errors, 0, self::REPORTED_ERRORS);
        $kept[] = sprintf(__('... et %d autre(s) erreur(s).', 'veilletool'), $extra);

        return $kept;
    }
}
