<?php
/**
 * Boot file for the vulnerability watch (class-vulns.php, check in checks-vulns.php) and the dashboard widget
 * (class-dashboard.php). plakette.php loads every includes/modules/*.php automatically.
 */

defined( 'ABSPATH' ) || exit;

require_once PLAKETTE_DIR . 'includes/class-vulns.php';
require_once PLAKETTE_DIR . 'includes/class-dashboard.php';

add_action( 'plakette_boot', array( 'Plakette_Vulns', 'boot' ) );
add_action( 'plakette_boot', array( 'Plakette_Dashboard', 'boot' ) );

// Registered right away (not on plakette_boot) so they also run in the request that switches the plugin off.
add_action( 'plakette_deactivate', array( 'Plakette_Vulns', 'unschedule' ) );
add_action( 'plakette_deactivate', array( 'Plakette_Dashboard', 'unschedule' ) );
