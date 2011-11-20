<?php

class Tax2PostTypeSync {
	var $doing_sync = false;
	
	var $sync_relationships = array();
	var $sync_post_types = array();
	var $sync_taxonomies = array();
	
	function __construct() {
		$this->init_sync_relationships();
		
		// Add save-sync actions
		add_action( 'save_post', array( $this, 'sync_save_post_callback' ), 11, 2 );
		add_action( 'edit_term', array( $this, 'sync_edit_term_callback' ), 11, 3 );
		add_action( 'create_term', array( $this, 'sync_edit_term_callback' ), 11, 3 );
		
		// Handle term delete
		add_action( 'delete_term', array( $this, 'sync_delete_term_callback' ), 11, 3 );
		// Handle post delete
		add_action( 'before_delete_post', array( $this, 'sync_delete_post_callback' ), 11 );
	}
	
	function init_sync_relationships() {
		if( ! is_array( $this->sync_relationships ) )
			$this->sync_relationships = array();
	}
	
	function get_sync_relationships() {
		if( empty( $this->sync_relationships ) )
			$this->sync_relationships = $this->init_sync_relationships();
			
		return $this->sync_relationships;
	}
	
	function register_sync( $post_type, $taxonomy ) {
		
		// Check that valid post_type and valid taxonomy
		if( ! post_type_exists( $post_type ) || ! taxonomy_exists( $taxonomy ) ) {
			_doing_it_wrong( __FUNCTION__, sprintf( __( 'Post Type (%1$s) or Taxonomy (%2$s) don\'t exist!', 'tax_to_posttype_sync' ), $post_type, $taxonomy ) );
			return;
		}
		
		// Link the taxonomy to the post_type
		register_taxonomy_for_object_type( $taxonomy, $post_type );
			
		$this->sync_relationships[] = array(
			'post_type' => $post_type,
			'taxonomy' => $taxonomy,
		);
		$this->sync_post_type_relationships[$post_type] = $taxonomy;
		$this->sync_taxonomy_relationships[$taxonomy] = $post_type;
	}
	
	function get_post_type_sync( $post_type ) {
		if( isset( $this->sync_post_type_relationships[$post_type] ) )
			return $this->sync_post_type_relationships[$post_type];
		return false;
	}
	
	function get_taxonomy_sync( $taxonomy ) {
		if( isset( $this->sync_taxonomy_relationships[$taxonomy] ) )
			return $this->sync_taxonomy_relationships[$taxonomy];
		return false;
	}
	
	function sync_save_post_callback( $post_id, $post ) {
		if( $this->doing_sync() )
			return;
		
		if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;
		
		if( $post->post_status == 'auto-draft' )
			return;
		
		// Check if sync enabled
		$post_type = $post->post_type;
		$taxonomy = $this->get_post_type_sync( $post_type );
		
		if( ! $taxonomy )
			return;
		
		$this->do_sync();
		
		$this->sync_post_to_term( $post_id, $post, $post_type, $taxonomy );
		
		$this->done_sync();
	}
	
	function sync_post_to_term( $post_id, $post, $post_type, $taxonomy ) {
		
		$title = $post->post_title;
		$slug = $post->post_name;
		
		if( empty( $slug ) )
			$slug = sanitize_title( $title );
			
		if( empty( $slug ) )
			return;
		
		$term = null;
		
		// Check if we have a synced term already
		$term = $this->get_synced_term( $post_id, $post_type );
		
		// We don't, so check to see if the term exists
		if( ! $term )
			$term = get_term_by( 'slug', $slug, $taxonomy );
		
		if( $term ) {
			
			// Update the existing term
			$term_save = wp_update_term( $term->term_id, $taxonomy, array(
				'name' => $title,
				'slug' => $slug,
			) );
			
		} else {
			
			if( empty( $title ) )
				$title = $slug;
			
			// Create a new term
			$term_save = wp_insert_term( $title, $taxonomy, array(
				'slug' => $slug
			) );
		}
		
		// Watch for is_wp_error( $term )
		if( $term_save && is_wp_error( $term_save ) ) {
			error_log( sprintf( __( 'Uh oh! %1$s failed! Details: %2$s' ), __FUNCTION__, var_export( $term_save, true ) ) );
			return;
		}
		
		// Store our post-term sync relationship
		$this->sync_post_term_relationship( $post_id, $post_type, $term_save['term_id'], $taxonomy );
	}
	
	function sync_delete_post_callback( $post_id ) {
		if( $this->doing_sync() )
			return;
		
		$post = get_post( $post_id );
		
		// Check if sync enabled
		$post_type = $post->post_type;
		$taxonomy = $this->get_post_type_sync( $post_type );
		
		if( ! $taxonomy )
			return;
		
		$this->do_sync();
		
		// Delete matching term
		$term = $this->get_synced_term( $post_id, $post_type );
		
		if( $term )
			wp_delete_term( $term->term_id, $taxonomy );
		
		$this->done_sync();	
	}
	
	function sync_edit_term_callback( $term_id, $tt_id, $taxonomy ) {
		if( $this->doing_sync() )
			return;
		
		// Check if sync enabled
		$post_type = $this->get_taxonomy_sync( $taxonomy );
		
		if( ! $post_type )
			return;
		
		$term = get_term( $term_id, $taxonomy );
		
		$this->do_sync();
		
		$this->sync_term_to_post( $term_id, $term, $taxonomy, $post_type );
		
		$this->done_sync();
	}
	
	function sync_term_to_post( $term_id, $term, $taxonomy, $post_type ) {
		
		$slug = $term->slug;
		$title = $term->name;
		
		// Get synced post
		$post = $this->get_synced_post( $term->term_id, $taxonomy );
			
		// If a synced post doesn't exist, try to find one matching the post_name
		if( ! $post ) {
			$post = $this->get_post_by_slug( $slug, $post_type );
			
			// We still don't have a post, so let's create one
			if( ! $post ) {
				$post_id = wp_insert_post( array(
					'post_title' => $title,
	     			'post_name' => $slug,
	     			'post_type' => $post_type,
	     			'post_status' => 'publish',
				) );
			} else {
				$post_id = $post->ID;
			}
			
			// Store our post-term sync relationship
			$this->sync_post_term_relationship( $post_id, $post_type, $term->term_id, $taxonomy );
			
		} else {
			wp_update_post( array(
				'ID' => $post->ID,
				'post_title' => $title,
				'post_name' => $slug,
				'post_type' => $post_type,
				'post_status' => 'publish',
			) );
		}
		
		// Now, let's hook into the post-term update hook and update /actually/ update our post 
		// TODO: Hook into into the right callback
		add_action( 'edited_term', array( $this, 'sync_edit_term_callback' ), 11, 3 );
	}
	
	function sync_delete_term_callback( $term, $tt_id, $taxonomy ) {
		if( $this->doing_sync() )
			return;
		
		// Check if sync enabled
		$post_type = $this->get_taxonomy_sync( $taxonomy );
		
		if( ! $post_type )
			return;
		
		$this->do_sync();
		
		// Get matching post and delete	
		$post = $this->get_synced_post( $term, $taxonomy );
		if( $post )
			wp_delete_post( $post->ID, true ); // force delete
		
		$this->done_sync();	
	}
	
	function sync_post_term_relationship( $post_id, $post_type, $term_id, $taxonomy ) {
		wp_set_object_terms( $post_id, (int)$term_id, $taxonomy );
	}
	
	function get_synced_term( $post_id, $post_type ) {
		$taxonomy = $this->get_post_type_sync( $post_type );
		$terms = wp_get_object_terms( $post_id, $taxonomy );
		
		if( ! empty( $terms ) && ! is_wp_error( $terms ) )
			return $terms[0];
			
		return false;
	}
	
	function get_synced_post( $term_id, $taxonomy ) {
		// In case the term object was passed in
		if ( is_object( $term_id ) )
			$term_id = $term_id->term_id;

		$post_type = $this->get_taxonomy_sync( $taxonomy );
		
		$posts = get_posts( array( 
			'posts_per_page' => 1,
			'post_type' => $post_type,
			'post_status' => 'any',
			'tax_query' => array(
				array( 
					'taxonomy' => $taxonomy,
					'field' => 'id',
					'terms' => $term_id
				),
			),
		) );
		
		if( ! empty( $posts ) )
			return $posts[0];
		
		return false;
	}
	
	function get_post_by_slug( $slug, $post_type ) {
		$posts = get_posts( array( 
			'posts_per_page' => 1,
			'name' => $slug,
			'post_type' => $post_type,
			'post_status' => 'any',
		) );
		
		if( ! empty( $posts ) )
			return $posts[0];
		
		return false;
	}
	
	// We use the following helper functions to avoid getting into recursive problems
	// i.e. from save_post, calling wp_update_term, which fires edited_terms, which calls wp_update_post, which fires save_post etc.
	private function doing_sync() {
		return $this->doing_sync;
	}
	
	private function do_sync() {
		$this->doing_sync = true;
	}
	
	private function done_sync() {
		$this->doing_sync = false;
	}

}

// Public wrapper functions
function x_register_sync( $post_type, $taxonomy ) {
	global $tax_to_posttype_sync;
	$tax_to_posttype_sync->register_sync( $post_type, $taxonomy );
}

function x_get_synced_term( $post_id, $post_type ) {
	global $tax_to_posttype_sync;
	return $tax_to_posttype_sync->get_synced_term( $post_id, $post_type );
}

function x_get_synced_post( $term_id, $taxonomy ) {
	global $tax_to_posttype_sync;
	return $tax_to_posttype_sync->get_synced_post( $term_id, $taxonomy );
}
