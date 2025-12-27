(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var filterInput = document.getElementById('ww-sku-filter');
        var select = document.getElementById('ww-sku-select');

        if (!filterInput || !select) {
            return;
        }

        var originalOptions = Array.prototype.map.call(select.options, function (opt) {
            return {
                value: opt.value,
                text: opt.textContent || '',
                disabled: opt.disabled,
            };
        });

        function renderOptions(keyword) {
            var currentValue = select.value;
            var normalized = keyword.trim().toLowerCase();

            var filtered = normalized
                ? originalOptions.filter(function (opt) {
                      return (
                          opt.text.toLowerCase().indexOf(normalized) !== -1 ||
                          opt.value.toLowerCase().indexOf(normalized) !== -1
                      );
                  })
                : originalOptions;

            var optionsToRender = filtered.length
                ? filtered
                : [
                      {
                          value: '',
                          text: '无匹配 SKU',
                          disabled: true,
                      },
                  ];

            select.innerHTML = '';

            optionsToRender.forEach(function (opt) {
                var option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.text;
                if (opt.disabled) {
                    option.disabled = true;
                }
                if (opt.value === currentValue && !opt.disabled) {
                    option.selected = true;
                }
                select.appendChild(option);
            });

            if (!select.value && optionsToRender.length && !optionsToRender[0].disabled) {
                select.value = optionsToRender[0].value;
            }
        }

        filterInput.addEventListener('input', function (event) {
            renderOptions(event.target.value || '');
        });
    });
})();
