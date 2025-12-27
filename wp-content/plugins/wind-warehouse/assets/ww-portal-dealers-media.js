(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.querySelector('.ww-dealers');

        if (!window.wp || !wp.media) {
            if (container) {
                var errorNotice = document.createElement('div');
                errorNotice.className = 'notice notice-error';
                var paragraph = document.createElement('p');
                paragraph.textContent = '媒体库不可用，请刷新页面并确认脚本已允许加载。';
                errorNotice.appendChild(paragraph);
                container.insertBefore(errorNotice, container.firstChild);
            }
            return;
        }

        initMediaSelector({
            inputId: 'ww_business_license_attachment_id',
            buttonId: 'ww_business_license_button',
            removeButtonId: 'ww_business_license_remove',
            previewId: 'ww_business_license_preview',
            title: '选择营业执照',
            buttonText: '使用此图片',
            previewAlt: '营业执照预览'
        });

        initMediaSelector({
            inputId: 'ww_authorization_letter_attachment_id',
            buttonId: 'ww_authorization_letter_button',
            removeButtonId: 'ww_authorization_letter_remove',
            previewId: 'ww_authorization_letter_preview',
            title: '选择授权书',
            buttonText: '使用此图片',
            previewAlt: '授权书预览'
        });
    });

    function initMediaSelector(config) {
        var input = document.getElementById(config.inputId);
        var button = document.getElementById(config.buttonId);
        var removeButton = document.getElementById(config.removeButtonId);
        var preview = document.getElementById(config.previewId);

        if (!input || !button || !removeButton || !preview) {
            return;
        }

        var frame;

        button.addEventListener('click', function () {
            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: config.title,
                button: { text: config.buttonText },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                input.value = attachment.id;

                var previewUrl = attachment.url;
                if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                    previewUrl = attachment.sizes.thumbnail.url;
                }

                preview.innerHTML = '<img src="' + previewUrl + '" alt="' + config.previewAlt + '" style="max-width:120px;height:auto;" />';
            });

            frame.open();
        });

        removeButton.addEventListener('click', function () {
            input.value = '';
            preview.innerHTML = '';
        });
    }
})();
