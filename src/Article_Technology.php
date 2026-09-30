<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use CronTask;
use Glpi\Error\ErrorHandler;
use Toolbox;

/**
 * Classification technologique d'une carte, matérialisée en base.
 *
 * Le rapprochement entre le texte d'une carte et le référentiel est coûteux
 * : il est donc calculé une fois, à l'enregistrement de la carte, et stocké
 * ici.
 * Les trois colonnes `categorie`, `famille` et `name` reprennent les
 * niveaux du référentiel, ce qui permet de filtrer et d'agréger les cartes
 * par une simple requête SQL, sans plafond ni traitement en mémoire.
 *
 * Une carte possède autant de lignes que de technologies reconnues.
 * Une carte sans ligne est une carte « hors référentiel ».
 *
 * La colonne `name` est vide lorsque le rattachement s'arrête à la famille,
 * faute d'élément permettant de trancher entre ses sous-catégories.
 *
 * La colonne `confidence` dit **sur quoi repose** le rattachement : un
 * libellé cité mot pour mot vaut 100, une simple racine de produit 50.
 * Elle alimente l'affichage des cartes et la composante technologique du
 * score d'intérêt.
 *
 * La colonne `is_manual` distingue enfin les rattachements décidés par un
 * administrateur de ceux trouvés dans le texte.
 * Un administrateur sait ce dont parle la carte quand la machine l'ignore :
 * sa décision vaut 100, et le reclassement automatique ne la remet jamais
 * en cause.
 */
class Article_Technology extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_veille';

    /** Nom de la tâche automatique de reclassement */
    public const CRON_TASK = 'ReindexTechnologies';

    /** Nombre de cartes traitées par passage de la tâche automatique */
    public const CRON_BATCH = 500;

    /**
     * Confiance d'un rattachement décidé à la main.
     *
     * Elle ne se discute pas : quelqu'un a lu la carte et l'a rattachée.
     */
    public const MANUAL_CONFIDENCE = 100;

    public static function getTypeName($nb = 0)
    {
        return _n('Technologie de la carte', 'Technologies de la carte', $nb, 'veilletool');
    }

    /**
     * Remplace la classification d'une carte.
     *
     * @param int                                                                                $articles_id
     * @param array<int, array{categorie:string, famille:string, name:string, confidence:int}>   $targets
     *
     * @param bool                                                             $may_exist
     *        Faux lorsque l'appelant sait déjà que la carte n'a aucun
     *        rattachement : la suppression préalable est alors inutile.
     *
     * @return array{links:int, confidence:int}
     *         Nombre de rattachements enregistrés, et meilleure confiance
     *         obtenue - laquelle est la composante « technologie » du score
     *         de la carte.
     */
    public static function setForArticle(int $articles_id, array $targets, bool $may_exist = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return ['links' => 0, 'confidence' => 0];
        }

        // Les rattachements décidés à la main ne sont pas une déduction :
        // ils survivent au reclassement, et le rapprochement automatique ne
        // les réécrit pas.
        // Leur confiance est relevée au passage : elle entre dans le score
        // au même titre que les autres.
        $manual = $may_exist ? self::getManualKeys($articles_id) : [];
        $best   = $manual === [] ? 0 : max($manual);
        $links  = count($manual);

        if ($may_exist) {
            // Lignes de liaison nues : ni historique, ni traitement après
            // enregistrement. `deleteByCriteria()` relisait la table puis
            // supprimait ligne à ligne, là où une seule requête suffit.
            $DB->delete(self::getTable(), [
                'plugin_veilletool_articles_id' => $articles_id,
                'is_manual'                       => 0,
            ]);
        }

        $rows = [];
        foreach ($targets as $target) {
            $key = $target['categorie'] . '|' . $target['famille'] . '|' . $target['name'];

            if (isset($manual[$key])) {
                continue;
            }

            // Deux chemins peuvent mener à la même entrée : on retient le
            // plus fiable.
            if (
                !isset($rows[$key])
                || (int) ($rows[$key]['confidence'] ?? 0) < (int) ($target['confidence'] ?? 0)
            ) {
                $rows[$key] = $target;
            }
        }

        foreach ($rows as $target) {
            $confidence = max(0, min(100, (int) ($target['confidence'] ?? 0)));
            $best       = max($best, $confidence);

            $DB->insert(self::getTable(), [
                'plugin_veilletool_articles_id' => $articles_id,
                'categorie'                       => mb_substr($target['categorie'], 0, 100),
                'famille'                         => mb_substr($target['famille'], 0, 190),
                'name'                            => mb_substr($target['name'], 0, 190),
                'confidence'                      => $confidence,
                'is_manual'                       => 0,
            ]);
        }

        return ['links' => $links + count($rows), 'confidence' => $best];
    }

    /**
     * Clés des rattachements décidés à la main pour une carte.
     *
     * @param int $articles_id
     *
     * @return array<string, int> Clé « catégorie|famille|sous-catégorie »
     *                             => confiance du rattachement
     */
    private static function getManualKeys(int $articles_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $keys     = [];
        $iterator = $DB->request([
            'SELECT' => ['categorie', 'famille', 'name', 'confidence'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_veilletool_articles_id' => $articles_id,
                'is_manual'                       => 1,
            ],
        ]);

        foreach ($iterator as $row) {
            $keys[$row['categorie'] . '|' . $row['famille'] . '|' . $row['name']] =
                (int) $row['confidence'];
        }

        return $keys;
    }

    /**
     * Rattache une carte à une entrée du référentiel, sur décision d'un
     * administrateur.
     *
     * La catégorie et la famille ne se saisissent pas : elles viennent de
     * l'entrée choisie, qui les porte déjà.
     * La confiance vaut 100 - c'est une lecture humaine, pas une
     * correspondance de texte.
     *
     * @param int $articles_id
     * @param int $technologies_id Entrée du référentiel
     *
     * @return array{status:string, label:string}
     *         `status` vaut `added`, `exists`, `promoted` ou `unknown`.
     */
    public static function addManual(int $articles_id, int $technologies_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $technology = new Technology();

        if (
            $articles_id <= 0
            || $technologies_id <= 0
            || !$technology->getFromDB($technologies_id)
        ) {
            return ['status' => 'unknown', 'label' => ''];
        }

        $criteria = [
            'plugin_veilletool_articles_id' => $articles_id,
            'categorie'                       => (string) $technology->fields['categorie'],
            'famille'                         => (string) $technology->fields['famille'],
            'name'                            => (string) $technology->fields['name'],
        ];

        $label = (string) $technology->fields['name'] !== ''
            ? (string) $technology->fields['name']
            : (string) $technology->fields['famille'];

        $existing = $DB->request([
            'SELECT' => ['id', 'is_manual'],
            'FROM'   => self::getTable(),
            'WHERE'  => $criteria,
            'LIMIT'  => 1,
        ])->current();

        if ($existing !== null) {
            if ((int) $existing['is_manual'] === 1) {
                return ['status' => 'exists', 'label' => $label];
            }

            // Le rapprochement automatique avait déjà vu cette technologie,
            // avec plus ou moins d'assurance : la décision la confirme.
            $DB->update(
                self::getTable(),
                ['confidence' => self::MANUAL_CONFIDENCE, 'is_manual' => 1],
                ['id' => (int) $existing['id']]
            );

            return ['status' => 'promoted', 'label' => $label];
        }

        (new self())->add($criteria + [
            'confidence' => self::MANUAL_CONFIDENCE,
            'is_manual'  => 1,
        ]);

        return ['status' => 'added', 'label' => $label];
    }

    /**
     * Retire un rattachement décidé à la main.
     *
     * Seuls ceux-là se retirent : un rattachement automatique reviendrait
     * au reclassement suivant, et le retirer donnerait l'illusion d'agir.
     *
     * @param int $articles_id
     * @param int $id Ligne de rattachement
     *
     * @return bool
     */
    public static function removeManual(int $articles_id, int $id): bool
    {
        if ($articles_id <= 0 || $id <= 0) {
            return false;
        }

        return (new self())->deleteByCriteria([
            'id'                              => $id,
            'plugin_veilletool_articles_id' => $articles_id,
            'is_manual'                       => 1,
        ], true);
    }

    /**
     * Retire tous les rattachements visant une entrée du référentiel.
     *
     * Appelé quand l'entrée disparaît : un rattachement décidé à la main
     * survit au reclassement, il faut donc le retirer explicitement, sans
     * quoi il désignerait une technologie qui n'existe plus.
     *
     * @param string $categorie
     * @param string $famille
     * @param string $name
     *
     * @return void
     */
    public static function forgetEntry(string $categorie, string $famille, string $name): void
    {
        (new self())->deleteByCriteria([
            'categorie' => $categorie,
            'famille'   => $famille,
            'name'      => $name,
        ], true);
    }

    /**
     * Retire les rattachements manuels devenus orphelins.
     *
     * Un rattachement automatique se refait tout seul au reclassement
     * suivant : il n'a pas besoin d'être nettoyé.
     * Un rattachement décidé à la main, lui, survit précisément à ce
     * reclassement - il faut donc le retirer explicitement quand l'entrée
     * du référentiel qu'il désigne a disparu.
     * Ils sont peu nombreux par construction : le contrôle se fait sur les
     * seuls triplets distincts.
     *
     * @return int Nombre de rattachements retirés
     */
    public static function forgetOrphanManualLinks(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $known = [];
        foreach (
            $DB->request([
                'SELECT' => ['categorie', 'famille', 'name'],
                'FROM'   => Technology::getTable(),
            ]) as $row
        ) {
            $known[$row['categorie'] . '|' . $row['famille'] . '|' . $row['name']] = true;
        }

        $orphans  = [];
        $iterator = $DB->request([
            'SELECT'   => ['categorie', 'famille', 'name'],
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
            'WHERE'    => ['is_manual' => 1],
        ]);

        foreach ($iterator as $row) {
            $key = $row['categorie'] . '|' . $row['famille'] . '|' . $row['name'];

            if (!isset($known[$key])) {
                $orphans[] = $row;
            }
        }

        $removed = 0;
        foreach ($orphans as $orphan) {
            $DB->delete(self::getTable(), [
                'categorie' => $orphan['categorie'],
                'famille'   => $orphan['famille'],
                'name'      => $orphan['name'],
                'is_manual' => 1,
            ]);

            $removed += $DB->affectedRows();
        }

        return $removed;
    }

    /**
     * Recalcule la classification d'une carte à partir de son texte.
     *
     * @param array<string, mixed>   $card    Champs de la carte
     * @param TechnologyMatcher|null $matcher Réutilisé lors d'une réindexation
     *
     * @return array{links:int, confidence:int} voir setForArticle()
     */
    public static function indexArticle(
        array $card,
        ?TechnologyMatcher $matcher = null,
        bool $may_exist = true
    ): array {
        $articles_id = (int) ($card['id'] ?? 0);

        if ($articles_id <= 0) {
            return ['links' => 0, 'confidence' => 0];
        }

        $matcher ??= TechnologyMatcher::getInstance();

        return self::setForArticle($articles_id, $matcher->match($card), $may_exist);
    }

    /**
     * Recalcule la classification de toutes les cartes, sans attendre.
     *
     * Réservé au bouton « Reclasser les cartes » : l'installation et les
     * modifications du référentiel se contentent, elles, de marquer les
     * cartes à revoir et laissent la tâche automatique faire le travail.
     *
     * @return array{articles:int, links:int}
     */
    public static function reindexAll(): array
    {
        self::markForReindex();

        // Un reclassement complet peut porter sur plusieurs milliers de
        // cartes : on desserre la limite de temps, comme le fait GLPI pour
        // ses propres traitements longs.
        $max_execution_time = (int) ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 300) {
            Toolbox::safeIniSet('max_execution_time', '300');
        }

        $articles = 0;
        $links    = 0;

        // Le traitement est le même que celui de la tâche automatique : il
        // est simplement répété jusqu'à épuisement de la file.
        do {
            $done = self::processPending(self::CRON_BATCH);
            $articles += $done['articles'];
            $links    += $done['links'];
        } while ($done['articles'] > 0);

        return ['articles' => $articles, 'links' => $links];
    }

    /**
     * Marque des cartes comme devant être reclassées.
     *
     * Une seule requête suffit : la tâche automatique reprendra la file au
     * passage suivant, par lots, sans bloquer l'écran qui a provoqué le
     * changement.
     *
     * @param array $criteria Critères GLPI restreignant les cartes visées.
     *                        vide = toutes les cartes.
     *
     * @return int nombre de cartes marquées
     */
    public static function markForReindex(array $criteria = []): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        // La condition `is_indexed = 1` évite de réécrire les lignes déjà
        // en attente, et donc de gonfler inutilement le journal binaire.
        $DB->update(
            Article::getTable(),
            ['is_indexed' => 0],
            $criteria + ['is_indexed' => 1]
        );

        return $DB->affectedRows();
    }

    /**
     * Nombre de cartes en attente de reclassement.
     *
     * @return int
     */
    public static function countPending(): int
    {
        return countElementsInTable(Article::getTable(), ['is_indexed' => 0]);
    }

    /**
     * Traite un lot de cartes en attente de reclassement.
     *
     * Chaque carte est validée séparément : une carte dont la
     * classification échoue ne laisse pas le lot à moitié écrit, et le
     * passage suivant la retrouvera dans la file.
     *
     * @param int $limit Nombre maximum de cartes traitées
     *
     * @return array{articles:int, links:int, errors:int}
     */
    public static function processPending(int $limit = self::CRON_BATCH): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $limit = max(1, $limit);

        $rows     = [];
        $iterator = $DB->request([
            'SELECT' => [
                'id',
                'name',
                'summary',
                'content',
                'date_published',
                'plugin_veilletool_sources_id',
            ],
            'FROM'   => Article::getTable(),
            'WHERE'  => ['is_indexed' => 0],
            'ORDER'  => ['id ASC'],
            'LIMIT'  => $limit,
        ]);

        foreach ($iterator as $row) {
            $rows[(int) $row['id']] = $row;
        }

        if ($rows === []) {
            return ['articles' => 0, 'links' => 0, 'errors' => 0];
        }

        // Le référentiel a pu changer depuis le dernier passage : on repart
        // d'un index neuf, construit une seule fois pour tout le lot.
        TechnologyMatcher::invalidate();
        $matcher = TechnologyMatcher::getInstance();

        // Les thèmes de tout le lot sont relevés en une requête.
        // La composante « catégorie » du score s'en déduit ensuite en
        // mémoire, là où elle demandait une jointure par carte reclassée.
        $themes = Article_Category::getCategoryIdsForArticles(array_keys($rows));

        $articles = 0;
        $links    = 0;
        $errors   = 0;

        foreach ($rows as $articles_id => $row) {
            $DB->beginTransaction();

            try {
                $indexed = self::indexArticle($row, $matcher);
                $links   += $indexed['links'];

                // Sort aussi la carte de la file de reclassement.
                // Les deux composantes viennent d'être établies : les
                // relire serait demander à la base ce qu'on vient de lui
                // écrire.
                Score::refresh($row, [
                    'technology' => $indexed['confidence'],
                    'category'   => Article_Category::bestInterestOf($themes[$articles_id] ?? []),
                ]);

                $DB->commit();
                $articles++;
            } catch (\Throwable $e) {
                $DB->rollBack();
                $errors++;

                // La carte reste dans la file.
                // On la saute pour ce passage afin qu'une seule carte
                // fautive ne bloque pas les autres.
                ErrorHandler::logCaughtException($e);
            }
        }

        return ['articles' => $articles, 'links' => $links, 'errors' => $errors];
    }

    /**
     * Informations affichées dans la configuration des actions
     * automatiques.
     *
     * @param string $name
     *
     * @return array<string, string>
     */
    public static function cronInfo($name)
    {
        if ($name === self::CRON_TASK) {
            return [
                'description' => __('Reclassement technologique des cartes de veille', 'veilletool'),
            ];
        }

        return [];
    }

    /**
     * Tâche automatique : reclasse les cartes en attente, par lots.
     *
     * @param CronTask $task
     *
     * @return int 1 si des cartes ont été reclassées, 0 sinon
     */
    public static function cronReindexTechnologies(CronTask $task)
    {
        $limit = max(1, (int) ($task->fields['param'] ?? self::CRON_BATCH));

        $done = self::processPending($limit);

        $task->setVolume($done['articles']);

        if ($done['articles'] === 0) {
            return 0;
        }

        $remaining = self::countPending();

        $task->log(sprintf(
            '%d carte(s) reclassée(s), %d rattachement(s), %d en attente.',
            $done['articles'],
            $done['links'],
            $remaining
        ));

        return 1;
    }

    /**
     * Meilleure confiance obtenue par une carte, toutes technologies
     * confondues.
     *
     * C'est la composante technologique de son score d'intérêt : une carte
     * dont le rattachement est certain vaut mieux qu'une carte rattachée
     * sur une simple racine de produit.
     *
     * @param int $articles_id
     *
     * @return int 0 si la carte est hors référentiel
     */
    public static function getBestConfidence(int $articles_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return 0;
        }

        $row = $DB->request([
            'SELECT' => ['MAX' => 'confidence AS best'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_veilletool_articles_id' => $articles_id],
        ])->current();

        return $row !== null ? (int) $row['best'] : 0;
    }

    /**
     * Technologies reconnues sur un lot de cartes, les plus sûres d'abord.
     *
     * @param int[] $articles_ids
     *
     * @return array<int, array<int, array{label:string, categorie:string, famille:string, name:string, confidence:int}>>
     */
    public static function getForArticles(array $articles_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_filter(array_map('intval', $articles_ids)));
        if ($articles_ids === []) {
            return [];
        }

        $result   = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_veilletool_articles_id' => $articles_ids],
            'ORDER' => ['is_manual DESC', 'confidence DESC', 'name ASC', 'famille ASC'],
        ]);

        foreach ($iterator as $row) {
            $articles_id = (int) $row['plugin_veilletool_articles_id'];

            $result[$articles_id][] = [
                'id'         => (int) $row['id'],
                // Un rattachement arrêté à la famille n'a pas de sous-
                // catégorie : c'est la famille qui fait office de libellé.
                'label'      => (string) $row['name'] !== '' ? (string) $row['name'] : (string) $row['famille'],
                'categorie'  => (string) $row['categorie'],
                'famille'    => (string) $row['famille'],
                'name'       => (string) $row['name'],
                'confidence' => (int) $row['confidence'],
                'is_manual'  => (int) $row['is_manual'] === 1,
            ];
        }

        return $result;
    }

    /**
     * Valeurs distinctes rencontrées pour un niveau donné.
     *
     * @param string $level `categorie` ou `famille`
     *
     * @return string[]
     */
    public static function getUsedValues(string $level): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!in_array($level, ['categorie', 'famille', 'name'], true)) {
            return [];
        }

        $values   = [];
        $iterator = $DB->request([
            'SELECT'   => [$level],
            'DISTINCT' => true,
            'FROM'     => self::getTable(),
            'ORDER'    => [$level . ' ASC'],
        ]);

        foreach ($iterator as $row) {
            $values[] = (string) $row[$level];
        }

        return $values;
    }
}
