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


    // 複製簽署連結（不使用 execCommand）
    $(document).on('click', '#woc-copy-link-btn', function (e) {
        e.preventDefault();
    
        var $btn   = $(this);
        var link   = $btn.data('link') || '';
        var $input = $('#woc-contract-link-url');
    
        // UI 提示（沿用你想要的「已複製」概念，不一定要 alert）
        function flashBtn(text) {
        var old = $btn.text();
        $btn.text(text);
        setTimeout(function () { $btn.text(old); }, 800);
        }
    
        function manualCopyFallback() {
        // 只能做到「讓使用者更好複製」：選取文字 + 提示 Ctrl+C
        if ($input.length) {
            if (link && $input.val() !== link) $input.val(link);
            $input.trigger('focus').trigger('select');
            alert('已選取連結，請按 Ctrl+C（Mac：⌘C）複製');
        } else {
            // 連 input 都沒有的話，用 prompt 讓使用者手動複製
            window.prompt('請手動複製以下連結：', link);
        }
        }
    
        if (!link && $input.length) {
        link = $input.val() || '';
        }
        if (!link) return;
    
        // 先走 Clipboard API（成功才算真的複製）
        if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () {
            flashBtn('已複製');
            // 你若堅持 alert，也可以改回 alert('連結已複製');
        }).catch(function () {
            manualCopyFallback();
        });
        } else {
        manualCopyFallback();
        }
    });
  

    // 開啟連結
    // $('#woc-open-link-btn').on('click', function (e) {
    //     e.preventDefault();

    //     var link = $(this).data('link');
    //     if (link) {
    //         window.open(link, '_blank');
    //     }
    // });

    
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

    // ========= 按鈕點擊複製變數 =========

    $('#woc-vars-helper-box').on('click', '.woc-copy-var', function(e){
        e.preventDefault();
        
        var code = $(this).data('copy');
        if (!code) return;
        
        var done = () => {
            // 最簡單的回饋：改文字 800ms 再改回來（不額外做 toast 系統）
            var $btn = $(this);
            var old = $btn.text();
            $btn.text('已複製');
            setTimeout(() => $btn.text(old), 800);
        };
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(function () {
            window.prompt('請手動複製以下內容：', code);
            });
        } else {
            window.prompt('請手動複製以下內容：', code);
        }
        });
    

    

    

});
