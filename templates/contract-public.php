<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

global $post;

$contract_id = $post->ID;

$required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
$given_token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';


$valid = ( ! empty( $required_token ) && hash_equals( $required_token, $given_token ) );

$status        = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
$is_signed     = ( $status === 'signed' );
$signed_at     = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_AT, true );
$signed_ip     = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_IP, true );
$signature_url = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
?>

<?php
$err = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : '';
?>

<?php if ( $valid && $err === 'nonce' ) : ?>
    <div style="padding:10px;border:1px solid #d63638;background:#fff5f5;margin:15px 0;">
        頁面逾時或被快取影響，請重新整理後再簽名。
    </div>
<?php endif; ?>


<div class="woc-contract-wrap woc-contract-wrap-print">
    <?php if ( ! $valid ) : ?>

        <h1 style="text-align:center;font-size:28px;margin-bottom:20px;">合約連結無效</h1>
        <p style="text-align:center;">此合約連結已失效或參數有誤，請聯絡提供合約的窗口。</p>

    <?php else : ?>

        <?php
            // 先擋密碼（且保留 ?t=...）
            if ( post_password_required( $post ) ) {

                $action   = esc_url( site_url( 'wp-login.php?action=postpass', 'login_post' ) );
                $redirect = esc_url( add_query_arg( null, null ) );

                echo '<form class="post-password-form" action="' . $action . '" method="post">';
                echo '<p>此內容受密碼保護。若要查看此內容，請在下方輸入密碼。</p>';
                echo '<p><label>密碼 <input name="post_password" type="password" size="20" /></label> ';
                echo '<input type="submit" name="Submit" value="送出" /></p>';
                echo '<input type="hidden" name="redirect_to" value="' . $redirect . '">';
                echo '</form>';

                get_footer();
                exit;
            }
        ?>


        <!-- <h1 style="text-align:center;font-size:28px;margin-bottom:30px;">
            <?php //echo esc_html( get_the_title( $contract_id ) ); ?>
        </h1> -->

        <div class="woc-contract-content">
            <?php
            $content = apply_filters( 'the_content', $post->post_content );

            // 套用全站合約變數（若 function 存在）
            if ( function_exists( 'woc_replace_contract_vars' ) ) {
                $content = woc_replace_contract_vars( $content );
            }

            echo $content;
            ?>
        </div>

        <div class="woc-contract-signed">

            <?php if ( $is_signed ) : ?>

                <h2 class="signUsingTitle no-print"><span>簽署資訊</span></h2>

                <?php if ( $signature_url ) : ?>
                    <p class="no-print">簽名：</p>
                    <div class="woc-signature-image-box">
                        <img src="<?php echo esc_url( $signature_url ); ?>" alt="Signature" class="woc-signature-image">
                    </div>
                <?php endif; ?>

                <div class="woc-signature-info">
                    <?php if ( $signed_at ) : ?>
                        <p>已簽約時間：<?php echo esc_html( $signed_at ); ?></p>
                    <?php endif; ?>

                    <?php if ( $signed_ip ) : ?>
                        <p>簽署 IP：<?php echo esc_html( $signed_ip ); ?></p>
                    <?php endif; ?>
                    <p class="no-print" style="margin-bottom:15px;">此合約已完成簽署，內容僅供檢視與列印。</p>
                </div>
                

                

                <div class="woc-button-warp no-print">
                    <button type="button" id="woc-print" onclick="window.print();">列印合約</button>
                </div>

                <?php else : ?>

                <h2 class="signUsingTitle"><span>簽　名</span></h2>
                <p class="signUsing">請使用手寫筆或滑鼠或手指簽名以授權此合約。通過電子簽名此文檔，表示您同意上面建立的條款。文檔簽名後，您可以列印保存。</p>

                <div>
                    <!-- 拿掉固定 width/height，交給 JS 依容器寬度設定 -->
                    <canvas id="woc-signature-pad"></canvas>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                    id="woc-sign-form" style="margin-top:15px;">

                    <input type="hidden" name="action" value="woc_sign_contract">
                    <input type="hidden" name="woc_contract_id" value="<?php echo esc_attr( $contract_id ); ?>">
                    <input type="hidden" name="woc_token" value="<?php echo esc_attr( $given_token ); ?>">
                    <input type="hidden" name="woc_signature_data" id="woc_signature_data" value="">
                    <?php wp_nonce_field( 'woc_sign_contract_' . $contract_id, 'woc_sign_nonce' ); ?>

                    <div class="woc-button-warp">
                        <button type="button" id="woc-clear-signature">清除簽名</button>
                        <button type="submit" id="woc-submit-signature">送出簽名</button>
                    </div>
                    
                </form>

                <!-- <p style="margin-top:10px;font-size:12px;color:#666;">
                    送出後合約內容將鎖定，如需修改請聯絡承辦人員重新建立合約。
                </p> -->

            <?php endif; ?>
            
        </div>
        

    <?php endif; ?>
</div>
<p class="woc-contract-copyright">技術支援 線上合約</p>

<script>
(function () {
    var canvas = document.getElementById('woc-signature-pad');
    if (!canvas) return;

    var ctx       = canvas.getContext('2d');
    var drawing   = false;
    var hasDrawn  = false;
    var scaleX = 1;

    // 固定輸出解析度（檔案一律 820px 寬）
    var BASE_WIDTH  = 820;
    var RATIO_DESKTOP = 1.5 / 3; //桌機比例
    var RATIO_MOBILE  = 3 / 2.5; // 手機比例

    function setupCanvasSize() {
    var cssWidth = canvas.clientWidth || BASE_WIDTH;

    // ✅ 依螢幕寬度切比例（你自己調斷點）
    var ratio = window.matchMedia('(max-width: 580px)').matches
        ? RATIO_MOBILE
        : RATIO_DESKTOP;

    var BASE_HEIGHT = Math.round(BASE_WIDTH * ratio);

    canvas.width  = BASE_WIDTH;
    canvas.height = BASE_HEIGHT;

    scaleX = BASE_WIDTH / cssWidth;

    var cssHeight = BASE_HEIGHT / scaleX;
    canvas.style.height = cssHeight + 'px';

    // 線寬邏輯照舊…
    var targetCssStroke;
    if ( scaleX <= 1.2 ) targetCssStroke = 4;
    else targetCssStroke = 2.5;

    ctx.lineWidth  = targetCssStroke * scaleX;
    ctx.lineCap    = 'round';
    ctx.lineJoin   = 'round';
    ctx.strokeStyle = '#000';
}


    setupCanvasSize();
    window.addEventListener('resize', setupCanvasSize);

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var clientX, clientY;

        if (e.touches && e.touches.length) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        return {
            // CSS 座標 * scaleX → canvas 解析度座標
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top)  * scaleX
        };
    }

    function startDraw(e) {
        e.preventDefault();
        drawing  = true;
        hasDrawn = true;

        var p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function draw(e) {
        if (!drawing) return;
        e.preventDefault();

        var p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }

    function endDraw(e) {
        if (!drawing) return;
        e.preventDefault();
        drawing = false;
    }

    // 滑鼠事件
    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);

    // 觸控事件
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove',  draw,      { passive: false });
    canvas.addEventListener('touchend',   endDraw);

    var clearBtn  = document.getElementById('woc-clear-signature');
    var form      = document.getElementById('woc-sign-form');
    var hiddenInp = document.getElementById('woc_signature_data');

    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasDrawn = false;
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!hasDrawn) {
                e.preventDefault();
                alert('請先在簽名欄位簽名。');
                return;
            }
            hiddenInp.value = canvas.toDataURL('image/png');
        });
    }
})();



// document.getElementById('woc-print')?.addEventListener('click', function () {
//   const oldTitle = document.title;

//   // 你要的檔名（不要加 .pdf，讓瀏覽器自己處理）
//   document.title = '委託契約書_' + (new Date().toISOString().slice(0,10));

//   window.print();

//   // 印完恢復
//   document.title = oldTitle;
// });
</script>



<?php
get_footer();
