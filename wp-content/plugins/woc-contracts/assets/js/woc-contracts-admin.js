jQuery(function ($) {

    function wocSetEditorContent(content) {
        // 1) Gutenberg 編輯器
        if (window.wp && wp.data && wp.data.dispatch) {
            try {
                wp.data.dispatch('core/editor').editPost({ content: content });
                return;
            } catch (e) {
                // 落到下面的 fallback
            }
        }

        // 2) 傳統編輯器（Classic Editor）
        var $textarea = $('#content');

        if ($textarea.length) {
            $textarea.val(content);
        }

        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('content');
            if (editor) {
                editor.setContent(content);
            }
        }
    }

    $('#woc-load-template-btn').on('click', function () {

        var templateId = $('#woc_template_id').val();
        var postId     = $(this).data('post-id');
        var nonce      = $(this).data('nonce');

        if (!templateId) {
            alert('請先選擇一個合約範本。');
            return;
        }

        if (!confirm('確認要以此範本內容覆蓋目前合約內容？')) {
            return;
        }

        $(this).prop('disabled', true).text('載入中…');

        $.post(ajaxurl, {
            action:      'woc_load_template',
            template_id: templateId,
            post_id:     postId,
            nonce:       nonce
        })
        .done(function (response) {
            if (response && response.success && response.data && response.data.content !== undefined) {
                wocSetEditorContent(response.data.content);
            } else {
                alert(response && response.data && response.data.message ? response.data.message : '載入失敗。');
            }
        })
        .fail(function () {
            alert('與伺服器溝通失敗，請稍後再試。');
        })
        .always(function () {
            $('#woc-load-template-btn').prop('disabled', false).text('載入範本內容');
        });
    });

});
