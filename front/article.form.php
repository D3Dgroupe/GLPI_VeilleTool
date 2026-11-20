<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Détail d'une carte de veille (contenu complet, source, commentaires) et
 * formulaire de création / modification d'une carte saisie manuellement.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\AiPrompt;
use GlpiPlugin\Veilletool\Article;
use GlpiPlugin\Veilletool\Article_Category;
use GlpiPlugin\Veilletool\Article_Technology;
use GlpiPlugin\Veilletool\Cards\ArticleDecorator;
use GlpiPlugin\Veilletool\Comment;
use GlpiPlugin\Veilletool\Favorite;
use GlpiPlugin\Veilletool\Feed\PageContent;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;
use GlpiPlugin\Veilletool\ReadStatus;
use GlpiPlugin\Veilletool\Score;
use GlpiPlugin\Veilletool\Source;
use GlpiPlugin\Veilletool\Technology;
use Symfony\Component\HttpFoundation\JsonResponse;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Article::$rightname, READ);

$users_id = (int) Session::getLoginUserID();
$article  = new Article();
$comment  = new Comment();

// --------------------------------------------------------------------------
// Création d'une carte saisie manuellement
// --------------------------------------------------------------------------
if (isset($_POST['add'])) {
    $article->check(-1, CREATE, $_POST);

    $input           = $_POST;
    $input['origin'] = Article::ORIGIN_MANUAL;
    $input['plugin_veilletool_sources_id'] = 0;

    $newID = $article->add($input);
    if ($newID) {
        Html::redirect(Article::getFormURLWithID($newID));
    }

    Navigation::back(Article::getSearchURL());
}

// --------------------------------------------------------------------------
// Mise à jour d'une carte
// --------------------------------------------------------------------------
if (isset($_POST['update'])) {
    $id = (int) $_POST['id'];

    $article->check($id, UPDATE);
    $article->update($_POST);

    Navigation::back(Article::getFormURLWithID($id));
}

// --------------------------------------------------------------------------
// Suppression définitive d'une carte
// --------------------------------------------------------------------------
if (isset($_POST['purge'])) {
    $article->check((int) $_POST['id'], PURGE);
    $article->delete(['id' => (int) $_POST['id']], true);

    Html::redirect(Article::getSearchURL());
}

// --------------------------------------------------------------------------
// Bascules favori et lu / non lu
//
// Envoyées depuis l'observatoire, elles partent en AJAX : la réponse est
// alors un objet JSON portant le nouvel état, et la page n'est pas
// rechargée - on garde sa place dans le tableau.
// Sans JavaScript, le formulaire part normalement et l'on revient à l'écran
// d'origine.
// --------------------------------------------------------------------------
$is_ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/**
 * Réponse JSON d'une bascule envoyée en AJAX.
 *
 * GLPI 11 transmet tel quel l'objet `Response` rendu par un script de
 * `front/`.
 *
 * @param int                  $status Code HTTP
 * @param array<string, mixed> $data
 */
$send_json = static function (int $status, array $data): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
    $response->headers->set('Cache-Control', 'no-store');

    return $response;
};

// --------------------------------------------------------------------------
// Favori (partagé entre tous les utilisateurs)
// --------------------------------------------------------------------------
if (isset($_POST['toggle_favorite'])) {
    $articles_id = (int) ($_POST['articles_id'] ?? 0);

    if ($is_ajax) {
        if (!Session::haveRight(Article::$rightname, UPDATE)) {
            return $send_json(403, ['ok' => false]);
        }
        if (!$article->getFromDB($articles_id)) {
            return $send_json(404, ['ok' => false]);
        }

        $now = Favorite::toggle($articles_id, $users_id);
        return $send_json(200, [
            'ok'      => true,
            'state'   => $now,
            'message' => $now
                ? __('Carte ajoutée aux favoris.', 'veilletool')
                : __('Carte retirée des favoris.', 'veilletool'),
        ]);
    }

    Session::checkRight(Article::$rightname, UPDATE);

    if ($article->getFromDB($articles_id)) {
        $now = Favorite::toggle($articles_id, $users_id);
        Session::addMessageAfterRedirect(
            $now
                ? __('Carte ajoutée aux favoris.', 'veilletool')
                : __('Carte retirée des favoris.', 'veilletool'),
            true,
            INFO
        );
    }

    Navigation::back(Article::getFormURLWithID($articles_id));
}

// --------------------------------------------------------------------------
// Marquage lu / non lu (propre à l'utilisateur connecté)
// --------------------------------------------------------------------------
if (isset($_POST['toggle_read'])) {
    $articles_id = (int) ($_POST['articles_id'] ?? 0);
    $found       = $article->getFromDB($articles_id);
    $now         = false;

    if ($found) {
        if (ReadStatus::isRead($articles_id, $users_id)) {
            ReadStatus::markAsUnread($articles_id, $users_id);
        } else {
            ReadStatus::markAsRead($articles_id, $users_id);
            $now = true;
        }
    }

    if ($is_ajax) {
        return $send_json($found ? 200 : 404, ['ok' => $found, 'state' => $now]);
    }

    Navigation::back(Article::getFormURLWithID($articles_id));
}

// --------------------------------------------------------------------------
// Rechargement du contenu depuis la page de l'article
//
// Corrige une carte importée avec le seul résumé du flux - avant que
// l'option « contenu complet » ne soit posée sur sa source, ou quand la
// page était alors inaccessible. Une carte saisie à la main n'a pas de page
// à relire : son contenu est celui de son auteur.
// --------------------------------------------------------------------------
if (isset($_POST['reload_content'])) {
    Session::checkRight(Article::$rightname, UPDATE);

    $articles_id = (int) ($_POST['articles_id'] ?? 0);

    if ($article->getFromDB($articles_id) && $article->fields['origin'] !== Article::ORIGIN_MANUAL) {
        $selector = '';
        $source   = new Source();
        if ($source->getFromDB((int) $article->fields['plugin_veilletool_sources_id'])) {
            $selector = (string) ($source->fields['content_selector'] ?? '');
        }

        $page = PageContent::fetch((string) $article->fields['url'], $selector);

        if ($page->isSuccess()) {
            $article->update([
                'id'                   => $articles_id,
                'content'              => $page->html,
                'date_content_fetched' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);

            Session::addMessageAfterRedirect(
                __('Contenu rechargé depuis la page de l\'article.', 'veilletool'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Rechargement impossible : %s', 'veilletool'),
                    htmlescape((string) $page->error)
                ),
                false,
                ERROR
            );
        }
    }

    Navigation::back(Article::getFormURLWithID($articles_id));
}

// --------------------------------------------------------------------------
// Classification technologique décidée à la main
//
// Le rapprochement automatique ne voit que ce qui est écrit.
// Un administrateur du référentiel peut rattacher lui-même une carte à une
// technologie : la catégorie et la famille viennent de l'entrée choisie, et
// la confiance vaut 100 - c'est une lecture humaine, pas une correspondance
// de texte.
// Le rattachement survit aux reclassements suivants.
// --------------------------------------------------------------------------
if (isset($_POST['add_technology'])) {
    Session::checkRight(Technology::$rightname, UPDATE);

    $articles_id = (int) ($_POST['articles_id'] ?? 0);

    if ($article->getFromDB($articles_id)) {
        $done = Article_Technology::addManual(
            $articles_id,
            (int) ($_POST['technologies_id'] ?? 0)
        );

        switch ($done['status']) {
            case 'added':
            case 'promoted':
                // La composante technologique du score suit le meilleur
                // rattachement : elle est recalculée aussitôt.
                // Le rapprochement automatique, lui, n'a pas été refait :
                // la carte ne doit donc pas sortir d'une éventuelle file de
                // reclassement.
                Score::refresh($article->fields, [], false);

                Session::addMessageAfterRedirect(
                    sprintf(
                        __('« %s » est désormais rattachée à cette carte.', 'veilletool'),
                        htmlescape($done['label'])
                    ),
                    true,
                    INFO
                );
                break;

            case 'exists':
                Session::addMessageAfterRedirect(
                    sprintf(
                        __('« %s » était déjà rattachée à cette carte.', 'veilletool'),
                        htmlescape($done['label'])
                    ),
                    true,
                    INFO
                );
                break;

            default:
                Session::addMessageAfterRedirect(
                    __('Choisissez une technologie du référentiel.', 'veilletool'),
                    false,
                    ERROR
                );
                break;
        }
    }

    Navigation::back(Article::getFormURLWithID($articles_id));
}

if (isset($_POST['remove_technology'])) {
    Session::checkRight(Technology::$rightname, UPDATE);

    $articles_id = (int) ($_POST['articles_id'] ?? 0);

    if ($article->getFromDB($articles_id)) {
        $removed = Article_Technology::removeManual(
            $articles_id,
            (int) $_POST['remove_technology']
        );

        if ($removed) {
            Score::refresh($article->fields, [], false);

            Session::addMessageAfterRedirect(
                __('Rattachement retiré.', 'veilletool'),
                true,
                INFO
            );
        }
    }

    Navigation::back(Article::getFormURLWithID($articles_id));
}

// --------------------------------------------------------------------------
// Commentaires
//
// Déposer un commentaire relève du droit « Commenter », que peut porter un
// profil en lecture seule.
// Reprendre ou retirer un commentaire n'est ouvert qu'à son auteur.
// Le droit d'écriture y ajoute la modération des autres.
// --------------------------------------------------------------------------
if (isset($_POST['add_comment'])) {
    if (!Comment::canComment()) {
        Session::checkRight(Article::$rightname, Article::RIGHT_COMMENT);
    }

    $articles_id = (int) $_POST['articles_id'];
    if ($article->getFromDB($articles_id)) {
        $comment->add([
            'plugin_veilletool_articles_id' => $articles_id,
            'content'                         => (string) ($_POST['content'] ?? ''),
        ]);
    }

    Navigation::back(Article::getFormURLWithID((int) ($_POST['articles_id'] ?? 0)));
}

if (isset($_POST['update_comment'])) {
    // Le formulaire d'un commentaire ne porte que son identifiant : la
    // carte à rouvrir se lit sur le commentaire lui-même.
    $articles_id = 0;

    if ($comment->getFromDB((int) $_POST['comments_id'])) {
        if (!Comment::canEditRow($comment->fields)) {
            Session::checkRight(Article::$rightname, UPDATE);
        }

        $articles_id = (int) $comment->fields['plugin_veilletool_articles_id'];

        $comment->update([
            'id'      => (int) $_POST['comments_id'],
            'content' => (string) ($_POST['content'] ?? ''),
        ]);
    }

    Navigation::back(
        $articles_id > 0 ? Article::getFormURLWithID($articles_id) : Article::getSearchURL()
    );
}

if (isset($_POST['delete_comment'])) {
    $articles_id = 0;

    if ($comment->getFromDB((int) $_POST['comments_id'])) {
        if (!Comment::canEditRow($comment->fields)) {
            Session::checkRight(Article::$rightname, UPDATE);
        }

        $articles_id = (int) $comment->fields['plugin_veilletool_articles_id'];

        $comment->delete(['id' => (int) $_POST['comments_id']], true);
    }

    Navigation::back(
        $articles_id > 0 ? Article::getFormURLWithID($articles_id) : Article::getSearchURL()
    );
}

// --------------------------------------------------------------------------
// Affichage
// --------------------------------------------------------------------------
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    // Formulaire de création d'une carte manuelle.
    Session::checkRight(Article::$rightname, CREATE);

    $article->getEmpty();

    Html::header(
        __('Nouvelle carte de veille', 'veilletool'),
        '',
        Menu::SECTOR,
        Menu::ITEM_ARTICLES
    );

    TemplateRenderer::getInstance()->display('@veilletool/article_form.html.twig', [
        'item'       => $article,
        'categories' => [],
    ]);

    Html::footer();

    return;
}

if (!$article->getFromDB($id)) {
    Html::displayNotFoundError();
}

// L'ouverture de la carte vaut lecture pour l'utilisateur courant.
ReadStatus::markAsRead($id, $users_id);

$decorated = ArticleDecorator::decorate([$id => $article->fields], $users_id, true);
$card      = $decorated[0] ?? null;

if ($card === null) {
    Html::displayNotFoundError();
}

Html::header(
    $card['name'],
    '',
    Menu::SECTOR,
    Menu::ITEM_ARTICLES
);

if (isset($_GET['edit']) && Session::haveRight(Article::$rightname, UPDATE)) {
    TemplateRenderer::getInstance()->display('@veilletool/article_form.html.twig', [
        'item'       => $article,
        'categories' => Article_Category::getCategoryIds($id),
    ]);
} else {
    TemplateRenderer::getInstance()->display('@veilletool/article_detail.html.twig', [
        'card'       => $card,
        'comments'   => Comment::getForArticle($id),
        'can_update'  => Session::haveRight(Article::$rightname, UPDATE),
        'can_purge'   => Session::haveRight(Article::$rightname, PURGE),
        'can_comment' => Comment::canComment(),
        // Rattacher une carte à une technologie relève de l'administration
        // du référentiel, pas de l'écriture des cartes.
        'can_classify' => Session::haveRight(Technology::$rightname, UPDATE),
        'ai_levels'   => AiPrompt::getLevels(),
        'ai_level'    => AiPrompt::LEVEL_SUMMARY,
    ]);
}

Html::footer();
