<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use DBmysql;
use Glpi\DBAL\QueryExpression;

/**
 * Score d'intérêt d'une carte et priorité qui en découle.
 *
 * Le score agrège quatre composantes, chacune notée sur 100 :
 *
 * - **source** - la note d'intérêt du flux dont la carte provient.
 * - **technologie** - la confiance du meilleur rapprochement technologique.
 * - **catégorie** - la meilleure note d'intérêt parmi les thèmes de la
 * carte.
 * - **fraîcheur** - décroissance linéaire depuis la date de publication.
 *
 * Les trois premières composantes ne bougent qu'à l'enregistrement de la
 * carte : elles sont donc **calculées une fois et stockées** dans les
 * colonnes `score_source`, `score_technology` et `score_category`.
 * La fraîcheur, elle, change tous les jours.
 * Elle est recalculée à la volée, en base pour le tri et en PHP pour
 * l'affichage.
 *
 * Le score final est la moyenne des quatre composantes pondérée par les
 * poids réglés par l'utilisateur, donc toujours **ramené sur 100** quelles
 * que soient les valeurs retenues.
 */
class Score
{
    /** Niveaux de priorité, du plus fort au plus faible */
    public const PRIORITY_HIGH      = 'high';
    public const PRIORITY_IMPORTANT = 'important';
    public const PRIORITY_TOREAD    = 'toread';
    public const PRIORITY_INFO      = 'info';
    public const PRIORITY_LOW       = 'low';

    /**
     * Rapport de contraste minimal recommandé (WCAG AA, texte courant).
     */
    public const MIN_CONTRAST = 4.5;

    /**
     * Âge attribué à une carte sans date de publication, en heures.
     *
     * Assez grand pour épuiser n'importe quelle pente de décroissance : une
     * carte sans date ne vaut rien en fraîcheur.
     */
    private const NULL_DATE_AGE_HOURS = 10000000;

    /**
     * Réglages associés à chaque niveau : fond, texte, puis libellé.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    private const SETTING_KEYS = [
        self::PRIORITY_HIGH      => ['color_high', 'text_high', 'label_high'],
        self::PRIORITY_IMPORTANT => ['color_important', 'text_important', 'label_important'],
        self::PRIORITY_TOREAD    => ['color_toread', 'text_toread', 'label_toread'],
        self::PRIORITY_INFO      => ['color_info', 'text_info', 'label_info'],
        self::PRIORITY_LOW       => ['color_low', 'text_low', 'label_low'],
    ];

    /**
     * Descriptions de priorité, de poids et de seuils mises en cache.
     *
     * Ces trois lectures reposent entièrement sur les réglages, qui ne
     * bougent pas au cours d'une requête.
     * Elles étaient jusqu'ici refaites pour **chaque carte** affichée -
     * avec, pour les priorités, cinq calculs de contraste et une quinzaine
     * de traductions à chaque fois.
     * Une page de cartes les demande maintenant une seule fois.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $priorities = null;

    /** @var array{source:int, technology:int, category:int, freshness:int}|null */
    private static ?array $weights = null;

    /** @var array<string, int>|null */
    private static ?array $thresholds = null;

    /**
     * Oublie les valeurs dérivées des réglages.
     *
     * Appelée à l'enregistrement des réglages : l'écran de configuration
     * affiche son propre aperçu juste après avoir écrit, et doit le
     * calculer sur les nouvelles valeurs.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::$priorities = null;
        self::$weights    = null;
        self::$thresholds = null;
    }

    /**
     * Libellé, couleur et icône de chaque niveau de priorité.
     *
     * Le libellé, le fond et le texte sont **libres** et viennent des
     * réglages.
     * Un libellé vide rend celui livré, qui a l'avantage de suivre la
     * langue de l'utilisateur.
     * L'écran de réglage affiche le contraste obtenu et signale les
     * combinaisons peu lisibles, sans les interdire.
     *
     * @return array<string, array{label:string, color:string, text_color:string, icon:string, contrast:float, readable:bool, suggested_text:string}>
     */
    public static function getPriorities(): array
    {
        if (self::$priorities !== null) {
            return self::$priorities;
        }

        $priorities = [];
        foreach (self::getDefaultLabels() as $level => [$default_label, $icon]) {
            [$color_key, $text_key, $label_key] = self::SETTING_KEYS[$level];

            $color = (string) Settings::get($color_key);
            $text  = (string) Settings::get($text_key);
            $label = (string) Settings::get($label_key);

            $contrast = self::contrastRatio($color, $text);

            $priorities[$level] = [
                'label'          => $label !== '' ? $label : $default_label,
                'default_label'  => $default_label,
                'custom_label'   => $label,
                'color'          => $color,
                'text_color'     => $text,
                'icon'           => $icon,
                'contrast'       => round($contrast, 2),
                'readable'       => $contrast >= self::MIN_CONTRAST,
                // Ce que donnerait le meilleur des deux extrêmes : de quoi
                // rattraper une combinaison illisible en un clic.
                'suggested_text' => self::readableTextColor($color),
            ];
        }

        return self::$priorities = $priorities;
    }

    /**
     * Libellé livré et icône de chaque niveau, dans l'ordre décroissant.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    private static function getDefaultLabels(): array
    {
        return [
            self::PRIORITY_HIGH      => [__('Prioritaire', 'veilletool'), 'ti ti-alert-triangle-filled'],
            self::PRIORITY_IMPORTANT => [__('Important', 'veilletool'), 'ti ti-flag-filled'],
            self::PRIORITY_TOREAD    => [__('À lire', 'veilletool'), 'ti ti-bookmark'],
            self::PRIORITY_INFO      => [__('Information', 'veilletool'), 'ti ti-info-circle'],
            self::PRIORITY_LOW       => [__('Faible intérêt', 'veilletool'), 'ti ti-circle-minus'],
        ];
    }

    /**
     * Rapport de contraste entre deux couleurs, au sens de la WCAG.
     *
     * De 1 (identiques) à 21 (noir contre blanc).
     * Le seuil AA pour du texte courant est 4,5.
     *
     * @param string $first  Notation `#rrggbb`
     * @param string $second Notation `#rrggbb`
     *
     * @return float
     */
    public static function contrastRatio(string $first, string $second): float
    {
        $a = self::relativeLuminance($first);
        $b = self::relativeLuminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Couleur de texte la plus lisible sur un fond donné : noir ou blanc.
     *
     * Le texte se règle librement.
     * Cette valeur n'est qu'une suggestion, proposée par l'écran de réglage
     * quand la combinaison retenue passe sous le seuil de lisibilité.
     *
     * @param string $background Notation `#rrggbb`
     *
     * @return string
     */
    public static function readableTextColor(string $background): string
    {
        $luminance = self::relativeLuminance($background);

        // Les deux contrastes s'égalisent à L ≈ 0,179 : au-dessus, le noir
        // l'emporte.
        // En dessous, le blanc.
        return ($luminance + 0.05) / 0.05 >= 1.05 / ($luminance + 0.05)
            ? '#000000'
            : '#ffffff';
    }

    /**
     * Luminance relative d'une couleur, au sens de la WCAG.
     *
     * @param string $color Notation `#rrggbb`
     *
     * @return float De 0 (noir) à 1 (blanc)
     */
    public static function relativeLuminance(string $color): float
    {
        $hex = ltrim(trim($color), '#');

        if (strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            // Fond inexploitable : on suppose un fond sombre, donc un texte
            // clair - c'est le cas le plus courant pour une étiquette.
            return 0.0;
        }

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value      = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Bornes basses effectives de chaque niveau, telles qu'elles sont
     * réglées par l'utilisateur.
     *
     * @return array<string, int>
     */
    public static function getThresholds(): array
    {
        return self::$thresholds ??= [
            self::PRIORITY_HIGH      => Settings::getInt('priority_high'),
            self::PRIORITY_IMPORTANT => Settings::getInt('priority_important'),
            self::PRIORITY_TOREAD    => Settings::getInt('priority_toread'),
            self::PRIORITY_INFO      => Settings::getInt('priority_info'),
        ];
    }

    /**
     * Niveau de priorité correspondant à un score.
     *
     * @param int $score
     *
     * @return string Une des constantes PRIORITY_*
     */
    public static function getPriority(int $score): string
    {
        $thresholds = self::getThresholds();

        foreach ($thresholds as $level => $floor) {
            if ($score >= $floor) {
                return $level;
            }
        }

        return self::PRIORITY_LOW;
    }

    /**
     * Description complète de la priorité d'un score, prête à afficher.
     *
     * @param int $score
     *
     * @return array{level:string, label:string, color:string, icon:string}
     */
    public static function describe(int $score): array
    {
        $level = self::getPriority($score);

        return ['level' => $level] + self::getPriorities()[$level];
    }

    /**
     * Poids des quatre composantes.
     *
     * @return array{source:int, technology:int, category:int, freshness:int}
     */
    public static function getWeights(): array
    {
        return self::$weights ??= [
            'source'     => Settings::getInt('weight_source'),
            'technology' => Settings::getInt('weight_technology'),
            'category'   => Settings::getInt('weight_category'),
            'freshness'  => Settings::getInt('weight_freshness'),
        ];
    }

    /**
     * Composante de fraîcheur d'une date de publication.
     *
     * Une carte vaut 100 tant qu'elle n'a pas dépassé le **délai de
     * grâce**, puis perd le nombre de **points par jour** réglé, jusqu'à
     * zéro.
     * Dire « elle commence à vieillir au bout de sept jours et perd cinq
     * points par jour » se vérifie d'un coup d'œil.
     * Un horizon de décroissance ne se vérifiait pas.
     *
     * Une date future est traitée comme le jour même.
     *
     * @param string $date_published
     *
     * @return int
     */
    public static function freshness(string $date_published): int
    {
        $date_published = trim($date_published);

        if ($date_published === '' || $date_published === '0000-00-00 00:00:00') {
            return 0;
        }

        $timestamp = strtotime($date_published);
        if ($timestamp === false) {
            return 0;
        }

        $grace_days   = Settings::getInt('freshness_grace_days');
        $loss_per_day = Settings::getInt('freshness_loss_per_day');

        $age_days = max(0, (time() - $timestamp) / DAY_TIMESTAMP);
        $decaying = max(0, $age_days - $grace_days);

        return (int) max(0, min(100, round(100 - $loss_per_day * $decaying)));
    }

    /**
     * Âge, en jours, auquel une carte cesse de valoir quelque chose en
     * fraîcheur.
     *
     * Renvoyé pour l'affichage : l'exploitant règle un délai et une pente,
     * mais c'est ce point d'extinction qui lui dit ce qu'il a réglé.
     *
     * @return int|null Null si la fraîcheur ne décroît jamais
     */
    public static function freshnessZeroDay(): ?int
    {
        $loss_per_day = Settings::getInt('freshness_loss_per_day');

        if ($loss_per_day <= 0) {
            return null;
        }

        return Settings::getInt('freshness_grace_days') + (int) ceil(100 / $loss_per_day);
    }

    /**
     * Score d'intérêt d'une ligne de carte déjà chargée.
     *
     * @param array<string, mixed> $row Colonnes de la carte
     *
     * @return int Note de 0 à 100
     */
    public static function fromRow(array $row): int
    {
        return self::combine([
            'source'     => (int) ($row['score_source'] ?? 0),
            'technology' => (int) ($row['score_technology'] ?? 0),
            'category'   => (int) ($row['score_category'] ?? 0),
            'freshness'  => self::freshness((string) ($row['date_published'] ?? '')),
        ]);
    }

    /**
     * Moyenne pondérée des quatre composantes, ramenée sur 100.
     *
     * @param array{source:int, technology:int, category:int, freshness:int} $components
     *
     * @return int
     */
    public static function combine(array $components): int
    {
        $weights = self::getWeights();
        $total   = array_sum($weights);

        if ($total <= 0) {
            return 0;
        }

        $sum = 0;
        foreach ($weights as $name => $weight) {
            $sum += $weight * max(0, min(100, (int) ($components[$name] ?? 0)));
        }

        return (int) max(0, min(100, round($sum / $total)));
    }

    /**
     * Détail des composantes d'une carte, pour l'expliquer à l'utilisateur.
     *
     * @param array<string, mixed> $row
     *
     * @return array<int, array{key:string, label:string, value:int, weight:int}>
     */
    public static function explain(array $row): array
    {
        $weights = self::getWeights();

        $values = [
            'source'     => (int) ($row['score_source'] ?? 0),
            'technology' => (int) ($row['score_technology'] ?? 0),
            'category'   => (int) ($row['score_category'] ?? 0),
            'freshness'  => self::freshness((string) ($row['date_published'] ?? '')),
        ];

        $labels = [
            'source'     => __('Source', 'veilletool'),
            'technology' => __('Technologie', 'veilletool'),
            'category'   => __('Catégorie', 'veilletool'),
            'freshness'  => __('Fraîcheur', 'veilletool'),
        ];

        $detail = [];
        foreach ($values as $key => $value) {
            $detail[] = [
                'key'    => $key,
                'label'  => $labels[$key],
                'value'  => $value,
                'weight' => $weights[$key],
            ];
        }

        return $detail;
    }

    /**
     * Colonnes de la table des cartes que le calcul du score lit.
     *
     * Le score dépend de l'heure : aucun index ne peut en porter l'ordre,
     * et la base doit noter chaque carte retenue avant de pouvoir classer.
     * Ce qui **peut** être indexé, ce sont ces entrées-là : la base trie
     * alors en ne lisant qu'un index étroit, au lieu de parcourir des
     * lignes qui portent le contenu des cartes.
     *
     * Le barème n'y figure pas, et c'est voulu : les poids, l'horizon de
     * fraîcheur et les seuils de priorité vivent dans la requête, pas dans
     * l'index.
     * On les règle donc librement sans jamais rien reconstruire.
     *
     * Cette liste est la **référence** de l'index `score_order` : c'est
     * elle que l'installation pose et que la mise à jour compare à ce qui
     * est en base.
     * Toucher au calcul ci-dessous sans la mettre à jour laisserait l'index
     * à côté de la plaque - et la comparaison faite à la mise à jour le
     * reconstruirait de travers.
     *
     * @return string[]
     */
    public static function orderColumns(): array
    {
        return ['score_source', 'score_technology', 'score_category', 'date_published'];
    }

    /**
     * Expression SQL calculant le score d'une carte.
     *
     * Le tri de l'observatoire s'appuie dessus : la fraîcheur y est donc
     * évaluée par la base, ce qui garantit un classement à jour sans
     * recalcul nocturne.
     *
     * Les colonnes lues ici sont celles qu'annonce `orderColumns()`.
     *
     * @param string $table Table des cartes, préfixée si besoin
     *
     * @return QueryExpression
     */
    public static function sqlExpression(string $table): QueryExpression
    {
        $weights = self::getWeights();
        $total   = array_sum($weights);

        if ($total <= 0) {
            return new QueryExpression('0');
        }

        $grace_days   = Settings::getInt('freshness_grace_days');
        $loss_per_day = Settings::getInt('freshness_loss_per_day');

        $source    = DBmysql::quoteName($table . '.score_source');
        $tech      = DBmysql::quoteName($table . '.score_technology');
        $category  = DBmysql::quoteName($table . '.score_category');
        $published = DBmysql::quoteName($table . '.date_published');

        // Une date nulle est traitée comme infiniment ancienne, une date
        // future comme le jour même : dans les deux cas le calcul reste
        // dans l'intervalle [0, 100].
        $age_days = sprintf(
            'GREATEST(0, COALESCE(TIMESTAMPDIFF(HOUR, %s, NOW()), %d) / 24)',
            $published,
            self::NULL_DATE_AGE_HOURS
        );

        $freshness = sprintf(
            'GREATEST(0, 100 - %d * GREATEST(0, %s - %d))',
            $loss_per_day,
            $age_days,
            $grace_days
        );

        return new QueryExpression(sprintf(
            'ROUND((%d * %s + %d * %s + %d * %s + %d * (%s)) / %d)',
            $weights['source'],
            $source,
            $weights['technology'],
            $tech,
            $weights['category'],
            $category,
            $weights['freshness'],
            $freshness,
            $total
        ));
    }

    /**
     * Expression SQL de tri par score d'intérêt.
     *
     * @param string $table
     * @param string $direction `DESC` ou `ASC`
     *
     * @return QueryExpression
     */
    public static function sqlOrder(string $table, string $direction = 'DESC'): QueryExpression
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        return new QueryExpression(self::sqlExpression($table)->getValue() . ' ' . $direction);
    }

    /**
     * Calcule les trois composantes stockées d'une carte.
     *
     * Les composantes déjà connues de l'appelant sont reprises telles
     * quelles.
     * Enregistrer une carte, c'est précisément lui poser ses thèmes et ses
     * technologies : les relire aussitôt pour en tirer le meilleur revenait
     * à demander à la base ce qu'on venait de lui écrire.
     *
     * @param array<string, mixed> $card Doit porter `id` et
     *                                   `plugin_veilletool_sources_id`
     * @param array<string, int>   $known Composantes déjà calculées, parmi
     *                                    `source`, `technology` et `category`
     *
     * @return array{score_source:int, score_technology:int, score_category:int}
     */
    public static function computeStoredComponents(array $card, array $known = []): array
    {
        $articles_id = (int) ($card['id'] ?? 0);

        return [
            'score_source'     => array_key_exists('source', $known)
                ? max(0, min(100, (int) $known['source']))
                : Source::getInterest((int) ($card['plugin_veilletool_sources_id'] ?? 0)),
            'score_technology' => array_key_exists('technology', $known)
                ? max(0, min(100, (int) $known['technology']))
                : Article_Technology::getBestConfidence($articles_id),
            'score_category'   => array_key_exists('category', $known)
                ? max(0, min(100, (int) $known['category']))
                : Article_Category::getBestInterest($articles_id),
        ];
    }

    /**
     * Recalcule et enregistre les composantes stockées d'une carte.
     *
     * L'écriture est directe : les composantes sont une donnée dérivée, pas
     * une saisie.
     * Passer par `CommonDBTM::update()` déclencherait un nouveau
     * `post_updateItem()` - donc un nouveau calcul - et gonflerait
     * l'historique d'une ligne à chaque réindexation.
     *
     * @param array<string, mixed> $card
     * @param array<string, int>   $known Composantes déjà calculées. Voir
     *                                    computeStoredComponents()
     * @param bool                 $indexed
     *        Vrai si la classification technologique vient d'être refaite.
     *        Faux, la carte **reste** dans la file de reclassement : sortir
     *        d'une file sans avoir fait le travail qu'elle commande laissait
     *        une carte classée selon l'ancien référentiel, sans que rien ne
     *        le signale.
     * @param array<string, mixed> $current
     *        Valeurs actuellement en base, quand l'appelant les a déjà.
     *
     * @return int Score obtenu
     */
    public static function refresh(
        array $card,
        array $known = [],
        bool $indexed = true,
        array $current = []
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_id = (int) ($card['id'] ?? 0);
        if ($articles_id <= 0) {
            return 0;
        }

        $components = self::computeStoredComponents($card, $known);

        // Une collecte repasse sur des dizaines de cartes dont rien n'a
        // bougé : leur réécrire les mêmes valeurs coûte une écriture, un
        // verrou et une ligne de journal binaire pour rien.
        $unchanged = $current !== []
            && (!$indexed || (int) ($current['is_indexed'] ?? 0) === 1);

        if ($unchanged) {
            foreach ($components as $column => $value) {
                if ((int) ($current[$column] ?? -1) !== $value) {
                    $unchanged = false;
                    break;
                }
            }
        }

        if (!$unchanged) {
            // La carte sort de la file de reclassement : ses composantes
            // reflètent désormais l'état du référentiel et des notes
            // d'intérêt.
            $DB->update(
                Article::getTable(),
                $indexed ? $components + ['is_indexed' => 1] : $components,
                ['id' => $articles_id]
            );
        }

        return self::combine([
            'source'     => $components['score_source'],
            'technology' => $components['score_technology'],
            'category'   => $components['score_category'],
            'freshness'  => self::freshness((string) ($card['date_published'] ?? '')),
        ]);
    }
}
