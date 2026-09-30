<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Veilletool;

use Html;

/**
 * Retour à l'écran d'origine après un traitement de formulaire.
 *
 * `Html::back()` n'a qu'une seule source : l'en-tête `Referer` du
 * navigateur.
 * Quand celui-ci manque - politique `Referrer-Policy` restrictive, passage
 * par un mandataire, ou simple divergence entre l'adresse réellement
 * employée et `url_base` -, GLPI ne sait plus d'où venait la demande et
 * renvoie à l'accueil.
 * L'écran de configuration paraît alors « se fermer » tout seul au moment
 * même où l'on enregistre.
 *
 * Cette classe rétablit le comportement attendu : on retourne là d'où l'on
 * vient quand c'est connu, et à un écran choisi par l'appelant sinon.
 * Jamais à l'accueil.
 */
final class Navigation
{
    /**
     * Retourne à la page précédente, ou à l'écran indiqué à défaut.
     *
     * @param string $fallback Adresse employée lorsque la page précédente
     *                         est inconnue
     *
     * @return never
     */
    public static function back(string $fallback): never
    {
        Html::redirect(self::resolve($fallback));
    }

    /**
     * Adresse absolue d'un écran du plugin.
     *
     * @param string $file Nom du fichier sous `front/`
     *
     * @return string
     */
    public static function page(string $file): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/veilletool/front/' . ltrim($file, '/');
    }

    /**
     * Adresse de retour retenue.
     *
     * @param string $fallback
     *
     * @return string
     */
    public static function resolve(string $fallback): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $back = Html::getBackUrl();

        if (!is_string($back) || trim($back) === '') {
            return $fallback;
        }

        // `Html::getBackUrl()` rend l'accueil de GLPI lorsqu'il n'a pas su
        // exploiter le `Referer` : c'est cette valeur-là qu'il faut
        // écarter, et elle seule.
        $home = (string) ($CFG_GLPI['url_base'] ?? '');

        if ($home !== '' && rtrim($back, '/') === rtrim($home, '/')) {
            return $fallback;
        }

        return $back;
    }
}
