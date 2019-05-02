<?php
/*
Plugin Name: Enhanced Post Cache
Description: Cache posts queries to not repeat same queries.
Version: 1.0
Author: TimeInc
Author URI: http://timeincuk.com/
*/

class Enhanced_Post_Cache {
	// IDs of all posts current SQL query returns
	public $all_post_ids = false;
	public $cache_salt = false;

	private $do_flush_cache = true;
	private $cache_queries = true;
	private $cache_group = 'enhanced_post_cache';
	private $found_posts = 0;
	private $cache_key = '';
	private $limits = '';
	private $last_result = array();

	public function __construct() {
		$this->setup_for_blog();

		add_action( 'switch_blog', array( $this, 'setup_for_blog' ), 10, 2 );

		add_action( 'clean_term_cache', array( $this, 'flush_cache' ) );
		add_action( 'clean_post_cache',  array( $this, 'clean_post_cache' ), 10, 2 );

		add_action( 'deleted_post_meta', array( $this, 'update_post_meta' ), 10, 2 );
		add_action( 'updated_post_meta', array( $this, 'update_post_meta' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'update_post_meta' ), 10, 2 );

		add_action( 'wp_updating_comment_count', array( $this, 'dont_clear_advanced_post_cache' ) );
		add_action( 'wp_update_comment_count', array( $this, 'do_clear_advanced_post_cache' ) );

		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );

		add_filter( 'posts_request_ids', array( $this, 'posts_request_ids' ) );
		add_filter( 'posts_results', array( $this, 'posts_results' ), 10, 2 );
		add_filter( 'posts_pre_query', array( $this, 'posts_pre_query' ), 999, 2 );
		add_filter( 'post_limits_request', array( $this, 'post_limits_request' ) );
	}

	public function setup_for_blog( $new_blog_id = false, $previous_blog_id = false ) {
		if ( $new_blog_id && (int) $new_blog_id === (int) $previous_blog_id ) {
			return;
		}

		$this->cache_salt = wp_cache_get( 'cache_incrementors', 'advanced_post_cache' );

		if ( false === $this->cache_salt ) {
			$this->set_cache_salt();
		}
	}

	public function clean_post_cache( $post_id, $post ) {
		if ( ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post ) ) {
			$this->flush_cache();
		}
	}

	public function update_post_meta( $ignored, $post_id ) {
		$post = get_post( $post_id );
		$this->clean_post_cache( $post_id, $post );
	}

	public function flush_cache() {
		if ( $this->needs_cache_clear() ) {
			$this->set_cache_salt();
		}
	}

	public function do_clear_advanced_post_cache() {
		$this->do_flush_cache = true;
	}

	public function dont_clear_advanced_post_cache() {
		$this->do_flush_cache = false;
	}

	/**
	 * Pre Get Posts
	 *
	 * An easy way to switch off the plugin for any specific query
	 *
	 * @see WP_Query::get_posts (pre_get_posts action)
	 *
	 * @param WP_Query $wp_query The WP_Query instance
	 */
	public function pre_get_posts( $wp_query ) {
		$this->cache_queries = apply_filters( 'use_enhanced_post_cache', true, $wp_query );
		add_filter( 'split_the_query', function() { return $this->cache_queries; } );
	}

	/**
	 * @param $posts
	 * @param $wp_query
	 *
	 * @return array
	 */
	function posts_pre_query( $posts, $wp_query ) {
		global $wpdb;

		if ( null !== $posts || ! $this->cache_queries ) {
			return $posts;
		}

		if ( $this->check_query_type( $wp_query, 'ids' ) ) {
			$wp_query->request = apply_filters( 'posts_request_ids', $wp_query->request, $wp_query );
			if ( ! $this->is_cached() ) {
				$posts = $wpdb->get_col( $wp_query->request );
				$wp_query->set_found_posts( $wp_query->query_vars, $this->limits );
			}
			$posts = $this->posts_results( $posts, $wp_query );
		}

		return $posts;
	}

	/**
	 * @param $limits
	 *
	 * @return mixed
	 */
	function post_limits_request( $limits ){
		$this->limits = $limits;
		return $limits;
	}

	/**
	 * Post Results Ids
	 *
	 * Tries to search if the current query is cached:
	 * If cached: stop the normal execution by emptying $sql and flushing $wpdb
	 * If not cached: returns to the normal WP_Query execution
	 *
	 * @see WP_Query::get_posts (post_request_ids filter)
	 *
	 * @param string $sql Query to be executed
	 * @return string $sql empty string (if cached) or same query (if not cached)
	 */
	public function posts_request_ids( $sql ) {
		if ( ! $this->cache_queries ) {
			return $sql;
		}

		global $wpdb;

		$query = $sql;
		// Check if method existing before using it for backwards compat
		if( method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
			// Remove placeholders, as they would break the cache key for searches.
			$query = $wpdb->remove_placeholder_escape( $query );
		}
		$this->cache_key   = md5( $query );
		$this->found_posts = 0;
		$cache             = wp_cache_get( $this->cache_key . $this->cache_salt, $this->cache_group );

		if ( ! empty( $cache ) && is_array( $cache ) ) {
			$this->last_result  = $wpdb->last_result;
			$wpdb->last_result  = array();
			$sql                = '';
			$this->found_posts  = $cache['found_posts'];
			$this->all_post_ids = $cache['post_ids'];
		}

		return $sql;
	}

	/**
	 * Post Results
	 *
	 * If is cached, we have posts cached for the current query, then filter running
	 * `get_posts` on every cached id, otherwise cache the original ids and return the original result
	 *
	 * @see WP_Query::get_posts (post_results filter)
	 *
	 * @param array $posts array of WP_Post elements
	 * @return array $posts array of WP_Post elements
	 */
	public function posts_results( $posts, $wp_query ) {
		if ( ! $this->cache_queries ) {
			return $posts;
		}

		global $wpdb;

		if ( $this->is_cached() ) {
			if ( $this->check_query_type( $wp_query, 'ids' ) ) {
				$posts = array_map( 'intval', $this->all_post_ids );
			} else {
				$posts = array_map( 'get_post', $this->all_post_ids );
			}
			$wp_query->found_posts = $this->found_posts;
			$wpdb->last_result     = $this->last_result;
			$this->last_result     = array();
			$this->limits          = '';
		} else {
			if ( $this->check_query_type( $wp_query, 'ids' ) ) {
				$post_ids = $posts;
			} else {
				$post_ids = wp_list_pluck( (array) $posts, 'ID' );
			}

			$value = array(
				'post_ids'    => $post_ids,
				'found_posts' => $wp_query->found_posts,
			);
			wp_cache_set( $this->cache_key . $this->cache_salt, $value, $this->cache_group );
		}

		if ( $wp_query->query_vars['posts_per_page'] > -1 ) {
			$wp_query->max_num_pages = ceil( $wp_query->found_posts / $wp_query->query_vars['posts_per_page'] );
		}

		return $posts;
	}

	private function is_cached() {
		return is_array( $this->all_post_ids );
	}

	/**
	 * Helper function to check the type of query.
	 *
	 * @param wp_query $wp_query Current WP_Query Object.
	 * @param string $type Either 'id' or empty QuickHashIntStringHash
	 *
	 * @return bool
	 */
	private function check_query_type( $wp_query, $type = '' ) {
		return isset( $wp_query->query_vars['fields'] ) && $type === $wp_query->query_vars['fields'];
	}
}

global $enhanced_post_cache_object;
$enhanced_post_cache_object = new Enhanced_Post_Cache;
