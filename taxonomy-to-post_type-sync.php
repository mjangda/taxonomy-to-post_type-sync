<?php
/**
 * Plugin Name: Taxonomy to Post Type Sync
 * Description: Create a 1-to-1 sync between objects in a specified post_type and taxonomy
 * Author: Mohammad Jangda
 * Author URI: http://digitalize.ca
 * License: GPL v2
 */

require( dirname( __FILE__ ) . '/taxonomy-to-post_type-sync.class.php' );
require( dirname( __FILE__ ) . '/taxonomy-to-post_type-sync.debug.php' );

// Initialize the class
global $tax_to_posttype_sync;
$tax_to_posttype_sync = new Tax2PostTypeSync;
