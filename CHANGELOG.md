# Journal des modifications

Toutes les versions notables du plugin **Outils de Veille** (`veilletool`).

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
versionnage [SemVer](https://semver.org/lang/fr/).

---

## [1.11.0] - 2026-11-20

### Ajouté

- **Contenu complet repris de la page de l'article** (#3).
  Certains flux ne publient qu'une phrase de résumé : c'est le cas du
  CERT-FR, dont la page d'un avis porte seule les risques, les systèmes
  affectés, les solutions et les liens vers les bulletins.
  Une option par flux, *Récupérer le contenu complet depuis la page de
  l'article* (désactivée par défaut), fait charger la page de chaque nouvel
  article - avec les mêmes garde-fous que le flux lui-même : adresse
  examinée, taille plafonnée, redirections revalidées une à une - et en
  reprend la zone de contenu.
  Cette zone se désigne par un sélecteur propre au flux (`article`,
  `.publication-content`, `main .corps`…) ; vide ou sans résultat, elle est
  cherchée dans `<article>` puis `<main>`.
  Au plus 15 pages par collecte d'un flux ; une page inaccessible laisse le
  résumé du flux en place et n'est pas comptée comme une erreur de collecte.
  Une carte complétée n'est plus écrasée par le résumé aux collectes
  suivantes.
- **« Recharger le contenu »** sur la fiche d'une carte importée, pour
  corriger les cartes déjà collectées avec le seul résumé.
- Format CSV des flux : deux colonnes facultatives, *Contenu complet* et
  *Sélecteur*. Un fichier d'avant la 1.11.0 se relit tel quel.

### Modifié

- **Vue liste : le bouton favori passe en tête de ligne** (#1), à la place
  de l'indicateur statique. Sans droit d'écriture, l'étoile reste un simple
  indicateur. La marque « non lue » passe devant le titre. Vue cartes : le
  favori devient la première action du pied de carte.
- **Favori et lu / non lu basculent sans recharger la page** (#2) : l'icône
  change sur place et l'on garde sa position dans le tableau. Dans l'onglet
  *Favoris*, retirer un favori masque la carte ; avec le filtre *non lues*,
  la marquer lue la masque. Sans JavaScript, les formulaires fonctionnent
  comme avant.
- Catalogue livré : les trois flux du CERT-FR suivent le déménagement du
  site (`www.cert.ssi.gouv.fr` → `cert.cyber.gouv.fr`) et ont le contenu
  complet activé.

### Migration

- Colonnes `fetch_full_content` et `content_selector` sur les flux,
  `date_content_fetched` sur les cartes.
- Les flux CERT-FR existants, reconnus à leur ancienne adresse, reçoivent la
  nouvelle et l'option de contenu complet. Les cartes déjà importées
  (dont la 2173) se corrigent avec *Recharger le contenu*, ou d'elles-mêmes
  si elles figurent encore dans le flux, à raison de 15 par collecte.

---

## [1.10.0] - 2026-09-29

### Sécurité

- **Le serveur ne va plus chercher un flux n'importe où.**
  Une adresse de flux est fournie par un utilisateur et ouverte par le
  serveur : c'est la définition d'une falsification de requête côté serveur
  (SSRF). La liste blanche de GLPI n'y suffisait pas - son expression
  régulière accepte explicitement les adresses numériques, `127.0.0.1` et
  `192.168.1.1` comprises.

  Le nom est désormais résolu avant l'appel, et **refusé s'il désigne une
  plage privée, réservée, de bouclage, de lien local ou d'opérateur**. Une
  seule adresse interne dans la réponse DNS suffit à écarter le nom : un jeu
  mêlant public et interne est la forme que prend une réattribution de nom
  (*DNS rebinding*).

  Le résultat est ensuite **épinglé** pour l'appel : la machine jointe est
  celle qui a été examinée, et non celle qu'une seconde résolution
  renverrait. Vérifier puis laisser cURL résoudre à nouveau laissait une
  fenêtre entre les deux.

  Un nom qui ne se résout pas est maintenant refusé, là où il était confié à
  cURL. Derrière un mandataire, rien de tout cela ne s'applique : ce n'est
  pas ce serveur qui résout ni qui se connecte, et le plugin le détecte.

- **Les redirections sont suivies une par une, et revalidées à chaque saut.**
  GLPI les suit en rappelant sa propre fonction *sans compteur* : deux
  adresses qui se renvoient l'une à l'autre font une récursion sans fin. Et
  le seul contrôle refait à chaque saut est celui de la liste blanche - une
  redirection vers `http://127.0.0.1:8080/` la traversait sans encombre.

  Le plugin les suit désormais lui-même : cinq sauts au maximum, chacun
  repassant l'examen complet, `Location` relatif résolu contre l'adresse
  courante, boucle détectée et nommée.

  **Une redirection de HTTPS vers HTTP est refusée.** Le chiffrement est une
  propriété du premier saut ; laisser le serveur distant décider de s'en
  passer en cours de route revient à ne l'avoir jamais exigé. L'inverse
  reste accepté.

- **Le téléchargement d'un flux est plafonné à 10 Mio, pendant le
  transfert.**
  Le plafond était de 5 Mio et s'appliquait **après coup**, sur un document
  déjà entièrement chargé en mémoire : il constatait l'excès sans l'empêcher.

  Il joue maintenant à deux endroits - `CURLOPT_MAXFILESIZE` renonce avant le
  premier octet quand la taille est annoncée, un récipient plafonné
  interrompt la connexion dès le dépassement quand elle ne l'est pas. Les
  octets comptés sont ceux **après décompression**, ce qui ferme la porte au
  document de quelques kilo-octets qui s'épanouit en plusieurs giga-octets.

### Corrigé

- **La collecte de tous les flux échouait, en tâche automatique comme au
  bouton « Collecter maintenant ».**
  Les deux appelaient `Source::batchDeadline()`, qui n'existait nulle part :
  l'appel levait une erreur fatale avant qu'un seul flux soit relevé. La
  méthode est écrite et vit désormais dans `Feed\SourceCron`.

  Elle borne le relevé par lot : passé `max_execution_time` moins une marge,
  les flux restants sont laissés au passage suivant et comptés dans le
  compte rendu, plutôt que de laisser PHP interrompre le script au milieu
  d'un flux sans que rien n'en rende compte.

### Modifié

- **Les grandes classes sont segmentées par métier.**
  Quatre classes dépassaient le millier de lignes et mélangeaient des
  préoccupations sans rapport - `Collector` faisait du réseau, de l'analyse
  XML et de l'écriture en base ; `Article` portait le moteur de filtrage, la
  mise en forme d'affichage et la purge.

  Le comportement ne change pas. Trois espaces de noms apparaissent :

  | Espace de noms | Ce qu'il porte |
  |---|---|
  | `Feed\` | Récupération, analyse, import, diagnostic, catalogue, recherche, purge et relevé automatique des flux |
  | `Cards\` | Requêtes, décoration et purge des cartes |
  | `Taxonomy\` | Code technique, ordre, échange CSV et icônes des thèmes |

  Les classes GLPI - `Article`, `Source`, `Category`, `Collector` - restent
  les points d'entrée et gardent ce qui les définit : champs, validation,
  cycle de vie. `Source::cronInfo()` et `Source::cronCollectFeeds()` restent
  en place et passent la main : GLPI appelle ses tâches par
  `<itemtype>::cron<NomDeLaTâche>()`, c'est une convention de nommage et non
  un choix de conception.

  | Fichier | Avant | Après |
  |---|---|---|
  | `Article.php` | 1384 | 483 |
  | `Category.php` | 1352 | 570 |
  | `Source.php` | 1284 | 589 |
  | `Collector.php` | 859 | 116 |

- **Le stéthoscope dit aussi vers quelle adresse un nom est résolu**, et
  liste les redirections suivies quand il y en a. Un flux refusé pour
  destination interne le dit, plutôt que d'échouer sans motif lisible.

- **La récupération passe par le client HTTP de GLPI**
  (`Toolbox::getGuzzleClient()`) plutôt que par `Toolbox::getURLContent()`.
  Le mandataire et ses identifiants restent ceux de la configuration
  générale ; ce qui change est le contrôle du transfert, que l'ancienne
  fonction n'offrait pas.

### Migration

- **Aucune.** Le schéma est inchangé : ni table, ni colonne, ni index.
  La mise à jour se limite à recopier les fichiers.

  Les droits fichiers sont à reposer après recopie - voir
  [README, Installation](README.md#installation).

---

## [1.9.1] - 2026-09-29

### Modifié

- **L'observatoire compose sa page sans lire toutes les cartes.**
  Le score d'intérêt se calcule à la volée et dépend de l'heure : aucun
  index ne peut en porter l'ordre, et la base devait donc noter chaque carte
  retenue par les filtres avant de pouvoir rendre la première page.
  Sur une base qui collecte depuis des mois, afficher quinze vignettes
  revenait à parcourir la table entière - contenu des cartes compris.

  Deux changements, qui vont ensemble :
  - un **index** sur les quatre entrées du calcul - les trois composantes
    stockées et la date de publication.
    La base fait alors son tri en ne lisant que cet index, sans toucher aux
    lignes.
  - la page se compose en **deux temps** : le tri et la pagination portent
    sur les seuls identifiants, puis le contenu n'est chargé que pour la
    vingtaine de cartes réellement affichées.
    En une seule requête, la base trimbalait le début du contenu de toutes
    les cartes filtrées dans son tampon de tri, pour n'en garder qu'une
    page.

  Rien ne change à l'écran : même page, mêmes cartes, même ordre.
  Le tri par date, lui, passait déjà par son index et n'était pas concerné.

  L'index ne dépend d'aucun réglage. Les poids, l'horizon de fraîcheur et
  les seuils de priorité se règlent librement sans jamais avoir à le
  reconstruire : c'est la requête qui porte le barème, pas l'index.

- **La pagination de l'observatoire se lit d'un coup d'œil.**
  « Page 3 / 84 » annonçait un nombre de pages qui ne sert qu'une fois - pour
  savoir si l'on est près de la fin - et qu'on relit à chaque affichage.

  Les deux vues paginées, cartes et liste, portent désormais deux flèches et
  le rang de la page : **‹ 3 ›**, centré.
  Les flèches inactives restent affichées, désactivées : sans elles, le rang
  sauterait d'un bord à l'autre entre la première page et les suivantes.

  Le nombre de cartes retenues par les filtres n'est pas perdu pour autant :
  il reste dans l'étiquette posée à côté du titre de la page, où il se lit
  mieux qu'au pied de la liste.

  Le référentiel des technologies garde sa pagination « Page x / y » : c'est
  une liste d'administration, qu'on parcourt du premier au dernier écran.

### Migration

- `glpi_plugin_veilletool_articles` : ajout de l'index `score_order`.
  Aucune donnée n'est touchée, et l'index se construit en place - le temps
  d'un `ALTER TABLE` proportionnel au nombre de cartes en base.

- L'étape **n'est gardée par aucune version**, volontairement.
  Elle lit les colonnes de l'index en place et les compare à celles
  qu'annonce le calcul du score : tant qu'elles concordent, elle ne fait
  rien.
  Le jour où le calcul lira d'autres colonnes, la mise à jour suivante
  reconstruira l'index d'elle-même, sans étape à écrire.

---

## [1.9.0] - 2026-09-29

### Ajouté

- **Des filtres enregistrés dans l'observatoire.**
  Un filtre de veille se reconstitue plusieurs fois par jour : les cartes non
  lues d'un thème, les cartes commentées du mois, les technologies suivies
  triées par score.
  Recomposer chaque fois la même combinaison de sept champs use, et on finit
  par ne plus filtrer du tout.

  Une ligne au pied de la barre de filtres suffit : une liste déroulante pour
  choisir, un bouton pour appliquer, une croix pour supprimer après
  confirmation, et un champ de nom pour ranger les critères réglés juste
  au-dessus.
  Le résumé de ce que chaque filtre retient apparaît en infobulle.

  Les filtres sont **propres à chaque utilisateur** : un filtre est une façon
  de travailler, pas un réglage d'administration.
  Le droit de lecture suffit donc à ranger sa propre vue, et deux personnes
  peuvent nommer leurs filtres de la même façon.
  Réenregistrer sous un nom déjà pris remplace le filtre au lieu d'en créer un
  second.
  Trente filtres au plus par personne.

### Modifié

- **Les liens ouvrent un nouvel onglet.**
  On parcourt une liste, on ouvre ce qui accroche, on revient : perdre sa
  place à chaque ouverture faisait recommencer le parcours.

  Trois endroits sont concernés :
  - le **titre** et le bouton *Détails* d'une carte, en vue Cartes comme en
    vue Liste.
  - l'**adresse de la source**, sur le détail d'une carte, qui l'ouvrait déjà
    ainsi.
  - les **liens du contenu** d'une carte, qui renvoient vers l'éditeur, le
    CERT ou le bulletin d'origine.
    Ceux-là sont réécrits côté serveur, de sorte que la règle vaut aussi sans
    JavaScript.
    Les liens relatifs, les ancres internes et les adresses `mailto:` ne sont
    pas touchés, et `rel="noopener noreferrer"` accompagne la cible - sans
    lui, la page ouverte garde une prise sur celle qui l'a ouverte.

- **Le texte du plugin est revu de bout en bout.**
  Une phrase par ligne dans les commentaires, plus de tiret cadratin ni de
  points de suspension en caractère unique, et plus de point-virgule dans la
  prose - remplacé par un point, qui coupe la phrase là où elle se coupait
  déjà.
  Les séparateurs des formats CSV documentés sont bien entendu conservés.

### Migration

- `glpi_plugin_veilletool_filterpresets` : une table, créée à la mise à jour.
  Aucune donnée existante n'est touchée.

---

## [1.8.0] - 2026-09-29

### Ajouté

- **Des positions de réglage nommées pour le score d'intérêt.** Le barème
  n'a pas une bonne réponse mais plusieurs, selon ce qu'on cherche : une
  veille de crise privilégie la fraîcheur et la note du flux, une veille
  produit privilégie la technologie. Passer de l'une à l'autre demandait de
  régler une quinzaine de curseurs à la main, et de se souvenir des valeurs
  précédentes.

  Chaque barème s'enregistre désormais sous un **titre** et une description,
  et se rappelle d'un clic depuis *Veille → Score d'intérêt*. Un préréglage
  ne s'applique jamais tout seul : c'est une copie qu'on rappelle, pas un
  mode dans lequel on entre. Le préréglage qui correspond exactement au
  barème en vigueur est signalé comme tel.

  | Action | Effet |
  |---|---|
  | **Appliquer** | Pose ce barème comme réglage en vigueur |
  | **Remplacer** | Écrase le préréglage par le barème actuellement réglé |
  | **Supprimer** | Retire le préréglage. Le barème en vigueur ne change pas |

  Vingt préréglages au plus. Un préréglage enregistré par une version
  antérieure reste applicable après une mise à jour qui ajoute un réglage :
  les clés manquantes reprennent leur valeur livrée.

- **L'import du barème demande lequel appliquer.** L'export « Score
  d'intérêt » emporte maintenant le barème en vigueur **et tous les
  préréglages**, un bloc chacun - le format passe de `Réglage;Valeur` à
  `Préréglage;Réglage;Valeur`. À l'import, le plugin liste ce que le fichier
  contient et pose la question, au lieu d'appliquer d'office le premier venu :

  - appliquer les réglages en vigueur dans le fichier.
  - appliquer l'un des préréglages qu'il contient.
  - **enregistrer tous ses préréglages sans toucher au barème en vigueur**.

  Les fichiers à deux colonnes produits par les versions antérieures restent
  lus, et s'appliquent directement - ils ne décrivaient qu'un seul barème,
  il n'y a rien à choisir.

### Migration

- `glpi_plugin_veilletool_scorepresets` : une table, créée à la mise à jour.
  Aucune donnée existante n'est touchée.

---

## [1.7.2] - 2026-09-29

Correction de robustesse réseau. Aucun changement de schéma, aucune option
touchée.

### Corrigé

- **Une collecte ne dépend plus de la rapidité du serveur de noms.** GLPI
  n'accorde que cinq secondes à l'établissement d'une connexion, **résolution
  DNS comprise**, et cette valeur n'est pas surchargeable depuis un plugin -
  la fusion `$opts + $eopts` conserve celle de GLPI. Or un serveur de noms
  défaillant coûte cinq secondes d'attente avant bascule sur le suivant : le
  budget était épuisé avant qu'une connexion soit seulement tentée, et
  **tous** les flux échouaient sur « Resolving timed out », y compris ceux
  dont le serveur répondait parfaitement.

  Le nom est désormais résolu par le plugin, **avant** l'appel, puis remis à
  cURL. L'attente sort du budget, qui n'a plus à couvrir que la connexion -
  quelques dizaines de millisecondes. La résolution est mémorisée par hôte
  pour la durée de la requête.

  > Aucun contrôle n'est contourné : l'adresse a déjà passé la liste blanche
  > de GLPI, et l'épinglage ne vaut que pour le couple hôte / port demandé.
  > Une redirection vers un autre domaine est résolue normalement par cURL.
  > Un hôte joignable en IPv6 seulement n'est pas épinglé - le comportement
  > y est inchangé.

- **Un flux muet n'emporte plus les autres.** GLPI ne borne que
  l'établissement de la connexion, rien ne bornait la suite : un serveur qui
  accepte la connexion puis n'envoie rien immobilisait la collecte jusqu'à
  `max_execution_time`. Un délai total de 30 secondes par flux est désormais
  imposé.

- **Une collecte de masse s'arrête d'elle-même avant la limite de PHP.**
  Relever quarante flux, c'est quarante attentes réseau bout à bout. Au-delà
  des trois quarts du temps d'exécution autorisé, les flux restants sont
  laissés au passage suivant et l'écran le dit - plutôt qu'une interruption
  de PHP au milieu d'un flux, dont rien ne rendait compte.

### Ajouté

- **Un diagnostic par flux**, au stéthoscope, dans la liste des flux. Il
  sépare ce que « délai dépassé » confond : recevabilité de l'adresse,
  **résolution du nom chronométrée**, mandataire éventuel, récupération, et
  validité du document XML. Il ne lit ni n'écrit aucune carte.

---

## [1.7.1] - 2026-09-29

Version de performance : aucune option ajoutée ni retirée, aucun contrôle de
sécurité relâché. Le coût de traitement d'une carte est ramené de quinze
requêtes à sept pour une carte neuve, et de douze à deux pour une carte
inchangée - ce que relève une collecte neuf fois sur dix.

### Performance

- **Une collecte ne repurifie plus un contenu qu'elle a déjà purifié.**
  `RichText::getSafeHtml()` est de loin l'opération la plus coûteuse d'un
  enregistrement - près de 3 ms pour un article de 6 Ko, contre 0,5 ms pour
  tout le reste réuni. Or un relevé horaire repasse sur les mêmes entrées :
  le plugin repurifiait chaque heure des contenus rigoureusement identiques.
  Une empreinte du contenu **brut**, avant purification, permet désormais de
  reconnaître une entrée inchangée. Le contenu stocké est alors, par
  construction, exactement ce que la purification produirait : il n'y a ni à
  la refaire, ni à réécrire la colonne - donc pas non plus à reclasser la
  carte. Sur un relevé de 2 150 entrées inchangées, cela fait **9 secondes de
  calcul et 21 000 requêtes en moins**.

  > Rien n'est relâché : le contenu est de toute façon repurifié **à
  > l'affichage** par le filtre `safe_html`, avec le nettoyeur courant de
  > GLPI. La purification au stockage reste une défense en profondeur. Elle
  > cesse seulement d'être refaite pour rien.

- **Le rapprochement technologique est 2,6 fois plus rapide** - de 1,27 ms à
  0,48 ms par carte sur le référentiel livré de 2 056 entrées, à résultat
  rigoureusement identique. Deux corrections :
  - les variantes de version d'un mot ne dépendent que de lui, mais étaient
    recalculées - expression régulière comprise - une fois par longueur de
    n-gramme, soit quatre fois par mot.
  - un index des préfixes permet d'abandonner dès le premier mot quand aucun
    terme connu ne commence là, au lieu d'allonger le n-gramme jusqu'à quatre
    mots à chaque position du texte.
- **Le dédoublonnage d'une collecte tient en une requête** au lieu d'une par
  entrée. Les entrées sont normalisées d'abord, toutes ensemble : leur
  identifiant de dédoublonnage est ainsi dérivé **une seule fois**, ce qui
  écarte au passage le risque qu'une entrée sans identifiant ni lien - dont
  la graine est l'heure courante - en obtienne deux différents à une seconde
  d'intervalle.
- **Les composantes du score ne sont plus relues juste après avoir été
  écrites.** Poser les thèmes d'une carte, c'est en connaître la meilleure
  note d'intérêt. La classer, c'est en connaître la meilleure confiance. Les
  deux alimentent directement le score, là où trois requêtes redemandaient à
  la base ce qu'on venait de lui écrire. La note du flux, elle, est mémorisée
  pour la durée de la requête - une collecte enregistre des dizaines de
  cartes venant toutes du même flux.
- **Les lignes de liaison s'écrivent directement.** Thèmes et technologies
  d'une carte sont des lignes nues : ni historique, ni traitement après
  enregistrement, ni contrôle de saisie. Passer par `CommonDBTM` instanciait
  un objet complet par ligne et relisait la table avant chaque suppression.
- **Une carte qui vient d'être créée n'est plus relue.** Elle n'a ni thème ni
  technologie à comparer, et rien à effacer avant d'écrire : trois requêtes
  de moins par carte collectée.
- **Le score n'est plus réécrit à l'identique.** Une carte dont rien n'a bougé
  ne coûte plus ni écriture, ni verrou, ni ligne de journal binaire.
- **Le reclassement de masse relève les thèmes de tout un lot en une
  requête**, au lieu d'une jointure par carte.

### Corrigé

- **Une carte pouvait sortir de la file de reclassement sans avoir été
  reclassée.** Toute écriture du score y remettait `is_indexed` à 1, y compris
  lorsque le rapprochement technologique n'avait pas été refait - une collecte
  sans changement de texte, ou l'ajout manuel d'une technologie. Une
  modification du référentiel pouvait ainsi être silencieusement perdue pour
  les cartes touchées entre-temps. La file n'est désormais vidée que par ce
  qui la traite réellement.

### Migration

- `glpi_plugin_veilletool_articles.content_hash` : empreinte du contenu brut,
  avant purification. Elle naît vide - la première collecte suivant la mise à
  jour purifie donc chaque carte une dernière fois, puis pose son empreinte.

---

## [1.7.0] - 2026-09-29

### Ajouté

- **Un onglet « Sauvegarde »** - *Veille → Sauvegarde* - qui exporte et
  réimporte la configuration du plugin, bloc par bloc, au format CSV :
  **Général** (l'ensemble des réglages), **Score d'intérêt** (le seul barème,
  pour le rejouer ailleurs sans emporter le reste), **Flux RSS / ATOM**,
  **Thèmes de veille** et **Référentiel technologique**. Chaque bloc produit
  exactement le fichier que son import sait relire, et l'écran indique dans
  quel ordre les rejouer pour reconstituer une installation. L'export ne
  contient aucune carte, aucun commentaire et aucun favori : c'est la
  configuration qu'il sauvegarde, pas les données collectées.
- **L'ordre des thèmes se règle en les déplaçant à la souris.** Une poignée
  par ligne, un bouton d'enregistrement qui n'apparaît qu'après un
  déplacement réel, et deux chevrons par ligne comme repli pour qui n'a ni
  souris ni JavaScript. Le réordonnancement est désactivé pendant une
  recherche : déplacer une ligne dans une liste partielle voudrait dire la
  déplacer par rapport à des thèmes qu'on ne voit pas.
- **Un moteur de recherche sur les thèmes** : mot clé - nom et description -
  et état d'activité.
- **Import et export CSV d'un référentiel de thèmes** -
  `Nom. Couleur de fond. Couleur du texte. Icône. Intérêt. Actif.
  Description`, seul le nom étant obligatoire. **Le nom fait foi** : un thème
  déjà présent est mis à jour plutôt que dupliqué, ce qui permet de rejouer
  un import pour corriger des couleurs sans repartir de zéro. L'ordre des
  lignes donne l'ordre d'affichage.

### Modifié

- **L'écran des thèmes ne passe plus par le moteur de recherche de GLPI.**
  Un thème se juge à son apparence - couleur, icône, contraste de son
  étiquette - et se range par rapport aux autres : un tableau de colonnes ne
  montrait ni l'un ni l'autre. Le nouvel écran affiche l'étiquette telle
  qu'elle paraîtra, la description, la note d'intérêt, le nombre de cartes
  portant le thème et le nombre de flux qui l'appliquent, avec une bascule
  d'activité directement dans la liste.
- **Le champ « Code » disparaît du formulaire.** Il est calculé à la création
  à partir du nom, rendu unique par un suffixe numérique si besoin, puis
  **figé**. Il désigne le thème dans les fichiers importés : le voir changer
  parce qu'on a corrigé une faute dans le libellé cassait ces renvois sans
  prévenir personne.
- **Le champ « Ordre d'affichage » disparaît lui aussi** : plus personne n'a
  à saisir un nombre pour dire qu'un thème passe devant un autre.

### Migration

Aucun changement de schéma.

- Les rangs des thèmes sont réécrits de dix en dix, dans leur ordre
  d'affichage courant. Rien ne l'imposait jusqu'ici, et une base pouvait
  porter plusieurs thèmes au même rang : le classement n'aurait alors plus
  bougé, quoi qu'on déplace.
- Un thème dont le code technique manquait s'en voit attribuer un.

---

## [1.6.0] - 2026-09-29

### Ajouté

- **Un catalogue de 43 flux de veille livré avec le plugin** - CERT et
  autorités (CERT-FR, ANSSI, CISA, NCSC, ENISA, NIST, CNIL), avis d'éditeurs
  (MSRC, Cisco, Fortinet, Ubuntu, Debian, Red Hat, AWS, Google, Mozilla),
  presse spécialisée, infrastructure et cloud. **Chacun porte une description**
  de ce qu'il publie et de ce qu'il vaut : on choisit ce qu'on active sans
  avoir à ouvrir quarante-trois adresses. **Tous arrivent désactivés** : ce
  qu'une organisation suit lui appartient, et rien ne doit se mettre à
  collecter sans qu'on l'ait décidé. Le catalogue est chargé à l'installation
  d'un plugin vierge, et reste chargeable à tout moment par le bouton
  **Charger le catalogue**. Les flux déjà définis ne sont jamais dupliqués,
  la comparaison portant sur l'adresse.
- **Import d'une liste de flux au format CSV** -
  `Nom ; URL ; Type ; Thèmes ; Intérêt ; Actif ; Description`, seules les deux
  premières colonnes étant obligatoires. Le type se déduit de l'adresse s'il
  manque, les thèmes se donnent par code ou par nom, et **`Actif` laissé vide
  vaut désactivé**, comme pour le catalogue. Un thème inconnu est signalé sans
  faire échouer la ligne.
- **Un moteur de recherche sur les flux** : mot clé - portant sur le nom,
  l'adresse **et la description**, ce qui permet de retrouver un flux par ce
  qu'il publie -, catégorie, état d'activité, et volume de cartes déjà
  collectées (aucune, au moins une, au moins 10, au moins 100). Ce dernier
  critère sert autant à repérer les flux productifs qu'à débusquer ceux qui
  n'ont jamais rien ramené.
- **Suppression de masse des flux et du référentiel technologique**, sur le
  modèle de « Vider les cartes » : *Compter*, puis *Supprimer les entrées
  trouvées* - qui reprend exactement les critères affichés - ou *Tout
  supprimer*, qui les ignore délibérément plutôt que d'être un raccourci des
  précédents. *Compter* n'écrit rien.
  - **Flux** : le décompte annonce les flux **et les cartes** qu'ils
    emportent, puisque supprimer un flux supprime ses cartes, leurs
    commentaires, favoris et marquages de lecture. Ces cartes sont retirées
    de façon ensembliste - six requêtes pour tout le lot, au lieu de six par
    carte : supprimer un catalogue entier n'y survivait pas autrement.
  - **Référentiel** : les suivis et les rattachements ajoutés à la main qui
    visaient les entrées retirées disparaissent avec elles, et toutes les
    cartes repassent dans la file de reclassement. La suppression se fait par
    lots de 500 en une requête chacun, et les nettoyages une seule fois à la
    fin - un référentiel complet compte plus de deux mille entrées.
- **L'activité d'un flux se bascule depuis la liste.** Activer cinq flux sur
  quarante-trois ne demande plus d'ouvrir quarante-trois fiches. L'en-tête
  affiche le nombre de flux actifs sur le total, et un bandeau prévient quand
  aucun ne l'est - rien n'étant collecté, on pourrait croire la veille en
  panne.
- **Un droit « Commenter » distinct du droit d'écriture.** Commenter n'est pas
  modifier : c'est ajouter son analyse à côté de l'information, sans y toucher.
  Un profil en lecture seule porte désormais ce droit et participe aux
  échanges, sans pouvoir récrire ni supprimer une carte. Chacun reprend ou
  retire ce qu'il a écrit. Modérer le propos d'autrui reste au droit
  d'écriture. Les profils existants qui commentaient par le droit d'écriture le
  conservent - la migration leur ajoute le nouveau bit.
- **Un administrateur peut rattacher lui-même une carte à une technologie.**
  Le rapprochement automatique ne voit que ce qui est écrit. Une carte peut
  parler d'un produit sans le nommer. Le détail d'une carte porte donc un
  sélecteur du référentiel, réservé au droit d'écriture sur les flux : la
  famille et la catégorie viennent de l'entrée choisie, la confiance vaut 100,
  et le rattachement **survit aux reclassements** - c'est une décision, pas une
  déduction. Il se retire d'un clic, et disparaît si l'entrée quitte le
  référentiel.

### Modifié

- **Les listes de sélection de l'écran « Vider les cartes » se cochent d'un
  simple clic**, et se décochent du suivant. Les listes déroulantes multiples
  exigeaient une combinaison de touches qui ne se devine pas et se perd au
  moindre faux mouvement. Chaque liste affiche le nombre d'entrées retenues, et
  une entrée cochée se distingue à l'œil.
- **« Vider les cartes » sait viser le référentiel technologique** : trois
  listes - catégories, familles, technologies - à choix multiple, qui se
  combinent entre elles par « ou ». Seules les valeurs effectivement portées
  par au moins une carte sont proposées. Au-delà de huit entrées, un champ de
  filtre permet de retrouver la bonne sans dérouler.
- **Les questions posées à l'IA s'adaptent à la nature de la carte.** Demander
  la cause, le risque et le correctif d'une réglementation ou d'une nouvelle
  fonctionnalité ne produisait qu'une colonne vide - ou pire, une invention.
  L'angle est déduit de la carte : ses rattachements technologiques d'abord
  (la catégorie « Réglementation » du référentiel tranche), ses mots ensuite.
  Quatre jeux de colonnes en découlent :
  - **sécurité** - *Cause | Risques | Correctifs*.
  - **réglementation** - *Ce qui change | Qui est concerné | Échéance | Ce qu'il
    faut faire*.
  - **nouveauté** - *Ce qui est nouveau | Ce que ça apporte | Qui est concerné |
    Ce qu'il faut faire*.
  - **neutre** - *De quoi il s'agit | Ce que ça change | Ce qu'il faut faire*.

  Les synthèses de lot, qui mélangent les natures, gagnent une colonne
  **Nature** plutôt que des colonnes imposées à tous.
- **Toutes les questions portent une exigence de lisibilité** : une idée par
  cellule, une quinzaine de mots au plus, pas de phrase rédigée ni de redite
  d'une colonne à l'autre, et « non précisé » plutôt qu'une invention. Le but
  est de comprendre de quoi on parle du premier regard.
- **Enregistrer un flux ramène à la liste des flux.** `Html::back()` ne dispose
  que de l'en-tête `Referer` : absent - politique de référent restrictive,
  mandataire, divergence avec `url_base` -, GLPI renvoyait à l'accueil, et la
  configuration paraissait se fermer d'elle-même. Création comme modification
  d'un flux reviennent désormais explicitement à **Veille → Flux RSS / ATOM**,
  d'où le flux enregistré peut être collecté aussitôt. Les autres écrans du
  plugin indiquent eux aussi où revenir : plus aucun ne retombe sur l'accueil.
- **Le marquage « Tout marquer comme lu » ne demande plus le droit
  d'écriture** : l'état de lecture est propre à chaque utilisateur et ne touche
  pas à la carte.

### Performance

L'affichage de l'observatoire ne dépend plus du nombre de cartes de la page,
et celui de la liste des flux plus du nombre de flux.

- **L'écran des flux fait deux requêtes au lieu de deux par ligne** : le nombre
  de cartes collectées et les thèmes de chaque flux sont désormais chargés en
  une passe pour tout le tableau. Avec le catalogue livré, cela fait
  quatre-vingt-six requêtes en moins.

- **Le contenu intégral des cartes n'est plus chargé dans les listes.** C'est un
  `longtext` : le lire entier pour chaque carte transférait plusieurs
  mégaoctets afin d'en afficher deux lignes. Les listes n'en demandent plus que
  le début, et l'aperçu est **calculé une seule fois côté serveur** au lieu
  d'être remis à plat par le gabarit à chaque rendu.
- **Les noms de flux sont résolus en une requête** pour toute la page, au lieu
  d'une requête par carte affichée.
- **Le barème du score est lu une fois par requête**, et non par carte : la
  description des cinq niveaux de priorité - quinze réglages et cinq calculs de
  contraste - était refaite pour chaque ligne.
- **Les listes ne préparent plus qu'une question à l'IA par carte** au lieu de
  quatre. Les quatre niveaux restent préparés d'avance sur le détail d'une
  carte, qui seul permet d'en changer.
- **La mise en forme d'une synthèse de lot devient linéaire.** Elle réencodait
  la question entière à chaque carte ajoutée pour en mesurer la longueur, soit
  le carré du nombre de cartes. La longueur est désormais tenue à jour au fil
  des cartes, au résultat près identique.
- **« Tout marquer comme lu » ne décore plus les cartes** dont il ne garde que
  l'identifiant, et écrit les marquages manquants en une passe.

### Migration

Rien de nouveau au schéma pour les flux : la description reprend la colonne
`comment`, déjà présente et déjà exposée par le formulaire d'un flux.

- `glpi_plugin_veilletool_articles_technologies.is_manual` : distingue le
  rattachement décidé à la main de celui trouvé par le rapprochement.
- Le droit « Commenter » est ajouté à tout profil disposant déjà de la lecture
  des cartes.

---

## [1.5.2] - 2026-09-28

### Ajouté

- **Vue statistique par technologie**, en plus de Catégorie et Famille. Le
  sélecteur de découpage passe au centre de l'écran, au-dessus du graphique et
  en grande taille : c'est lui qui commande tout le reste. Au niveau
  technologie, une carte rattachée seulement à une famille relève d'« Autre ».
- **Le détail d'une carte expose son analyse** : le score d'intérêt décomposé
  en ses quatre composantes avec leurs poids, et toutes les technologies
  reconnues avec la confiance de chaque rapprochement - cliquables pour
  filtrer l'observatoire dessus.

- **Les cinq niveaux de priorité sont renommables.** Un champ de libellé sur la
  ligne de chaque seuil. Laissé vide, il rend le libellé livré, affiché en
  filigrane. Le libellé livré suit la langue de l'utilisateur, un libellé saisi
  s'applique tel quel à tout le monde.
- **La couleur du texte des thèmes de veille** (`..._categories.text_color`),
  réglée avec son fond dans un champ unique qui porte l'aperçu de l'étiquette
  et son rapport de contraste - la même présentation que les niveaux de
  priorité, animée par le même script.

### Modifié

- **La fraîcheur se règle en deux valeurs explicites** : au bout de combien de
  jours une carte commence à perdre des points, et combien elle en perd par
  jour. L'ancien « horizon de fraîcheur » disait au bout de combien de temps
  la note atteignait zéro - une conséquence, pas une consigne. L'écran affiche
  désormais ce point d'extinction, recalculé à chaque changement. Zéro point
  par jour neutralise la fraîcheur sans toucher à son poids.
- **L'observatoire s'allège** : la jauge du score disparaît de la vue Cartes,
  et la confiance chiffrée des technologies n'y est plus affichée. Les deux
  restent sur le détail d'une carte. On parcourt une grille, on lit une fiche.
- L'étiquette d'un thème n'impose plus de texte blanc ni d'ombre portée :
  l'ombre compensait le blanc et salissait un texte sombre sur fond clair.
  Les thèmes existants gardent le blanc, désormais comme valeur et non comme
  contrainte.

---

## [1.5.1] - 2026-09-27

### Modifié

- **Le suivi passe de la catégorie à la famille et à la technologie.** Suivre
  « Application » ou « OS » revenait à retenir des centaines d'entrées sans
  rapport entre elles, donc à ne rien filtrer. Le suivi descend d'un cran :
  - une **famille** - *Linux - Debian*, *Normes ISO / IEC* - retient toute carte
    qui l'évoque, quelle que soit la finesse du rattachement.
  - une **technologie** - *GLPI 11* - retient les seules cartes rattachées à
    cette entrée.
- Le réglage se fait par **bouton *Suivre / Suivie*** : un panneau **Familles**
  en haut de *Veille → Technologies*, et un bouton sur chaque ligne du tableau
  des technologies. Une entrée déjà couverte par le suivi de sa famille
  l'indique au lieu de proposer un bouton sans effet.
- Le commutateur de l'observatoire s'appelle désormais **Technologies suivies**.
- Le **score d'intérêt** passe en **pied de carte**, juste avant la barre
  d'actions. Son infobulle, jusque-là masquée par la surcouche du lien de
  titre, est de nouveau accessible.
- **La couleur de fond de chaque niveau de priorité est réglable**, par un
  sélecteur placé à côté de son seuil, avec aperçu en direct. L'étiquette
  héritait jusque-là de `--tblr-badge-color`, que GLPI fixe selon la palette :
  le libellé passait mal sur les fonds colorés du badge.
- **La couleur du texte de l'étiquette est réglable elle aussi.** L'écran
  affiche le **rapport de contraste** obtenu, recalculé à chaque mouvement de
  sélecteur, et passe en avertissement sous 4,5:1 - sans jamais refuser
  l'enregistrement. Le calcul est repris à l'identique en PHP et en JavaScript.
  Les cinq combinaisons livrées atteignent le niveau AA.
- En **vue liste**, la couleur du niveau passe sur une pastille : portée par le
  chiffre, un fond clair l'aurait rendu illisible.
- La **vue liste** porte une colonne **Score** réduite au pourcentage, sans
  jauge : un tableau se parcourt à la verticale, et des chiffres alignés se
  comparent mieux qu'une succession de barres.

### Ajouté

- **Filtre « Suivi »** dans la recherche du référentiel - *Toutes / Suivies
  uniquement / Non suivies*. Il s'applique au tableau des technologies comme au
  panneau des familles. *Suivies uniquement* retient une entrée qu'elle le soit
  pour elle-même ou par sa famille : c'est exactement le périmètre que retient
  l'observatoire.
- Table `glpi_plugin_veilletool_followedtechnologies`, dont les colonnes sont
  celles de la table de classification - `name` vide désigne une famille
  entière, `name` renseigné une technologie précise. Le filtrage se réduit à
  une comparaison directe.
- Suppression d'une entrée du référentiel : le suivi qui la visait est retiré.
  Un import qui remplace le référentiel purge les suivis devenus sans objet.

### Supprimé

- Le réglage `freshness_days`, remplacé par `freshness_grace_days` et
  `freshness_loss_per_day`. Il est effacé de la configuration de GLPI à la
  mise à jour, comme toute clé laissée par une version antérieure.
- La colonne `..._technologies.is_followed`, qui portait le suivi par catégorie.
  Les catégories suivies ne sont **pas** converties en familles - reporter
  « OS » sur ses familles produirait des dizaines de suivis que personne n'a
  demandés. La migration signale ce qui a été perdu.

### Corrigé

- **Installation impossible depuis une base « veilletechno »** :
  `Migration::renameItemtype()` renomme les tables immédiatement mais empile le
  renommage des colonnes de clé étrangère, lequel n'a lieu qu'au vidage de la
  file. La 1.5.0 ne vidait cette file que pour cinq tables sur dix, puis
  interrogeait une des cinq autres - d'où un `Unknown column
  'plugin_veilletool_categories_id'` en plein milieu de la migration.
  La file est désormais vidée pour **toutes** les tables avant la moindre
  requête de données.
- **Rattrapage d'une reprise interrompue** : une migration coupée entre le
  renommage des tables et celui de leurs colonnes laissait des colonnes
  `plugin_veilletechno_*` dans des tables `glpi_plugin_veilletool_*`, que plus
  aucune tentative ne corrigeait - la reprise se croyant faite. La migration
  les renomme explicitement.
- L'import du référentiel relisait la liste des suivis à chaque ligne, soit
  deux mille requêtes superflues.

---

## [1.5.0] - 2026-09-27

Les versions 1.0.0 à 1.4.2 ont été publiées sous la clé technique
`veilletechno`. Le plugin s'appelle désormais `veilletool`, et la numérotation
reprend là où elle s'était arrêtée : **la 1.5.0 succède à la 1.4.2**, elle ne
recommence pas à zéro. Une installation portant l'ancienne clé est reprise
automatiquement, tables, droits et actions automatiques compris.

### Ajouté

- **Score d'intérêt** sur chaque carte, noté de 0 à 100. Il agrège quatre
  composantes - intérêt de la **source**, confiance du rapprochement
  **technologique**, intérêt de la **catégorie**, et **fraîcheur** de
  l'information. Les poids sont réglables par l'utilisateur. La note est
  toujours ramenée sur un barème de 100, quelles que soient les valeurs
  retenues.
- **Priorité** déduite du score, avec des seuils réglables :
  *Prioritaire* (80-100), *Important* (60-79), *À lire* (50-59),
  *Information* (20-49), *Faible intérêt* (0-19).
- **Score d'intérêt en vue liste** : une colonne réduite au pourcentage, sans
  jauge - un tableau se parcourt à la verticale, et des chiffres alignés se
  comparent mieux qu'une succession de barres.
- **Écran « Veille → Score d'intérêt »** : poids des composantes avec leur
  part réelle affichée en direct, horizon de fraîcheur, seuils de priorité et
  tri par défaut de l'observatoire.
- **Note d'intérêt** réglable sur chaque **flux** et sur chaque **thème**.
- **Score de confiance** sur chaque rapprochement technologique, selon ce sur
  quoi il repose : libellé exact **100**, version tronquée **90**,
  alias **85**, famille **60**, racine de produit **50**. Il est affiché sur
  les cartes - et sur elles seules.
- **Référentiel des technologies suivies**, réglable depuis le même écran que
  le référentiel général. Il permet de trier les cartes indépendamment de la
  finesse de leur qualification, via un commutateur dédié dans l'observatoire.
  *(Le suivi portait d'abord sur la catégorie. Il porte sur la famille et la
  technologie depuis la 1.5.1.)*
- **Tri de l'observatoire** par score d'intérêt ou par date de publication.
  Le score est le tri par défaut. Chaque utilisateur peut y renoncer depuis la
  barre de filtres, son choix valant pour sa session.
- **Action automatique « Reclassement technologique »** (itemtype
  `GlpiPlugin\Veilletool\Article_Technology`), qui traite par lots la file des
  cartes à reclasser. La taille du lot se règle dans le champ *Paramètre* de
  la tâche.
- Ce journal des modifications.

### Modifié

- **Installation et migration séparées** : `install/install.php` ne décrit que
  l'état cible du schéma, `install/migration.php` ne contient que les écarts
  entre versions. `hook.php` se limite à choisir lequel s'applique, à partir de
  la version enregistrée dans la configuration de GLPI.
- **La réindexation des technologies n'a plus lieu pendant l'installation.**
  L'installation, la migration et toute modification du référentiel se
  contentent de placer les cartes dans une file. L'action automatique la vide
  par lots. Une base de plusieurs milliers de cartes n'immobilise plus l'écran
  d'installation. Le bouton **Reclasser maintenant** reste disponible pour qui
  préfère attendre.
- **Recherche par mot clé en index plein texte** (`MATCH ... AGAINST` en mode
  booléen) sur le titre, le résumé et le contenu, au lieu de trois `LIKE
  '%...%'` qui ne pouvaient pas s'appuyer sur un index. La recherche sur
  l'**auteur** reste en `LIKE` : c'est un nom propre, souvent cherché
  partiellement. Les saisies dont tous les mots font moins de trois caractères
  - en deçà de ce que l'index retient - retombent sur l'ancien comportement.
- **Collecte : une transaction par entrée**, au lieu d'une pour tout le flux.
  Enregistrer une carte, c'est écrire la carte, ses thèmes, ses technologies et
  son score : valider entrée par entrée garantit qu'une carte enregistrée est
  une carte complète, et qu'une entrée fautive n'emporte pas les autres.
- L'écran des technologies affiche l'état de l'action automatique de
  reclassement et le nombre de cartes en attente.

### Supprimé

- Le thème **« Réglementation »** livré par défaut, qui faisait doublon avec la
  catégorie « Réglementation » du référentiel technologique : la même
  information était qualifiée deux fois, par deux mécanismes différents. La
  migration ne le retire d'une installation existante que s'il n'a jamais servi
  et n'a pas été personnalisé. Sinon elle le conserve et le signale.

---

## [1.4.0]

### Ajouté

- **Résumé par IA** : écran de sélection des cartes, quatre niveaux d'analyse,
  question modifiable avant envoi et export d'un document `resume.md` autonome.
- Bouton **Demander à l'IA** sur chaque carte et dans son détail.

### Corrigé

- **Action automatique de collecte** : elle était enregistrée en mode *CLI*,
  lequel n'est déclenché que par un cron système. Sur une installation
  dépourvue de cron système, la tâche restait indéfiniment en attente sans
  jamais s'exécuter. Elle est désormais enregistrée en mode *GLPI*, sauf si
  `GLPI_SYSTEM_CRON` est effectivement activé.
- L'écran des flux affiche l'état réel de la tâche - périodicité, mode,
  dernière exécution - et avertit lorsqu'elle est désactivée ou introuvable.

---

## [1.3.0]

### Ajouté

- **Vue statistique** de l'observatoire : répartition des cartes filtrées par
  catégorie ou par famille technologique, sous forme de camembert (ECharts,
  déjà fourni par GLPI - aucune ressource externe).
- Tableau des vingt sous-catégories les plus citées, cliquable pour basculer en
  vue cartes avec le filtre correspondant.
- **Filtre technologique** dans l'observatoire, combinable avec tous les
  autres, et jeton « Hors référentiel » pour les cartes sans rattachement.
- Les chiffres sont obtenus par agrégation SQL sur la classification
  matérialisée : l'analyse porte sur l'intégralité des cartes filtrées, sans
  plafond ni traitement en mémoire.

---

## [1.2.0]

### Ajouté

- **Référentiel technologique** à trois niveaux - Catégorie / Famille /
  Sous-catégorie - livré pré-rempli avec plus de deux mille entrées.
- Écran **Veille → Technologies** : filtrage, ajout manuel, suppression d'une
  sélection et import CSV (`Catégorie;Famille;Sous-catégorie`).
- **Rapprochement automatique** entre le texte d'une carte et le référentiel,
  volontairement tolérant : libellé exact, version tronquée (« Debian 13.0.1 »
  → « Debian 13 »), alias entre parenthèses et racine de produit.
- La classification est **matérialisée en base**, ce qui ramène le filtrage et
  les agrégats à de simples requêtes SQL.

---

## [1.1.0]

### Supprimé

- **Import de fichiers XML** et type de source « saisie manuelle » : deux voies
  d'alimentation redondantes avec les flux RSS / Atom, qui multipliaient les
  cas particuliers sans rien apporter.

### Modifié

- Les sources obsolètes dépourvues d'URL sont désactivées plutôt que laissées
  silencieusement cassées. Les cartes issues d'un import XML deviennent des
  saisies manuelles.

---

## [1.0.0]

Version initiale.

### Ajouté

- Secteur de menu **Veille** de premier niveau, avec ses sous-onglets.
- **Cartes de veille** collectées depuis des flux **RSS 2.0, RSS 1.0 (RDF) et
  Atom**, ou saisies à la main.
- **Catégories** (thèmes) avec couleur, icône et ordre d'affichage, alimentant
  les onglets de filtrage de l'observatoire.
- Trois vues : **cartes**, **liste** et détail.
- Marquage **lu / non lu** propre à chaque utilisateur, **favoris** partagés et
  **commentaires** collaboratifs.
- Recherche par mot clé, filtrage par flux, par dates, par état de lecture.
- Écran de **purge** par lot, avec préservation des favoris par défaut.
- **Action automatique de collecte** des flux actifs.
- Deux droits réglables depuis l'onglet « Veille » du formulaire des profils.
