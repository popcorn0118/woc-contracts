<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

global $post;

$contract_id = $post->ID;

$required_token = get_post_meta( $contract_id, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
$given_token    = isset( $_GET['t'] ) ? sanitize_text_field( $_GET['t'] ) : '';

$valid = ( ! empty( $required_token ) && hash_equals( $required_token, $given_token ) );

$status        = get_post_meta( $contract_id, WOC_Contracts_CPT::META_STATUS, true );
$is_signed     = ( $status === 'signed' );
$signed_at     = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_AT, true );
$signed_ip     = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNED_IP, true );
$signature_url = get_post_meta( $contract_id, WOC_Contracts_CPT::META_SIGNATURE_IMAGE, true );
?>

<div class="woc-contract-wrap" style="max-width:900px;margin:40px auto;padding:40px;background:#fff;">
    <?php if ( ! $valid ) : ?>

        <h1 style="text-align:center;font-size:28px;margin-bottom:20px;">合約連結無效</h1>
        <p style="text-align:center;">此合約連結已失效或參數有誤，請聯絡提供合約的窗口。</p>

    <?php else : ?>

        <h1 style="text-align:center;font-size:28px;margin-bottom:30px;">
            <?php echo esc_html( get_the_title( $contract_id ) ); ?>
        </h1>

        <div class="woc-contract-content" style="font-size:14px;line-height:1.8;">
            <?php echo apply_filters( 'the_content', $post->post_content ); ?>
        </div>

        <?php if ( $is_signed ) : ?>

            <hr style="margin:40px 0;">

            <h2 style="font-size:20px;margin-bottom:15px;">簽署資訊</h2>

            <?php if ( $signature_url ) : ?>
                <p>簽名：</p>
                <div style="border:1px solid #ccc;display:inline-block;padding:10px 20px;margin-bottom:15px;">
                    <img src="<?php echo esc_url( $signature_url ); ?>" alt="Signature"
                         style="max-width:400px;height:auto;">
                </div>
            <?php endif; ?>

            <?php if ( $signed_at ) : ?>
                <p>已簽約時間：<?php echo esc_html( $signed_at ); ?></p>
            <?php endif; ?>

            <?php if ( $signed_ip ) : ?>
                <p>簽署 IP：<?php echo esc_html( $signed_ip ); ?></p>
            <?php endif; ?>

            <p style="margin-top:15px;">此合約已完成簽署，內容僅供檢視與列印。</p>

            <p style="margin-top:20px;">
                <button type="button" onclick="window.print();"
                        style="padding:8px 18px;border:1px solid #333;background:#333;color:#fff;cursor:pointer;">
                    列印合約
                </button>
            </p>

        <?php else : ?>

            <hr style="margin:40px 0 20px;">

            <h2 style="font-size:20px;margin-bottom:10px;">簽名</h2>
            <p style="margin-bottom:10px;">請使用滑鼠或手指在下方框內簽名，確認無誤後送出。</p>

            <div style="border:1px solid #ccc;padding:10px;display:inline-block;">
                <canvas id="woc-signature-pad" width="600" height="200"
                        style="border:1px solid #ccc;background:#fafafa;"></canvas>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  id="woc-sign-form" style="margin-top:15px;">

                <input type="hidden" name="action" value="woc_sign_contract">
                <input type="hidden" name="woc_contract_id" value="<?php echo esc_attr( $contract_id ); ?>">
                <input type="hidden" name="woc_token" value="<?php echo esc_attr( $given_token ); ?>">
                <input type="hidden" name="woc_signature_data" id="woc_signature_data" value="">
                <?php wp_nonce_field( 'woc_sign_contract_' . $contract_id, 'woc_sign_nonce' ); ?>

                <button type="button" id="woc-clear-signature"
                        style="padding:6px 14px;margin-right:10px;border:1px solid #777;background:#f5f5f5;cursor:pointer;">
                    清除簽名
                </button>

                <button type="submit" id="woc-submit-signature"
                        style="padding:6px 18px;border:1px solid #0073aa;background:#0073aa;color:#fff;cursor:pointer;">
                    送出簽名
                </button>
            </form>

            <p style="margin-top:10px;font-size:12px;color:#666;">
                送出後合約內容將鎖定，如需修改請聯絡承辦人員重新建立合約。
            </p>

        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
(function() {
    var canvas = document.getElementById('woc-signature-pad');
    if (!canvas) return;

    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasDrawn = false;

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        if (e.touches && e.touches.length) {
            return {
                x: e.touches[0].clientX - rect.left,
                y: e.touches[0].clientY - rect.top
            };
        }
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    function startDraw(e) {
        e.preventDefault();
        drawing = true;
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

    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);

    canvas.addEventListener('touchstart', startDraw, {passive:false});
    canvas.addEventListener('touchmove', draw, {passive:false});
    canvas.addEventListener('touchend', endDraw);

    var clearBtn  = document.getElementById('woc-clear-signature');
    var form      = document.getElementById('woc-sign-form');
    var hiddenInp = document.getElementById('woc_signature_data');

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasDrawn = false;
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (!hasDrawn) {
                e.preventDefault();
                alert('請先在簽名欄位簽名。');
                return;
            }
            hiddenInp.value = canvas.toDataURL('image/png');
        });
    }
})();
</script>

<?php
get_footer();
