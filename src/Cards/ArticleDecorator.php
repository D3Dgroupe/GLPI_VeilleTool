<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool\Cards;

use Glpi\RichText\RichText;
use GlpiPlugin\Veilletool\AiPrompt;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Category;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Favorite;
use GlpiPlugin\Veilletool\FollowedTechnology;
use GlpiPlugin\Veilletool\ReadStatus;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\Source;

/**
 * Ce qu'une ligne de la base doit devenir pour être affichée.
 *
 * Une carte brute ne porte que ses colonnes. Il lui manque ses thèmes, son
 * état de lecture pour l'utilisateur courant, son favori, ses commentaires,
 * le nom de son flux, ses technologies, son score et son aperçu : douze
 * informations qui viennent d'ailleurs.
 *
 * Toutes sont relevées **pour le lot entier**, jamais carte par carte. C'est
 * la seule règle de cette classe, et c'est elle qui sépare une page qui
 * s'affiche d'une page qui fait deux cents requêtes.
 */
final class ArticleDecorator
{
    /** Longueur de l'aperçu affiché sur une carte */
    private const EXCERPT_LENGTH = 240;

    /**
     * Ajoute aux lignes brutes les catégories, l'état de lecture, le
     * favori, le nombre de commentaires et le nom de la source.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param int                              $users_id
     * @param bool                             $full
     *        Vrai pour le détail d'une carte, qui a besoin des quatre
     *        niveaux de question à l'IA. Les listes n'en affichent qu'un :
     *        les préparer tous multipliait par quatre le travail de mise en
     *        forme, pour trois questions que personne n'ouvrait.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function decorate(array $rows, int $users_id, bool $full = false): array
    {
        if ($rows === []) {
            return [];
        }

        $ids            = array_keys($rows);
        $all_categories = Category::getActiveCategories();
        $by_article     = Article_Category::getCategoryIdsForArticles($ids);
        $read_ids       = array_flip(ReadStatus::getReadArticleIds($users_id, $ids));
        $favorite_ids   = array_flip(Favorite::getFavoriteArticleIds($ids));
        $comment_counts = Comment::countForArticles($ids);
        $technologies   = Article_Technology::getForArticles($ids);
        $origins        = Article::getOrigins();

        // Les noms de flux sont résolus en une requête pour tout le lot :
        // un appel par carte faisait autant de requêtes que de lignes
        // affichées.
        $source_names = Source::getDisplayNames(array_map(
            static fn(array $row): int => (int) $row['plugin_veilletool_sources_id'],
            $rows
        ));

        $decorated = [];
        foreach ($rows as $id => $row) {
            $categories = [];
            foreach ($by_article[$id] ?? [] as $categories_id) {
                if (isset($all_categories[$categories_id])) {
                    $categories[] = $all_categories[$categories_id];
                }
            }

            $sources_id = (int) $row['plugin_veilletool_sources_id'];

            $row['id']            = $id;
            $row['categories']    = $categories;
            $row['is_read']       = isset($read_ids[$id]);
            $row['is_favorite']   = isset($favorite_ids[$id]);
            $row['comment_count'] = $comment_counts[$id] ?? 0;
            $row['source_name']   = $source_names[$sources_id] ?? '';
            $row['origin_label']  = $origins[$row['origin']] ?? (string) $row['origin'];
            $row['is_manual']     = $sources_id === 0 || $row['origin'] === Article::ORIGIN_MANUAL;

            // Technologies reconnues, les plus sûres d'abord, chacune avec
            // la confiance de son rapprochement.
            // Elles précèdent la question posée à l'IA, qui s'en sert pour
            // choisir ses colonnes.
            $row['technologies'] = $technologies[$id] ?? [];
            $row['is_followed']  = false;
            foreach ($row['technologies'] as $technology) {
                if (
                    FollowedTechnology::covers(
                        $technology['categorie'],
                        $technology['famille'],
                        $technology['name']
                    )
                ) {
                    $row['is_followed'] = true;
                    break;
                }
            }

            // Aperçu affiché sur la carte, calculé une fois : le gabarit
            // remettait sinon le contenu HTML à plat à chaque rendu.
            $row['excerpt'] = self::buildExcerpt($row);

            if ($full) {
                // Les liens du contenu renvoient hors de GLPI : ils s'ouvrent
                // à côté, pour qu'on ne perde pas la carte qu'on lit.
                $row['content_html'] = self::openLinksInNewTab((string) ($row['content'] ?? ''));

                // Les quatre niveaux sont préparés d'avance : le détail
                // d'une carte bascule de l'un à l'autre sans recharger.
                $row['ai_prompts'] = AiPrompt::forCardAllLevels($row);
                $row['ai_prompt']  = $row['ai_prompts'][AiPrompt::LEVEL_SUMMARY];
            } else {
                $row['ai_prompt'] = AiPrompt::forCard($row);
            }

            $row['ai_url'] = AiPrompt::toUrl($row['ai_prompt']);

            $row['interest_score'] = Score::fromRow($row);
            $row['priority']       = Score::describe($row['interest_score']);
            $row['score_detail']   = Score::explain($row);

            $decorated[] = $row;
        }

        return $decorated;
    }

    /**
     * Fait ouvrir dans un nouvel onglet les liens du contenu d'une carte.
     *
     * Le contenu vient du flux : ses liens renvoient vers l'éditeur, le CERT,
     * le bulletin d'origine.
     * Les suivre dans l'onglet courant fait quitter GLPI, et il faut revenir
     * en arrière pour retrouver la carte qu'on était en train de lire.
     *
     * La transformation est faite ici plutôt qu'en JavaScript : elle vaut
     * alors aussi pour qui n'en a pas, et le nettoyeur de GLPI conserve
     * l'attribut lorsqu'il repasse sur le contenu à l'affichage.
     *
     * `rel="noopener noreferrer"` va avec : sans lui, la page ouverte garde
     * une prise sur celle qui l'a ouverte.
     *
     * @param string $html Contenu déjà purifié
     *
     * @return string
     */
    public static function openLinksInNewTab(string $html): string
    {
        if ($html === '' || !str_contains($html, '<a ')) {
            return $html;
        }

        // Le contenu est purifié : un chevron dans une valeur d'attribut y est
        // déjà une entité, de sorte que `[^>]*` ne peut pas déborder de la
        // balise.
        return preg_replace_callback(
            '/<a\s([^>]*)>/i',
            static function (array $m): string {
                $attributs = $m[1];

                // Un lien interne, ou déjà pourvu d'une cible, est laissé tel
                // quel.
                if (
                    preg_match('/\btarget\s*=/i', $attributs) === 1
                    || preg_match('/\bhref\s*=\s*["\']https?:/i', $attributs) !== 1
                ) {
                    return $m[0];
                }

                // Le `rel` est refait, pas complété : deux attributs de même
                // nom sur une balise, et le navigateur ne retient que le
                // premier. L'ancre du motif accepte le début de chaîne, où se
                // trouve le premier attribut.
                $attributs = preg_replace(
                    '/(?:^|\s)rel\s*=\s*("[^"]*"|\'[^\']*\')/i',
                    ' ',
                    $attributs
                ) ?? $attributs;

                return '<a ' . trim($attributs) . ' target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;
    }

    /**
     * Aperçu en texte brut d'une carte, prêt à afficher.
     *
     * @param array<string, mixed> $row
     *
     * @return string
     */
    private static function buildExcerpt(array $row): string
    {
        $text = trim((string) ($row['summary'] ?? ''));

        if ($text === '') {
            $text = trim(RichText::getTextFromHtml((string) ($row['content'] ?? ''), false));
        }

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > self::EXCERPT_LENGTH
            ? mb_substr($text, 0, self::EXCERPT_LENGTH) . '...'
            : $text;
    }
}
