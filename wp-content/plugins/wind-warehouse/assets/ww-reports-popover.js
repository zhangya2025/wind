(function () {
    var CLOSE_DELAY = 150;
    function qs(el, selector) {
        return el ? el.querySelector(selector) : null;
    }

    function closeAll(popovers) {
        popovers.forEach(function (item) {
            if (item.panel) {
                item.panel.hidden = true;
            }
            if (item.container) {
                item.container.classList.remove('is-open');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var popoverContainers = Array.prototype.slice.call(document.querySelectorAll('.ww-popover'));
        if (!popoverContainers.length) {
            return;
        }

        var popovers = popoverContainers.map(function (container) {
            return {
                key: container.getAttribute('data-popover-key'),
                container: container,
                trigger: qs(container, '.ww-popover-trigger'),
                panel: qs(container, '.ww-popover-panel'),
                closeTimer: null,
            };
        });

        function scheduleClose(popover) {
            if (!popover) {
                return;
            }
            if (popover.closeTimer) {
                clearTimeout(popover.closeTimer);
            }
            popover.closeTimer = setTimeout(function () {
                closeAll(popovers);
            }, CLOSE_DELAY);
        }

        function cancelClose(popover) {
            if (popover && popover.closeTimer) {
                clearTimeout(popover.closeTimer);
                popover.closeTimer = null;
            }
        }

        function openPopover(targetKey) {
            popovers.forEach(function (item) {
                var shouldOpen = item.key === targetKey;
                if (item.panel) {
                    item.panel.hidden = !shouldOpen;
                }
                if (item.container) {
                    item.container.classList.toggle('is-open', shouldOpen);
                }
                cancelClose(item);
            });
        }

        popovers.forEach(function (popover) {
            if (!popover.trigger || !popover.panel) {
                return;
            }

            popover.trigger.addEventListener('click', function () {
                var isOpen = !popover.panel.hidden;
                if (isOpen) {
                    closeAll(popovers);
                } else {
                    openPopover(popover.key);
                }
            });

            [popover.container, popover.panel].forEach(function (el) {
                if (!el) {
                    return;
                }
                el.addEventListener('mouseenter', function () {
                    cancelClose(popover);
                });
                el.addEventListener('mouseleave', function () {
                    scheduleClose(popover);
                });
            });
        });

        document.addEventListener('mousedown', function (evt) {
            var target = evt.target;
            var clickedInside = popovers.some(function (popover) {
                return popover.container && popover.container.contains(target);
            });
            if (!clickedInside) {
                closeAll(popovers);
            }
        });

        document.addEventListener('keydown', function (evt) {
            if (evt.key === 'Escape' || evt.key === 'Esc') {
                closeAll(popovers);
            }
        });
    });
})();
