<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 取得全站合約變數的「搜尋字串 => 取代值」對照表
 * 例： [ '{company_name}' => 'OO有限公司', '{company_address}' => '高雄市…' ]
 */
function woc_get_global_var_pairs() {

    $vars = get_option( 'woc_contract_global_vars', [] );

    if ( ! is_array( $vars ) ) {
        return [];
    }

    $pairs = [];

    foreach ( $vars as $key => $row ) {

        $key = sanitize_key( $key );
        if ( $key === '' ) {
            continue;
        }

        // 兼容舊資料（純字串）與新資料（陣列）
        if ( is_array( $row ) ) {
            $value = isset( $row['value'] ) ? (string) $row['value'] : '';
        } else {
            $value = (string) $row;
        }

        if ( $value === '' ) {
            continue;
        }

        $pairs[ '{' . $key . '}' ] = $value;
    }

    // ===== 固定系統變數（寫死，不存 options）=====
    // 使用網站時區（current_time），避免吃到伺服器時區
    $ts = current_time( 'timestamp' );
    $pairs['{current_year}']  = date_i18n( 'Y', $ts );
    $pairs['{current_month}'] = date_i18n( 'm', $ts );
    $pairs['{current_day}']   = date_i18n( 'd', $ts );

    return $pairs;
}

/**
 * 將內容中的 {var_key} 用全站變數值取代
 */
function woc_replace_contract_vars( $content ) {

    if ( ! is_string( $content ) || $content === '' ) {
        return $content;
    }

    $pairs = woc_get_global_var_pairs();

    if ( empty( $pairs ) ) {
        return $content;
    }

    return strtr( $content, $pairs );
}
