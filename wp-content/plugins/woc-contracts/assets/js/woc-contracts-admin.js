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


    // 複製網址
    $('#woc-copy-link-btn').on('click', function (e) {
        e.preventDefault();

        var link  = $(this).data('link');
        var $input = $('#woc-contract-link-url');

        function fallbackCopy() {
            if ($input.length) {
                $input.trigger('focus').trigger('select');
            }
            try {
                document.execCommand('copy');
                alert('連結已複製');
            } catch (err) {
                alert('請手動複製上方連結');
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(function () {
                alert('連結已複製');
            }).catch(function () {
                fallbackCopy();
            });
        } else {
            fallbackCopy();
        }
    });

    // 開啟連結
    $('#woc-open-link-btn').on('click', function (e) {
        e.preventDefault();

        var link = $(this).data('link');
        if (link) {
            window.open(link, '_blank');
        }
    });

    
    // ========= 已簽署時鎖住內容編輯器 =========
    if (window.wocContractsAdmin && wocContractsAdmin.is_signed) {

        // Classic Editor textarea – 鎖定內容
        var $textarea = $('#content');
        if ($textarea.length) {
            $textarea.prop('readonly', true);
        }

        // TinyMCE 編輯器（若有啟用）
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get('content');
            if (editor) {
                editor.setMode('readonly');
            }
        }

        // 給外層一個狀態 class，讓 CSS 控制樣式
        $('#postdivrich').addClass('is_signed');
    }

    

    

});
