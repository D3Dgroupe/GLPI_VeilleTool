<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Feed;

use CronTask;
use GlpiPlugin\Veilletool\CronStatus;
use GlpiPlugin\Veilletool\Source;

/**
 * Le relevé automatique des flux, et le budget de temps qu'il se donne.
 *
 * GLPI appelle la tâche par son itemtype - `Source::cronCollectFeeds()` -
 * et cette porte d'entrée ne peut donc pas déménager. Ce qu'elle fait, si :
 * parcourir les flux actifs, s'arrêter avant que PHP ne le fasse pour nous,
 * et rendre compte de ce qui n'a pas pu être relevé.
 */
final class SourceCron
{
    /**
     * Temps réservé, en secondes, pour rendre compte et refermer
     * proprement.
     */
    private const BATCH_MARGIN = 10;

    /**
     * Informations affichées dans la configuration des actions
     * automatiques.
     *
     * @param string $name
     *
     * @return array<string, string>
     */
    public static function describe(string $name): array
    {
        if ($name === Source::CRON_TASK) {
            return [
                'description' => __('Collecte des flux RSS / Atom de la veille technologique', 'veilletool'),
            ];
        }

        return [];
    }

    /**
     * Tâche automatique : collecte de toutes les sources distantes actives.
     *
     * @param CronTask $task
     *
     * @return int 1 si des cartes ont été créées, 0 sinon, -1 en erreur
     */
    public static function collectAll(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $total_new = 0;
        $errors    = 0;

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Source::getTable(),
            'WHERE'  => [
                'is_active'  => 1,
                'sourcetype' => [Source::TYPE_RSS, Source::TYPE_ATOM],
                ['NOT' => ['url' => '']],
                ['NOT' => ['url' => null]],
            ],
        ]);

        $source   = new Source();
        $deadline = self::batchDeadline();
        $skipped  = 0;

        foreach ($iterator as $row) {
            if (!$source->getFromDB($row['id'])) {
                continue;
            }

            // Le temps restant ne suffit plus : les flux non relevés le
            // seront au passage suivant.
            // Mieux vaut cela qu'une interruption de PHP au milieu d'un
            // flux, dont rien ne rendrait compte.
            if ($deadline !== null && microtime(true) >= $deadline) {
                $skipped++;
                continue;
            }

            $result     = $source->collect();
            $total_new += $result['imported'];

            if ($result['errors'] !== []) {
                $errors++;
                $task->log(sprintf('%s : %s', $source->fields['name'], implode(' | ', $result['errors'])));
            }
        }

        if ($skipped > 0) {
            $task->log(sprintf(
                '%d flux non relevé(s) faute de temps. Ils le seront au passage suivant.',
                $skipped
            ));
        }

        $task->setVolume($total_new);

        if ($errors > 0 && $total_new === 0) {
            return -1;
        }

        return $total_new > 0 ? 1 : 0;
    }

    /**
     * État de la tâche automatique de collecte, pour l'écran des flux.
     *
     * @return array<string, mixed> voir CronStatus::describe()
     */
    public static function status(): array
    {
        return CronStatus::describe(Source::class, Source::CRON_TASK);
    }

    /**
     * Instant au-delà duquel un relevé par lot doit s'arrêter.
     *
     * Relever quarante flux, c'est quarante attentes réseau bout à bout.
     * Rien ne garantit que PHP en ait le temps : passé `max_execution_time`,
     * il interrompt le script au milieu d'un flux, sans que rien n'en rende
     * compte - ni les cartes déjà créées, ni celles qui manquent.
     *
     * Mieux vaut s'arrêter soi-même un peu avant, en le disant. Les flux non
     * relevés le seront au passage suivant, et le décompte est juste.
     *
     * La marge est prise sur le budget, pas sur l'heure courante : ce que
     * PHP compte, c'est le temps écoulé depuis le début du script, dont une
     * partie est déjà consommée quand on arrive ici.
     *
     * @return ?float Null lorsque PHP n'est pas borné dans le temps, ce qui
     *                est le cas d'une exécution en ligne de commande
     */
    public static function batchDeadline(): ?float
    {
        $budget = (int) ini_get('max_execution_time');

        if ($budget <= 0) {
            return null;
        }

        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return $start + max(self::BATCH_MARGIN, $budget - self::BATCH_MARGIN);
    }
}
