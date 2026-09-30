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
 * Réglage de filtre nommé de l'observatoire.
 *
 * Un filtre de veille se reconstitue plusieurs fois par jour : les cartes
 * non lues d'un thème, les cartes commentées du mois, les technologies
 * suivies triées par score.
 * Recomposer chaque fois la même combinaison de sept champs use, et on finit
 * par ne plus filtrer du tout.
 *
 * Un préréglage fige la barre de filtres sous un titre, et la repose d'un
 * choix dans une liste.
 *
 * Il est **propre à chaque utilisateur** : un filtre est une façon de
 * travailler, pas un réglage d'administration.
 * Deux personnes qui suivent la même veille ne la regardent pas sous le même
 * angle, et personne n'a à demander un droit pour ranger sa propre vue.
 */
class FilterPreset extends CommonDBTM
{
    /**
     * La lecture des cartes suffit.
     *
     * Enregistrer sa propre vue ne modifie ni les cartes, ni la
     * configuration : exiger un droit d'écriture reviendrait à réserver le
     * confort d'usage aux administrateurs.
     */
    public static $rightname = 'plugin_veilletool_veille';

    public $dohistory = false;

    /** Nombre maximum de préréglages par utilisateur */
    public const MAX_PRESETS = 30;

    /**
     * Paramètres de la barre de filtres retenus par un préréglage.
     *
     * Ni `start` ni `favorites` : le premier est une position de pagination,
     * le second distingue deux écrans et ne se transporte pas de l'un à
     * l'autre.
     *
     * @var string[]
     */
    public const KEYS = [
        'cat',
        'q',
        'src',
        'tech',
        'from',
        'to',
        'unread',
        'commented',
        'followed',
        'sort',
        'view',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Filtre enregistré', 'Filtres enregistrés', $nb, 'veilletool');
    }

    public static function getIcon()
    {
        return 'ti ti-filter-star';
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareCommonInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * Contrôles communs : un filtre sans titre ne se retrouve pas.
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
                    __('Donnez un nom au filtre : c\'est par lui que vous le retrouverez.', 'veilletool'),
                    false,
                    ERROR
                );

                return false;
            }

            $input['name'] = mb_substr($input['name'], 0, 255);
        }

        return $input;
    }

    /**
     * Retient les seuls paramètres de filtre d'une requête.
     *
     * Les valeurs sont conservées telles quelles, en texte : elles seront
     * relues par l'observatoire, qui les contrôle déjà une à une.
     * Un préréglage n'est donc jamais une porte d'entrée vers un filtre que
     * la barre elle-même refuserait.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, string>
     */
    public static function extract(array $request): array
    {
        $kept = [];

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $request)) {
                continue;
            }

            $value = $request[$key];

            if (is_array($value)) {
                continue;
            }

            $value = trim((string) $value);

            // Un champ vide, une case décochée, « toutes les sources » : rien
            // à retenir.
            // Le préréglage ne porte que ce qui filtre vraiment.
            if ($value === '' || $value === '0') {
                continue;
            }

            $kept[$key] = mb_substr($value, 0, 255);
        }

        return $kept;
    }

    /**
     * Paramètres portés par ce préréglage.
     *
     * @return array<string, string>
     */
    public function getParams(): array
    {
        $stored = json_decode((string) ($this->fields['params'] ?? ''), true);

        if (!is_array($stored)) {
            return [];
        }

        $params = [];

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $stored) && !is_array($stored[$key])) {
                $params[$key] = (string) $stored[$key];
            }
        }

        return $params;
    }

    /**
     * Résumé lisible d'un préréglage, pour la liste déroulante.
     *
     * Le titre seul ne dit pas ce que le filtre retient : deux préréglages
     * bien nommés restent deux lignes qu'on hésite à choisir.
     *
     * @return string
     */
    public function describe(): string
    {
        $params = $this->getParams();
        $bouts  = [];

        if (isset($params['cat'])) {
            $themes = Category::getActiveCategories();
            $theme  = $themes[(int) $params['cat']] ?? null;

            $bouts[] = $theme !== null
                ? (string) $theme['name']
                : __('un thème retiré depuis', 'veilletool');
        }

        if (isset($params['q'])) {
            $bouts[] = '« ' . $params['q'] . ' »';
        }

        if (isset($params['tech'])) {
            $label = Statistics::filterLabel($params['tech']);

            if ($label !== '') {
                $bouts[] = $label;
            }
        }

        if (isset($params['unread'])) {
            $bouts[] = __('non lues', 'veilletool');
        }

        if (isset($params['commented'])) {
            $bouts[] = __('commentées', 'veilletool');
        }

        if (isset($params['followed'])) {
            $bouts[] = __('technologies suivies', 'veilletool');
        }

        if (isset($params['from']) || isset($params['to'])) {
            $bouts[] = __('sur une période', 'veilletool');
        }

        return implode(', ', $bouts);
    }

    /**
     * Préréglages de l'utilisateur courant, par ordre alphabétique.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getMine(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $users_id = (int) Session::getLoginUserID();

        if ($users_id <= 0) {
            return [];
        }

        $preset = new self();
        $rows   = [];

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['users_id' => $users_id],
                'ORDER' => ['name ASC'],
            ]) as $row
        ) {
            $preset->fields = $row;

            $row['id']      = (int) $row['id'];
            $row['params']  = $preset->getParams();
            $row['summary'] = $preset->describe();

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Enregistre les filtres d'une requête sous un titre.
     *
     * Le titre fait foi : réenregistrer sous un nom déjà pris remplace le
     * filtre au lieu d'en créer un second.
     * C'est ce qui permet de corriger une vue qu'on vient de ranger, sans
     * avoir à la supprimer d'abord.
     *
     * @param string               $name
     * @param array<string, mixed> $request
     *
     * @return array{status:string, name:string}
     *         `status` vaut `added`, `replaced`, `empty` ou `full`.
     */
    public static function save(string $name, array $request): array
    {
        $users_id = (int) Session::getLoginUserID();
        $name     = trim($name);

        if ($users_id <= 0 || $name === '') {
            return ['status' => 'empty', 'name' => $name];
        }

        $params = self::extract($request);

        if ($params === []) {
            return ['status' => 'empty', 'name' => $name];
        }

        $preset     = new self();
        $presets_id = self::findByName($name);

        $encoded = (string) json_encode($params, JSON_UNESCAPED_UNICODE);

        if ($presets_id > 0) {
            $preset->update([
                'id'          => $presets_id,
                'params'      => $encoded,
                '_no_message' => true,
            ]);

            return ['status' => 'replaced', 'name' => $name];
        }

        if (self::countMine() >= self::MAX_PRESETS) {
            return ['status' => 'full', 'name' => $name];
        }

        $preset->add([
            'users_id'    => $users_id,
            'name'        => $name,
            'params'      => $encoded,
            '_no_message' => true,
        ]);

        return ['status' => 'added', 'name' => $name];
    }

    /**
     * Retrouve un préréglage de l'utilisateur courant par son titre.
     *
     * @param string $name
     *
     * @return int Identifiant, ou 0
     */
    public static function findByName(string $name): int
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return 0;
        }

        foreach (self::getMine() as $row) {
            if (mb_strtolower(trim((string) $row['name'])) === $name) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * Nombre de préréglages de l'utilisateur courant.
     *
     * @return int
     */
    public static function countMine(): int
    {
        return countElementsInTable(
            self::getTable(),
            ['users_id' => (int) Session::getLoginUserID()]
        );
    }

    /**
     * Charge un préréglage de l'utilisateur courant.
     *
     * L'appartenance est vérifiée ici, et non par l'appelant : un
     * identifiant deviné dans une adresse ne doit pas donner accès à la vue
     * de quelqu'un d'autre.
     *
     * @param int $presets_id
     *
     * @return array{name:string, params:array<string, string>}|null
     */
    public static function load(int $presets_id): ?array
    {
        $preset = new self();

        if ($presets_id <= 0 || !$preset->getFromDB($presets_id)) {
            return null;
        }

        if ((int) $preset->fields['users_id'] !== (int) Session::getLoginUserID()) {
            return null;
        }

        return [
            'name'   => (string) $preset->fields['name'],
            'params' => $preset->getParams(),
        ];
    }

    /**
     * Supprime un préréglage de l'utilisateur courant.
     *
     * @param int $presets_id
     *
     * @return string Titre supprimé, chaîne vide si rien ne correspond
     */
    public static function remove(int $presets_id): string
    {
        $preset = new self();

        if ($presets_id <= 0 || !$preset->getFromDB($presets_id)) {
            return '';
        }

        if ((int) $preset->fields['users_id'] !== (int) Session::getLoginUserID()) {
            return '';
        }

        $name = (string) $preset->fields['name'];

        return $preset->delete(['id' => $presets_id], true) ? $name : '';
    }

    /**
     * Retire les préréglages d'un utilisateur supprimé.
     *
     * @param int $users_id
     *
     * @return void
     */
    public static function purgeForUser(int $users_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($users_id <= 0) {
            return;
        }

        $DB->delete(self::getTable(), ['users_id' => $users_id]);
    }
}
