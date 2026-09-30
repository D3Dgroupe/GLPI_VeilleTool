<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use Glpi\RichText\RichText;
use GlpiPlugin\Veilletool\Cards\ArticleDecorator;
use GlpiPlugin\Veilletool\Cards\ArticlePurge;
use GlpiPlugin\Veilletool\Cards\ArticleQuery;
use GlpiPlugin\Veilletool\Feed\UrlGuard;
use Session;

/**
 * Carte d'information de veille.
 *
 * Une carte provient soit d'un flux RSS / Atom, soit d'un fichier XML
 * importé, soit d'une saisie manuelle.
 *
 * Ne subsiste ici que le cycle de vie d'une carte : validation de la saisie,
 * effets de bord de l'enregistrement, nettoyage à la suppression. Ce qu'on
 * fait ensuite d'un lot de cartes vit dans
 * {@see \GlpiPlugin\Veilletool\Cards} :
 *
 * - {@see ArticleQuery} les cherche, les compte, les trie et les pagine ;
 * - {@see ArticleDecorator} les habille de tout ce qui n'est pas dans leur
 *   ligne - thèmes, lecture, favori, score, aperçu ;
 * - {@see ArticlePurge} les efface.
 */
class Article extends CommonDBTM
{
    public const ORIGIN_RSS    = 'rss';
    public const ORIGIN_ATOM   = 'atom';
    public const ORIGIN_MANUAL = 'manual';

    /** Tri par score d'intérêt décroissant */
    public const SORT_SCORE = 'score';

    /** Tri par date de publication décroissante */
    public const SORT_DATE = 'date';

    /**
     * Nom de l'index qui porte le tri par score de l'observatoire.
     *
     * Ses colonnes sont celles qu'annonce Score::orderColumns() : c'est
     * l'installation qui le pose, et la mise à jour qui le remet en
     * correspondance si le calcul du score vient à changer.
     */
    public const SCORE_INDEX = 'score_order';

    public static $rightname = 'plugin_veilletool_veille';

    /**
     * Droit de déposer un commentaire sur une carte.
     *
     * Commenter n'est pas modifier : c'est ajouter son analyse à côté de
     * l'information, sans y toucher.
     * Ce droit est donc distinct de `UPDATE`, qui autorise à récrire une
     * carte, à la mettre en favori partagé et à modérer les commentaires
     * des autres.
     * Un lecteur peut ainsi participer sans pouvoir rien altérer.
     *
     * Le bit choisi est au-delà de tous ceux que GLPI se réserve
     * (`UPDATE_OWNED` = 2048) : il ne peut en recouvrir aucun.
     */
    public const RIGHT_COMMENT = 4096;

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Carte de veille', 'Cartes de veille', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-news';
    }

    public static function getSectorizedDetails(): array
    {
        return ['veille', 'veille_articles'];
    }

    /**
     * Libellés des origines possibles d'une carte.
     *
     * @return array<string, string>
     */
    public static function getOrigins(): array
    {
        return [
            self::ORIGIN_RSS    => __('Flux RSS', 'veilletool'),
            self::ORIGIN_ATOM   => __('Flux Atom', 'veilletool'),
            self::ORIGIN_MANUAL => __('Saisie manuelle', 'veilletool'),
        ];
    }

    public function post_getEmpty()
    {
        $this->fields['origin']         = self::ORIGIN_MANUAL;
        $this->fields['date_published'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareCommonInput($input);
        if ($input === false) {
            return false;
        }

        if (!isset($input['origin']) || !array_key_exists($input['origin'], self::getOrigins())) {
            $input['origin'] = self::ORIGIN_MANUAL;
        }

        if (!isset($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }

        if (empty($input['date_published'])) {
            $input['date_published'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }

        if (empty($input['guid'])) {
            $input['guid'] = self::buildGuid(
                (string) ($input['url'] ?? ''),
                (string) ($input['name'] ?? ''),
                (string) $input['date_published']
            );
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * Contrôles et normalisations communs.
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function prepareCommonInput($input)
    {
        if (!is_array($input)) {
            return $input;
        }

        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(
                    __('Le titre de la carte est obligatoire.', 'veilletool'),
                    false,
                    ERROR
                );
                return false;
            }
            $input['name'] = mb_substr($input['name'], 0, 255);
        }

        foreach (['url', 'image_url'] as $url_field) {
            if (array_key_exists($url_field, $input)) {
                $input[$url_field] = trim((string) $input[$url_field]);
                if ($input[$url_field] !== '' && !UrlGuard::isAcceptable($input[$url_field])) {
                    $input[$url_field] = '';
                }
            }
        }

        if (array_key_exists('content', $input)) {
            $input = $this->prepareContent($input);
        }

        if (array_key_exists('summary', $input)) {
            // Le résumé est toujours stocké en texte brut : il est affiché
            // tel quel dans les cartes et ne doit pas contenir de balises.
            $input['summary'] = mb_substr(
                RichText::getTextFromHtml((string) $input['summary'], false),
                0,
                600
            );
        }

        return $input;
    }

    /**
     * Purifie le contenu d'une carte, sauf s'il l'a déjà été.
     *
     * La purification est de loin l'opération la plus coûteuse d'un
     * enregistrement - plusieurs millisecondes pour un article de quelques
     * kilo-octets, contre une fraction de milliseconde pour tout le reste.
     * Or une collecte repasse sur les mêmes entrées à chaque relevé : sans
     * garde, le plugin repurifiait chaque heure un contenu rigoureusement
     * identique à celui de la fois précédente.
     *
     * L'empreinte retenue est celle du contenu **brut**, avant
     * purification.
     * Quand elle n'a pas changé, le contenu stocké est déjà, par
     * construction, exactement ce que la purification produirait : il n'y a
     * ni à la refaire, ni même à réécrire la colonne.
     * Dès que le brut diffère d'un octet, la purification a lieu comme
     * avant.
     *
     * Rien n'est relâché au passage : le contenu est de toute façon
     * repurifié **à l'affichage** par le filtre `safe_html`, avec le
     * nettoyeur courant de GLPI.
     * La purification au stockage reste une défense en profondeur, elle ne
     * cesse pas d'être appliquée - elle cesse d'être refaite pour rien.
     *
     * @param array $input
     *
     * @return array
     */
    private function prepareContent(array $input): array
    {
        $raw  = (string) $input['content'];
        $hash = $raw === '' ? '' : sha1($raw);

        if ($hash !== '' && $hash === (string) ($this->fields['content_hash'] ?? '')) {
            // Le champ sort de l'enregistrement : la valeur en base est
            // déjà la bonne, et l'écrire à nouveau ferait croire à une
            // modification - ce qui relancerait le rapprochement
            // technologique sans raison.
            unset($input['content']);

            return $input;
        }

        $input['content']      = $raw === '' ? '' : RichText::getSafeHtml($raw);
        $input['content_hash'] = $hash;

        return $input;
    }

    public function post_addItem()
    {
        // Les deux opérations savent ce qu'elles viennent de poser : leur
        // résultat nourrit directement le score, au lieu d'être redemandé à
        // la base juste après y avoir été écrit.
        //
        // La carte vient d'être créée : elle n'a ni thème ni technologie à
        // relire, et rien à effacer avant d'écrire.
        $known = [];

        $category = $this->updateCategoriesFromInput(false);
        if ($category !== null) {
            $known['category'] = $category;
        }

        $known['technology'] = $this->indexTechnologies(false);

        $this->refreshScore($known, true);
    }

    public function post_updateItem($history = true)
    {
        $known = [];

        $category = $this->updateCategoriesFromInput();
        if ($category !== null) {
            $known['category'] = $category;
        }

        // Le rapprochement ne dépend que du texte : inutile de le refaire
        // quand seules les catégories ou les métadonnées ont changé.
        // Sans lui, la confiance reste inconnue et sera relue.
        $indexed = array_intersect(['name', 'summary', 'content'], $this->updates) !== [];

        if ($indexed) {
            $known['technology'] = $this->indexTechnologies();
        }

        // Le score, lui, dépend aussi du flux, des thèmes et de la date :
        // il est systématiquement rafraîchi.
        $this->refreshScore($known, $indexed);
    }

    /**
     * Recalcule les composantes stockées du score d'intérêt de la carte.
     *
     * @param array<string, int> $known   Composantes déjà calculées
     * @param bool               $indexed Vrai si la classification
     *                                    technologique vient d'être refaite
     *
     * @return void
     */
    private function refreshScore(array $known = [], bool $indexed = true): void
    {
        Score::refresh(
            [
                'id'                             => (int) $this->fields['id'],
                'plugin_veilletool_sources_id'   => (int) ($this->fields['plugin_veilletool_sources_id'] ?? 0),
                'date_published'                 => (string) ($this->fields['date_published'] ?? ''),
            ],
            $known,
            $indexed,
            // Les valeurs en base sont déjà chargées : l'écriture est
            // évitée quand elle ne changerait rien.
            $this->fields
        );
    }

    /**
     * Recalcule et enregistre la classification technologique de la carte.
     *
     * @param bool $may_exist Faux pour une carte qui vient d'être créée
     *
     * @return int Meilleure confiance obtenue
     */
    private function indexTechnologies(bool $may_exist = true): int
    {
        return Article_Technology::indexArticle(
            [
                'id'      => (int) $this->fields['id'],
                'name'    => (string) ($this->fields['name'] ?? ''),
                'summary' => (string) ($this->fields['summary'] ?? ''),
                'content' => (string) ($this->fields['content'] ?? ''),
            ],
            null,
            $may_exist
        )['confidence'];
    }

    /**
     * Applique les catégories transmises par le formulaire.
     *
     * @param bool $may_exist Faux pour une carte qui vient d'être créée
     *
     * @return int|null Meilleure note d'intérêt des thèmes retenus, ou null
     *                  si le formulaire n'en portait pas
     */
    private function updateCategoriesFromInput(bool $may_exist = true): ?int
    {
        if (!isset($this->input['_categories_defined']) && !isset($this->input['categories'])) {
            return null;
        }

        $categories = $this->input['categories'] ?? [];
        if (!is_array($categories)) {
            $categories = [$categories];
        }

        return Article_Category::setCategories(
            (int) $this->fields['id'],
            array_map('intval', $categories),
            $may_exist
        );
    }

    public function cleanDBonPurge()
    {
        $articles_id = (int) $this->fields['id'];
        $criteria    = ['plugin_veilletool_articles_id' => $articles_id];

        foreach (
            [
                Article_Category::class,
                ReadStatus::class,
                Favorite::class,
                Comment::class,
                Article_Technology::class,
            ] as $itemtype
        ) {
            (new $itemtype())->deleteByCriteria($criteria, true);
        }
    }

    /**
     * Construit un identifiant de déduplication stable pour une carte.
     *
     * @param string $url
     * @param string $title
     * @param string $date
     *
     * @return string
     */
    public static function buildGuid(string $url, string $title, string $date = ''): string
    {
        $seed = $url !== '' ? $url : ($title . '|' . $date);

        return substr(sha1($seed), 0, 40);
    }

    /**
     * Identifiants des catégories de la carte.
     *
     * @return int[]
     */
    public function getCategoryIds(): array
    {
        return Article_Category::getCategoryIds((int) $this->fields['id']);
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(2),
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => static::getTable(),
            'field'         => 'name',
            'name'          => __('Titre', 'veilletool'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '2',
            'table'         => static::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => Source::getTable(),
            'field'    => 'name',
            'name'     => Source::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => static::getTable(),
            'field'    => 'origin',
            'name'     => __('Origine', 'veilletool'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => static::getTable(),
            'field'    => 'date_published',
            'name'     => __('Date de publication', 'veilletool'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '6',
            'table'    => static::getTable(),
            'field'    => 'author',
            'name'     => __('Auteur', 'veilletool'),
            'datatype' => 'string',
        ];

        $tab[] = [
            'id'       => '7',
            'table'    => static::getTable(),
            'field'    => 'url',
            'name'     => __('URL'),
            'datatype' => 'weblink',
        ];

        $tab[] = [
            'id'            => '19',
            'table'         => static::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }
}
