<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'contracts' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost:8889' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         ',.XOLkz<.ah##Tjok_2HS+P-(huGs#d(F6pE2W-uuyoI-d):|RGFrsF^}C9*BF*2' );
define( 'SECURE_AUTH_KEY',  '@BoTrU+6*Fs?|X,cU$^tPzwPL4c#Yw)m$1ux!Q[/w>M1u^I),~S QJI+g 0EGc,@' );
define( 'LOGGED_IN_KEY',    '&Q{ +kqJ>+RN0<!$*>WZ9fO<M9gVX8o8.V<Xy]api[odPjP{O4O*k*7)`uvQNJSa' );
define( 'NONCE_KEY',        '-+CvClG$N7{,q7@]/LW,R_N5eROC&lK3G5/#I8Smi(l|szKcWC p d So/~{c5WE' );
define( 'AUTH_SALT',        '&j^)DUwqQtom9V4ha,E~(GP0bSmY0$f<-;gF TtB]?P`Y;S=V e6Sj!xew+G:#kW' );
define( 'SECURE_AUTH_SALT', '_Orf4hXopX+]OtROrXVjP1@ Xg;p#Q6^1;nKmY.Xp%;IN}hInQE^~tekt7UE/EuH' );
define( 'LOGGED_IN_SALT',   'MUbg)+d@*jfNzlrnI@,@`{X{6+W6^0m6hpcVwt,KCk~pc+.?=?RS;aN*yh_H7D$u' );
define( 'NONCE_SALT',       '[)~j2An:%H28 QiY/:iK{;bgZM<D8T<<%oFCJ07K#/m1?Z^.v{.W!8}-Ol8[*;)K' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
