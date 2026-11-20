/*
 * Plugin GLPI « Outils de Veille » (veilletool)
 *
 * Confort d'utilisation uniquement : toutes les actions restent des
 * formulaires HTML classiques et fonctionnent sans JavaScript. Les bascules
 * favori et lu / non lu de l'observatoire passent en AJAX quand le script
 * est chargé.
 */
(function () {
    'use strict';

    /**
     * Applique immédiatement le filtre quand la source ou un commutateur
     * change, sans attendre le clic sur « Filtrer ».
     *
     * @param {ParentNode} root
     */
    function autoSubmitFilters(root) {
        // Le marqueur suffit : l'observatoire et l'écran des flux portent la
        // même barre de filtres, dans deux conteneurs différents.
        root.querySelectorAll('form[data-veille-filters]').forEach(function (form) {
            if (form.dataset.veilleFiltersReady === '1') {
                return;
            }
            form.dataset.veilleFiltersReady = '1';

            form.querySelectorAll('select[name="src"], select[name="tech"], select[name="sort"], select[name="cat"], select[name="active"], select[name="cards"], input[name="unread"], input[name="commented"], input[name="followed"]')
                .forEach(function (field) {
                    field.addEventListener('change', function () {
                        form.submit();
                    });
                });
        });
    }

    /**
     * Demande confirmation en nommant ce qui va disparaître.
     *
     * Un bouton qui agit sur le choix d'une liste déroulante ne peut pas
     * porter ce nom dans son libellé : il change à chaque sélection.
     * On le relit donc dans la liste au moment du clic.
     *
     * Sans JavaScript, la confirmation n'a pas lieu - comme toute
     * confirmation de navigateur - et le formulaire part directement.
     *
     * @param {ParentNode} root
     */
    function initSelectConfirms(root) {
        root.querySelectorAll('[data-veille-confirm-select]').forEach(function (button) {
            if (button.dataset.veilleConfirmReady === '1') {
                return;
            }
            button.dataset.veilleConfirmReady = '1';

            button.addEventListener('click', function (event) {
                var list = document.getElementById(button.dataset.veilleConfirmSelect);

                if (list === null) {
                    return;
                }

                // Rien de choisi : le serveur le dira mieux que nous.
                if (list.value === '' || list.value === '0') {
                    return;
                }

                var nom = list.options[list.selectedIndex].text.trim();
                var message = (button.dataset.veilleConfirmMessage || '%s').replace('%s', nom);

                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    /**
     * Filtre le contenu d'une liste à cocher au fil de la frappe.
     *
     * Les listes du référentiel technologique comptent parfois plus de cent
     * entrées : sans filtre, retrouver la bonne revient à dérouler. Une
     * entrée cochée reste toujours visible - la masquer donnerait
     * l'impression de l'avoir perdue, alors qu'elle serait bel et bien
     * envoyée avec le formulaire.
     *
     * Sans JavaScript, le champ de filtre est simplement sans effet : la
     * liste reste complète et utilisable.
     *
     * @param {ParentNode} root
     */
    function initListFilters(root) {
        root.querySelectorAll('[data-veille-list-filter]').forEach(function (input) {
            if (input.dataset.veilleListFilterReady === '1') {
                return;
            }
            input.dataset.veilleListFilterReady = '1';

            var list = document.getElementById(input.dataset.veilleListFilter);
            if (list === null) {
                return;
            }

            var items = list.querySelectorAll('.veille-check-item');

            input.addEventListener('input', function () {
                var needle = input.value.trim().toLowerCase();

                items.forEach(function (item) {
                    var box = item.querySelector('input[type="checkbox"]');
                    var keep = needle === ''
                        || (box !== null && box.checked)
                        || item.textContent.toLowerCase().indexOf(needle) !== -1;

                    item.hidden = !keep;
                });
            });
        });
    }

    /**
     * Réordonnancement d'un tableau à la souris.
     *
     * Chaque ligne porte un champ caché `order[]` : déplacer la ligne dans le
     * DOM déplace le champ avec elle, et le formulaire envoie donc l'ordre
     * affiché sans qu'aucun script n'ait à le recomposer. Le bouton
     * d'enregistrement n'apparaît qu'après un déplacement réel.
     *
     * Sans JavaScript, les lignes ne se glissent pas : les boutons monter et
     * descendre de chaque ligne restent le moyen de ranger ses thèmes.
     *
     * @param {ParentNode} root
     */
    function initSortableTables(root) {
        root.querySelectorAll('[data-veille-sortable]').forEach(function (body) {
            if (body.dataset.veilleSortableReady === '1') {
                return;
            }
            body.dataset.veilleSortableReady = '1';

            var form = body.closest('form');
            var save = form === null ? null : form.querySelector('[data-veille-sort-save]');
            var initial = order(body);
            var dragged = null;

            /**
             * Ordre courant des lignes, sous forme de chaîne comparable.
             *
             * @param {Element} container
             *
             * @returns {string}
             */
            function order(container) {
                return Array.prototype.map.call(
                    container.querySelectorAll('[data-veille-sort-id]'),
                    function (row) { return row.dataset.veilleSortId; }
                ).join(',');
            }

            function refresh() {
                if (save !== null) {
                    save.hidden = order(body) === initial;
                }
            }

            body.querySelectorAll('[data-veille-sort-id]').forEach(function (row) {
                var handle = row.querySelector('[data-veille-sort-handle]');

                if (handle === null) {
                    return;
                }

                // La poignée seule est saisissable : sans cela, sélectionner
                // le texte d'une cellule déclencherait un déplacement.
                handle.draggable = true;

                handle.addEventListener('dragstart', function (event) {
                    dragged = row;
                    row.classList.add('veille-sort-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    // Firefox n'amorce pas le glissement sans charge utile.
                    event.dataTransfer.setData('text/plain', row.dataset.veilleSortId);
                });

                handle.addEventListener('dragend', function () {
                    row.classList.remove('veille-sort-dragging');
                    dragged = null;
                    refresh();
                });

                row.addEventListener('dragover', function (event) {
                    if (dragged === null || dragged === row) {
                        return;
                    }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';

                    // Le point de bascule est le milieu de la ligne survolée :
                    // au-dessus on passe devant, en dessous on passe derrière.
                    var box = row.getBoundingClientRect();
                    var before = event.clientY < box.top + box.height / 2;

                    body.insertBefore(dragged, before ? row : row.nextSibling);
                });

                row.addEventListener('drop', function (event) {
                    event.preventDefault();
                    refresh();
                });
            });

            refresh();
        });
    }

    /**
     * Affiche la part réelle de chaque composante du score d'intérêt.
     *
     * Les poids sont des valeurs libres ; ce qui compte est leur proportion.
     * L'écran de réglage la recalcule à chaque mouvement de curseur, pour
     * que l'utilisateur voie ce qu'il règle plutôt que des nombres bruts.
     *
     * @param {ParentNode} root
     */
    function initWeightSliders(root) {
        root.querySelectorAll('[data-veille-weights]').forEach(function (panel) {
            if (panel.dataset.veilleWeightsReady === '1') {
                return;
            }
            panel.dataset.veilleWeightsReady = '1';

            var sliders = panel.querySelectorAll('[data-veille-weight]');

            var refresh = function () {
                var total = 0;
                sliders.forEach(function (slider) {
                    total += parseInt(slider.value, 10) || 0;
                });

                sliders.forEach(function (slider) {
                    var badge = panel.querySelector(
                        '[data-veille-share="' + slider.dataset.veilleWeight + '"]'
                    );
                    if (badge === null) {
                        return;
                    }

                    var weight = parseInt(slider.value, 10) || 0;
                    // Quatre poids nuls : le plugin rétablit le barème
                    // équilibré, autant le montrer tout de suite.
                    var share = total > 0 ? (weight * 100 / total) : 25;

                    badge.textContent = (Math.round(share * 10) / 10) + ' %';
                });
            };

            sliders.forEach(function (slider) {
                slider.addEventListener('input', refresh);
            });

            refresh();
        });
    }

    /** Rapport de contraste minimal recommandé (WCAG AA, texte courant) */
    var MIN_CONTRAST = 4.5;

    /**
     * Luminance relative d'une couleur, au sens de la WCAG.
     *
     * Reprend le calcul de Score::relativeLuminance() côté PHP, pour que
     * l'aperçu annonce exactement ce que l'enregistrement produira.
     *
     * @param {string} color Notation #rrggbb
     *
     * @returns {number} De 0 (noir) à 1 (blanc)
     */
    function relativeLuminance(color) {
        var hex = String(color).replace('#', '');
        if (!/^[0-9a-fA-F]{6}$/.test(hex)) {
            return 0;
        }

        var channels = [0, 2, 4].map(function (offset) {
            var value = parseInt(hex.substr(offset, 2), 16) / 255;
            return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        });

        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    }

    /**
     * Rapport de contraste entre deux couleurs, de 1 à 21.
     *
     * @param {string} first
     * @param {string} second
     *
     * @returns {number}
     */
    function contrastRatio(first, second) {
        var a = relativeLuminance(first);
        var b = relativeLuminance(second);

        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    /**
     * Fait suivre une étiquette d'aperçu à son libellé et à ses deux
     * sélecteurs de couleur, et annonce le contraste obtenu.
     *
     * Le mécanisme est le même pour les niveaux de priorité et pour les thèmes
     * de veille : seule la valeur de `data-veille-color` change.
     *
     * Le réglage des niveaux de priorité se juge à l'œil : montrer le résultat
     * avant l'enregistrement évite l'aller-retour. Le contraste, lui, ne se
     * juge pas à l'œil - d'où le chiffre.
     *
     * @param {ParentNode} root
     */
    function initColorPairs(root) {
        root.querySelectorAll('[data-veille-color]').forEach(function (background) {
            if (background.dataset.veilleColorReady === '1') {
                return;
            }
            background.dataset.veilleColorReady = '1';

            var level = background.dataset.veilleColor;
            var text = root.querySelector('[data-veille-text="' + level + '"]');
            var preview = root.querySelector('[data-veille-preview="' + level + '"]');
            var gauge = root.querySelector('[data-veille-contrast="' + level + '"]');
            var label = root.querySelector('[data-veille-label="' + level + '"]');

            if (text === null || preview === null) {
                return;
            }

            var refresh = function () {
                preview.style.backgroundColor = background.value;
                preview.style.color = text.value;

                if (gauge === null) {
                    return;
                }

                var ratio = contrastRatio(background.value, text.value);
                var readable = ratio >= MIN_CONTRAST;
                var icon = gauge.querySelector('i');

                gauge.classList.toggle('text-secondary', readable);
                gauge.classList.toggle('text-orange', !readable);

                if (icon !== null) {
                    icon.className = 'ti ' + (readable ? 'ti-contrast' : 'ti-alert-triangle') + ' me-1';
                }

                var value = gauge.querySelector('[data-veille-ratio]');
                if (value !== null) {
                    value.textContent = String(Math.round(ratio * 100) / 100);
                }
            };

            background.addEventListener('input', refresh);
            text.addEventListener('input', refresh);

            // Le libellé se relit dans l'étiquette : un champ vide rend celui
            // livré, que le champ porte déjà en filigrane.
            if (label !== null) {
                var preview_label = preview.querySelector('[data-veille-preview-label]');

                if (preview_label !== null) {
                    label.addEventListener('input', function () {
                        preview_label.textContent = label.value.trim() !== ''
                            ? label.value
                            : label.getAttribute('placeholder');
                    });
                }
            }
        });
    }

    /**
     * Rend une entrée du sélecteur d'icônes : le pictogramme puis son nom.
     *
     * @param {object} state
     *
     * @returns {object|string}
     */
    function renderIconOption(state) {
        if (!state.id) {
            // Titre de groupe : pas d'icône à rendre.
            return state.text;
        }

        var $ = window.jQuery;
        var safe = String(state.id).replace(/[^a-z0-9 -]/gi, '');

        return $('<span class="veille-icon-option"></span>')
            .append($('<i></i>').attr('class', safe))
            .append($('<span></span>').text(state.text));
    }

    /**
     * Sélecteur d'icônes des catégories.
     *
     * Le formulaire des catégories est chargé par GLPI dans un onglet en
     * Ajax : il n'existe pas encore au chargement de la page. L'observateur
     * plus bas rappelle donc cette fonction à chaque insertion dans le DOM.
     *
     * Sans jQuery ni select2, la liste reste une liste déroulante ordinaire.
     *
     * @param {ParentNode} root
     */
    function initIconPickers(root) {
        var pickers = root.querySelectorAll('[data-veille-icon-picker]');

        if (pickers.length === 0) {
            return;
        }

        var $ = window.jQuery;
        if (typeof $ === 'undefined' || typeof $.fn.select2 !== 'function') {
            return;
        }

        pickers.forEach(function (picker) {
            if (picker.dataset.veilleIconReady === '1') {
                return;
            }
            picker.dataset.veilleIconReady = '1';

            var $picker = $(picker);
            var $modal = $picker.closest('.modal');

            $picker.select2({
                width: '100%',
                templateResult: renderIconOption,
                templateSelection: renderIconOption,
                dropdownParent: $modal.length ? $modal : $(document.body),
            });
        });
    }

    /**
     * Zone de rédaction de la question envoyée à l'IA : le lien de
     * redirection suit la saisie. Sans JavaScript, le lien conserve la
     * question par défaut rendue côté serveur.
     *
     * @param {ParentNode} root
     */
    function initAiComposers(root) {
        root.querySelectorAll('[data-veille-ai]').forEach(function (composer) {
            if (composer.dataset.veilleAiReady === '1') {
                return;
            }
            composer.dataset.veilleAiReady = '1';

            var textarea = composer.querySelector('[data-veille-ai-prompt]');
            var link     = composer.querySelector('[data-veille-ai-link]');
            var reset    = composer.querySelector('[data-veille-ai-reset]');
            var counter  = composer.querySelector('[data-veille-ai-length]');
            var overflow = composer.querySelector('[data-veille-ai-overflow]');

            if (textarea === null || link === null) {
                return;
            }

            var level   = composer.querySelector('[data-veille-ai-level]');
            var base    = composer.dataset.veilleAiBase || 'https://chatgpt.com/?q=';
            var budget  = parseInt(composer.dataset.veilleAiBudget || '0', 10);

            // Les quatre variantes de la question, préparées côté serveur.
            var prompts = {};
            try {
                prompts = JSON.parse(composer.dataset.veilleAiPrompts || '{}');
            } catch (error) {
                prompts = {};
            }

            var initial = textarea.value;

            var refresh = function () {
                var prompt = textarea.value.trim();
                var url    = base + encodeURIComponent(prompt);

                link.href = url;

                // Une question vide n'a pas de sens : le lien est neutralisé.
                if (prompt === '') {
                    link.classList.add('disabled');
                    link.setAttribute('aria-disabled', 'true');
                } else {
                    link.classList.remove('disabled');
                    link.removeAttribute('aria-disabled');
                }

                if (counter !== null) {
                    counter.textContent = prompt.length + ' ' + (prompt.length > 1 ? 'caractères' : 'caractère');
                }

                if (overflow !== null && budget > 0) {
                    overflow.classList.toggle('d-none', url.length <= budget);
                }

                if (reset !== null) {
                    reset.hidden = textarea.value === initial;
                }
            };

            textarea.addEventListener('input', refresh);

            // Changer de niveau réécrit la question, sans recharger la page.
            if (level !== null) {
                level.addEventListener('change', function () {
                    if (Object.prototype.hasOwnProperty.call(prompts, level.value)) {
                        initial = prompts[level.value];
                        textarea.value = initial;
                        refresh();
                    }
                });
            }

            if (reset !== null) {
                reset.addEventListener('click', function () {
                    textarea.value = initial;
                    refresh();
                    textarea.focus();
                });
            }

            refresh();
        });
    }

    /**
     * Palette du camembert. Les teintes sont assez contrastées pour rester
     * distinguables côte à côte, en thème clair comme en thème sombre.
     *
     * @type {string[]}
     */
    var CHART_COLORS = [
        '#4b7bec', '#e74c3c', '#16a085', '#f39c12', '#9b59b6',
        '#3498db', '#2ecc71', '#e67e22', '#e84393', '#00b894',
        '#6c5ce7', '#d63031', '#0984e3', '#fdcb6e', '#00cec9',
    ];

    /**
     * Camembert de répartition des cartes, rendu avec ECharts - la
     * bibliothèque de graphiques déjà fournie par GLPI.
     *
     * @param {ParentNode} root
     */
    function initCharts(root) {
        var targets = root.querySelectorAll('[data-veille-chart]');

        if (targets.length === 0 || typeof window.echarts === 'undefined') {
            return;
        }

        targets.forEach(function (target) {
            if (target.dataset.veilleChartReady === '1') {
                return;
            }
            target.dataset.veilleChartReady = '1';

            var slices;
            try {
                slices = JSON.parse(target.dataset.veilleChart);
            } catch (error) {
                return;
            }

            if (!Array.isArray(slices) || slices.length === 0) {
                return;
            }

            // La couleur du texte suit le thème actif de GLPI.
            var textColor = window.getComputedStyle(target).color;

            var chart = window.echarts.init(target);

            chart.setOption({
                animationDuration: 400,
                tooltip: {
                    trigger: 'item',
                    appendToBody: true,
                    // On affiche la part calculée côté serveur, et non celle
                    // recalculée par ECharts : une carte relevant de
                    // plusieurs catégories est comptée dans chacune, si bien
                    // que le total des parts peut dépasser 100 %.
                    formatter: function (params) {
                        var share = params.data && params.data.share !== undefined
                            ? ' (' + params.data.share + ' %)'
                            : '';
                        return params.name + '<br/>' + params.value + share;
                    },
                },
                // Légende sous le graphique, sur une seule ligne défilante
                // avec des flèches gauche / droite : elle ne peut donc plus
                // recouvrir le camembert, même avec 93 familles.
                legend: {
                    type: 'scroll',
                    orient: 'horizontal',
                    bottom: 0,
                    left: 'center',
                    width: '92%',
                    itemGap: 14,
                    pageButtonPosition: 'end',
                    pageIconSize: 12,
                    pageIconColor: textColor,
                    pageIconInactiveColor: 'rgba(128,128,128,.4)',
                    pageTextStyle: { color: textColor },
                    textStyle: { color: textColor },
                },
                series: [{
                    type: 'pie',
                    radius: ['40%', '68%'],
                    center: ['50%', '44%'],
                    color: CHART_COLORS,
                    avoidLabelOverlap: true,
                    itemStyle: { borderRadius: 3, borderColor: 'rgba(255,255,255,.35)', borderWidth: 2 },
                    label: {
                        color: textColor,
                        formatter: function (params) {
                            return params.name + ' - ' + params.value;
                        },
                    },
                    labelLine: { showAbove: true },
                    data: slices.map(function (slice) {
                        return { name: slice.label, value: slice.count, share: slice.share };
                    }),
                }],
            });

            // Cliquer sur une part ouvre les cartes correspondantes.
            var template = target.dataset.veilleChartUrl || '';
            var level = target.dataset.veilleChartLevel || '';
            var otherLabel = target.dataset.veilleChartOther || '';
            var otherValue = target.dataset.veilleChartOtherValue || '';

            if (template !== '' && level !== '') {
                target.style.cursor = 'pointer';
                chart.on('click', function (params) {
                    var value = params.name === otherLabel ? otherValue : level + ':' + params.name;
                    window.location.href = template.replace('__TECH__', encodeURIComponent(value));
                });
            }

            window.addEventListener('resize', function () {
                chart.resize();
            });
        });
    }

    /**
     * Met à jour un bouton de bascule et sa carte selon le nouvel état.
     *
     * Les deux états du bouton (icône, infobulle, classe) sont décrits par
     * ses attributs `data-veille-*` : aucun libellé n'est écrit ici.
     *
     * @param {HTMLFormElement}   form
     * @param {HTMLButtonElement} button
     * @param {boolean}           state  true = en favori / lue
     */
    function applyToggle(form, button, state) {
        var data = button.dataset;
        var icon = button.querySelector('i.ti');

        if (icon !== null) {
            icon.classList.remove(data.veilleOnIcon, data.veilleOffIcon);
            icon.classList.add(state ? data.veilleOnIcon : data.veilleOffIcon);
        }
        if (data.veilleOnClass) {
            button.classList.toggle(data.veilleOnClass, state);
        }
        button.title = state ? data.veilleOnTitle : data.veilleOffTitle;
        button.setAttribute('aria-pressed', state ? 'true' : 'false');

        var item = form.closest('[data-veille-item]');
        var container = form.closest('[data-veille-items]');
        if (item === null || container === null) {
            return;
        }

        var type = form.dataset.veilleToggle;

        if (type === 'read') {
            var target = item.querySelector('[data-veille-read-target]') || item;
            if (container.dataset.veilleUnreadClass) {
                target.classList.toggle(container.dataset.veilleUnreadClass, !state);
            }
            if (container.dataset.veilleReadClass) {
                target.classList.toggle(container.dataset.veilleReadClass, state);
            }
        }

        // L'onglet « Favoris » et le filtre « non lues » ne montrent plus la
        // carte après la bascule : elle disparaît, comme elle l'aurait fait
        // au rechargement.
        if ((type === 'favorite' && !state && container.hasAttribute('data-veille-drop-unfavorited'))
            || (type === 'read' && state && container.hasAttribute('data-veille-drop-read'))) {
            item.remove();
        }
    }

    /**
     * Repli : envoi classique du formulaire, avec rechargement de la page.
     *
     * `form.submit()` n'emporte pas le bouton cliqué : son nom est donc
     * ajouté en champ caché.
     *
     * @param {HTMLFormElement}   form
     * @param {HTMLButtonElement} button
     */
    function submitClassic(form, button) {
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = button.name;
        field.value = button.value;
        form.appendChild(field);
        form.submit();
    }

    /**
     * Bascules favori et lu / non lu sans recharger la page.
     *
     * Recharger l'observatoire ramène en haut du tableau : on perd l'endroit
     * où l'on était. La bascule part donc en AJAX - GLPI attend alors le
     * jeton CSRF dans l'en-tête `X-Glpi-Csrf-Token`, et le conserve, ce qui
     * permet de rebasculer la même carte sans recharger.
     *
     * Le serveur répond par le nouvel état ; c'est lui, et non une simple
     * inversion locale, qui décide de l'affichage.
     *
     * En cas d'échec avant traitement (droit, jeton, réseau), le formulaire
     * repart de façon classique : GLPI affiche alors son message d'erreur.
     * Une réponse acceptée mais illisible fait recharger la page : la
     * bascule a pu avoir lieu, la renvoyer l'annulerait.
     *
     * Sans JavaScript, les formulaires fonctionnent comme avant.
     *
     * @param {ParentNode} root
     */
    function initToggles(root) {
        if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
            return;
        }

        root.querySelectorAll('form[data-veille-toggle]').forEach(function (form) {
            if (form.dataset.veilleToggleReady === '1') {
                return;
            }
            form.dataset.veilleToggleReady = '1';

            form.addEventListener('submit', function (event) {
                var button = form.querySelector('button[type="submit"][name]');
                if (button === null) {
                    return;
                }

                event.preventDefault();

                if (button.getAttribute('aria-busy') === 'true') {
                    return;
                }
                button.setAttribute('aria-busy', 'true');

                var token = form.querySelector('input[name="_glpi_csrf_token"]');
                var body = new FormData(form);
                body.append(button.name, button.value);

                window.fetch(form.action, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Glpi-Csrf-Token': token === null ? '' : token.value
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        button.removeAttribute('aria-busy');
                        submitClassic(form, button);
                        return;
                    }

                    return response.json().then(function (payload) {
                        if (payload === null || payload.ok !== true) {
                            throw new Error('invalid payload');
                        }

                        button.removeAttribute('aria-busy');
                        applyToggle(form, button, payload.state === true);

                        if (payload.message && typeof window.glpi_toast_info === 'function') {
                            window.glpi_toast_info(payload.message);
                        }
                    }).catch(function () {
                        window.location.reload();
                    });
                }, function () {
                    button.removeAttribute('aria-busy');
                    submitClassic(form, button);
                });
            });
        });
    }

    /**
     * @param {ParentNode} root
     */
    function boot(root) {
        autoSubmitFilters(root);
        initSelectConfirms(root);
        initListFilters(root);
        initSortableTables(root);
        initWeightSliders(root);
        initColorPairs(root);
        initIconPickers(root);
        initAiComposers(root);
        initCharts(root);
        initToggles(root);
    }

    function start() {
        boot(document);

        // Les contenus chargés en Ajax (onglets GLPI, fenêtres modales)
        // apparaissent après le chargement initial.
        if (typeof window.MutationObserver !== 'function') {
            return;
        }

        var pending = null;
        var schedule = function () {
            if (pending !== null) {
                return;
            }
            pending = window.setTimeout(function () {
                pending = null;
                boot(document);
            }, 50);
        };

        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0) {
                    schedule();
                    return;
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
