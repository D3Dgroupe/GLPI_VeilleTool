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
 * Commentaire déposé sur une carte de veille.
 *
 * Déposer un commentaire relève du droit « Commenter », distinct du droit
 * d'écriture : un lecteur peut donner son analyse sans pouvoir toucher aux
 * cartes.
 * Chacun reprend ou retire ce qu'il a écrit.
 * La modération du propos d'autrui, elle, reste au droit d'écriture.
 */
class Comment extends CommonDBTM
{
    public static $rightname = 'plugin_veilletool_veille';

    public static function getTypeName($nb = 0)
    {
        return _n('Commentaire', 'Commentaires', $nb, 'veilletool');
    }

    /**
     * L'utilisateur courant peut-il déposer un commentaire ?
     *
     * @return bool
     */
    public static function canComment(): bool
    {
        return Session::haveRightsOr(self::$rightname, [Article::RIGHT_COMMENT, UPDATE]);
    }

    /**
     * L'utilisateur courant peut-il reprendre le commentaire d'un autre ?
     *
     * @return bool
     */
    public static function canModerate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    /**
     * L'utilisateur courant peut-il modifier ou retirer ce commentaire ?
     *
     * @param array<string, mixed> $comment Ligne du commentaire
     *
     * @return bool
     */
    public static function canEditRow(array $comment): bool
    {
        if (self::canModerate()) {
            return true;
        }

        return self::canComment()
            && (int) ($comment['users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    public static function getIcon()
    {
        return 'ti ti-message-circle';
    }

    public function prepareInputForAdd($input)
    {
        $input['content'] = trim((string) ($input['content'] ?? ''));

        if ($input['content'] === '') {
            Session::addMessageAfterRedirect(
                __('Le commentaire ne peut pas être vide.', 'veilletool'),
                false,
                ERROR
            );
            return false;
        }

        $input['users_id']        = (int) Session::getLoginUserID();
        $input['users_id_editor'] = 0;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('content', $input)) {
            $input['content'] = trim((string) $input['content']);

            if ($input['content'] === '') {
                Session::addMessageAfterRedirect(
                    __('Le commentaire ne peut pas être vide.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        $input['users_id_editor'] = (int) Session::getLoginUserID();

        return $input;
    }

    /**
     * Commentaires d'une carte, du plus ancien au plus récent.
     *
     * @param int $articles_id
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getForArticle(int $articles_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($articles_id <= 0) {
            return [];
        }

        $comments = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_veilletool_articles_id' => $articles_id],
            'ORDER' => ['date_creation ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $row['author_name'] = getUserName((int) $row['users_id']);
            $row['editor_name'] = (int) $row['users_id_editor'] > 0
                ? getUserName((int) $row['users_id_editor'])
                : '';
            // On se fie à l'éditeur enregistré plutôt qu'à la comparaison
            // des dates : une correction faite dans la même seconde que la
            // création passerait sinon inaperçue.
            $row['is_edited']   = (int) $row['users_id_editor'] > 0;
            // Chacun reprend ce qu'il a écrit.
            // Le droit d'écriture permet en plus de modérer les autres.
            $row['can_edit']    = self::canEditRow($row);
            $comments[] = $row;
        }

        return $comments;
    }

    /**
     * Nombre de commentaires pour chacune des cartes fournies.
     *
     * @param int[] $articles_ids
     *
     * @return array<int, int>
     */
    public static function countForArticles(array $articles_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $articles_ids = array_values(array_filter(array_map('intval', $articles_ids)));
        if ($articles_ids === []) {
            return [];
        }

        $counts   = array_fill_keys($articles_ids, 0);
        $iterator = $DB->request([
            'SELECT'   => [
                'plugin_veilletool_articles_id',
                'COUNT DISTINCT' => 'id AS nb',
            ],
            'FROM'     => self::getTable(),
            'WHERE'    => ['plugin_veilletool_articles_id' => $articles_ids],
            'GROUPBY'  => ['plugin_veilletool_articles_id'],
        ]);

        foreach ($iterator as $row) {
            $counts[(int) $row['plugin_veilletool_articles_id']] = (int) $row['nb'];
        }

        return $counts;
    }
}
