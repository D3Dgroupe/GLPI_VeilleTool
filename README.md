# Outils de Veille - plugin GLPI 11

Centralise la veille technologique dans GLPI : collecte de flux **RSS / Atom**,
affichage des informations sous forme de **cartes** filtrables et **classées par
score d'intérêt**, marquage **lu / non lu** propre à chaque utilisateur,
**favoris** partagés et **commentaires** collaboratifs.

L'historique des versions est dans [CHANGELOG.md](CHANGELOG.md).

* Clé technique du plugin : `veilletool`
* Auteur : Kevin D3D
* Version : 1.10.0
* Compatibilité : GLPI >= 11.0.0 et < 12.0.0, PHP >= 8.2

---

## Installation

1. Copier le dossier `veilletool` dans le répertoire des plugins de GLPI :

   ```
   sudo cp -r veilletool /var/www/glpi/plugins/
   sudo chown -R root:www-data /var/www/glpi/plugins/veilletool
   ```

   (adapter `www-data` à l'utilisateur de votre serveur web)

2. **Poser les droits.** Les répertoires en `750`, les fichiers en `640` :

   ```
   sudo find /var/www/glpi/plugins/veilletool -type d -exec chmod 750 {} +
   sudo find /var/www/glpi/plugins/veilletool -type f -exec chmod 640 {} +
   ```

3. Dans GLPI : **Configuration → Plugins**, puis **Installer** et **Activer**
   « Outils de Veille ».

4. **Se déconnecter puis se reconnecter.** GLPI met le menu en cache dans la
   session : sans reconnexion, le nouvel onglet « Veille » n'apparaît pas.

L'installation crée les quatre thèmes livrés (Cybersécurité, Infrastructure,
Technologie D3D, Innovation), les deux tâches automatiques, les réglages du
score d'intérêt, et attribue tous les droits du plugin au profil avec lequel
l'installation a été réalisée.

Elle charge aussi le **catalogue de 43 flux** livré avec le plugin - **tous
désactivés**. Rien n'est collecté tant que l'administrateur n'a pas activé ce
qu'il souhaite suivre, dans *Veille → Flux RSS / ATOM*. Voir
[Catalogue livré](#catalogue-livré).

> Il n'y a **pas** de thème « Réglementation » : la réglementation est une
> catégorie du référentiel technologique, où elle est suivie avec beaucoup plus
> de finesse. En faire aussi un thème revenait à qualifier deux fois la même
> information par deux mécanismes différents.

### Pourquoi deux commandes, et pas un `chmod -R`

`chmod -R 750` poserait le bit d'exécution sur **tous** les fichiers. Or un
répertoire et un fichier n'ont pas besoin des mêmes droits, et le bit `x` n'y
veut pas dire la même chose :

| | Droit | Ce qu'il permet |
|---|---|---|
| Répertoires | `750` | `rwx` pour `www-data`, `r-x` pour son groupe, **rien** pour les autres. Le `x` d'un répertoire n'exécute rien : il autorise à le traverser, et sans lui plus rien n'est lisible dessous |
| Fichiers | `640` | `rw-` pour `www-data`, `r--` pour son groupe, **rien** pour les autres. Un `.php` est lu par l'interpréteur, jamais exécuté par le système : il n'a aucune raison d'être exécutable |

Le `find … -exec … {} +` distingue les deux, et regroupe les arguments -
un `\;` relancerait `chmod` une fois par fichier.

Ce qui compte vraiment ici, c'est le **`0` final**. Le plugin embarque le
catalogue de flux, le référentiel technologique et ses gabarits ; GLPI, lui,
porte `config/glpicrypt.key` et ses clés OAuth dans la même arborescence. Rien
de tout cela n'a à être lisible par les autres comptes de la machine - un
compte applicatif voisin, un service qui tourne sous son propre utilisateur.

> Les mêmes droits valent pour l'ensemble de GLPI, pas seulement pour ce
> plugin. Deux exceptions : `files/` et `config/` doivent rester **accessibles
> en écriture** au serveur web (`750` convient, l'utilisateur en étant
> propriétaire), et `marketplace/` aussi si vous l'utilisez.
>
> Après une mise à jour par recopie, **repasser les deux commandes** : les
> fichiers arrivent avec les droits de leur archive, pas avec ceux du
> répertoire qui les accueille.

## Mise à jour

Recopier le dossier par-dessus le précédent, puis **Configuration → Plugins →
Mettre à jour**.

Une mise à jour ne charge **pas** le catalogue de flux : un flux écarté ne doit
pas réapparaître. Le bouton **Charger le catalogue** est là pour qui le veut.

Installation et migration sont deux chemins distincts :

| Fichier | Rôle |
|---|---|
| `install/install.php` | L'**état cible** du schéma et les données livrées. Aucune notion des versions antérieures. |
| `install/migration.php` | Les seuls **écarts** entre une version connue et la version courante, appliqués dans l'ordre. |

`hook.php` ne fait que choisir lequel s'applique. Il s'appuie sur la version du
schéma enregistrée dans la configuration de GLPI (contexte
`plugin:veilletool`) : absente, c'est une première installation. Présente,
c'est une migration à partir de cette version. Elle n'est écrite qu'une fois
tout le reste passé, de sorte qu'une installation interrompue soit reprise au
prochain essai au lieu de se croire à jour.

Chaque étape de migration est **idempotente** : la rejouer ne casse rien.

> Les versions 1.0.0 à 1.4.2 ont été publiées sous la clé technique
> `veilletechno`. Une installation portant cette clé est reprise
> automatiquement - tables, droits, historique et actions automatiques.

## Désinstallation

**Configuration → Plugins → Désinstaller**. Toutes les tables du plugin, ses
droits et sa tâche automatique sont supprimés. Les données de veille sont
définitivement perdues : faites une sauvegarde au préalable.

---

## Utilisation

### L'onglet « Veille »

Le plugin ajoute un secteur de premier niveau **Veille** dans le menu, au même
titre que Parc, Assistance, Gestion, Outils, Administration et Configuration.
Il contient :

| Sous-onglet | Rôle |
|---|---|
| **Observatoire** | Toutes les cartes, avec les onglets de filtrage et la recherche |
| **Favoris** | Les mêmes cartes, restreintes à celles mises en favori |
| **Flux RSS / ATOM** | Flux d'alimentation et collecte manuelle |
| **Catégories** | Gestion des thèmes (les « tags » qui alimentent les onglets) |
| **Technologies** | Référentiel technologique, et référentiel des technologies suivies |
| **Score d'intérêt** | Poids des composantes du score, seuils de priorité, tri par défaut |
| **Sauvegarde** | Export et import CSV de la configuration, bloc par bloc |

### Les trois vues de l'observatoire

Un sélecteur en haut de page bascule entre trois présentations des mêmes
cartes, filtres compris. Le choix est mémorisé d'une visite à l'autre.

| Vue | Usage |
|---|---|
| **Cartes** | Grille illustrée, pour parcourir et lire - seule vue à porter la confiance des rapprochements |
| **Liste** | Tableau compact : titre, thèmes, flux, date, score, commentaires, actions |
| **Statistiques** | Camembert de répartition par technologie et par réglementation |

### Filtrage

* Onglets **Tous / Cybersécurité / Infrastructure / Technologie D3D /
  Innovation** - un onglet par catégorie active. Ajouter une catégorie dans
  **Veille → Catégories** ajoute automatiquement un onglet.
* Recherche **par mot clé** sur le titre, le résumé, le contenu et l'auteur
  (voir [Recherche par mot clé](#recherche-par-mot-clé)).
* Filtre par **flux d'origine**.
* Filtre par **technologie** : n'importe quelle catégorie ou famille
  effectivement rencontrée sur au moins une carte, ou « Autre » pour les cartes
  hors référentiel. Le filtre actif est rappelé en haut de page et se retire en
  un clic. Il se combine librement avec tous les autres.
* Commutateur **Technologies suivies** : ne garde que les cartes rattachées à
  une famille ou à une technologie suivie (voir
  [Les technologies suivies](#les-technologies-suivies)).
* Commutateur **Non lues** et commutateur **Commentées** (cartes portant au
  moins un commentaire).
* Filtre de **dates** sur la date de publication : une seule borne renseignée
  donne « après le » ou « avant le », les deux ensemble donnent une fourchette.

### Filtres enregistrés

Un filtre de veille se reconstitue plusieurs fois par jour.
Une ligne au pied de la barre de filtres suffit à les ranger :

| | |
|---|---|
| **Liste déroulante** | Les filtres enregistrés. Le résumé de ce que chacun retient apparaît en infobulle |
| **▶** | Applique le filtre choisi |
| **✕** | Supprime le filtre choisi, après confirmation nommant le filtre |
| **Nom + Enregistrer** | Range les critères réglés juste au-dessus sous ce nom |

Les filtres sont **propres à chaque utilisateur**.
Un filtre est une façon de travailler, pas un réglage d'administration : le
droit de lecture suffit à ranger sa propre vue, et deux personnes peuvent
nommer leurs filtres de la même façon.

Le filtre retient les critères de la barre, le tri et la vue.
Il ne retient ni la page courante, ni l'onglet Favoris, qui ne se
transportent pas d'un écran à l'autre.
Réenregistrer sous un nom déjà pris remplace le filtre.
Trente filtres au plus par personne.

### Tri

Un sélecteur **Trier par** propose deux classements :

| Tri | Effet |
|---|---|
| **Score d'intérêt** *(défaut)* | Les cartes les plus pertinentes en premier, à date égale les plus récentes |
| **Date de publication** | Classement chronologique, comme avant la 1.5.0 |

Le tri par défaut est celui réglé dans **Veille → Score d'intérêt**. Chaque
utilisateur peut y renoncer depuis la barre de filtres. Son choix est mémorisé
dans sa session, comme le mode d'affichage.

### Recherche par mot clé

La recherche sur le **titre, le résumé et le contenu** s'appuie sur l'**index
plein texte** de la table des cartes (`MATCH ... AGAINST` en mode booléen). Tous
les mots saisis sont exigés, et chacun est ouvert sur ses dérivés : « chiffr »
retrouve « chiffrement » comme « chiffré ».

La recherche sur l'**auteur** reste une recherche de sous-chaîne (`LIKE`) :
c'est un nom propre, souvent cherché partiellement - « Dupont » dans
« J. Dupont (ANSSI) » - et il ne fait pas partie de l'index.

> MySQL et MariaDB n'indexent pas les mots de moins de trois caractères
> (`innodb_ft_min_token_size`). Une saisie dont **tous** les mots sont plus
> courts retombe automatiquement sur l'ancienne recherche de sous-chaîne : elle
> est plus lente, mais elle trouve.

Une carte porte les catégories du flux dont elle provient. Si l'entrée déclare
elle-même des catégories reconnues par GLPI, ce sont **celles-ci qui
s'appliquent** à la place de celles du flux. Les libellés non reconnus - le cas
courant des balises `<category>` libres des flux RSS - sont ignorés et la carte
retombe sur les catégories de son flux.

### Ce que porte une carte

Outre son titre, son illustration et son extrait, chaque carte de la vue
**Cartes** affiche :

* ses **thèmes**, et l'étiquette *Non lue* le cas échéant.
* les **technologies reconnues** - les trois plus sûres, puis un compteur.
* en **pied de carte**, juste avant les actions, son **niveau de priorité** et
  son **score d'intérêt**.

Ni jauge, ni confiance chiffrée : dans l'observatoire on parcourt. **Ouvrir une
carte** donne le détail - le score décomposé en ses quatre composantes avec
leurs poids, et toutes les technologies reconnues avec la **confiance** de
chaque rapprochement, cliquables pour filtrer l'observatoire dessus.

La vue **Liste** porte une colonne **Score** réduite au pourcentage, sans jauge :
elle se parcourt à la verticale, et des chiffres alignés se comparent mieux
qu'une succession de barres. Le niveau de priorité colore le chiffre et se lit
en infobulle, avec le même détail. La **confiance des rapprochements**, elle,
reste propre aux cartes.

### Les liens s'ouvrent à côté

On parcourt une liste, on ouvre ce qui accroche, on revient.
Perdre sa place à chaque ouverture ferait recommencer le parcours : les liens
ouvrent donc un nouvel onglet.

| Lien | Où |
|---|---|
| Titre d'une carte, bouton *Détails* | Vue Cartes et vue Liste |
| Adresse de la source | Détail d'une carte |
| Liens du contenu de la carte | Détail d'une carte |
| *Demander à l'IA* | Partout |

Les liens du contenu viennent du flux : ils renvoient vers l'éditeur, le CERT
ou le bulletin d'origine.
Ils sont réécrits **côté serveur**, de sorte que la règle vaut aussi pour qui
n'a pas de JavaScript.
Les liens relatifs, les ancres internes et les adresses `mailto:` ne sont pas
touchés.

`rel="noopener noreferrer"` accompagne chaque cible : sans lui, la page
ouverte garde une prise sur celle qui l'a ouverte.

### Lu / non lu, favoris, commentaires

* **Lu / non lu** : propre à chaque utilisateur. Une carte lue par un
  utilisateur reste non lue pour les autres. L'ouverture du détail d'une carte
  la marque comme lue. Le bouton enveloppe permet de revenir en arrière.
* **Favoris** : partagés. Une carte mise en favori par un utilisateur apparaît
  dans l'onglet « Favoris » de tous les utilisateurs.
* **Commentaires** : déposer un commentaire relève du droit **Commenter**,
  distinct du droit d'écriture - un profil en lecture seule participe donc aux
  échanges sans pouvoir toucher aux cartes. Chacun reprend ou retire ce qu'il a
  écrit. **modérer** le commentaire d'un autre demande le droit d'écriture.
  L'auteur d'origine et le dernier modificateur restent affichés.
* **Demander à l'IA** : chaque carte porte un bouton qui ouvre ChatGPT dans un
  nouvel onglet avec une question déjà rédigée, demandant une réponse **sous
  forme de tableau**, en reprenant le titre, le résumé et le lien source. Les
  colonnes demandées **dépendent de la nature de la carte** (voir
  [Résumer par IA](#résumer-par-ia)). Depuis la page de détail, le bouton
  déplie une zone où l'on choisit le **niveau d'analyse** et où la question est
  **modifiable avant l'envoi**. C'est une simple redirection : rien n'est
  transmis sans action de l'utilisateur sur le site de destination.

### Flux

Deux formats sont pris en charge :

| Type | Alimentation |
|---|---|
| **Flux RSS** | URL d'un flux RSS 2.0 ou RSS 1.0 (RDF) |
| **Flux Atom** | URL d'un flux Atom |

> **Le collecteur est strictement XML.** Un document est analysé par
> `simplexml_load_string()`, puis routé sur son élément racine - `rss`, `rdf` ou
> `feed`. Toute autre racine est refusée, et un document JSON est rejeté dès
> l'analyse avec « Le document n'est pas un XML valide ». Les API JSON - NVD,
> CISA KEV, GitHub Advisories - ne sont **pas** exploitables en l'état. Voir
> [Sources JSON](#sources-json-nvd-et-consorts).

Chaque flux porte une ou plusieurs catégories, appliquées aux cartes
collectées. Le champ « Nombre maximum d'entrées par collecte » borne le volume
importé à chaque passage.

Les cartes qui ne proviennent pas d'un flux sont créées à la main avec le
bouton **Ajouter une carte** de l'observatoire. Elles sont signalées comme
« Saisie manuelle ».

Le dédoublonnage repose sur l'identifiant du flux (`guid`, `id`), à défaut sur
le lien, à défaut sur le couple titre + date : une collecte répétée met à jour
les cartes existantes au lieu de les dupliquer.

Un flux **inactif** n'est relevé ni par la tâche automatique, ni par le bouton
**Collecter maintenant**. L'activité se bascule directement dans la colonne
*Actif* de la liste. L'en-tête indique combien de flux sont actifs sur le
total, et un bandeau prévient quand aucun ne l'est.

### Catalogue livré

Le plugin embarque une sélection de **43 flux** de veille cyber, IT et
réglementaire (`install/data/sources_veille.csv`) :

| Famille | Exemples |
|---|---|
| **CERT et autorités** | CERT-FR (alertes, avis, actualités), ANSSI, CISA, NCSC UK, ENISA, NIST, CNIL |
| **Avis d'éditeurs** | Microsoft MSRC, Cisco PSIRT, Fortinet PSIRT, Ubuntu USN, Debian DSA, Red Hat, AWS, Google, Mozilla, GitHub |
| **Presse spécialisée** | SANS ISC, Krebs on Security, BleepingComputer, The Hacker News, The Record, SecurityWeek, Dark Reading, Schneier, ZATAZ, LeMagIT, Le Monde Informatique |
| **Infrastructure et cloud** | Azure, AWS, Kubernetes, Docker, The Register, Ars Technica |
| **Outils** | GLPI - actualités du projet et versions publiées |

Chaque flux porte une **description** de ce qu'il publie et de ce qu'il vaut,
affichée sous son nom dans la liste. C'est elle qui permet de choisir quoi
activer sans ouvrir quarante-trois adresses - et c'est aussi sur elle que porte
la recherche.

**Tous ces flux arrivent désactivés.** Ce qu'une organisation suit lui
appartient, et rien ne doit se mettre à collecter sans qu'on l'ait décidé :
l'administrateur active ce qui l'intéresse, ligne par ligne, depuis la colonne
*Actif*.

Le catalogue est chargé automatiquement à l'installation d'un plugin vierge -
jamais lors d'une mise à jour, pour ne pas réintroduire des flux écartés. Le
bouton **Charger le catalogue**, en bas de *Veille → Flux RSS / ATOM*, le
(re)charge à tout moment. Les flux déjà définis ne sont pas dupliqués, la
comparaison portant sur l'adresse (protocole, casse et barre oblique finale
ignorés).

> Les adresses de flux changent au fil du temps. Vérifiez la première collecte
> d'un flux que vous venez d'activer : le résultat s'affiche dans la colonne
> *Dernière collecte*.

### Rechercher un flux

Au-dessus de la liste, quatre critères se combinent :

| Critère | Portée |
|---|---|
| **Mot clé** | Nom, adresse **et description**. C'est ce dernier point qui permet de retrouver « les flux qui parlent de rançongiciels » sans connaître leur nom |
| **Catégorie** | Thème de veille affecté au flux |
| **Actif** | Actifs seulement, inactifs seulement, ou les deux |
| **Cartes** | Volume déjà collecté : aucune, au moins une, au moins 10, au moins 100 |

Le dernier critère sert dans les deux sens : repérer les flux productifs, et
débusquer ceux qui n'ont jamais rien ramené - adresse périmée, flux tari ou
jamais activé.

### Supprimer des flux en masse

En bas de *Veille → Flux RSS / ATOM*, un encadré rouge reprend le modèle de
« Vider les cartes » :

| Bouton | Effet |
|---|---|
| **Compter** | N'écrit rien. Annonce le nombre de flux **et de cartes** que la suppression emporterait |
| **Supprimer les flux trouvés** | Supprime ce que la recherche en cours retient. Inactif tant qu'aucun critère n'est posé |
| **Tout supprimer** | Supprime tous les flux, **en ignorant la recherche en cours** |

> Supprimer un flux supprime **toutes ses cartes**, avec leurs commentaires,
> favoris et marquages de lecture. D'où le décompte en deux nombres : on ne
> supprime jamais seulement des flux.

Les cartes sont retirées de façon **ensembliste** : six requêtes pour tout le
lot, et non six par carte. C'est admissible parce qu'une carte n'a pas
d'historique et que son seul effet de bord à la suppression est justement le
nettoyage de ces tables - mais c'est indispensable, sans quoi supprimer un
catalogue entier dépasserait n'importe quelle limite de temps.

Les critères de la recherche sont renvoyés en champs cachés : l'action porte
exactement sur ce que le tableau affichait. *Tout supprimer* est une action
distincte, pas un raccourci de la précédente - et le reste quand aucun filtre
n'est posé.

### Importer une liste de flux

Le même écran accepte un fichier CSV encodé en UTF-8, séparateur
point-virgule :

```
Nom;URL;Type;Thèmes;Intérêt;Actif;Description
CERT-FR - Avis;https://www.cert.ssi.gouv.fr/avis/feed/;rss;cybersecurite;95;0;Vulnérabilités publiées, versions touchées et correctifs.
Blog interne;https://exemple.test/feed;;technologie-d3d;;oui;Publications de l'équipe infrastructure.
```

| Colonne | Obligatoire | Valeur |
|---|---|---|
| **Nom** | non | À défaut, le domaine du flux |
| **URL** | **oui** | Adresse `http(s)` du flux |
| **Type** | non | `rss` ou `atom` ; à défaut, déduit de l'adresse. Simple étiquette d'affichage - l'analyseur reconnaît seul le format réellement servi |
| **Thèmes** | non | Codes ou noms de catégories de veille, séparés par une barre verticale. Un thème inconnu est signalé, la ligne reste importée |
| **Intérêt** | non | 0 à 100 ; à défaut, la note par défaut des réglages |
| **Actif** | non | `1`, `oui`, `vrai`... pour activer. **Vide vaut désactivé** |
| **Description** | non | Ce que le flux publie. Enregistrée dans le champ *Commentaires* du flux, affichée sous son nom et cherchée par le mot clé |

La première ligne peut être un en-tête (`Nom;...`). Les flux déjà connus sont
ignorés : réimporter le même fichier n'ajoute rien. Taille maximale : 1 Mio.

### Quand un flux ne se collecte pas

Le bouton **stéthoscope** de chaque ligne sépare ce que le message « délai
dépassé » confond :

| Étape | Ce qu'elle dit |
|---|---|
| **Adresse** | Elle est en http(s) et passe la liste blanche de GLPI |
| **Résolution du nom** | Le nom est résolu, **en combien de temps**, et **vers quelle adresse** |
| **Mandataire** | GLPI passe par un proxy : les échecs viendront de lui d'abord |
| **Récupération** | Octets reçus, ou échec avec sa durée |
| **Redirections** | Les sauts suivis, quand il y en a |
| **Format** | Le document est un XML exploitable |

> **Le cas le plus déroutant est le DNS.** GLPI n'accorde que cinq secondes à
> l'établissement d'une connexion, **résolution comprise**. Un serveur de noms
> qui ne répond plus coûte cinq secondes d'attente avant bascule sur le
> suivant : le budget est épuisé avant qu'une connexion soit tentée, et *tous*
> les flux échouent - alors que les adresses s'ouvrent sans peine depuis un
> navigateur, lequel n'a pas cette contrainte.
>
> Le plugin résout donc les noms lui-même, avant l'appel, ce qui sort cette
> attente du budget. La collecte fonctionne alors même avec un résolveur
> lent, simplement plus lentement. Le vrai remède reste de retirer le serveur
> de noms défaillant de `/etc/resolv.conf`, ou d'y ajouter
> `options timeout:1 attempts:1`.

### Collecte automatique

Une tâche GLPI **`CollectFeeds`** (itemtype `GlpiPlugin\Veilletool\Source`)
collecte tous les flux actifs, par défaut toutes les heures. Sa fréquence et
son mode d'exécution se règlent dans **Configuration → Actions automatiques**.

L'onglet **Flux RSS / ATOM** affiche l'état réel de cette tâche - périodicité
telle qu'elle est configurée, mode d'exécution, dernière exécution - et un
bouton **Paramètres de la tâche** y mène directement. Le bandeau passe en
avertissement si la tâche est désactivée ou introuvable.

Elle est enregistrée en mode **GLPI** (interne) : GLPI la déclenche au fil de la
navigation, sans configuration serveur. Si vous disposez d'un cron système
(`/etc/cron.d`, timer systemd, `php bin/console glpi:cron`), basculez-la en mode
**CLI** depuis le même écran - c'est plus régulier, et cela n'impose plus qu'un
utilisateur consulte GLPI pour que la collecte ait lieu.

> Le mode **CLI** suppose qu'un cron système appelle effectivement GLPI. Sans
> cela, la tâche reste indéfiniment « en attente » sans jamais s'exécuter :
> c'est le symptôme d'une tâche en mode CLI sur une installation qui n'a pas de
> cron système.

Le bouton **Collecter maintenant** de l'onglet « Flux RSS / ATOM » déclenche la
même collecte immédiatement. L'icône de rafraîchissement d'une ligne ne
collecte que le flux concerné.

#### Les deux tâches automatiques

| Tâche | Itemtype | Rôle |
|---|---|---|
| `CollectFeeds` | `GlpiPlugin\Veilletool\Source` | Collecte tous les flux actifs |
| `ReindexTechnologies` | `GlpiPlugin\Veilletool\Article_Technology` | Vide par lots la file de reclassement technologique |

#### Une transaction par entrée

Enregistrer une carte, ce n'est pas une écriture mais plusieurs : la carte,
ses thèmes, ses technologies et les composantes de son score.

Chaque entrée d'un flux est donc validée **séparément**. Une carte enregistrée
est ainsi une carte **complète** : si l'une de ses relations n'a pas pu être
construite, rien n'est conservé de cette entrée - et les autres entrées du flux
passent quand même. Une collecte peut ainsi rapporter dix-neuf cartes sur vingt
et signaler précisément celle qui a échoué.

> Les URL sont soumises à la liste blanche de sécurité de GLPI
> (`GLPI_SERVERSIDE_URL_ALLOWLIST`), aux garde-fous décrits dans
> [Ce que le serveur accepte d'aller chercher](#ce-que-le-serveur-accepte-daller-chercher),
> et passent par le proxy configuré dans GLPI le cas échéant.

---

## Score d'intérêt et priorité

Chaque carte reçoit une note de **0 à 100**, affichée sur la carte avec le
niveau de priorité qui en découle. C'est elle qui, par défaut, classe
l'observatoire.

### Les quatre composantes

| Composante | Ce qu'elle vaut | Où elle se règle |
|---|---|---|
| **Source** | Note d'intérêt du flux d'origine | Sur chaque flux (*Veille → Flux RSS / ATOM*) |
| **Technologie** | Confiance du meilleur rapprochement technologique de la carte | Automatique (voir [La confiance](#la-confiance-des-rapprochements)) |
| **Catégorie** | Meilleure note d'intérêt parmi les thèmes de la carte | Sur chaque thème (*Veille → Catégories*) |
| **Fraîcheur** | 100 pendant un délai de grâce, puis un nombre de points perdus par jour | *Veille → Score d'intérêt* |

Une carte saisie à la main, ou issue d'un flux dont la note n'a pas été réglée,
reçoit la **note neutre** définie dans l'écran de réglage (50 par défaut).

#### La fraîcheur

Deux réglages, énoncés comme on les pense : **au bout de combien de jours une
carte commence à perdre des points**, et **combien elle en perd par jour**.

Avec les valeurs livrées - 7 jours de grâce, 5 points par jour - une carte vaut
100 pendant une semaine, 95 le huitième jour, 35 au vingtième, et 0 à partir du
vingt-septième. L'écran affiche ce point d'extinction, recalculé à chaque
changement : c'est lui qui dit ce qu'on vient de régler.

Mettre **0 point par jour** neutralise la fraîcheur sans avoir à toucher à son
poids : la carte garde 100 indéfiniment.

Une carte sans date de publication, ou dont la date est illisible, vaut 0.
une date future est traitée comme le jour même.

### Le barème

Le score est la **moyenne des quatre composantes pondérée par leurs poids**.
Seule la **proportion** des poids compte : quelles que soient les valeurs
retenues, la note est toujours ramenée sur **100**. L'écran de réglage affiche
d'ailleurs la part réelle de chaque composante, recalculée à chaque mouvement de
curseur - c'est elle qui parle, pas le poids brut.

Mettre les quatre poids à zéro rétablit le barème équilibré livré par défaut :
un score indéfini n'aurait aucun sens.

### Les niveaux de priorité

| Libellé livré | Plage par défaut | Fond livré | Texte livré | Contraste |
|---|---|---|---|---|
| **Prioritaire** | 80 - 100 | `#d63939` | `#ffffff` | 4,66:1 |
| **Important** | 60 - 79 | `#f76707` | `#000000` | 6,90:1 |
| **À lire** | 50 - 59 | `#f59f00` | `#000000` | 9,84:1 |
| **Information** | 20 - 49 | `#4299e1` | `#000000` | 6,88:1 |
| **Faible intérêt** | 0 - 19 | `#667382` | `#ffffff` | 4,84:1 |

Les cinq libellés sont **renommables**, les seuils et les couleurs réglables.

Les quatre seuils sont réglables. Ils sont automatiquement remis en ordre
décroissant à l'enregistrement : un seuil « Important » supérieur au seuil
« Prioritaire » rendrait un niveau inatteignable.

#### Le nom et la couleur des étiquettes

Le **libellé**, le **fond** et le **texte** de chaque niveau se choisissent
librement, sur la même ligne que son seuil. L'étiquette d'aperçu suit les trois
en direct, sans recharger la page.

Un **libellé laissé vide** rend celui livré - affiché en filigrane dans le
champ. C'est le réglage par défaut, et il a un avantage : le libellé livré suit
la langue de l'utilisateur, alors qu'un libellé saisi s'applique tel quel à
tout le monde. Renommer « Prioritaire » en « Intéressant » revient donc à
figer ce mot pour toutes les langues - c'est voulu, mais c'est à savoir.

À droite du libellé, le **rapport de contraste** obtenu est affiché et
recalculé à chaque mouvement. Il est **indicatif** : en dessous de 4,5:1 - le
seuil de lisibilité recommandé par la WCAG pour du texte courant -
l'indicateur passe en avertissement, mais l'enregistrement n'est jamais
refusé. Le choix reste celui de l'exploitant.

Le calcul du contraste est repris à l'identique en PHP et en JavaScript, pour
que l'aperçu annonce exactement ce que l'enregistrement produira.

En **vue liste**, la couleur du niveau est portée par une pastille et non par
le chiffre : un fond clair, parfaitement lisible sur une étiquette, rendrait le
pourcentage illisible sur la ligne du tableau.

### Préréglages du barème

Le barème n'a pas une bonne réponse mais plusieurs, selon ce qu'on cherche.
Chaque position se range sous un **titre** en bas de *Veille → Score
d'intérêt*, et se rappelle d'un clic.

| Action | Effet |
|---|---|
| **Appliquer** | Pose ce barème comme réglage en vigueur |
| **Remplacer** | Écrase le préréglage par le barème actuellement réglé |
| **Supprimer** | Retire le préréglage ; le barème en vigueur ne change pas |

Un préréglage ne s'applique jamais tout seul : c'est une copie qu'on
rappelle, pas un mode dans lequel on entre. Celui qui correspond exactement
au barème en vigueur porte la mention *en vigueur*.

Le préréglage fige les poids, la fraîcheur, les seuils, les couleurs et les
libellés - mais pas ce qui n'a pas encore été enregistré : réglez et
enregistrez d'abord, puis capturez. Vingt préréglages au plus.

> Un préréglage enregistré par une version antérieure reste applicable après
> une mise à jour qui ajoute un réglage : les clés manquantes reprennent leur
> valeur livrée.

### Ce qui est stocké, ce qui est calculé

Les trois premières composantes ne bougent qu'à l'enregistrement de la carte :
elles sont **calculées une fois et stockées** dans les colonnes `score_source`,
`score_technology` et `score_category`.

La **fraîcheur**, elle, change tous les jours. Elle est recalculée à la volée -
en base pour le tri, en PHP pour l'affichage. C'est ce qui évite un recalcul
nocturne de l'ensemble des cartes, et ce qui fait que **modifier un poids ou un
seuil prend effet immédiatement**, sans reclassement.

Seule la modification de la **note neutre** replace les cartes dans la file de
reclassement, puisqu'elle change une composante stockée.

> Le tri par score s'appuie sur une expression SQL, non sur un index : sur une
> base de plusieurs dizaines de milliers de cartes, le tri chronologique reste
> plus rapide.

### Sources JSON (NVD et consorts)

La NVD ne publie plus que du **JSON** : les anciens flux RSS et les fichiers
`nvdcve-1.1-*.json.gz` ont été retirés au profit de l'**API 2.0**
(`services.nvd.nist.gov/rest/json/cves/2.0`). Elle n'est donc pas collectable
par le plugin aujourd'hui. Quatre obstacles, du plus dirimant au plus mineur :

| Obstacle | Détail |
|---|---|
| **Format** | `Collector::parseXml()` rejette le JSON ; `importContent()` n'accepte que les racines `rss`, `rdf` et `feed` |
| **Type de source** | `Source::getTypes()` n'expose que `rss` et `atom`, et la saisie d'un autre type est refusée |
| **Pagination** | L'API 2.0 pagine par `startIndex` / `resultsPerPage` ; le collecteur lit une réponse, une seule fois |
| **Transport** | `Toolbox::callCurl()` ne pose ni `CURLOPT_ENCODING` (donc pas de `.gz`), ni en-tête personnalisé (donc pas de clé d'API NVD), et désactive le suivi de redirection |

La liste blanche de GLPI (`GLPI_SERVERSIDE_URL_ALLOWLIST`) n'est **pas** en
cause : son motif par défaut accepte tout domaine public en http(s).

En attendant, la veille CVE passe par des sources qui publient bien en
RSS / Atom - le CERT-FR, par exemple, diffuse ses avis et alertes en RSS.

---

## Référentiel technologique et statistiques

### Le référentiel

L'onglet **Veille → Technologies** gère un référentiel à trois niveaux
- **Catégorie / Famille / Sous-catégorie** - livré pré-rempli avec
2 056 entrées réparties en 7 catégories (Application, Protocole, OS,
Réglementation, BDD, Hyperviseur, Noyaux) et 93 familles.

Ce référentiel est **indépendant des thèmes** portés par les cartes : les
thèmes servent au classement éditorial, le référentiel au rapprochement
automatique.

L'écran permet de filtrer - par **catégorie**, **famille**, **mot clé** et
**état de suivi** (*Toutes / Suivies uniquement / Non suivies*) -, d'ajouter une
entrée à la main, de supprimer une sélection, et d'importer un CSV (UTF-8, séparateur point-virgule, colonnes
`Catégorie;Famille;Sous-catégorie`, en-tête facultatif). L'import ajoute les
entrées inconnues sans toucher aux existantes, sauf si l'on coche
« Remplacer le référentiel existant ».

Le référentiel livré n'est chargé qu'à la première installation : une mise à
jour du plugin ne réécrit jamais vos ajustements.

### Les technologies suivies

Le référentiel général **qualifie** : il dit de quoi parle une carte. Le
référentiel des **technologies suivies** dit lesquelles de ces qualifications
nous intéressent.

Le suivi porte sur **deux niveaux, et deux seulement** :

| Niveau | Exemple | Effet |
|---|---|---|
| **Famille** | *Linux - Debian*, *Normes ISO / IEC* | Retient toute carte rattachée à la famille, quelle que soit la finesse du rattachement |
| **Technologie** | *GLPI 11*, *ISO/IEC 27001:2022* | Retient les seules cartes rattachées à cette entrée |

La **catégorie** n'est pas suivable. *Application* ou *OS* regroupent des
centaines d'entrées sans rapport entre elles : les suivre revenait à ne rien
filtrer du tout.

Le réglage se fait dans le même écran que le référentiel qu'il sélectionne,
**Veille → Technologies** :

* le panneau **Familles**, en haut, porte un bouton *Suivre / Suivie* par
  famille. Il suit les mêmes filtres que le tableau, donc chercher « ISO »
  ramène les familles correspondantes autant que les entrées.
* le **tableau des technologies** porte le même bouton sur chaque ligne. Une
  entrée déjà couverte par le suivi de sa famille l'indique - *via la famille* -
  au lieu de proposer un bouton qui n'ajouterait rien.

Le filtre **Suivi** de la barre de recherche restreint les deux à ce qui est
suivi, ou à ce qui ne l'est pas. *Suivies uniquement* retient une entrée qu'elle
le soit pour elle-même ou par sa famille : c'est exactement le périmètre que
retient l'observatoire, donc celui qu'on veut pouvoir vérifier.

L'observatoire porte alors un commutateur **Technologies suivies** qui ne
retient que les cartes concernées. Son intérêt : trier **indépendamment de la
finesse de la qualification**. Qu'une carte ait été rattachée à *Debian 13
(Trixie)* avec une confiance de 100 ou à la famille *Linux - Debian* avec une
confiance de 60, si la famille est suivie, elle est retenue.

Supprimer une entrée du référentiel retire le suivi qui la visait. Remplacer
le référentiel par un import purge les suivis devenus sans objet. Le suivi
d'une famille, lui, survit tant que la famille existe.

> Tant que rien n'est suivi, le commutateur ne ramène **rien** - et le dit.
> C'est préférable à un filtre silencieusement ignoré, qui laisserait croire
> que tout est suivi.

### Vider le référentiel

Le même encadré existe en bas de *Veille → Technologies*, avec les filtres de
l'écran (catégorie, famille, mot clé, suivi) pour périmètre :

| Bouton | Effet |
|---|---|
| **Compter** | Annonce le nombre d'entrées que la suppression emporterait |
| **Supprimer les entrées filtrées** | Supprime ce que les filtres retiennent |
| **Tout supprimer** | Vide le référentiel, **en ignorant les filtres** |

Ce que la suppression entraîne :

* les **suivis** qui ne visent plus rien sont retirés.
* les **rattachements ajoutés à la main** devenus sans objet aussi - les
  rattachements automatiques, eux, se refont d'eux-mêmes.
* toutes les cartes repassent dans la **file de reclassement**.

La suppression se fait par lots de 500 en une requête chacun, et les
nettoyages une seule fois à la fin : un référentiel complet compte plus de
deux mille entrées, et les traiter une par une aurait multiplié les mêmes
opérations d'autant. Le référentiel livré peut ensuite être réimporté depuis
`install/data/technologies_IT.csv`.

### Le rapprochement des cartes

Le rattachement d'une carte aux technologies qu'elle évoque est **calculé une
fois puis enregistré en base**, dans une table dédiée dont les colonnes
reprennent les trois niveaux du référentiel. Le filtrage et les statistiques
sont donc de simples requêtes SQL : ils portent sur l'intégralité des cartes,
sans plafond ni traitement en mémoire.

Le recalcul est déclenché :

* **immédiatement**, à l'enregistrement d'une carte, si son titre, son résumé ou
  son contenu ont changé.
* **immédiatement**, à la demande, par le bouton **Reclasser maintenant** de
  l'onglet Technologies.
* **en différé** à toute modification du référentiel - ajout, suppression,
  import CSV - et à l'installation ou la mise à jour du plugin.

#### Le reclassement différé

Reclasser plusieurs milliers de cartes prend des dizaines de secondes. Le faire
pendant l'installation, ou à chaque ligne ajoutée au référentiel, immobilisait
l'écran pour un travail dont personne n'attend le résultat sur-le-champ.

Depuis la 1.5.0, ces opérations se contentent de **placer les cartes dans une
file** - une seule requête, quelle que soit la volumétrie. L'action automatique
**`ReindexTechnologies`** (itemtype `GlpiPlugin\Veilletool\Article_Technology`)
la vide ensuite **par lots**, 500 cartes par passage par défaut, réglable dans
le champ *Paramètre* de la tâche.

Chaque carte est traitée dans sa **propre transaction** : une carte dont le
reclassement échoue reste dans la file et n'emporte pas le lot.

L'onglet Technologies affiche l'état de cette tâche et le nombre de cartes en
attente.

Une carte est rattachée à une entrée lorsque son texte - titre, résumé,
contenu - évoque le libellé de celle-ci. Le rapprochement est volontairement
tolérant.

Quand le libellé exact et la racine correspondent tous deux, le plus précis
l'emporte : « Debian 13.0.1 » ne compte pas aussi pour Debian 8 à 12.

### La confiance des rapprochements

Toutes les correspondances ne se valent pas. « Cette carte parle de Debian 13 »
et « cette carte prononce le mot Debian » sont deux affirmations très
différentes - et jusqu'à la 1.5.0, rien ne les distinguait.

Chaque rattachement porte désormais un **score de confiance**, déterminé par ce
sur quoi il repose :

| Confiance | Règle | Exemple |
|---:|---|---|
| **100** | **Libellé exact** - le texte cite l'entrée mot pour mot | « HTTP/2 » → sous-catégorie *HTTP/2* |
| **90** | **Version tronquée** - le texte est plus précis que l'entrée | « Debian 13.0.1 » → *Debian 13 (Trixie)* |
| **85** | **Alias** - le texte cite le nom entre parenthèses | « Trixie » → *Debian 13 (Trixie)* |
| **60** | **Famille** - le texte cite la famille, sans désigner d'entrée | « Linux kernel » → famille *Linux kernel* |
| **50** | **Racine du produit** - le texte cite le produit sans sa version | « GLPI » → famille du produit, sans trancher entre ses versions |

Une même entrée peut être atteinte par plusieurs chemins : c'est le **plus sûr**
qui est retenu.

La confiance est **affichée sur le détail d'une carte**, et là seulement :
l'observatoire, la vue liste et la vue statistique n'en font pas état. C'est
une information qu'on consulte quand on s'arrête sur une carte, pas quand on
parcourt une grille. Elle sert aussi de composante
**technologie** du score d'intérêt - une carte dont le rattachement est certain
vaut mieux qu'une carte rattachée sur une simple racine de produit.

> Les rattachements créés avant la 1.5.0 n'ont pas de confiance. La migration
> place toutes les cartes dans la file de reclassement : la confiance est
> établie au fil des passages de la tâche automatique.

### Rattacher une carte à la main

Le rapprochement ne voit que ce qui est écrit : une carte peut parler d'un
produit sans jamais le nommer, ou le désigner par un terme absent du
référentiel. Le détail d'une carte porte donc, pour qui dispose du droit
d'écriture sur les flux, un **sélecteur du référentiel** et un bouton
*Rattacher*.

* La **famille** et la **catégorie** ne se saisissent pas : elles viennent de
  l'entrée choisie, qui les porte déjà.
* La **confiance vaut 100** - c'est une lecture humaine, pas une correspondance
  de texte. Elle alimente la composante technologie du score comme n'importe
  quel autre rattachement.
* Le rattachement **survit au reclassement automatique** : c'est une décision,
  pas une déduction. Les étiquettes concernées portent une main, et se
  retirent d'un clic.
* Si l'entrée choisie quitte le référentiel, le rattachement disparaît avec
  elle.

Rattacher une technologie déjà reconnue automatiquement **promeut** le
rattachement existant : sa confiance passe à 100 et il devient durable.

### Le camembert

La vue **Statistiques** découpe les cartes filtrées selon trois niveaux, au
choix - le sélecteur est au centre, au-dessus du graphique, parce que c'est lui
qui commande tout l'écran :

| Niveau | Parts | Ce qu'on y voit |
|---|---|---|
| **Catégorie** | 7 | La répartition d'ensemble : OS, Application, Réglementation... |
| **Famille** | 93 | Le grain de travail : *Linux - Debian*, *Normes ISO / IEC* |
| **Technologie** | des milliers | Le détail : *Debian 13 (Trixie)*, *ISO/IEC 27001:2022* |

Au niveau **Technologie**, une carte rattachée seulement à une famille - sans
que sa version ait pu être déterminée - relève d'« Autre » : elle ne désigne
aucune technologie précise, et la compter ailleurs fausserait les parts.

Le tableau des vingt technologies les plus citées reste affiché sous le
graphique, quel que soit le niveau.

La légende est placée **sous le graphique**, sur une ligne défilante munie de
flèches gauche / droite : elle ne recouvre jamais le camembert, même avec
quatre-vingt-treize familles.

**Cliquer sur une part** - ou sur une ligne du tableau - bascule en vue cartes
avec le filtre technologique correspondant. Les autres filtres sont **remis à
zéro** : on repart d'une sélection propre, quitte à la resserrer ensuite.

* Une carte relevant de **plusieurs catégories** est comptée **dans chacune** :
  le total des parts peut donc dépasser 100 %.
* Une carte ne correspondant à **aucune** entrée du référentiel est comptée
  dans **« Autre »**.
* L'analyse porte sur **l'intégralité** des cartes filtrées : les chiffres sont
  obtenus par agrégation SQL sur la table de classification.

Le graphique s'appuie sur ECharts, la bibliothèque déjà fournie par GLPI :
aucune ressource externe n'est chargée.

---

## Résumer par IA

Le bouton **Résumer par IA** de l'observatoire mène à un écran de sélection en
trois temps :

La demande figure **en haut de page** : niveau d'analyse, bouton
**Demander à l'IA**, téléchargement du fichier Markdown, et la question
elle-même, modifiable. La sélection des cartes se règle en dessous.

1. **La demande** - on choisit le niveau d'analyse. La question se réécrit
   aussitôt, sans recharger la page. Elle reste modifiable, le lien se met à
   jour à la volée, et un bouton rétablit la version par défaut.
2. **Sélectionner les cartes** - thèmes et flux se cochent **d'un simple clic**
   dans deux listes, sans combinaison de touches. S'y ajoutent une fourchette de
   dates et deux commutateurs, favoris et non lues. Arriver depuis un onglet de
   l'observatoire pré-coche le thème correspondant.
3. **Affiner le lot** - la liste des cartes retenues s'affiche avec une case par
   carte, pour écarter au cas par cas ce qui n'a pas sa place dans la synthèse.

### Niveaux d'analyse

Le niveau retenu change la consigne, le découpage attendu et le volume de
contexte transmis. Dans tous les cas, la réponse est demandée **sous forme de
tableau**, dont les colonnes sont énoncées explicitement.

| Niveau | Tableau demandé pour un lot |
|---|---|
| **Résumé synthétique** | Sujet / Nature / Ce qu'il faut retenir / Niveau d'attention |
| **Résumé** *(défaut)* | Sujet / Nature / Ce que ça change / Ce qu'il faut faire |
| **Explication détaillée** | Sujet / Nature / Ce qu'il faut en comprendre / Qui est concerné / Ce qu'il faut faire |
| **Analyse des risques** | Risque / Information à l'origine / Gravité / Probabilité / Mesure |

Un lot mélange les natures : d'où la colonne **Nature**, et la consigne de
laisser « sans objet » là où la question ne se pose pas, plutôt que d'imposer
un risque à une réglementation.

### L'angle, pour une carte isolée

Demander la cause, le risque et le correctif d'une réglementation ou d'une
nouvelle fonctionnalité ne produit qu'une colonne vide - ou pire, une
invention. Pour une carte isolée, les colonnes sont donc choisies selon ce dont
la carte parle :

| Angle | Colonnes demandées (niveau *Résumé*) |
|---|---|
| **Sécurité** - faille, incident, correctif | Cause / Risques / Correctifs |
| **Réglementation** - texte, norme, obligation | Ce qui change / Qui est concerné / Échéance / Ce qu'il faut faire |
| **Nouveauté** - version, fonctionnalité, annonce | Ce qui est nouveau / Ce que ça apporte / Qui est concerné / Ce qu'il faut faire |
| **Neutre** - rien ne tranche | De quoi il s'agit / Ce que ça change / Ce qu'il faut faire |

L'angle est déduit dans cet ordre :

1. les **rattachements technologiques** de la carte - la catégorie
   « Réglementation » du référentiel tranche, parce qu'elle vient d'une entrée
   du référentiel et non d'un mot aperçu dans un titre.
2. les **mots du titre et du résumé**, comptés par angle. Le plus représenté
   l'emporte.
3. à égalité de zéro, la lecture reste **neutre** - mieux vaut ne rien
   présumer que présumer un risque.

Le niveau *Analyse des risques* échappe à cette adaptation : l'utilisateur l'a
choisi explicitement. La consigne lui demande simplement de le dire plutôt que
d'inventer, si l'information ne porte aucun risque.

### Lisibilité

Toutes les questions, carte isolée comme lot, portent la même exigence : une
idée par cellule, une quinzaine de mots au plus, pas de phrase rédigée ni de
redite d'une colonne à l'autre, aucune colonne ajoutée d'initiative, et
« non précisé » plutôt qu'une invention. Le tableau doit dire de quoi il s'agit
**du premier regard**.

### Le fichier `resume.md`

Le bouton **Télécharger resume.md** produit un document Markdown autonome,
destiné à être remis tel quel à une IA : il contient la consigne, puis une
section par carte avec ses thèmes, son flux, sa date, son auteur, sa source et
son texte.

Son intérêt : **il n'est pas borné par la longueur d'une adresse web**. Là où la
redirection doit écarter des cartes au-delà d'environ 7 500 caractères, le
fichier les contient toutes, avec un contexte nettement plus large - jusqu'à
4 000 caractères par carte contre 220 dans le lien. C'est la voie à privilégier
pour un lot important.

Les caractères qui structureraient le Markdown à tort - barres verticales,
dièses en début de ligne - sont neutralisés, de sorte qu'aucun contenu de carte
ne puisse casser la mise en forme du document.

Comme pour le bouton d'une carte isolée, il s'agit d'une simple redirection :
rien n'est transmis sans action de l'utilisateur sur le site de destination.

La sélection est bornée à 200 cartes, et l'écran signale les cartes écartées
lorsque la requête dépasse la longueur maximale admise par une adresse web.

---

## Vider les cartes

L'observatoire propose un bouton **Vider les cartes** (droit de suppression
requis) menant à un écran de purge par lot. Les critères se combinent :

* **thèmes** - rien de coché = tous, y compris les cartes sans thème.
* **flux d'origine** - rien de coché = tous, y compris la saisie manuelle.
* **référentiel technologique** - trois listes, *catégories*, *familles* et
  *technologies*.
* **publiées avant le** - vide = aucune limite de date.
* **cartes en favori** - *Préserver les favoris* (par défaut), *Supprimer aussi
  les favoris*, ou *Supprimer uniquement les favoris*.

Chaque liste se coche **d'un simple clic**, et se décoche du suivant : pas de
combinaison de touches à connaître, et aucune sélection perdue au premier faux
mouvement. Le nombre d'entrées retenues s'affiche à côté du libellé, et une
entrée cochée se distingue à l'œil.

Les **trois niveaux technologiques se combinent entre eux par « ou »** : cocher
la catégorie *OS* et la technologie *GLPI 11* vise les cartes qui relèvent de
l'une ou de l'autre. Seules les valeurs effectivement portées par au moins une
carte sont proposées - purger sur une technologie qu'aucune carte ne porte ne
viderait rien, et les deux mille entrées du référentiel rendraient la liste
impraticable. Au-delà de huit entrées, un champ de filtre permet de retrouver
la bonne sans dérouler. Une entrée déjà cochée y reste toujours visible.

Avec le réglage par défaut, une carte mise en favori n'est **jamais** supprimée,
même si elle correspond aux autres critères. Laisser tous les critères vides
vise l'ensemble des cartes.

Le bouton **Compter les cartes concernées** affiche le nombre de cartes visées
avant toute suppression, qui est définitive.

---

## Thèmes de veille

Les thèmes se gèrent dans **Veille → Catégories**. Chacun porte un nom, une
description, les **deux couleurs de son étiquette** - fond et texte, réglées
ensemble avec leur aperçu et leur rapport de contraste, exactement comme les
niveaux de priorité - et une **icône choisie dans une liste déroulante**
de 275 pictogrammes Tabler répartis en dix familles (cybersécurité, réseau,
infrastructure, cloud, développement, données et IA, réglementation,
innovation, entreprise, général).

L'écran ne passe pas par le moteur de recherche de GLPI : un thème se juge à
son apparence et se range par rapport aux autres, ce qu'un tableau de colonnes
ne montre ni l'un ni l'autre. La liste affiche donc l'étiquette telle qu'elle
paraîtra, la description, la note d'intérêt, le nombre de cartes portant le
thème et le nombre de flux qui l'appliquent, avec une bascule d'activité
directement dans la liste.

### L'ordre se règle à la souris

L'ordre du tableau est celui des onglets de l'observatoire. Chaque ligne porte
une **poignée** : on la saisit, on la déplace, et un bouton *Enregistrer
l'ordre* apparaît - seulement après un déplacement réel. Deux chevrons par
ligne font la même chose, une place à la fois, pour qui n'a ni souris ni
JavaScript.

> Le réordonnancement est **désactivé pendant une recherche** : déplacer une
> ligne dans une liste partielle voudrait dire la déplacer par rapport à des
> thèmes qu'on ne voit pas.

### Le code technique ne se saisit pas

Il est calculé à la création à partir du nom, rendu unique par un suffixe
numérique si besoin, puis **figé**. C'est lui qui désigne le thème dans les
fichiers importés - flux, cartes XML : le voir changer parce qu'on a corrigé
une faute dans le libellé casserait ces renvois sans prévenir personne.

### Rechercher, importer, exporter

Un mot clé - portant sur le nom **et la description** - et un filtre
d'activité. En bas de l'écran, l'export et l'import d'un référentiel de
thèmes :

```
Nom;Couleur de fond;Couleur du texte;Icône;Intérêt;Actif;Description
Cybersécurité;#e74c3c;#ffffff;ti ti-shield-lock;90;1;Failles, incidents et correctifs.
```

Seul le nom est obligatoire. **Le nom fait foi** : un thème déjà présent est
mis à jour plutôt que dupliqué - c'est ce qui permet de rejouer un import pour
corriger des couleurs sans repartir de zéro - et son code technique ne bouge
pas. L'ordre des lignes donne l'ordre d'affichage.

L'option *Retirer les thèmes absents du fichier* ne supprime que ce qui n'est
plus porté : un thème encore appliqué à une carte ou à un flux est conservé,
et signalé.

---

## Sauvegarde

**Veille → Sauvegarde** exporte et réimporte la configuration du plugin, bloc
par bloc, au format CSV UTF-8 :

| Bloc | Contenu |
|---|---|
| **Général** | L'ensemble des réglages : barème du score, seuils et couleurs de priorité, note par défaut, tri de l'observatoire |
| **Score d'intérêt** | Le barème en vigueur **et tous les préréglages**, un bloc chacun |
| **Flux RSS / ATOM** | Tous les flux, avec leurs thèmes, leur note, leur activité et leur description |
| **Thèmes de veille** | Les thèmes dans leur ordre d'affichage |
| **Référentiel technologique** | Le triplet Catégorie / Famille / Sous-catégorie |

Chaque bloc produit **exactement le fichier que son import sait relire** : une
sauvegarde se rejoue telle quelle, sur la même instance comme sur une autre.

Le bloc **Score d'intérêt** fait exception sur un point : un fichier de barème
pouvant en porter plusieurs - celui qui était en vigueur, et chaque
préréglage -, l'import **demande lequel appliquer** plutôt que d'en choisir un
d'office. Trois réponses : appliquer les réglages en vigueur dans le fichier,
appliquer l'un de ses préréglages, ou **enregistrer tous ses préréglages sans
toucher au barème en vigueur**. Les fichiers à deux colonnes des versions
antérieures restent lus et s'appliquent directement.
Rien n'y est propre à une installation - ni identifiant interne, ni version de
schéma -, de sorte qu'un export reste lisible et modifiable au tableur.

Pour reporter une configuration complète, importez dans cet ordre : **thèmes**
(les flux s'y rattachent), **référentiel technologique**, **flux**, puis
**général**. Un thème cité par un flux mais absent de l'instance est signalé et
ignoré : le flux est tout de même créé, sans ce thème.

> L'export ne contient **aucune carte de veille**, aucun commentaire et aucun
> favori : c'est la configuration qu'il sauvegarde, pas les données
> collectées.

L'export demande le droit de lecture sur les flux. L'import, le droit de
modification pour les réglages et de création pour les trois référentiels.

---

## Droits

Deux droits sont ajoutés, réglables dans **Administration → Profils →
onglet « Veille »** :

| Droit | Portée |
|---|---|
| `plugin_veilletool_veille` | Consulter les cartes et les thèmes, marquer lu / non lu, commenter, gérer les favoris, créer et supprimer des cartes manuelles |
| `plugin_veilletool_source` | Créer, modifier et supprimer les flux, les thèmes, le référentiel technologique, les réglages du score, et accéder à l'onglet Sauvegarde |

Le droit de veille se décompose en cases indépendantes :

| Case | Ce qu'elle ouvre |
|---|---|
| **Lecture** | Voir les cartes, les filtrer, les marquer lues ou non lues |
| **Commenter** | Déposer un commentaire, reprendre ou retirer **les siens** |
| **Mise à jour** | Modifier une carte, gérer les favoris partagés, **modérer** les commentaires des autres |
| **Création** | Créer une carte saisie manuellement |
| **Purge** | Supprimer définitivement une carte, vider les cartes |

**Commenter** se coche seul : c'est ce qui permet à un profil en lecture seule
de participer aux échanges sans pouvoir altérer la veille. Le marquage
lu / non lu, lui, ne demande que la lecture - il est propre à chaque
utilisateur et ne touche pas à la carte.

Le **rattachement manuel d'une technologie** à une carte relève du droit sur
les sources : c'est une intervention sur la classification, pas sur la carte.

Les catégories sont **lisibles** avec le droit de veille - c'est nécessaire
pour les onglets de filtrage et pour les choisir sur une carte - mais leur
**administration** requiert le droit sur les sources.

Il en va de même des **réglages du score d'intérêt** : ils pèsent sur le
classement de toutes les cartes, et relèvent donc de la configuration.

À l'installation, tous les profils reçoivent **la lecture et le commentaire**
des cartes. Les profils disposant des droits de configuration reçoivent en
plus la gestion des sources. Le profil de l'administrateur qui installe reçoit
l'ensemble.

> Lors d'une mise à jour depuis une version antérieure à la 1.6.0, le droit
> **Commenter** est ajouté à tout profil disposant déjà de la lecture : ceux
> qui commentaient par le droit d'écriture continuent de le pouvoir.

---

## Structure du plugin

```
veilletool/
├── setup.php                     Déclaration du plugin, hooks, menu « Veille »
├── hook.php                      Aiguillage installation / migration, désinstallation
├── CHANGELOG.md                  Journal des modifications
├── src/                          Classes (namespace GlpiPlugin\Veilletool)
│   ├── Menu.php                  Secteur de menu et sous-onglets
│   ├── Category.php              Catégories de filtrage (CommonDropdown)
│   ├── Source.php                Flux d'alimentation (champs, validation, collecte)
│   ├── Source_Category.php       Catégories d'un flux
│   ├── Article.php               Cartes de veille (cycle de vie)
│   ├── Article_Category.php      Catégories d'une carte
│   ├── ReadStatus.php            Marquage « lu » par utilisateur
│   ├── Favorite.php              Favoris partagés
│   ├── Comment.php               Commentaires
│   ├── Collector.php             Enchaînement récupération → lecture → import
│   ├── Feed/                     Tout ce qui touche aux flux
│   │   ├── UrlGuard.php          Ce que le serveur accepte d'aller chercher
│   │   ├── Destination.php       Verdict rendu sur une adresse
│   │   ├── FeedFetcher.php       Récupération : plafond, délais, redirections
│   │   ├── CappedStream.php      Récipient qui refuse de dépasser 10 Mio
│   │   ├── Redirection.php       Suivre ce saut, ou le refuser et pourquoi
│   │   ├── FetchResult.php       Contenu, motif d'échec, chemin parcouru
│   │   ├── FeedParser.php        RSS 2.0, RSS 1.0 (RDF) et Atom → vocabulaire unique
│   │   ├── FeedImporter.php      Dédoublonnage et écriture des cartes
│   │   ├── FeedDiagnostic.php    Le stéthoscope, étape par étape
│   │   ├── SourceCatalog.php     Catalogue livré, import et export CSV
│   │   ├── SourceFilter.php      Recherche dans la liste des flux
│   │   ├── SourcePurge.php       Suppression de flux, cartes comprises
│   │   └── SourceCron.php        Relevé automatique et budget de temps
│   ├── Cards/                    Tout ce qu'on fait d'un lot de cartes
│   │   ├── ArticleQuery.php      Filtres, tri, pagination, comptage
│   │   ├── ArticleDecorator.php  Thèmes, lecture, favoris, score, aperçu
│   │   └── ArticlePurge.php      Suppression par critères ou par flux
│   ├── Taxonomy/                 Tout ce qui touche aux thèmes
│   │   ├── CategoryCode.php      Code technique, dérivé et unique
│   │   ├── CategoryOrder.php     Rang d'affichage
│   │   ├── CategoryCsv.php       Import et export du référentiel
│   │   └── CategoryIcons.php     Bibliothèque de pictogrammes
│   ├── Technology.php            Référentiel technologique général
│   ├── FollowedTechnology.php    Référentiel des familles et technologies suivies
│   ├── TechnologyMatcher.php     Rapprochement carte ↔ technologie, et sa confiance
│   ├── Article_Technology.php    Classification d'une carte + tâche de reclassement
│   ├── Statistics.php            Répartition des cartes pour le camembert
│   ├── Score.php                 Score d'intérêt et niveaux de priorité
│   ├── ScorePreset.php           Positions de réglage nommées du barème
│   ├── FilterPreset.php          Filtres enregistrés de l'observatoire
│   ├── Settings.php              Réglages du plugin (contexte plugin:veilletool)
│   ├── CronStatus.php            État d'une tâche automatique, mis en forme
│   ├── AiPrompt.php              Rédaction des questions et du document Markdown
│   ├── Navigation.php            Retour à l'écran d'origine après un formulaire
│   ├── Csv.php                   Normalisation et échappement des échanges CSV
│   └── Profile.php               Onglet de droits sur le formulaire des profils
├── front/                        Pages (article, summary, purge, source, category,
│                                 backup,
│                                 technology, scoring + formulaires)
├── install/
│   ├── install.php               État cible du schéma et données livrées
│   ├── migration.php             Écarts entre versions
│   └── data/                     Référentiel technologique et catalogue de flux (CSV)
├── templates/                    Vues Twig (@veilletool/..., parts/ pour les fragments)
├── public/css, public/js         Feuille de style et script des cartes
└── locales/                      Modèle de traduction (les sources sont en français)
```

### Tables créées

`glpi_plugin_veilletool_categories`,
`glpi_plugin_veilletool_sources`,
`glpi_plugin_veilletool_sources_categories`,
`glpi_plugin_veilletool_articles`,
`glpi_plugin_veilletool_articles_categories`,
`glpi_plugin_veilletool_readstatuses`,
`glpi_plugin_veilletool_favorites`,
`glpi_plugin_veilletool_comments`,
`glpi_plugin_veilletool_technologies`,
`glpi_plugin_veilletool_articles_technologies`,
`glpi_plugin_veilletool_followedtechnologies`,
`glpi_plugin_veilletool_scorepresets`,
`glpi_plugin_veilletool_filterpresets`.

Les réglages du plugin ne créent **pas** de table : ils sont rangés dans
`glpi_configs`, contexte `plugin:veilletool`, et effacés à la désinstallation.

### Colonnes introduites en 1.5.0

| Table | Colonne | Rôle |
|---|---|---|
| `..._sources` | `interest` | Composante « source » du score |
| `..._categories` | `interest` | Composante « catégorie » du score |
| `..._articles` | `score_source`, `score_technology`, `score_category` | Composantes stockées du score |
| `..._articles` | `is_indexed` | File de reclassement (0 = à traiter) |
| `..._articles` | index `fulltext` | Recherche plein texte sur titre, résumé, contenu |
| `..._articles_technologies` | `confidence` | Confiance du rapprochement (50 à 100) |

### Introduit en 1.6.0

| Table | Colonne | Rôle |
|---|---|---|
| `..._articles_technologies` | `is_manual` | Rattachement décidé par un administrateur : confiance 100, préservé par le reclassement automatique |

### Introduit en 1.9.1

| Table | Index | Rôle |
|---|---|---|
| `..._articles` | `score_order` | Tri par score d'intérêt de l'observatoire. Porte les quatre **entrées** du calcul - `score_source`, `score_technology`, `score_category`, `date_published` -, et non le score lui-même, qui dépend de l'heure et ne peut donc pas être indexé. La base trie en ne lisant que cet index, sans toucher aux lignes |

L'index est **indépendant du barème** : les poids, l'horizon de fraîcheur et
les seuils de priorité vivent dans la requête, pas dans l'index.
Les régler ne demande jamais de le reconstruire.

Ses colonnes sont déclarées une seule fois, par `Score::orderColumns()`.
L'installation pose l'index à partir de cette liste, et la mise à jour la
compare à ce qui est en base : le jour où le calcul du score lira d'autres
colonnes, l'index suivra de lui-même, sans étape de migration à écrire.

### Introduit en 1.9.0

| Table | Rôle |
|---|---|
| `..._filterpresets` | Filtres enregistrés de l'observatoire, un jeu par utilisateur. La clé d'unicité porte sur le couple utilisateur / nom |

### Introduit en 1.7.1

| Table | Colonne | Rôle |
|---|---|---|
| `..._articles` | `content_hash` | Empreinte du contenu **brut**, avant purification. Permet à une collecte de reconnaître une entrée inchangée et de ne pas repurifier pour rien |

Le droit `plugin_veilletool_veille` gagne le bit **4096** - *Commenter* -, au
delà de tous ceux que GLPI se réserve (`UPDATE_OWNED` = 2048).

### Introduit en 1.5.2

| Table | Colonne | Rôle |
|---|---|---|
| `..._categories` | `text_color` | Couleur du texte de l'étiquette du thème |

### Introduit en 1.5.1

| Table | Rôle |
|---|---|
| `..._followedtechnologies` | Référentiel des technologies suivies. Ses colonnes sont celles de la table de classification : `name` vide désigne une famille entière, `name` renseigné une technologie précise. |

La colonne `..._technologies.is_followed` de la 1.5.0, qui portait le suivi par
catégorie, est supprimée.

---

## Accès à la base et sécurité

### Écritures

Toute écriture sur les objets du plugin passe par **`CommonDBTM`**
(`add()`, `update()`, `delete()`, `deleteByCriteria()`) : validation des
entrées, contrôle d'unicité, hooks `item_add` / `item_update` / `item_purge`,
historique lorsque l'itemtype le demande, et nettoyage des relations.

Le **découpage des transactions** suit l'unité de travail réelle :

| Opération | Transaction |
|---|---|
| Import CSV du référentiel | Une pour tout le fichier - deux mille validations disque successives dureraient des dizaines de secondes |
| Collecte d'un flux | **Une par entrée** - une carte enregistrée doit être une carte complète, et une entrée fautive ne doit pas emporter les autres |
| Reclassement technologique | **Une par carte** - même raison, et une carte fautive reste dans la file au lieu de bloquer le lot |

Quelques écritures directes, assumées, hors du périmètre de `CommonDBTM` :

* les **créations et suppressions de tables** à l'installation et à la
  désinstallation.
* les `UPDATE` de **reprise des données** antérieures à la 1.1.0, qui
  normalisent des colonnes en masse sur des enregistrements que le code ne
  manipule plus.
* les **composantes du score** et le drapeau `is_indexed` : données dérivées,
  pas des saisies. Passer par `update()` déclencherait un nouveau
  `post_updateItem()` - donc un nouveau calcul - et ajouterait une ligne
  d'historique à chaque reclassement.
* la mise en file de reclassement : une seule requête au lieu de plusieurs
  milliers, sur une colonne sans contrôle de saisie ni relation.

### Lectures

Les lectures utilisent `$DB->request()`, le constructeur de requêtes natif de
GLPI 11, qui produit des requêtes préparées et échappées. `CommonDBTM`
n'expose pas d'API de lecture pour des agrégats ou des jointures : `getFromDB()`
et `getFromDBByCrit()` sont employés là où ils suffisent.

**La page de l'observatoire se compose en deux temps** (`ArticleQuery::getList()`).
Le premier applique les filtres, le tri et la pagination sur les **seuls
identifiants** : la base n'a alors à lire que l'index `score_order`, jamais les
lignes. Le second charge les colonnes d'affichage - dont le début du contenu -
pour la vingtaine d'identifiants retenus, puis les remet dans l'ordre du
premier temps.

Les faire en une seule requête revenait à demander à la base de trimbaler le
contenu de **toutes** les cartes filtrées dans son tampon de tri pour n'en
garder qu'une page : le tri par score dépend de l'heure, il ne peut pas
s'arrêter avant d'avoir tout vu.

**La décoration des cartes est groupée** (`ArticleDecorator::decorate()`). Thèmes, état
de lecture, favoris, nombre de commentaires, technologies reconnues et noms de
flux sont résolus par **une requête par lot**, jamais une par carte. Une page
de cartes tient ainsi en une quinzaine de requêtes, quel que soit le nombre de
cartes en base.

### Ce que le serveur accepte d'aller chercher

Un flux est une **adresse fournie par un utilisateur, que le serveur ouvre
lui-même**. C'est la définition d'une falsification de requête côté serveur
(SSRF), et la liste blanche de GLPI n'y suffit pas : son expression régulière
accepte explicitement les adresses numériques, `127.0.0.1` et `192.168.1.1`
comprises.

Quatre garde-fous s'ajoutent, dans `Feed\UrlGuard` et `Feed\FeedFetcher`.

| Garde-fou | Règle |
|---|---|
| **Schéma** | `http` et `https` seulement. GLPI accepte aussi `feed://`, dont cURL ne sait rien faire |
| **Destination réelle** | Le nom est résolu **ici**, et refusé s'il pointe vers une plage privée, réservée, de bouclage, de lien local ou d'opérateur (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `169.254/16`, `100.64/10`, `fc00::/7`, `fe80::/10`, `::1`…) |
| **Taille** | **10 Mio**, appliqués **pendant** le transfert |
| **Redirections** | Cinq sauts au maximum, **chacun réexaminé** comme s'il était le premier |

**Sur la destination.** Une seule adresse privée dans la réponse DNS suffit à
écarter le nom : un jeu mêlant public et interne n'est pas une configuration
ordinaire, c'est la forme que prend une tentative de réattribution de nom
(*DNS rebinding*). Le résultat est ensuite **épinglé** pour l'appel
(`CURLOPT_RESOLVE`) : la machine jointe est celle qui a été examinée, et non
celle qu'une seconde résolution renverrait. Vérifier puis laisser cURL
résoudre à nouveau laissait une fenêtre entre les deux.

Cet épinglage sert aussi la collecte elle-même : la résolution sort du délai
de connexion de cinq secondes, ce qui est le remède au cas du DNS lent décrit
plus haut. Un nom qui ne se résout pas ici est désormais **refusé**, au lieu
d'être confié à cURL.

> **Derrière un mandataire, rien de tout cela ne s'applique** : ce n'est pas
> ce serveur qui résout les noms ni qui ouvre les connexions, mais le proxy.
> Le plugin le détecte et n'épingle rien. Le filtrage des destinations internes
> relève alors de la configuration du mandataire.

**Sur la taille.** Contrôler la taille d'un document *après* l'avoir reçu ne
protège de rien : le serveur distant a déjà envoyé ce qu'il voulait, et c'est
la mémoire de PHP qui en a fait les frais. Le plafond s'applique donc à deux
endroits : `CURLOPT_MAXFILESIZE` renonce avant le premier octet quand la
taille est annoncée, et un récipient plafonné (`Feed\CappedStream`)
interrompt la connexion dès le dépassement quand elle ne l'est pas. Les octets
comptés sont ceux **après décompression**, ce qui ferme la porte au document
de quelques kilo-octets qui s'épanouit en plusieurs giga-octets.

**Sur les redirections.** GLPI les suit lui-même, en rappelant sa propre
fonction sans compteur : deux adresses qui se renvoient l'une à l'autre font
une récursion sans fin. Et le seul contrôle refait à chaque saut est celui de
la liste blanche - une redirection vers `http://127.0.0.1:8080/` la traverse
sans encombre. Le plugin les suit donc lui-même :

* chaque saut repasse **l'examen complet**, résolution comprise ;
* un `Location` relatif est résolu contre l'adresse courante ;
* une boucle est détectée et nommée ;
* **une redirection de HTTPS vers HTTP est refusée.** Le chiffrement est une
  propriété du premier saut ; laisser le serveur distant décider de s'en
  passer en cours de route revient à ne l'avoir jamais exigé. L'inverse - HTTP
  vers HTTPS - est la bonne pratique, et reste accepté.

Ces refus ne sont pas silencieux : ils remontent dans le message d'erreur du
flux, et le **stéthoscope** dit lequel des quatre a joué.

### Protection CSRF

GLPI 11 vérifie le jeton CSRF de façon **centralisée**, dans
`CheckCsrfListener` accroché à `KernelEvents::CONTROLLER` : toute requête dont
la méthode n'est pas `GET`, `HEAD`, `OPTIONS` ou `TRACE` est contrôlée avant
que le script du plugin ne s'exécute. Le plugin déclare
`$PLUGIN_HOOKS['csrf_compliant']`, et ses **22 formulaires POST** portent tous
`_glpi_csrf_token`.

> **Ne pas ajouter d'appel explicite à `Session::checkCSRF()` dans les pages du
> plugin.** La validation **consomme** le jeton : un second contrôle échouerait
> systématiquement et rendrait chaque envoi impossible. C'est vérifié : une
> deuxième validation du même jeton est refusée.

Toutes les opérations d'écriture sont placées derrière un test
`isset($_POST[...])`, de sorte qu'aucune ne peut être déclenchée par une simple
requête `GET`. Seule exception, délibérée : l'ouverture d'une carte la marque
comme lue pour l'utilisateur courant - une action propre à l'utilisateur,
idempotente et réversible d'un clic.

### Contrôle des droits

Chaque page appelle `Session::checkRight()` en entrée, et les actions
sensibles revérifient le droit correspondant (`CREATE`, `UPDATE`, `PURGE`,
`Article::RIGHT_COMMENT`) avant d'écrire. La modification d'un commentaire
compare en plus son auteur à l'utilisateur courant : seul le droit d'écriture
dispense de cette comparaison.

---

### Redirections après formulaire

`Html::back()` ne dispose que de l'en-tête `Referer`. Quand le navigateur ne le
transmet pas - politique de référent restrictive, mandataire, ou divergence
entre l'adresse employée et `url_base` -, GLPI renvoie à l'accueil : l'écran de
configuration paraissait « se fermer » au moment même où l'on enregistrait.

`GlpiPlugin\Veilletool\Navigation::back($repli)` retient la page précédente
quand elle est connue, et l'écran indiqué sinon - jamais l'accueil.

L'enregistrement d'un **flux** ne s'en remet même pas au `Referer` : création
comme modification redirigent vers **Veille → Flux RSS / ATOM**. Enregistrer,
c'est avoir fini. Et la liste porte le bouton de collecte, qui est ce qu'on
veut faire juste après. L'enregistrement d'un **thème** revient de même à sa
fiche, où GLPI rouvre l'onglet actif à partir de la session.

---

## Notes techniques

* Toutes les actions passent par des formulaires HTML classiques protégés par
  le jeton CSRF de GLPI : le plugin reste utilisable sans JavaScript.
* Les flux sont analysés avec `LIBXML_NONET` et sans résolution d'entités
  externes (protection XXE), et sont limités à 5 Mio.
* Un reclassement complet **à la demande** desserre `max_execution_time` à
  300 s, comme le fait GLPI pour ses propres traitements longs. Compter environ
  une minute pour dix mille cartes. La classification d'une carte enregistrée
  coûte, elle, de l'ordre de la milliseconde. Le reclassement **différé** n'a
  pas cette contrainte : il avance par lots bornés.
* La recherche par mot clé s'appuie sur un index `FULLTEXT` InnoDB. Il est créé
  à l'installation, et ajouté par la migration sur une base existante -
  opération qui peut durer sur une très grosse table.
* Le score d'intérêt est trié par expression SQL et non par index : le tri
  chronologique reste le plus rapide sur de très gros volumes.
* Le contenu HTML issu des flux est nettoyé par `Glpi\RichText\RichText` avant
  stockage.

### Coût de traitement d'une carte

Enregistrer une carte, ce n'est pas une écriture mais plusieurs : la carte,
ses thèmes, ses technologies, son score. Le plugin s'attache à ce que rien
n'y soit fait deux fois.

| | Requêtes | Temps processeur |
|---|---:|---:|
| Carte neuve | 7 | ~3,5 ms |
| Carte inchangée, revue par une collecte | 2 | ~0 ms |

* **La purification HTML ne se refait pas sur un contenu inchangé.**
  `RichText::getSafeHtml()` coûte près de 3 ms pour un article de 6 Ko -
  davantage que tout le reste réuni. La colonne `content_hash` retient
  l'empreinte du contenu **brut**, avant purification : quand elle n'a pas
  changé, le contenu stocké est déjà ce que la purification produirait.
  Rien n'est relâché pour autant - le contenu est repurifié **à l'affichage**
  par le filtre `safe_html`, avec le nettoyeur courant de GLPI. Celle du
  stockage reste une défense en profondeur.
* **Le rapprochement technologique coûte 0,48 ms par carte** sur un
  référentiel de 2 056 entrées : les variantes de version d'un mot sont
  calculées une fois par mot et non une fois par n-gramme, et un index des
  préfixes permet d'abandonner dès le premier mot quand aucun terme connu ne
  commence là.
* **Le dédoublonnage d'une collecte tient en une requête** pour tout le flux.
* **Les composantes du score ne sont pas relues juste après avoir été
  écrites** : poser les thèmes d'une carte en donne la meilleure note, la
  classer en donne la meilleure confiance.
* **Les lignes de liaison s'écrivent directement**, sans passer par
  `CommonDBTM` : ce sont des lignes nues, sans historique ni traitement après
  enregistrement.
* **Le score n'est pas réécrit à l'identique.**

### Coût d'affichage d'une liste de cartes

Le temps de chargement de l'observatoire ne doit dépendre que de la taille de
la page, pas du nombre de cartes de la base ni du volume de chaque carte.

* Les listes **ne lisent pas la colonne `content`** en entier - c'est un
  `longtext` - mais ses premiers milliers de caractères, assez pour l'aperçu et
  pour le contexte transmis à l'IA. Le détail d'une carte, lui, la lit
  entièrement.
* L'**aperçu** est remis à plat une seule fois, côté serveur, et non à chaque
  rendu du gabarit.
* Les **noms de flux** sont résolus en une requête pour toute la page.
* Le **barème du score** - cinq niveaux de priorité, leurs couleurs et leurs
  contrastes, les quatre poids, les quatre seuils - est lu une fois par
  requête, non par carte.
* Les listes ne préparent **qu'une question à l'IA par carte**. Les quatre
  niveaux ne sont préparés que sur le détail d'une carte, seul endroit où l'on
  peut en changer.
* L'assemblage d'une **synthèse de lot** mesure la longueur de l'adresse de
  façon incrémentale : `rawurlencode()` encode octet par octet, donc la
  longueur encodée d'une concaténation est la somme des longueurs encodées.
