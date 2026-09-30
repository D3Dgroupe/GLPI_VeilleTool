<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Onglet « Veille » ajouté au formulaire des profils GLPI, permettant de
 * régler les deux droits du plugin.
 */
class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Veille', 'veilletool');
    }

    /** Droit de consultation et d'interaction avec les cartes */
    public const RIGHT_VEILLE = 'plugin_veilletool_veille';

    /** Droit d'administration des flux et des catégories */
    public const RIGHT_SOURCE = 'plugin_veilletool_source';

    /**
     * Description des droits du plugin pour la matrice de cases à cocher.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'field'  => self::RIGHT_VEILLE,
                'label'  => __('Cartes de veille', 'veilletool'),
                'rights' => [
                    READ                   => __('Read'),
                    // « Commenter » se coche seul : un profil en lecture
                    // seule peut alors participer aux échanges sans pouvoir
                    // modifier ni supprimer une carte.
                    Article::RIGHT_COMMENT => [
                        'short' => __('Commenter', 'veilletool'),
                        'long'  => __('Commenter les cartes (sans droit de modification)', 'veilletool'),
                    ],
                    UPDATE                 => __('Update'),
                    CREATE                 => __('Create'),
                    PURGE                  => ['short' => __('Purge'), 'long' => __('Purge')],
                ],
            ],
            [
                'field'  => self::RIGHT_SOURCE,
                'label'  => __('Flux RSS / ATOM et catégories', 'veilletool'),
                'rights' => [
                    READ   => __('Read'),
                    UPDATE => __('Update'),
                    CREATE => __('Create'),
                    PURGE  => ['short' => __('Purge'), 'long' => __('Purge')],
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int) $item->getField('id') > 0) {
            return self::createTabEntry(__('Veille', 'veilletool'), 0, self::class, Menu::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && (int) $item->getField('id') > 0) {
            self::showRightsForm((int) $item->getID());
        }

        return true;
    }

    /**
     * Affiche la matrice des droits du plugin pour un profil.
     *
     * @param int $profiles_id
     *
     * @return void
     */
    public static function showRightsForm(int $profiles_id): void
    {
        $profile = new GlpiProfile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $current = ProfileRight::getProfileRights(
            $profiles_id,
            [self::RIGHT_VEILLE, self::RIGHT_SOURCE]
        );
        foreach ($current as $name => $value) {
            $profile->fields[$name] = $value;
        }

        TemplateRenderer::getInstance()->display('@veilletool/profile_rights.html.twig', [
            'item'    => $profile,
            'rights'  => self::getAllRights(),
            'canedit' => Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]),
        ]);
    }

    /**
     * Donne tous les droits du plugin au profil actuellement actif, afin
     * que l'administrateur qui installe le plugin puisse l'utiliser
     * aussitôt.
     *
     * @return void
     */
    public static function initDefaultProfile(): void
    {
        // Les lignes de droits ont déjà été créées pour l'ensemble des
        // profils par Migration::addRight() dans hook.php.
        // Il ne faut surtout pas appeler ici
        // ProfileRight::addProfileRights() : cette méthode insère sans
        // aucun contrôle d'existence et violerait la clé unique
        // (profiles_id, name) de glpi_profilerights, ce qui interromprait
        // l'installation.
        // Seul le cache des droits connus doit être vidé pour que les
        // nouveaux noms soient pris en compte immédiatement.
        ProfileRight::cleanAllPossibleRights();

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profiles_id <= 0) {
            return;
        }

        // updateProfileRights() met à jour la ligne existante, ou la crée
        // si elle manque : l'opération est idempotente.
        $veille = ALLSTANDARDRIGHT | Article::RIGHT_COMMENT;

        ProfileRight::updateProfileRights($profiles_id, [
            self::RIGHT_VEILLE => $veille,
            self::RIGHT_SOURCE => ALLSTANDARDRIGHT,
        ]);

        $_SESSION['glpiactiveprofile'][self::RIGHT_VEILLE] = $veille;
        $_SESSION['glpiactiveprofile'][self::RIGHT_SOURCE] = ALLSTANDARDRIGHT;
    }
}
