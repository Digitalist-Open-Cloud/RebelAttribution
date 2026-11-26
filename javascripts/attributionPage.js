/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

(function () {
    var REMEMBERED = 'rebelAttributionGoal';
    var ROLES = ['nb_introduced', 'nb_assisted', 'nb_closed', 'nb_only'];
    var ARROW = ' → ';
    var TOP_JOURNEYS = 10;
    var TOP_NAMES = 10;

    function el(tag, className, text) {
        var element = document.createElement(tag);
        element.className = className;
        if (text !== undefined) {
            element.textContent = text;
        }
        return element;
    }

    function number(value) {
        return window.CoreHome.NumberFormatter.formatNumber(value, 1);
    }

    function conversions(root, count) {
        var texts = root.rebelAttribution.texts;
        return count === 1 ? texts.oneConversion : texts.conversions.replace('%s', number(count));
    }

    // Conversions, and the revenue when the goal has any.
    function amount(root, count, revenue) {
        var text = conversions(root, count);
        if (revenue > 0) {
            text += ' · ' + window.CoreHome.NumberFormatter.formatCurrency(revenue, root.getAttribute('data-currency'), 2);
        }
        return text;
    }

    function bar(className, share) {
        var element = el('span', className);
        element.style.width = (100 * share).toFixed(1) + '%';
        return element;
    }

    // Asks the API about the picked goal. An answer that comes after another goal or model was picked is dropped.
    function ask(root, method, parameters, callback) {
        var state = root.rebelAttribution;
        var asked = state.asked;
        var ajax = new window.ajaxHelper();
        parameters.module = 'API';
        parameters.method = 'RebelAttribution.' + method;
        parameters.format = 'json';
        parameters.idGoal = root.querySelector('.rebelAttributionGoal').value;
        parameters.filter_limit = parameters.filter_limit || -1;
        ajax.addParams(parameters, 'get');
        ajax.setFormat('json');
        ajax.setCallback(function (rows) {
            if (state.asked === asked && rows && rows.result !== 'error') {
                callback(rows);
            }
        });
        ajax.send();
    }

    function hint(box, text) {
        box.textContent = '';
        box.appendChild(el('p', 'rebelAttributionHint', text));
    }

    // A channel, or a name of one, with what it gets today and with the model.
    function channelLine(root, row, max, className) {
        var line = el('div', className);
        line.appendChild(el('strong', 'rebelAttributionChannelName', row.label));

        var bars = el('div', 'rebelAttributionBars');
        [['rebelAttributionLast', row.nb_conversions_last, row.revenue_last], ['rebelAttributionPicked', row.nb_conversions, row.revenue]].forEach(function (one) {
            var wrap = el('div', 'rebelAttributionBarRow');
            wrap.appendChild(bar('rebelAttributionBar ' + one[0], one[1] / max));
            wrap.appendChild(el('span', 'rebelAttributionBarText', amount(root, one[1], one[2])));
            bars.appendChild(wrap);
        });
        line.appendChild(bars);

        var change = row.nb_conversions_last ? Math.round(100 * (row.nb_conversions - row.nb_conversions_last) / row.nb_conversions_last) : null;
        var changed = el('span', 'rebelAttributionChange', change === null ? '' : (change > 0 ? '+' : change < 0 ? '−' : '±') + Math.abs(change) + '%');
        if (change === null) {
            changed.title = root.rebelAttribution.texts.notCredited;
            changed.textContent = '+';
        }
        changed.classList.add(change === 0 ? 'rebelAttributionSame' : change === null || change > 0 ? 'rebelAttributionUp' : 'rebelAttributionDown');
        line.appendChild(changed);

        return line;
    }

    function drawCompare(root, rows) {
        var state = root.rebelAttribution;
        var box = root.querySelector('.rebelAttributionCompare');
        var max = Math.max.apply(null, rows.map(function (row) { return Math.max(row.nb_conversions, row.nb_conversions_last); }));
        box.textContent = '';

        rows.forEach(function (row) {
            var line = channelLine(root, row, max, 'rebelAttributionChannel');
            box.appendChild(line);
            if (!row.idsubdatatable) {
                return;
            }

            // A channel with names opens to show them. It stays open when another model is picked.
            var names = el('div', 'rebelAttributionNames');
            box.appendChild(names);
            var show = function () {
                var isOpen = !!state.open[row.label];
                line.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                names.textContent = '';
                if (!isOpen) {
                    return;
                }
                var model = root.querySelector('.rebelAttributionModel[aria-pressed="true"]').getAttribute('data-model');
                ask(root, 'getChannels', { model: model, idSubtable: row.idsubdatatable, filter_limit: TOP_NAMES }, function (nameRows) {
                    names.textContent = '';
                    nameRows.forEach(function (nameRow) {
                        names.appendChild(channelLine(root, nameRow, max, 'rebelAttributionChannel rebelAttributionName'));
                    });
                });
            };
            var toggle = function () {
                state.open[row.label] = !state.open[row.label];
                show();
            };
            line.classList.add('rebelAttributionHasNames');
            line.setAttribute('role', 'button');
            line.setAttribute('tabindex', '0');
            line.title = state.texts.showNames;
            line.addEventListener('click', toggle);
            line.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
            show();
        });
    }

    function drawRoles(root, rows) {
        var texts = root.rebelAttribution.texts;
        var box = root.querySelector('.rebelAttributionRoles');
        var total = function (row) { return ROLES.reduce(function (sum, role) { return sum + row[role]; }, 0); };
        var max = Math.max.apply(null, rows.map(total));
        box.textContent = '';

        rows.slice().sort(function (a, b) { return total(b) - total(a); }).forEach(function (row) {
            var line = el('div', 'rebelAttributionRole');
            var name = el('div', 'rebelAttributionRoleName');
            name.appendChild(el('strong', '', row.label));
            name.appendChild(el('span', 'rebelAttributionRoleCount', conversions(root, row.nb_touched)));
            line.appendChild(name);

            var stack = el('div', 'rebelAttributionStack');
            stack.style.width = Math.max(4, 100 * total(row) / max).toFixed(1) + '%';
            ROLES.forEach(function (role) {
                if (!row[role]) {
                    return;
                }
                var share = row[role] / total(row);
                var part = el('span', 'rebelAttribution_' + role, share > 0.12 ? Math.round(100 * share) + '%' : '');
                part.style.flexGrow = row[role];
                part.title = texts.roles[role] + ': ' + conversions(root, row[role]);
                stack.appendChild(part);
            });
            var wrap = el('div', 'rebelAttributionStackWrap');
            wrap.appendChild(stack);
            line.appendChild(wrap);
            box.appendChild(line);
        });
    }

    function loadChannels(root) {
        var picked = root.querySelector('.rebelAttributionModel[aria-pressed="true"]');
        root.querySelector('.rebelAttributionModelHelp').textContent = picked.getAttribute('data-help');

        ask(root, 'getChannels', { model: picked.getAttribute('data-model') }, function (rows) {
            if (!rows.length) {
                hint(root.querySelector('.rebelAttributionCompare'), root.rebelAttribution.texts.noData);
                root.querySelector('.rebelAttributionRoles').textContent = '';
                return;
            }
            drawCompare(root, rows);
            drawRoles(root, rows);
        });
    }

    function drawJourneys(root, rows) {
        var texts = root.rebelAttribution.texts;
        var box = root.querySelector('.rebelAttributionJourneys');
        var select = root.querySelector('.rebelAttributionGoal');
        var goal = select.options[select.selectedIndex].textContent;
        if (!rows.length) {
            hint(box, texts.noData);
            return;
        }
        box.textContent = '';

        rows.forEach(function (row) {
            var line = el('div', 'rebelAttributionJourney');
            var chips = el('div', 'rebelAttributionChips');
            // Visits in a row from one channel are one chip.
            var steps = [];
            String(row.label).split(ARROW).forEach(function (channel) {
                var previous = steps[steps.length - 1];
                if (previous && previous[0] === channel) {
                    previous[1]++;
                } else {
                    steps.push([channel, 1]);
                }
            });
            steps.forEach(function (step) {
                var chip = el('span', 'rebelAttributionChip', step[0]);
                if (step[1] > 1) {
                    var times = el('span', 'rebelAttributionTimes', ' ×' + step[1]);
                    times.title = texts.times.replace('%s', step[1]);
                    chip.appendChild(times);
                }
                chips.appendChild(chip);
                chips.appendChild(el('span', 'rebelAttributionArrow', '→')).setAttribute('aria-hidden', 'true');
            });
            chips.appendChild(el('span', 'rebelAttributionChip rebelAttributionChipGoal', goal));
            line.appendChild(chips);

            var meter = el('div', 'rebelAttributionMeter');
            meter.appendChild(bar('rebelAttributionBar rebelAttributionPicked', row.nb_conversions / rows[0].nb_conversions));
            line.appendChild(meter);
            line.appendChild(el('div', 'rebelAttributionCount', amount(root, row.nb_conversions, row.revenue)));
            box.appendChild(line);
        });
    }

    function drawGroups(root, selector, rows) {
        var box = root.querySelector(selector);
        var max = Math.max.apply(null, rows.map(function (row) { return row.nb_conversions; }));
        box.textContent = '';
        if (!max) {
            return;
        }

        rows.forEach(function (row) {
            var line = el('div', 'rebelAttributionGroup');
            line.appendChild(el('span', 'rebelAttributionGroupName', row.label));
            var meter = el('div', 'rebelAttributionMeter');
            meter.appendChild(bar('rebelAttributionBar rebelAttributionPicked', row.nb_conversions / max));
            line.appendChild(meter);
            line.appendChild(el('span', 'rebelAttributionGroupCount', number(row.nb_conversions)));
            box.appendChild(line);
        });
    }

    function load(root) {
        root.rebelAttribution.asked++;

        root.querySelector('.rebelAttributionGenerated').textContent = '';
        ask(root, 'getReportGenerated', {}, function (generated) {
            root.querySelector('.rebelAttributionGenerated').textContent = generated.value || '';
        });

        if (root.getAttribute('data-page') === 'RebelAttribution_Channels') {
            loadChannels(root);
            return;
        }

        hint(root.querySelector('.rebelAttributionJourneys'), root.rebelAttribution.texts.loading);
        ask(root, 'getJourneys', { filter_limit: TOP_JOURNEYS }, function (rows) { drawJourneys(root, rows); });
        ask(root, 'getVisitsToConversion', {}, function (rows) { drawGroups(root, '.rebelAttributionVisits', rows); });
        ask(root, 'getDaysToConversion', {}, function (rows) { drawGroups(root, '.rebelAttributionDays', rows); });
    }

    function start(root) {
        var select = root.querySelector('.rebelAttributionGoal');
        if (!select) {
            return;
        }
        root.rebelAttribution = { texts: JSON.parse(root.getAttribute('data-texts')), asked: 0, open: {} };

        // The goal that was picked on the other page is picked here too.
        try {
            var remembered = window.sessionStorage.getItem(REMEMBERED);
            if (remembered !== null && select.querySelector('option[value="' + parseInt(remembered, 10) + '"]')) {
                select.value = parseInt(remembered, 10);
            }
        } catch (e) {
            // No storage in this browser.
        }

        select.addEventListener('change', function () {
            try {
                window.sessionStorage.setItem(REMEMBERED, select.value);
            } catch (e) {
                // No storage in this browser.
            }
            load(root);
        });
        root.querySelectorAll('.rebelAttributionModel').forEach(function (button) {
            button.addEventListener('click', function () {
                root.querySelectorAll('.rebelAttributionModel').forEach(function (other) {
                    other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                });
                load(root);
            });
        });

        load(root);
    }

    // Matomo puts the widget in the page after it has loaded, and again when the date changes.
    function startAll() {
        document.querySelectorAll('.rebelAttribution:not([data-started])').forEach(function (root) {
            root.setAttribute('data-started', '1');
            start(root);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        startAll();
        new MutationObserver(startAll).observe(document.body, { childList: true, subtree: true });
    });
})();
