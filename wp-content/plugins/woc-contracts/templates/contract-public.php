<?php
/**
 * 前台合約檢視 / 簽署頁面（暫時只有檢視）
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

global $post;

$required_token = get_post_meta( $post->ID, WOC_Contracts_CPT::META_VIEW_TOKEN, true );
$given_token    = isset( $_GET['t'] ) ? sanitize_text_field( $_GET['t'] ) : '';

$valid = ( ! empty( $required_token ) && hash_equals( $required_token, $given_token ) );
?>

<div class="woc-contract-public" style="max-width: 800px; margin: 40px auto; padding: 20px;">
    <?php if ( ! $valid ) : ?>

        <h1>合約連結無效</h1>
        <p>此合約連結已失效或參數有誤，請聯絡提供合約的窗口。</p>

    <?php else : ?>

        <h1 style="text-align:center; margin-bottom: 30px;">
            <?php echo esc_html( get_the_title() ); ?>
        </h1>

        <div class="woc-contract-content">
            <?php
            // 合約內容
            the_content();
            ?>
        </div>

        <hr style="margin:40px 0;">

        <div class="woc-contract-sign-placeholder">
            <p style="text-align:center; font-style:italic;">
                （這裡之後會放簽名欄位與簽署按鈕。）
            </p>
        </div>

    <?php endif; ?>
</div>

<?php
get_footer();
