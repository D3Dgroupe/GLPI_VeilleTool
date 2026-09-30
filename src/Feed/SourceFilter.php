<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Source;

/**
 * La recherche dans la liste des flux.
 *
 * Quarante flux tiennent sur un écran, quatre cents non. Le filtrage se fait
 * en mémoire - la liste est déjà chargée, et le volume de cartes de chaque
 * flux se compte en une requête pour tout le lot - plutôt qu'en multipliant
 * les conditions SQL sur un tableau de cette taille.
 */
final class SourceFilter
{
    /**
     * Nombre de cartes collectées par chacun des flux fournis.
     *
     * @param int[] $sources_ids
     *
     * @return array<int, int> Identifiant de flux => nombre de cartes
     */
    public static function countArticles(array $sources_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sources_ids = array_values(array_filter(array_map('intval', $sources_ids)));

        if ($sources_ids === []) {
            return [];
        }

        $counts   = array_fill_keys($sources_ids, 0);
        $iterator = $DB->request([
            'SELECT'  => [
                'plugin_veilletool_sources_id',
                'COUNT' => 'id AS cpt',
            ],
            'FROM'    => Article::getTable(),
            'WHERE'   => ['plugin_veilletool_sources_id' => $sources_ids],
            'GROUPBY' => ['plugin_veilletool_sources_id'],
        ]);

        foreach ($iterator as $row) {
            $counts[(int) $row['plugin_veilletool_sources_id']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Filtres du moteur de recherche des flux, portant sur le volume de
     * cartes déjà collectées.
     *
     * @return array<string, string>
     */
    public static function getVolumeFilters(): array
    {
        return [
            ''      => __('Tous les volumes', 'veilletool'),
            'none'  => __('Aucune carte collectée', 'veilletool'),
            'some'  => __('Au moins une carte', 'veilletool'),
            '10'    => __('Au moins 10 cartes', 'veilletool'),
            '100'   => __('Au moins 100 cartes', 'veilletool'),
        ];
    }

    /**
     * Ce flux répond-il au filtre de volume retenu ?
     *
     * @param int    $articles Nombre de cartes collectées
     * @param string $filter   Clé de getVolumeFilters()
     *
     * @return bool
     */
    public static function matchesVolume(int $articles, string $filter): bool
    {
        return match ($filter) {
            'none'  => $articles === 0,
            'some'  => $articles > 0,
            '10'    => $articles >= 10,
            '100'   => $articles >= 100,
            default => true,
        };
    }

    /**
     * Critères de recherche normalisés, lus depuis la requête.
     *
     * @param array<string, mixed> $request
     *
     * @return array{keyword:string, category:int, active:string, volume:string}
     */
    public static function readSearch(array $request): array
    {
        $active = (string) ($request['active'] ?? '');
        $volume = (string) ($request['cards'] ?? '');

        return [
            'keyword'  => trim((string) ($request['q'] ?? '')),
            'category' => (int) ($request['cat'] ?? 0),
            // '' = tous, '1' = actifs, '0' = inactifs.
            'active'   => in_array($active, ['', '0', '1'], true) ? $active : '',
            'volume'   => array_key_exists($volume, self::getVolumeFilters()) ? $volume : '',
        ];
    }

    /**
     * La recherche porte-t-elle sur quelque chose ?
     *
     * Sert à distinguer « supprimer ce que je vois » de « tout supprimer »
     * : sans critère, les deux reviendraient au même, et l'écran ne doit
     * pas laisser croire le contraire.
     *
     * @param array<string, mixed> $search
     *
     * @return bool
     */
    public static function hasSearch(array $search): bool
    {
        return ($search['keyword'] ?? '') !== ''
            || (int) ($search['category'] ?? 0) > 0
            || ($search['active'] ?? '') !== ''
            || ($search['volume'] ?? '') !== '';
    }

    /**
     * Un flux répond-il aux critères de recherche ?
     *
     * @param array<string, mixed> $row            Ligne du flux, portant au
     *                                             moins `is_active` et
     *                                             `article_count`
     * @param array<string, mixed> $search         Critères normalisés
     * @param int[]                $categories_ids Thèmes du flux
     *
     * @return bool
     */
    public static function matches(array $row, array $search, array $categories_ids): bool
    {
        if (($search['active'] ?? '') !== '' && (string) (int) $row['is_active'] !== $search['active']) {
            return false;
        }

        if ((int) ($search['category'] ?? 0) > 0 && !in_array((int) $search['category'], $categories_ids, true)) {
            return false;
        }

        if (!self::matchesVolume((int) ($row['article_count'] ?? 0), (string) ($search['volume'] ?? ''))) {
            return false;
        }

        $keyword = (string) ($search['keyword'] ?? '');

        if ($keyword === '') {
            return true;
        }

        // Le mot clé porte sur ce qui identifie un flux : son nom, son
        // adresse et sa description.
        // C'est cette dernière qui permet de retrouver « les flux qui
        // parlent de rançongiciels » sans connaître leur nom.
        foreach (['name', 'url', 'comment'] as $field) {
            if (mb_stripos((string) ($row[$field] ?? ''), $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
