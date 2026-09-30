<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CronTask;
use Html;

/**
 * État d'une tâche automatique, mis en forme pour l'affichage.
 *
 * Le plugin en compte deux - la collecte des flux et le reclassement
 * technologique - et chacune est présentée sur l'écran qu'elle concerne :
 * l'utilisateur voit sur place si le travail a lieu, sans avoir à ouvrir la
 * configuration des actions automatiques.
 */
class CronStatus
{
    /**
     * Décrit une tâche automatique.
     *
     * @param string $itemtype Classe portant la tâche
     * @param string $name     Nom de la tâche
     *
     * @return array{
     *     exists: bool,
     *     id: int,
     *     frequency: string,
     *     enabled: bool,
     *     state: string,
     *     mode: string,
     *     is_cli: bool,
     *     lastrun: string,
     *     url: string
     * }
     */
    public static function describe(string $itemtype, string $name): array
    {
        $crontask = new CronTask();

        if (!$crontask->getFromDBbyName($itemtype, $name)) {
            return [
                'exists'    => false,
                'id'        => 0,
                'frequency' => '',
                'enabled'   => false,
                'state'     => '',
                'mode'      => '',
                'is_cli'    => false,
                'lastrun'   => '',
                // Chemin relatif : le gabarit le préfixe avec path().
                'url'       => CronTask::getSearchURL(false),
            ];
        }

        return [
            'exists'    => true,
            'id'        => (int) $crontask->fields['id'],
            'frequency' => self::formatFrequency((int) $crontask->fields['frequency']),
            'enabled'   => (int) $crontask->fields['state'] !== CronTask::STATE_DISABLE,
            'state'     => CronTask::getStateName((int) $crontask->fields['state']),
            'mode'      => CronTask::getModeName((int) $crontask->fields['mode']),
            'is_cli'    => (int) $crontask->fields['mode'] === CronTask::MODE_EXTERNAL,
            'lastrun'   => (string) ($crontask->fields['lastrun'] ?? ''),
            'url'       => CronTask::getFormURLWithID((int) $crontask->fields['id'], false),
        ];
    }

    /**
     * Met une périodicité en toutes lettres.
     *
     * Html::timestampToString() produirait « 1 hours 0 minutes » : on
     * préfère une formulation lisible, adaptée à la valeur réellement
     * configurée.
     *
     * @param int $seconds
     *
     * @return string
     */
    public static function formatFrequency(int $seconds): string
    {
        if ($seconds <= 0) {
            return __('à chaque passage', 'veilletool');
        }

        if ($seconds % DAY_TIMESTAMP === 0) {
            $days = intdiv($seconds, DAY_TIMESTAMP);

            return $days === 1
                ? __('une fois par jour', 'veilletool')
                : sprintf(_n('tous les %d jour', 'tous les %d jours', $days, 'veilletool'), $days);
        }

        if ($seconds % HOUR_TIMESTAMP === 0) {
            $hours = intdiv($seconds, HOUR_TIMESTAMP);

            return $hours === 1
                ? __('toutes les heures', 'veilletool')
                : sprintf(_n('toutes les %d heure', 'toutes les %d heures', $hours, 'veilletool'), $hours);
        }

        if ($seconds % MINUTE_TIMESTAMP === 0) {
            $minutes = intdiv($seconds, MINUTE_TIMESTAMP);

            return $minutes === 1
                ? __('toutes les minutes', 'veilletool')
                : sprintf(_n('toutes les %d minute', 'toutes les %d minutes', $minutes, 'veilletool'), $minutes);
        }

        return sprintf(__('toutes les %s', 'veilletool'), Html::timestampToString($seconds, false));
    }
}
