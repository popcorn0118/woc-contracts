<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOC_Contracts_Frontend {

    public static function init() {
        add_filter( 'template_include', [ __CLASS__, 'override_single_template' ] );
    }

    /**
     * 單篇合約使用外掛自己的 template
     */
    public static function override_single_template( $template ) {

        if ( is_singular( WOC_Contracts_CPT::POST_TYPE_CONTRACT ) ) {

            $custom = WOC_CONTRACTS_PATH . 'templates/contract-public.php';

            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }

        return $template;
    }
}

WOC_Contracts_Frontend::init();
