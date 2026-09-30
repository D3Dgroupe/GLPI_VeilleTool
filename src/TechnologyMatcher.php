<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use Glpi\RichText\RichText;

/**
 * Rapproche le texte d'une carte des entrées du référentiel technologique.
 *
 * Le rapprochement se veut volontairement tolérant : une carte qui
 * mentionne « Debian 13.0.1 » doit être rattachée à l'entrée « Debian 13
 * (Trixie) », et une carte qui ne parle que de « Linux » doit être
 * rattachée à la famille « Linux kernel », dont les entrées s'appellent «
 * Linux 6.x ».
 *
 * La méthode retenue est un index de termes plutôt qu'une recherche de
 * sous-chaîne : le texte de la carte est découpé en mots, puis les
 * combinaisons de un à quatre mots consécutifs sont cherchées dans l'index.
 * Le coût reste proportionnel à la longueur du texte, et non au nombre
 * d'entrées du référentiel - lequel dépasse deux mille lignes.
 */
class TechnologyMatcher
{
    /** Le texte cite le libellé complet de l'entrée */
    public const KIND_EXACT = 'exact';

    /** Le texte cite une version plus précise que le libellé de l'entrée */
    public const KIND_VERSION = 'version';

    /** Le texte cite l'alias de l'entrée, indiqué entre parenthèses */
    public const KIND_ALIAS = 'alias';

    /** Le texte cite le nom de la famille, sans désigner d'entrée précise */
    public const KIND_FAMILY = 'family';

    /** Le texte cite le produit sans sa version */
    public const KIND_ROOT = 'root';

    /**
     * Score de confiance attaché à chaque nature de rapprochement.
     *
     * Plus le texte de la carte désigne précisément l'entrée du
     * référentiel, plus la confiance est élevée : un libellé cité mot pour
     * mot ne laisse aucun doute, une simple racine de produit en laisse
     * beaucoup.
     *
     * @var array<string, int>
     */
    public const CONFIDENCE = [
        self::KIND_EXACT   => 100,
        self::KIND_VERSION => 90,
        self::KIND_ALIAS   => 85,
        self::KIND_FAMILY  => 60,
        self::KIND_ROOT    => 50,
    ];

    /** Nombre maximum de mots d'un terme indexé */
    private const MAX_TERM_WORDS = 4;

    /** Longueur de texte retenue par carte, pour borner le coût */
    private const MAX_TEXT_LENGTH = 6000;

    /** Longueur minimale d'un terme, en caractères */
    private const MIN_TERM_LENGTH = 3;

    /**
     * Mots trop courants pour constituer un terme discriminant à eux seuls.
     *
     * @var string[]
     */
    private const STOP_WORDS = [
        'os', 've', 'ng', 'lts', 'esr', 'web', 'api', 'net', 'app', 'pro',
        'cloud', 'server', 'serveur', 'client', 'service', 'services',
        'autre', 'autres', 'divers', 'generique', 'generiques', 'standard',
        'protocole', 'protocoles', 'application', 'applications', 'noyau',
        'noyaux', 'kernel', 'base', 'bases', 'outil', 'outils', 'gestion',
        'reseau', 'reseaux', 'systeme', 'systemes', 'version', 'versions',
    ];

    /**
     * Suffixes de qualification à retirer en fin de libellé.
     *
     * @var string[]
     */
    private const TRAILING_NOISE = ['lts', 'esr', 'sp1', 'sp2', 'r2', 'ga', 'eol'];

    /**
     * Index terme => entrées visées.
     *
     * `kind` retient la nature du rapprochement que ce terme matérialise :
     * c'est elle qui donnera sa confiance au rattachement.
     *
     * @var array<string, array<int, array{categorie:string, famille:string, name:string, kind:string}>>
     */
    private array $index = [];

    /**
     * Préfixes des termes à plusieurs mots.
     *
     * Deux tiers du référentiel s'indexent sous des termes de plusieurs
     * mots
     * - « debian 13 », « iso 27001 2022 ».
     * Sans cet ensemble, la recherche allonge le n-gramme jusqu'à quatre
     * mots à **chaque** position du texte, alors que la quasi-totalité
     * d'entre elles ne commencent aucun terme connu.
     * Le savoir permet d'abandonner dès le premier mot.
     *
     * @var array<string, true>
     */
    private array $prefixes = [];

    /**
     * Instance partagée pour la durée de la requête.
     *
     * Construire l'index coûte une vingtaine de millisecondes et plus de
     * deux mille lectures : le refaire à chaque carte enregistrée rendrait
     * une collecte de flux insupportablement lente.
     */
    private static ?self $instance = null;

    /**
     * Instance partagée, construite au premier appel.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Oublie l'instance partagée.
     *
     * À appeler dès que le référentiel change, sans quoi les cartes
     * enregistrées ensuite seraient classées avec l'ancien index.
     *
     * @return void
     */
    public static function invalidate(): void
    {
        self::$instance = null;
    }

    /**
     * @param array<int, array{categorie:string, famille:string, name:string}>|null $entries
     *        Référentiel à indexer.
     * Chargé depuis la base si absent.
     */
    public function __construct(?array $entries = null)
    {
        $this->buildIndex($entries ?? Technology::getAllActive());
    }

    /**
     * Nombre de termes indexés, utile au diagnostic.
     *
     * @return int
     */
    public function countTerms(): int
    {
        return count($this->index);
    }

    /**
     * Construit l'index des termes à partir du référentiel.
     *
     * @param array<int, array{categorie:string, famille:string, name:string}> $entries
     *
     * @return void
     */
    private function buildIndex(array $entries): void
    {
        $families = [];

        foreach ($entries as $entry) {
            foreach ($this->buildTerms($entry['name']) as $term => $kind) {
                // Un libellé complet (« Debian 13 ») désigne la sous-
                // catégorie.
                // Sa racine (« Debian ») ne permet pas de trancher entre
                // les versions et vise donc la famille.
                $this->addTerm($term, [
                    'categorie' => $entry['categorie'],
                    'famille'   => $entry['famille'],
                    'name'      => $kind === self::KIND_ROOT ? '' : $entry['name'],
                    'kind'      => $kind,
                ]);
            }

            // La famille est indexée une seule fois : une carte qui cite «
            // Linux kernel » vise la famille entière.
            $family_key = $entry['categorie'] . '|' . $entry['famille'];
            if (!isset($families[$family_key])) {
                $families[$family_key] = true;
                foreach (array_keys($this->buildTerms($entry['famille'], false)) as $term) {
                    $this->addTerm((string) $term, [
                        'categorie' => $entry['categorie'],
                        'famille'   => $entry['famille'],
                        'name'      => '',
                        'kind'      => self::KIND_FAMILY,
                    ]);
                }
            }
        }
    }

    /**
     * Ajoute un terme à l'index, sans doublon de cible.
     *
     * Quand la même cible est déjà visée par ce terme sous une autre
     * nature, seule la plus fiable est retenue : deux entrées du
     * référentiel peuvent produire le même terme, l'une mot pour mot et
     * l'autre par sa racine.
     *
     * @param string                                                             $term
     * @param array{categorie:string, famille:string, name:string, kind:string}  $target
     *
     * @return void
     */
    private function addTerm(string $term, array $target): void
    {
        // La clé de dédoublonnage est figée ici, une fois pour toutes :
        // elle servira à chaque rapprochement, et la recomposer à chaque
        // fois revenait à refaire deux concaténations par carte et par
        // occurrence.
        $target['key'] = $target['categorie'] . '|' . $target['famille'] . '|' . $target['name'];

        if (!isset($this->index[$term])) {
            $this->index[$term] = [];
            $this->indexPrefixes($term);
        }

        foreach ($this->index[$term] as $position => $existing) {
            if (
                $existing['categorie'] !== $target['categorie']
                || $existing['famille'] !== $target['famille']
                || $existing['name'] !== $target['name']
            ) {
                continue;
            }

            if (self::CONFIDENCE[$target['kind']] > self::CONFIDENCE[$existing['kind']]) {
                $this->index[$term][$position] = $target;
            }

            return;
        }

        $this->index[$term][] = $target;
    }

    /**
     * Retient qu'un terme commence par telle ou telle suite de mots.
     *
     * La remontée s'arrête au premier préfixe déjà connu : ses propres
     * préfixes le sont alors nécessairement.
     *
     * @param string $term
     *
     * @return void
     */
    private function indexPrefixes(string $term): void
    {
        $cut = strrpos($term, ' ');

        while ($cut !== false) {
            $prefix = substr($term, 0, $cut);

            if (isset($this->prefixes[$prefix])) {
                return;
            }

            $this->prefixes[$prefix] = true;
            $cut = strrpos($prefix, ' ');
        }
    }

    /**
     * Dérive les termes recherchables d'un libellé.
     *
     * Exemples : « Debian 13 (Trixie) » => debian 13, debian, trixie «
     * Linux 6.17 » => linux 6.17, linux « ISO/IEC 27001:2022 » => iso iec
     * 27001 2022, iso iec 27001, iso 27001 2022, iso 27001 « MySQL 8.4 LTS
     * » => mysql 8.4, mysql
     *
     * @param string $label
     * @param bool   $with_root Générer la racine produit (libellé sans version)
     *
     * @return array<string, string> terme => nature du rapprochement (KIND_*)
     */
    public function buildTerms(string $label, bool $with_root = true): array
    {
        $label = trim($label);
        if ($label === '') {
            return [];
        }

        // Le contenu entre parenthèses est un alias : « Debian 13 (Trixie)
        // ».
        $alias = '';
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $label, $m) === 1) {
            $label = trim($m[1]);
            $alias = trim($m[2]);
        }

        $variants = [$label];

        // « ISO/IEC 27001 » est aussi cité « ISO 27001 » : on conserve les
        // deux lectures des acronymes séparés par une barre oblique.
        if (preg_match('#\b([A-Za-z]{2,5})/([A-Za-z]{2,5})\b#u', $label) === 1) {
            $variants[] = preg_replace('#\b([A-Za-z]{2,5})/([A-Za-z]{2,5})\b#u', '$1', $label) ?? $label;
        }

        $terms = [];

        foreach ($variants as $variant) {
            $tokens = $this->tokenize($variant);
            $tokens = $this->stripTrailingNoise($tokens);

            if ($tokens === []) {
                continue;
            }

            $terms[implode(' ', $tokens)] = self::KIND_EXACT;

            // Racine produit : le libellé amputé de sa version finale, et
            // d'elle seule, pour ne pas dériver vers des termes trop
            // larges.
            if ($with_root) {
                $root = $this->stripTrailingVersion($tokens);
                if ($root !== [] && $root !== $tokens) {
                    $key = implode(' ', $root);
                    // Une racine ne prime jamais sur un libellé exact
                    // homonyme (« GLPI 11 » face au produit « GLPI »).
                    $terms[$key] = $terms[$key] ?? self::KIND_ROOT;
                }
            }
        }

        if ($alias !== '') {
            $alias_tokens = $this->tokenize($alias);
            if ($alias_tokens !== []) {
                $key = implode(' ', $alias_tokens);
                // Un alias qui redit le libellé n'abaisse pas la confiance.
                $terms[$key] = $terms[$key] ?? self::KIND_ALIAS;
            }
        }

        foreach (array_keys($terms) as $term) {
            if (!$this->isUsableTerm((string) $term)) {
                unset($terms[$term]);
            }
        }

        return $terms;
    }

    /**
     * Un terme est-il assez discriminant pour être indexé ?
     *
     * @param string $term
     *
     * @return bool
     */
    private function isUsableTerm(string $term): bool
    {
        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return false;
        }

        $words = explode(' ', $term);

        if (count($words) > self::MAX_TERM_WORDS) {
            return false;
        }

        // Un terme d'un seul mot doit être un nom propre de produit : ni un
        // nombre, ni un mot du langage courant du domaine.
        if (count($words) === 1) {
            if (is_numeric($words[0]) || in_array($words[0], self::STOP_WORDS, true)) {
                return false;
            }
        }

        // « autres distributions », « divers » ... ne désignent rien de
        // précis.
        if (in_array($words[0], ['autre', 'autres', 'divers'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Découpe une chaîne en mots normalisés : minuscules, sans accent, la
     * ponctuation devenant séparateur, les points des versions conservés.
     *
     * @param string $value
     *
     * @return string[]
     */
    public function tokenize(string $value): array
    {
        $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9.]+/', ' ', $value) ?? '';

        // Un point isolé ou en fin de mot est une ponctuation, pas une
        // version.
        $value = preg_replace('/\.(?![0-9])/', ' ', $value) ?? '';
        $value = preg_replace('/(?<![0-9a-z])\./', ' ', $value) ?? '';

        return array_values(array_filter(explode(' ', $value), static fn(string $t): bool => $t !== ''));
    }

    /**
     * Retire les qualificatifs de fin de libellé (LTS, ESR, R2 ...).
     *
     * @param string[] $tokens
     *
     * @return string[]
     */
    private function stripTrailingNoise(array $tokens): array
    {
        // On ne réduit jamais un libellé à un seul mot par ce biais : «
        // Linux-lts » doit rester « linux lts », sans quoi il deviendrait
        // un homonyme exact du mot « linux ».
        while (count($tokens) > 2 && in_array(end($tokens), self::TRAILING_NOISE, true)) {
            array_pop($tokens);
        }

        return array_values($tokens);
    }

    /**
     * Retire le dernier groupe de version d'une suite de mots.
     *
     * @param string[] $tokens
     *
     * @return string[] Tableau vide si le retrait ne laisse rien d'exploitable
     */
    private function stripTrailingVersion(array $tokens): array
    {
        if (count($tokens) < 2) {
            return [];
        }

        $last = end($tokens);

        if (preg_match('/^v?\d+(\.\d+)*(\.x)?$/', $last) !== 1) {
            return [];
        }

        array_pop($tokens);

        return array_values($tokens);
    }

    /**
     * Variantes d'un jeton de version, du plus précis au plus général : «
     * 13.0.1 » donne 13.0.1, 13.0 puis 13.
     *
     * @param string $token
     *
     * @return string[]
     */
    private function versionVariants(string $token): array
    {
        if (!str_contains($token, '.') || preg_match('/^\d+(\.\d+)+$/', $token) !== 1) {
            return [$token];
        }

        $parts    = explode('.', $token);
        $variants = [];

        for ($length = count($parts); $length >= 1; $length--) {
            $variants[] = implode('.', array_slice($parts, 0, $length));
        }

        return $variants;
    }

    /**
     * Rapproche une carte du référentiel.
     *
     * @param array<string, mixed> $card
     *
     * @return array<int, array{categorie:string, famille:string, name:string, confidence:int}>
     */
    public function match(array $card): array
    {
        $text = implode(' ', [
            (string) ($card['name'] ?? ''),
            (string) ($card['summary'] ?? ''),
            RichText::getTextFromHtml((string) ($card['content'] ?? ''), false),
        ]);

        return $this->matchText($text);
    }

    /**
     * Rapproche un texte libre du référentiel.
     *
     * @param string $text
     *
     * @return array<int, array{categorie:string, famille:string, name:string, confidence:int}>
     */
    public function matchText(string $text): array
    {
        $tokens = $this->tokenize(mb_substr($text, 0, self::MAX_TEXT_LENGTH));
        $count  = count($tokens);
        $found  = [];

        // Les variantes d'un jeton ne dépendent que de lui.
        // Le même jeton étant le dernier mot de quatre n-grammes
        // différents, les calculer dans la boucle revenait à les recalculer
        // quatre fois - expression régulière comprise.
        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $variants[$i] = $this->versionVariants($tokens[$i]);
        }

        for ($i = 0; $i < $count; $i++) {
            $prefix = '';

            for ($length = 1; $length <= self::MAX_TERM_WORDS && ($i + $length) <= $count; $length++) {
                $last  = $i + $length - 1;
                $token = $tokens[$last];
                $head  = $length === 1 ? '' : $prefix . ' ';

                // Le dernier mot est testé dans ses variantes de version,
                // ce qui rattache « debian 13.0.1 » à l'entrée « Debian 13
                // ».
                foreach ($variants[$last] as $variant) {
                    $term = $head . $variant;

                    if (!isset($this->index[$term])) {
                        continue;
                    }

                    // Le texte cite une version plus précise que le libellé
                    // retenu : le rattachement reste juste, mais il repose
                    // sur une troncature, donc sur une hypothèse.
                    $truncated = $variant !== $token;

                    foreach ($this->index[$term] as $target) {
                        $confidence = self::CONFIDENCE[
                            $truncated && $target['kind'] === self::KIND_EXACT
                                ? self::KIND_VERSION
                                : $target['kind']
                        ];

                        $key = $target['key'];

                        // Une même cible peut être atteinte par plusieurs
                        // chemins : on garde le plus sûr.
                        // Le candidat n'est composé que s'il l'emporte.
                        if (isset($found[$key]) && $found[$key]['confidence'] >= $confidence) {
                            continue;
                        }

                        $found[$key] = [
                            'categorie'  => $target['categorie'],
                            'famille'    => $target['famille'],
                            'name'       => $target['name'],
                            'confidence' => $confidence,
                        ];
                    }
                }

                $prefix = $head . $token;

                // Aucun terme connu ne commence par cette suite de mots :
                // l'allonger ne peut plus rien donner.
                // C'est le cas à la quasi-totalité des positions d'un texte
                // courant.
                if (!isset($this->prefixes[$prefix])) {
                    break;
                }
            }
        }

        return $this->preferMostPrecise($found);
    }

    /**
     * Ne conserve, pour chaque famille où une sous-catégorie a été
     * reconnue, que ces sous-catégories : une carte qui cite « Debian 13 »
     * ne doit pas être comptée une seconde fois au titre de la famille
     * Debian prise dans son ensemble.
     *
     * @param array<string, array{categorie:string, famille:string, name:string, confidence:int}> $found
     *
     * @return array<int, array{categorie:string, famille:string, name:string, confidence:int}>
     */
    private function preferMostPrecise(array $found): array
    {
        $precise_families = [];

        foreach ($found as $target) {
            if ($target['name'] !== '') {
                $precise_families[$target['categorie'] . '|' . $target['famille']] = true;
            }
        }

        $kept = [];
        foreach ($found as $target) {
            if (
                $target['name'] === ''
                && isset($precise_families[$target['categorie'] . '|' . $target['famille']])
            ) {
                continue;
            }
            $kept[] = $target;
        }

        return $kept;
    }
}
