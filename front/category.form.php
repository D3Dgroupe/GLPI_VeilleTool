<?php

/**
 * -------------------------------------------------------------------------
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Création et modification d'une catégorie de veille.
 * -------------------------------------------------------------------------
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Veilletool\Category;
use GlpiPlugin\Veilletool\Menu;
use GlpiPlugin\Veilletool\Navigation;

if (!class_exists(Kernel::class, autoload: false)) {
    header('HTTP/1.1 404 Not Found');
    exit();
}

Session::checkRight(Category::$rightname, READ);

$category = new Category();

if (isset($_POST['add'])) {
    $category->check(-1, CREATE, $_POST);

    $newID = $category->add($_POST);
    if ($newID) {
        Html::redirect(Category::getFormURLWithID($newID));
    }

    Navigation::back(Category::getSearchURL());
}

if (isset($_POST['update'])) {
    $id = (int) $_POST['id'];

    $category->check($id, UPDATE);
    $category->update($_POST);

    // Comme pour un flux : enregistrer ne ferme pas la fiche.
    Html::redirect(Category::getFormURLWithID($id));
}

if (isset($_POST['purge'])) {
    $category->check((int) $_POST['id'], PURGE);
    $category->delete(['id' => (int) $_POST['id']], true);

    Html::redirect(Category::getSearchURL());
}

$id = (int) ($_GET['id'] ?? -1);

Category::displayFullPageForItem(
    $id,
    [Menu::SECTOR, Menu::ITEM_CATEGORIES],
    ['formoptions' => 'data-track-changes=true']
);
