<?php

if( defined( 'T2PT_DEBUG' ) && T2PT_DEBUG ) {
	
	add_action( 'init', 'x_init_post_types' );
	
	// Just sample code pulled from the Codex
	function t2p2_init_post_types() {
		// register taxonomy
		register_taxonomy( 't-writer', null, array(
		    'hierarchical' => true,
		    'label' => 'Writer (Taxonomy)',
		    'show_ui' => true,
		    'query_var' => true,
		    'rewrite' => array( 'slug' => 'writer' ),
	    ) );
	
		// register post_type 
	    register_post_type( 'p-writer', array(
		    'label' => 'Writer (Post Type)',
		    'public' => true,
		    'publicly_queryable' => true,
		    'show_ui' => true, 
		    'show_in_menu' => true, 
		    'query_var' => true,
		    'rewrite' => false,
		    'capability_type' => 'post',
		    'has_archive' => true, 
		    'hierarchical' => false,
		    'menu_position' => null,
		    'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
	    ) );
	    
	    // Set up sync between the post_type and taxonomy
	    x_register_sync( 'my-writer-post', 'my-writer-tax' );
	}
}