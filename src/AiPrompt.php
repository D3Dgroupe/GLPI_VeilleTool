<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use Glpi\RichText\RichText;

/**
 * Rédaction des questions posées à l'IA.
 *
 * Toutes les questions demandent une réponse **sous forme de tableau**,
 * dont les colonnes sont énoncées explicitement : la réponse est ainsi
 * directement exploitable, et comparable d'une carte à l'autre.
 *
 * Deux réglages se superposent :
 *
 * - le **niveau de détail**, choisi par l'utilisateur, qui décide du volume
 * de contexte transmis et de la finesse attendue.
 * - l'**angle**, déduit de la carte, qui décide des colonnes.
 * Une faille appelle une cause, un risque et un correctif.
 * Une réglementation appelle un périmètre, une échéance et une obligation.
 * Une nouveauté appelle un apport et une incidence.
 * Demander le risque d'une nouvelle fonctionnalité ne produit qu'une
 * colonne vide, ou pire, une invention.
 *
 * Toutes les consignes portent enfin la même exigence de lisibilité : le
 * tableau doit se comprendre du premier regard, pas se déchiffrer.
 */
class AiPrompt
{
    /** Service de conversation vers lequel les questions sont redirigées */
    public const ENDPOINT = 'https://chatgpt.com/?q=';

    /**
     * Longueur maximale retenue pour l'URL de redirection.
     *
     * Borne prudente : au-delà, certains serveurs refusent la requête.
     * Le fichier Markdown n'a lui aucune limite de ce genre.
     */
    public const URL_BUDGET = 7500;

    /** Résumé court, pour balayer rapidement */
    public const LEVEL_BRIEF = 'synthese';

    /** Cause, risques et correctifs : le niveau par défaut */
    public const LEVEL_SUMMARY = 'resume';

    /** Explication complète, rubrique par rubrique */
    public const LEVEL_DETAILED = 'detail';

    /** Lecture par les risques */
    public const LEVEL_RISKS = 'risques';

    /** Faille, incident, correctif de sécurité */
    public const ANGLE_SECURITY = 'securite';

    /** Texte réglementaire, norme, obligation de conformité */
    public const ANGLE_REGULATION = 'reglementation';

    /** Nouvelle version, nouvelle fonctionnalité, annonce produit */
    public const ANGLE_NOVELTY = 'nouveaute';

    /** Rien ne tranche : lecture neutre */
    public const ANGLE_GENERAL = 'general';

    /**
     * Catégorie du référentiel technologique désignant un texte normatif.
     *
     * C'est un signal de structure, bien plus sûr qu'un mot relevé dans le
     * texte : il pèse davantage dans la détection de l'angle.
     */
    private const REGULATION_CATEGORY = 'Réglementation';

    /** Poids d'un signal de structure face à un simple mot relevé */
    private const STRUCTURAL_WEIGHT = 3;

    /**
     * Mots qui trahissent l'angle d'une carte.
     *
     * La liste est volontairement courte et sans accent : le texte est
     * translittéré avant comparaison, ce qui évite d'écrire chaque terme en
     * deux versions.
     *
     * @var array<string, string[]>
     */
    private const ANGLE_HINTS = [
        self::ANGLE_SECURITY => [
            'cve-', 'cvss', 'vulnerabilit', 'faille', 'exploit', 'zero-day', '0-day',
            'ransomware', 'rancongiciel', 'correctif', 'patch', 'malware', 'compromission',
            'backdoor', 'porte derobee', 'hameconnage', 'phishing', 'fuite de donnees',
            'elevation de privileges', 'deni de service', 'attaque',
        ],
        self::ANGLE_REGULATION => [
            'reglement', 'reglementaire', 'directive', 'nis2', 'nis 2', 'rgpd', 'gdpr',
            'dora', 'cyber resilience act', 'ai act', 'conformite', 'obligation legale',
            'decret', 'arrete', 'norme iso', 'iso 27', 'referentiel de securite',
            'mise en demeure', 'sanction',
        ],
        self::ANGLE_NOVELTY => [
            'nouvelle version', 'nouveaute', 'nouvelle fonctionnalite', 'fonctionnalite',
            'disponibilite generale', 'disponible', 'lancement', 'annonce', 'release',
            'roadmap', 'beta', 'preview', 'feuille de route', 'refonte', 'sortie de',
        ],
    ];

    /**
     * Niveaux proposés à l'utilisateur.
     *
     * @return array<string, string>
     */
    public static function getLevels(): array
    {
        return [
            self::LEVEL_BRIEF    => __('Résumé synthétique', 'veilletool'),
            self::LEVEL_SUMMARY  => __('Résumé', 'veilletool'),
            self::LEVEL_DETAILED => __('Explication détaillée', 'veilletool'),
            self::LEVEL_RISKS    => __('Analyse des risques', 'veilletool'),
        ];
    }

    /**
     * Ramène une valeur reçue de l'interface à un niveau connu.
     *
     * @param string $level
     *
     * @return string
     */
    public static function normalizeLevel(string $level): string
    {
        return array_key_exists($level, self::getLevels()) ? $level : self::LEVEL_SUMMARY;
    }

    /**
     * Exigence de lisibilité, jointe à toutes les consignes.
     *
     * Un tableau de veille se parcourt : il doit dire de quoi il s'agit du
     * premier regard.
     * D'où l'interdiction de la phrase rédigée, de la redite d'une colonne
     * à l'autre, et de la colonne ajoutée d'initiative.
     *
     * @return string
     */
    public static function getReadabilityRule(): string
    {
        return __(
            "Le tableau doit se lire d'un coup d'œil : une seule idée par cellule, "
            . "une quinzaine de mots au maximum, pas de phrase rédigée ni de redite "
            . "d'une colonne à l'autre. On doit comprendre de quoi il s'agit dès la "
            . "première ligne. N'ajoute aucune colonne, ne commente pas le tableau, "
            . "et n'invente rien : si une information manque, écris « non précisé ».",
            'veilletool'
        );
    }

    /**
     * Ramène une valeur à un angle connu.
     *
     * @param string $angle
     *
     * @return string
     */
    public static function normalizeAngle(string $angle): string
    {
        return in_array(
            $angle,
            [self::ANGLE_SECURITY, self::ANGLE_REGULATION, self::ANGLE_NOVELTY],
            true
        ) ? $angle : self::ANGLE_GENERAL;
    }

    /**
     * Angle de lecture d'une carte.
     *
     * Les rattachements technologiques priment sur les mots du texte : une
     * carte classée dans « Réglementation » l'est parce qu'une entrée du
     * référentiel l'y a mise, ce qui vaut mieux qu'un terme aperçu dans un
     * titre.
     * À défaut, les mots tranchent - et s'ils ne tranchent pas, la lecture
     * reste neutre plutôt que faussement sécuritaire.
     *
     * @param array<string, mixed> $card
     * @param string|null          $text
     *        Texte de la carte déjà extrait, pour éviter de remettre son
     *        contenu HTML à plat une seconde fois.
     *
     * @return string Une des constantes ANGLE_*
     */
    public static function detectAngle(array $card, ?string $text = null): string
    {
        $scores = [
            self::ANGLE_SECURITY   => 0,
            self::ANGLE_REGULATION => 0,
            self::ANGLE_NOVELTY    => 0,
        ];

        foreach ((array) ($card['technologies'] ?? []) as $technology) {
            if ((string) ($technology['categorie'] ?? '') === self::REGULATION_CATEGORY) {
                $scores[self::ANGLE_REGULATION] += self::STRUCTURAL_WEIGHT;
                break;
            }
        }

        $haystack = self::flatten(
            (string) ($card['name'] ?? '') . ' ' . ($text ?? self::extractText($card))
        );

        foreach (self::ANGLE_HINTS as $angle => $hints) {
            foreach ($hints as $hint) {
                if (str_contains($haystack, $hint)) {
                    $scores[$angle]++;
                }
            }
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : self::ANGLE_GENERAL;
    }

    /**
     * Met un texte à plat pour la recherche de mots indicatifs :
     * minuscules, sans accent, espaces normalisés.
     *
     * @param string $text
     *
     * @return string
     */
    private static function flatten(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Consigne pour une carte isolée.
     *
     * @param string $level
     * @param string $angle
     *
     * @return string
     */
    public static function getCardInstruction(
        string $level,
        string $angle = self::ANGLE_GENERAL
    ): string {
        $level = self::normalizeLevel($level);
        $angle = self::normalizeAngle($angle);

        return match ($level) {
            self::LEVEL_BRIEF => __(
                "Résume l'information de veille technologique ci-dessous. "
                . "Présente ta réponse sous forme de tableau à deux colonnes : "
                . "Point clé | Ce qu'il faut retenir. Cinq lignes au maximum.",
                'veilletool'
            ),
            self::LEVEL_RISKS => __(
                "Analyse les risques que fait peser l'information de veille technologique ci-dessous. "
                . "Présente ta réponse sous forme de tableau à cinq colonnes : "
                . "Risque | Gravité | Probabilité | Actifs concernés | Mesure de réduction. "
                . "Si l'information ne porte aucun risque pour une DSI, dis-le en une ligne "
                . "plutôt que d'en inventer.",
                'veilletool'
            ),
            self::LEVEL_DETAILED => self::getDetailedInstruction($angle),
            default              => self::getSummaryInstruction($angle),
        } . ' ' . self::getReadabilityRule();
    }

    /**
     * Colonnes du niveau « Résumé », selon l'angle.
     *
     * @param string $angle
     *
     * @return string
     */
    private static function getSummaryInstruction(string $angle): string
    {
        return match ($angle) {
            self::ANGLE_REGULATION => __(
                "L'information de veille ci-dessous porte sur un texte réglementaire ou "
                . "normatif. Présente ta réponse sous forme de tableau à quatre colonnes : "
                . "Ce qui change | Qui est concerné | Échéance | Ce qu'il faut faire. "
                . "Ne parle pas de risque technique : ce n'est pas le sujet.",
                'veilletool'
            ),
            self::ANGLE_NOVELTY => __(
                "L'information de veille ci-dessous annonce une nouveauté - version, "
                . "fonctionnalité ou produit. Présente ta réponse sous forme de tableau à "
                . "quatre colonnes : Ce qui est nouveau | Ce que ça apporte | Qui est concerné | "
                . "Ce qu'il faut faire. Ne cherche pas de risque ni de correctif : "
                . "il n'y a rien à corriger.",
                'veilletool'
            ),
            self::ANGLE_SECURITY => __(
                "Pour l'information de veille technologique ci-dessous, explique-moi la cause, "
                . "les risques et les correctifs. Présente ta réponse sous forme de tableau à "
                . "trois colonnes : Cause | Risques | Correctifs.",
                'veilletool'
            ),
            default => __(
                "Résume l'information de veille technologique ci-dessous. Présente ta réponse "
                . "sous forme de tableau à trois colonnes : De quoi il s'agit | Ce que ça change | "
                . "Ce qu'il faut faire. N'ajoute une colonne « risque » que si l'information "
                . "en porte vraiment un.",
                'veilletool'
            ),
        };
    }

    /**
     * Rubriques du niveau « Explication détaillée », selon l'angle.
     *
     * @param string $angle
     *
     * @return string
     */
    private static function getDetailedInstruction(string $angle): string
    {
        $intro = __(
            "Explique en détail l'information de veille technologique ci-dessous. "
            . "Présente ta réponse sous forme de tableau à deux colonnes : Rubrique | Détail, "
            . "en couvrant au minimum ",
            'veilletool'
        );

        $topics = match ($angle) {
            self::ANGLE_REGULATION => __(
                "l'objet du texte, son périmètre d'application, les obligations qu'il crée, "
                . "les échéances, les sanctions prévues, et ce qu'une DSI doit engager.",
                'veilletool'
            ),
            self::ANGLE_NOVELTY => __(
                "la nature de la nouveauté, le périmètre concerné, ce qu'elle apporte, "
                . "ses prérequis et limites, son incidence sur l'existant, et ce qu'il y a "
                . "à préparer.",
                'veilletool'
            ),
            self::ANGLE_SECURITY => __(
                "le contexte, le mécanisme en cause, le périmètre touché, les impacts pour "
                . "une DSI, les correctifs et contournements, et les points de vigilance.",
                'veilletool'
            ),
            default => __(
                "l'objet de l'information, le périmètre concerné, ce qu'elle change, "
                . "ce qu'il y a à faire, et les points de vigilance.",
                'veilletool'
            ),
        };

        return $intro . $topics;
    }

    /**
     * Consigne pour un lot de cartes.
     *
     * @param string $level
     *
     * @return string
     */
    public static function getBatchInstruction(string $level): string
    {
        $commun = ' ' . __(
            'Regroupe ce qui se recoupe, reste factuel et ne conserve que ce qui est utile à une DSI.',
            'veilletool'
        );

        // Un lot mélange les natures : la colonne « Nature » remplace ici
        // la détection faite carte par carte, et dispense d'imposer les
        // mêmes colonnes à une faille et à un texte réglementaire.
        $natures = ' ' . __(
            "Les informations n'ont pas toutes la même nature : indique-la (vulnérabilité, "
            . "réglementation, nouveauté, évolution technique) et adapte le contenu des "
            . "cellules en conséquence - une nouveauté ou une réglementation n'appelle ni "
            . "risque ni correctif, laisse alors « sans objet ».",
            'veilletool'
        );

        return match (self::normalizeLevel($level)) {
            self::LEVEL_BRIEF => __(
                "À partir des informations de veille listées ci-dessous, produis une synthèse courte. "
                . "Présente ta réponse sous forme de tableau à quatre colonnes : "
                . "Sujet | Nature | Ce qu'il faut retenir | Niveau d'attention.",
                'veilletool'
            ) . $commun . $natures,
            self::LEVEL_DETAILED => __(
                "À partir des informations de veille listées ci-dessous, produis une analyse détaillée. "
                . "Présente ta réponse sous forme de tableau à cinq colonnes : "
                . "Sujet | Nature | Ce qu'il faut en comprendre | Qui est concerné | Ce qu'il faut faire.",
                'veilletool'
            ) . $commun . $natures,
            self::LEVEL_RISKS => __(
                "À partir des informations de veille listées ci-dessous, dresse le panorama des risques. "
                . "Présente ta réponse sous forme de tableau à cinq colonnes : "
                . "Risque | Information à l'origine | Gravité | Probabilité | Mesure de réduction. "
                . "Classe les lignes de la plus critique à la moins critique, et écarte "
                . "simplement les informations qui ne portent aucun risque.",
                'veilletool'
            ) . $commun,
            default => __(
                "À partir des informations de veille listées ci-dessous, produis une synthèse. "
                . "Présente ta réponse sous forme de tableau à quatre colonnes : "
                . "Sujet | Nature | Ce que ça change | Ce qu'il faut faire.",
                'veilletool'
            ) . $commun . $natures,
        } . ' ' . self::getReadabilityRule();
    }

    /**
     * Met une question en forme d'adresse de redirection.
     *
     * @param string $prompt
     *
     * @return string
     */
    public static function toUrl(string $prompt): string
    {
        return self::ENDPOINT . rawurlencode($prompt);
    }

    /**
     * Question portant sur une carte isolée.
     *
     * @param array<string, mixed> $card
     * @param string               $level
     *
     * @return string
     */
    public static function forCard(array $card, string $level = self::LEVEL_SUMMARY): string
    {
        // Le texte sert deux fois - à reconnaître l'angle, puis à nourrir
        // la question : on ne le remet à plat qu'une fois.
        $text = self::extractText($card);

        return self::buildCardPrompt($card, $level, $text, self::detectAngle($card, $text));
    }

    /**
     * Assemble la question d'une carte, texte et angle déjà connus.
     *
     * @param array<string, mixed> $card
     * @param string               $level
     * @param string               $text
     * @param string               $angle
     *
     * @return string
     */
    private static function buildCardPrompt(
        array $card,
        string $level,
        string $text,
        string $angle
    ): string {
        $parts = [
            self::getCardInstruction($level, $angle),
            sprintf(__('Information : « %s ».', 'veilletool'), (string) ($card['name'] ?? '')),
        ];

        if ($text !== '') {
            // Le niveau détaillé mérite davantage de contexte.
            $length  = $level === self::LEVEL_DETAILED ? 1500 : 600;
            $parts[] = sprintf(__('Résumé : %s.', 'veilletool'), rtrim(mb_substr($text, 0, $length), " \t\n.;,"));
        }

        $url = trim((string) ($card['url'] ?? ''));
        if ($url !== '') {
            $parts[] = sprintf(__('Source : %s', 'veilletool'), $url);
        }

        return implode(' ', $parts);
    }

    /**
     * Les quatre questions possibles pour une carte, pour permettre à
     * l'interface de basculer d'un niveau à l'autre sans recharger la page.
     *
     * @param array<string, mixed> $card
     *
     * @return array<string, string>
     */
    public static function forCardAllLevels(array $card): array
    {
        // Le texte et l'angle ne dépendent pas du niveau : les quatre
        // variantes les partagent.
        $text  = self::extractText($card);
        $angle = self::detectAngle($card, $text);

        $prompts = [];
        foreach (array_keys(self::getLevels()) as $level) {
            $prompts[$level] = self::buildCardPrompt($card, $level, $text, $angle);
        }

        return $prompts;
    }

    /**
     * Question portant sur un lot de cartes.
     *
     * Deux mises en forme sont tentées : la forme détaillée reprend le
     * résumé et le lien de chaque carte.
     * Si le lot n'y tient pas, la forme abrégée ne conserve que les thèmes,
     * le titre et la date.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param string                           $level
     * @param int                              $budget
     *
     * @return array{url:string, included:int, skipped:int, prompt:string, compact:bool}
     */
    public static function forCards(
        array $cards,
        string $level = self::LEVEL_SUMMARY,
        int $budget = self::URL_BUDGET
    ): array {
        $detailed = self::assemble($cards, $level, $budget, false);

        if ($detailed['skipped'] === 0) {
            return $detailed;
        }

        $compact = self::assemble($cards, $level, $budget, true);

        return $compact['included'] > $detailed['included'] ? $compact : $detailed;
    }

    /**
     * Assemble la question dans une mise en forme donnée, en s'arrêtant à
     * la longueur d'adresse admissible.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param string                           $level
     * @param int                              $budget
     * @param bool                             $compact
     *
     * @return array{url:string, included:int, skipped:int, prompt:string, compact:bool}
     */
    private static function assemble(array $cards, string $level, int $budget, bool $compact): array
    {
        $intro    = self::getBatchInstruction($level);
        $lines    = [];
        $included = 0;
        $skipped  = 0;

        // `rawurlencode()` encode octet par octet : la longueur de
        // l'adresse est donc la somme des longueurs encodées de ses
        // morceaux.
        // On la tient à jour au fil des cartes, plutôt que de réencoder la
        // question entière à chaque essai - ce qui coûtait le carré du
        // nombre de cartes, et se voyait dès la centaine.
        $length = strlen(self::ENDPOINT)
            + strlen(rawurlencode($intro . "\n\n"));

        foreach ($cards as $card) {
            $line = self::formatCardLine($included + 1, $card, $compact, $level);

            // Chaque ligne sauf la première est précédée d'un saut de
            // ligne.
            $added = strlen(rawurlencode($included > 0 ? "\n" . $line : $line));

            if ($length + $added > $budget) {
                // Une carte plus courte peut encore tenir : on poursuit.
                $skipped++;
                continue;
            }

            $length += $added;
            $lines[] = $line;
            $included++;
        }

        if ($lines === []) {
            return ['url' => '', 'included' => 0, 'skipped' => $skipped, 'prompt' => '', 'compact' => $compact];
        }

        $prompt = $intro . "\n\n" . implode("\n", $lines);

        return [
            'url'      => self::toUrl($prompt),
            'included' => $included,
            'skipped'  => $skipped,
            'prompt'   => $prompt,
            'compact'  => $compact,
        ];
    }

    /**
     * Met une carte en forme sur une ligne.
     *
     * @param int                  $position
     * @param array<string, mixed> $card
     * @param bool                 $compact  Ne garder que thèmes, titre et date
     * @param string               $level
     *
     * @return string
     */
    private static function formatCardLine(int $position, array $card, bool $compact, string $level): string
    {
        $line = $position . '. ';

        $categories = self::extractCategories($card);
        if ($categories !== '') {
            $line .= '[' . $categories . '] ';
        }

        $line .= (string) ($card['name'] ?? '');

        $published = (string) ($card['date_published'] ?? '');
        if ($published !== '') {
            $line .= ' (' . substr($published, 0, 10) . ')';
        }

        if ($compact) {
            return $line;
        }

        $summary = self::extractText($card);
        if ($summary !== '') {
            $length = $level === self::LEVEL_DETAILED ? 400 : 220;
            $line  .= ' - ' . rtrim(mb_substr($summary, 0, $length), " \t\n.;,") . '.';
        }

        $url = trim((string) ($card['url'] ?? ''));
        if ($url !== '') {
            $line .= ' ' . sprintf(__('Source : %s', 'veilletool'), $url);
        }

        return $line;
    }

    /**
     * Document Markdown décrivant un lot de cartes, destiné à être remis
     * tel quel à une IA : la consigne y figure, suivie du détail de chaque
     * carte.
     *
     * Contrairement à la redirection, ce format n'est pas borné par la
     * longueur d'une adresse : toutes les cartes retenues y figurent.
     *
     * @param array<int, array<string, mixed>> $cards
     * @param string                           $level
     *
     * @return string
     */
    public static function toMarkdown(array $cards, string $level = self::LEVEL_SUMMARY): string
    {
        $level  = self::normalizeLevel($level);
        $levels = self::getLevels();
        $now    = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $md = [];
        $md[] = '# ' . __('Veille technologique - sélection de cartes', 'veilletool');
        $md[] = '';
        $md[] = sprintf(
            __('_Export du %1$s - niveau d\'analyse : %2$s - %3$d carte(s)._', 'veilletool'),
            substr($now, 0, 16),
            $levels[$level],
            count($cards)
        );
        $md[] = '';
        $md[] = '## ' . __('Demande', 'veilletool');
        $md[] = '';
        $md[] = self::getBatchInstruction($level);
        $md[] = '';
        $md[] = __(
            'Les informations à analyser sont listées ci-dessous, une section par carte.',
            'veilletool'
        );
        $md[] = '';
        $md[] = '## ' . __('Informations', 'veilletool');

        foreach ($cards as $index => $card) {
            $md[] = '';
            $md[] = '### ' . ($index + 1) . '. ' . self::escapeMarkdown((string) ($card['name'] ?? ''));
            $md[] = '';

            $categories = self::extractCategories($card);
            if ($categories !== '') {
                $md[] = '- **' . __('Thèmes', 'veilletool') . '** : ' . self::escapeMarkdown($categories);
            }

            $source = trim((string) ($card['source_name'] ?? ''));
            if ($source !== '') {
                $md[] = '- **' . __('Flux', 'veilletool') . '** : ' . self::escapeMarkdown($source);
            }

            $published = (string) ($card['date_published'] ?? '');
            if ($published !== '') {
                $md[] = '- **' . __('Publié le', 'veilletool') . '** : ' . substr($published, 0, 10);
            }

            $author = trim((string) ($card['author'] ?? ''));
            if ($author !== '') {
                $md[] = '- **' . __('Auteur', 'veilletool') . '** : ' . self::escapeMarkdown($author);
            }

            $url = trim((string) ($card['url'] ?? ''));
            $md[] = '- **' . __('Source', 'veilletool') . '** : '
                . ($url !== '' ? '<' . $url . '>' : __('saisie manuelle, aucune source en ligne', 'veilletool'));

            $text = self::extractText($card);
            if ($text !== '') {
                $md[] = '';
                // Le Markdown n'a pas la contrainte de longueur d'une URL :
                // on transmet un contexte nettement plus large.
                $md[] = self::escapeMarkdown(mb_substr($text, 0, 4000));
            }
        }

        $md[] = '';
        $md[] = '---';
        $md[] = '';
        $md[] = __('Rappel du format de réponse attendu :', 'veilletool') . ' '
            . self::getBatchInstruction($level);
        $md[] = '';

        return implode("\n", $md);
    }

    /**
     * Nom de fichier proposé au téléchargement.
     *
     * @return string
     */
    public static function getMarkdownFilename(): string
    {
        return 'resume.md';
    }

    /**
     * Texte exploitable d'une carte : son résumé, ou son contenu réduit en
     * texte brut si le résumé est vide.
     *
     * @param array<string, mixed> $card
     *
     * @return string
     */
    private static function extractText(array $card): string
    {
        $summary = trim((string) ($card['summary'] ?? ''));

        if ($summary !== '') {
            return $summary;
        }

        return trim(RichText::getTextFromHtml((string) ($card['content'] ?? ''), false));
    }

    /**
     * Thèmes d'une carte, sur une seule ligne.
     *
     * @param array<string, mixed> $card
     *
     * @return string
     */
    private static function extractCategories(array $card): string
    {
        $names = [];
        foreach ((array) ($card['categories'] ?? []) as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Neutralise les caractères qui structureraient le Markdown à tort.
     *
     * @param string $value
     *
     * @return string
     */
    private static function escapeMarkdown(string $value): string
    {
        // Les pipes casseraient les tableaux, les dièses en début de ligne
        // créeraient des titres parasites.
        $value = str_replace('|', '\\|', $value);

        return preg_replace('/^(\s*)#/m', '$1\\#', $value) ?? $value;
    }
}
