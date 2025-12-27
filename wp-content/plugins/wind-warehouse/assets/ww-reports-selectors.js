(function () {
    function qs(el, selector) {
        return el.querySelector(selector);
    }

    function qsa(el, selector) {
        return Array.prototype.slice.call(el.querySelectorAll(selector));
    }

    function uniqueIds(list, limit) {
        var map = {};
        var result = [];
        for (var i = 0; i < list.length; i++) {
            var id = parseInt(list[i], 10);
            if (!id || map[id]) {
                continue;
            }
            map[id] = true;
            result.push(id);
            if (result.length >= limit) {
                break;
            }
        }
        return result;
    }

    function renderSelector(el, options, selectedIds, syncCallback) {
        var type = el.getAttribute('data-type');
        var inputName = el.getAttribute('data-name');
        var baseLabel = el.getAttribute('data-label') || '';
        var searchInput = qs(el, '.ww-ms-search');
        var resultsEl = qs(el, '.ww-ms-results');
        var selectedEl = qs(el, '.ww-ms-selected');
        var hiddenEl = qs(el, '.ww-ms-hidden');
        var countEl = qs(el, '.ww-ms-count');
        var actionButtons = qsa(el, '[data-action]');

        var selectedMap = {};
        var countTarget = el.getAttribute('data-count-target');
        uniqueIds(selectedIds, 500).forEach(function (id) {
            selectedMap[id] = true;
        });

        function updateCount() {
            var currentCount = Object.keys(selectedMap).length;
            var countText = '（已选' + currentCount + '）';
            var triggerText = countText;

            if (countEl) {
                countEl.textContent = countText;
            }

            if (countTarget) {
                var popoverContainer = el.closest('.ww-popover');
                var popoverLabel = popoverContainer ? (popoverContainer.getAttribute('data-label') || '') : '';
                var popoverCounts = document.querySelectorAll('[data-popover-count="' + countTarget + '"]');

                if (popoverLabel) {
                    triggerText = popoverLabel + countText;
                }

                Array.prototype.slice.call(popoverCounts).forEach(function (node) {
                    node.textContent = triggerText;
                });
            }
        }

        function syncHidden() {
            if (!hiddenEl) {
                return;
            }
            hiddenEl.innerHTML = '';
            Object.keys(selectedMap).forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = inputName;
                input.value = id;
                hiddenEl.appendChild(input);
            });
            if (typeof syncCallback === 'function') {
                syncCallback(type, inputName, selectedMap);
            }
        }

        function renderChips() {
            if (!selectedEl) {
                return;
            }
            selectedEl.innerHTML = '';
            Object.keys(selectedMap).forEach(function (id) {
                var parsedId = parseInt(id, 10);
                var label = id;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].id === parsedId) {
                        label = options[i].label;
                        break;
                    }
                }
                var chip = document.createElement('span');
                chip.className = 'ww-chip';
                chip.textContent = label;

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '×';
                removeBtn.addEventListener('click', function () {
                    delete selectedMap[id];
                    renderChips();
                    syncHidden();
                    updateCount();
                    renderResults(searchInput ? searchInput.value : '');
                });
                chip.appendChild(removeBtn);
                selectedEl.appendChild(chip);
            });
        }

        function renderResults(query) {
            if (!resultsEl) {
                return;
            }
            resultsEl.innerHTML = '';
            var normalized = (query || '').toLowerCase();
            var shown = 0;
            for (var i = 0; i < options.length; i++) {
                if (normalized && options[i].search.indexOf(normalized) === -1) {
                    continue;
                }
                shown++;
                if (shown > 50) {
                    break;
                }
                var optionEl = document.createElement('div');
                optionEl.className = 'ww-option';
                optionEl.textContent = options[i].label;
                optionEl.setAttribute('data-id', options[i].id);
                if (selectedMap[options[i].id]) {
                    optionEl.className += ' selected';
                }
                optionEl.addEventListener('click', function (evt) {
                    var id = parseInt(evt.currentTarget.getAttribute('data-id'), 10);
                    if (!id) {
                        return;
                    }
                    if (!selectedMap[id] && Object.keys(selectedMap).length >= 500) {
                        return;
                    }
                    selectedMap[id] = true;
                    renderChips();
                    syncHidden();
                    updateCount();
                    renderResults(searchInput ? searchInput.value : '');
                });
                resultsEl.appendChild(optionEl);
            }
            if (shown === 0) {
                var empty = document.createElement('div');
                empty.className = 'ww-option';
                empty.textContent = 'No results';
                resultsEl.appendChild(empty);
            }
        }

        function selectAll() {
            for (var i = 0; i < options.length; i++) {
                selectedMap[options[i].id] = true;
                if (Object.keys(selectedMap).length >= 500) {
                    break;
                }
            }
            renderChips();
            syncHidden();
            updateCount();
            renderResults(searchInput ? searchInput.value : '');
        }

        function selectNone() {
            selectedMap = {};
            renderChips();
            syncHidden();
            updateCount();
            renderResults(searchInput ? searchInput.value : '');
        }

        if (searchInput) {
            searchInput.addEventListener('input', function (evt) {
                renderResults(evt.target.value);
            });
        }

        if (resultsEl) {
            resultsEl.addEventListener('scroll', function () {
                // placeholder for potential virtualization
            });
        }

        actionButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                if (action === 'all') {
                    selectAll();
                } else if (action === 'none') {
                    selectNone();
                }
            });
        });

        renderChips();
        syncHidden();
        renderResults('');
        updateCount();
    }

    function initDateControls() {
        var state = window.WW_REPORTS_STATE || {};
        var defaults = state.rangeDefaults || {};
        var rangeEl = document.getElementById('ww-report-range');
        var startEl = document.getElementById('ww-report-start');
        var endEl = document.getElementById('ww-report-end');

        function lockDates(rangeVal) {
            var isCustom = rangeVal === 'custom';
            if (startEl) {
                startEl.readOnly = !isCustom;
            }
            if (endEl) {
                endEl.readOnly = !isCustom;
            }
            if (!isCustom && defaults[rangeVal]) {
                if (startEl) {
                    startEl.value = defaults[rangeVal].start;
                }
                if (endEl) {
                    endEl.value = defaults[rangeVal].end;
                }
            }
        }

        if (rangeEl) {
            rangeEl.addEventListener('change', function (evt) {
                lockDates(evt.target.value);
            });
        }

        [startEl, endEl].forEach(function (el) {
            if (!el) {
                return;
            }
            el.addEventListener('input', function () {
                if (rangeEl) {
                    rangeEl.value = 'custom';
                }
                if (startEl) {
                    startEl.readOnly = false;
                }
                if (endEl) {
                    endEl.readOnly = false;
                }
            });
        });

        lockDates(state.currentRange || (rangeEl ? rangeEl.value : '1m'));
    }

    document.addEventListener('DOMContentLoaded', function () {
        var data = window.WW_REPORTS_DATA || {};
        var state = window.WW_REPORTS_STATE || { selected: {} };
        var selected = state.selected || {};

        var dealerSelected = selected.dealers || [];
        var dealerHidden = document.querySelectorAll('.ww-ms[data-type="dealers"] .ww-ms-hidden input');
        dealerHidden.forEach(function (input) { dealerSelected.push(input.value); });

        var skuSelected = selected.skus || [];
        var skuHidden = document.querySelectorAll('.ww-ms[data-type="skus"] .ww-ms-hidden input');
        skuHidden.forEach(function (input) { skuSelected.push(input.value); });

        var exportForm = document.getElementById('ww-report-export');
        function syncExport(type, inputName, map) {
            if (!exportForm) {
                return;
            }
            var target = exportForm.querySelector('.ww-export-' + type);
            if (!target) {
                return;
            }
            target.innerHTML = '';
            Object.keys(map).forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = inputName;
                input.value = id;
                target.appendChild(input);
            });
        }

        var dealerEls = document.querySelectorAll('.ww-ms[data-type="dealers"]');
        dealerEls.forEach(function (el) {
            renderSelector(el, data.dealers || [], dealerSelected, syncExport);
        });

        var skuEls = document.querySelectorAll('.ww-ms[data-type="skus"]');
        skuEls.forEach(function (el) {
            renderSelector(el, data.skus || [], skuSelected, syncExport);
        });

        initDateControls();
    });
})();
