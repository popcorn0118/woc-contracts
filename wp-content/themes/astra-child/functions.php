<?php
/**
 * astra-child Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package astra-child
 * @since 1.0.0
 */

/**
 * Define Constants
 */
define( 'CHILD_THEME_ASTRA_CHILD_VERSION', '1.0.0' );

/**
 * Enqueue styles
 */
function child_enqueue_styles() {

	wp_enqueue_style( 'astra-child-theme-css', get_stylesheet_directory_uri() . '/style.css', array('astra-theme-css'), CHILD_THEME_ASTRA_CHILD_VERSION, 'all' );

}

add_action( 'wp_enqueue_scripts', 'child_enqueue_styles', 15 );



function create_post_type() {

	register_post_type('case', array(
		'labels' => array(
			'name'          => __('案例見證'),
			'singular_name' => __('案例見證'),
		),
		'public'      => true,
		'has_archive' => true,
		'menu_icon'   => 'dashicons-welcome-write-blog',
		'supports'    => array('title', 'editor', 'thumbnail', 'excerpt', 'revisions'),
		'taxonomies'  => array('case-type', 'case-tag'),

		// ✅ 自訂權限（重點）
		'capability_type' => array('case', 'cases'),
		'map_meta_cap'    => true,
		'capabilities'    => array(
			'create_posts' => 'edit_cases', // ✅ 讓「新增」跟 edit_cases 綁一起
		),
	));
}
add_action('init', 'create_post_type');


add_action('init', function () {

	if ( get_option('qz_case_caps_added') ) return;

	$role = get_role('administrator');
	if ( ! $role ) return;

	$caps = array(
		'edit_case',
		'read_case',
		'delete_case',
		'edit_cases',
		'edit_others_cases',
		'publish_cases',
		'read_private_cases',
		'delete_cases',
		'delete_private_cases',
		'delete_published_cases',
		'delete_others_cases',
		'edit_private_cases',
		'edit_published_cases',
	);

	foreach ( $caps as $cap ) {
		$role->add_cap($cap);
	}

	update_option('qz_case_caps_added', 1);
}, 20);



add_action('init', function () {

	register_post_type('case_template', array(
		'labels' => array(
			'name'          => '案例範本',
			'singular_name' => '案例範本',
		),
		'public'      => false,
		'show_ui'     => true,
		'show_in_menu'=> 'edit.php?post_type=case', // ✅ 掛到「案例見證」底下
		'supports'    => array('title', 'editor', 'revisions'),
	));

});





