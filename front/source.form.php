<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Création et modification d'une source (flux RSS / Atom, fichier XML,
 * saisie manuelle).
 * -------------------------------------------------------------------------
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Source;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Source::$rightname, READ);

$source = new Source();

if (isset($_POST['add'])) {
    $source->check(-1, CREATE, $_POST);

    $source->add($_POST);

    // Enregistrer, c'est avoir fini : on revient à la liste des flux, d'où
    // le nouveau flux peut être collecté aussitôt.
    Html::redirect(Source::getSearchURL());
}

if (isset($_POST['update'])) {
    $id = (int) $_POST['id'];

    $source->check($id, UPDATE);
    $source->update($_POST);

    // Retour à la liste des flux, et non au `Referer` : celui-ci renvoyait
    // à l'accueil de GLPI dès que le navigateur ne le transmettait pas.
    Html::redirect(Source::getSearchURL());
}

if (isset($_POST['purge'])) {
    $source->check((int) $_POST['id'], PURGE);
    $source->delete(['id' => (int) $_POST['id']], true);

    Html::redirect(Source::getSearchURL());
}

$id = (int) ($_GET['id'] ?? -1);

Source::displayFullPageForItem(
    $id,
    [Menu::SECTOR, Menu::ITEM_SOURCES],
    ['formoptions' => 'data-track-changes=true']
);
