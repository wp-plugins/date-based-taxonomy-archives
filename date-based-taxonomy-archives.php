<?php
/*
Plugin Name: Date-based Taxonomy Archives
Plugin URI: https://ethitter.com/plugins/date-based-taxonomy-archives/
Description: Add support for date-based taxonomy archives. Render an unordered list of years with months, linked to corresponding date-based taxonomy archive, nested therein.
Author: Erick Hitter
Version: 0.3.1
Author URI: https://ethitter.com/

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class Date_Based_Taxonomy_Archives {
	/**
	 * Singleton
	 */
	private static $__instance = null;

	/**
	 * Class variables
	 */
	private $defaults = array(
		'taxonomies'      => false,
		'show_post_count' => false,
		'limit'           => '',
		'before'          => '',
		'after'           => '',
		'echo'            => true,
	);

	private $cache_key_incrementor = 'incrementor';
	private $cache_group = 'date_based_taxonomy_archives';

	private $filter_archive_links = false;

	/**
	 * Silence is golden!
	 */
	private function __construct() {}

	/**
	 * Singleton implementation
	 *
	 * @uses self::setup
	 * @return object
	 */
	public static function get_instance() {
		if ( ! is_a( self::$__instance, __CLASS__ ) ) {
			self::$__instance = new self;

			self::$__instance->setup();
		}

		return self::$__instance;
	}

	/**
	 * Magic getter to provide access to class variables that were public prior to v0.3.
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( property_exists( $this, $name ) )
			return $this->$name;
		else
			return null;
	}

	/**
	 * Register actions and filters
	 *
	 * @uses add_action
	 * @uses add_filter
	 * @return null
	 */
	private function setup() {
		add_filter( 'date_based_taxonomy_archives_where', array( $this, 'filter_date_based_taxonomy_archives_where' ), 10, 2 );
		add_filter( 'date_based_taxonomy_archives_join', array( $this, 'filter_date_based_taxonomy_archives_join' ), 10, 2 );
		add_filter( 'get_archives_link', array( $this, 'filter_get_archives_link' ) );

		add_action( 'generate_rewrite_rules', array( $this, 'action_generate_rewrite_rules' ) );

		add_action( 'transition_post_status', array( $this, 'action_transition_post_status' ), 50, 2 );
	}

	/**
	 * Render unordered lists of monthly archive links grouped by year
	 *
	 * @global $wpdb
	 * @global $wp_locale
	 * @param array $args
	 * @uses apply_filters
	 * @uses wp_parse_args
	 * @uses absint
	 * @uses this::get_incrementor
	 * @uses wp_cache_get
	 * @uses wp_cache_set
	 * @uses number_format_i18n
	 * @uses get_month_link
	 * @uses get_archives_link
	 * @return string or false
	 */
	function get_archives( $args = array() ) {
		global $wpdb, $wp_locale;

		$args = apply_filters( 'date_based_taxonomy_archives_args', $args );
		$args = wp_parse_args( $args, $this->defaults );
		extract( $args );

		// Build query
		$where = apply_filters( 'date_based_taxonomy_archives_where', "WHERE post_type = 'post' AND post_status = 'publish'", $args );
		$join = apply_filters( 'date_based_taxonomy_archives_join', '', $args );

		if ( is_numeric( $limit ) )
			$limit = ' LIMIT ' . absint( $limit );

		$query = "SELECT YEAR(post_date) AS `year`, MONTH(post_date) AS `month`, count(ID) as posts FROM $wpdb->posts $join $where GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC $limit";

		// Generate cache key, check cache, query DB if necessary and cache results
		$cache_key = $this->get_incrementor() . md5( $query );

		if ( ! $results = wp_cache_get( $cache_key, $this->cache_group ) ) {
			$results = $wpdb->get_results( $query );

			wp_cache_set( $cache_key, $results, $this->cache_group );
		}

		// Bail out if necessary data isn't available
		if ( ! is_array( $results ) || empty( $results ) )
			return false;

		// Render archive
		$output = '<ul>';
		$cy = false;

		// Alias $after for use inside of foreach
		$_after = $after;

		foreach ( $results as $result ) {
			if ( $cy !== false && $cy != $result->year )
				$output .= '</ul></li>';

			if ( $cy === false || $cy != $result->year ) {
				$cy = $result->year;

				$output .= '<li><span>' . absint( $result->year ) . '</span>';
				$output .= '<ul>';
			}

			$url = get_month_link( $result->year, $result->month );

			$text = $wp_locale->get_month( $result->month );

			$after = $show_post_count ? '&nbsp;(' . number_format_i18n( absint( $result->posts ) ) . ')' . $_after : $_after;

			$output .= get_archives_link( $url, $text, 'html', $before, $after );
		}

		if ( $cy == $result->year )
			$output .= '</ul></li>';

		$output .= '</ul>';

		// Reset archive links filter indicator
		$this->filter_archive_links = false;

		if ( $echo )
			echo $output;
		else
			return $output;
	}

	/**
	 * Filter where clause used in this::get_archives
	 *
	 * @global $wpdb
	 * @param string $where
	 * @param array $args
	 * @uses apply_filters
	 * @uses wp_parse_args
	 * @uses is_category
	 * @uses is_tag
	 * @uses is_tax
	 * @uses get_queried_object
	 * @filter date_based_taxonomy_archives_where
	 * @return string
	 */
	function filter_date_based_taxonomy_archives_where( $where, $args ) {
		global $wpdb;

		$args = apply_filters( 'date_based_taxonomy_archives_args', $args );
		$args = wp_parse_args( $args, $this->defaults );
		extract( $args );

		if (
			( $taxonomies == 'all' && ( is_category() || is_tag() || is_tax() ) ) ||
			( $taxonomies == 'custom' && ! is_category() && ! is_tag() && is_tax() )
		) {
			$queried_object = get_queried_object();

			if ( is_object( $queried_object ) && property_exists( $queried_object, 'term_taxonomy_id' ) )
				$where .= $wpdb->prepare( ' AND dbtrtr.term_taxonomy_id = %d', $queried_object->term_taxonomy_id );
		} elseif ( is_array( $taxonomies ) ) {
			$queried_object = get_queried_object();

			if ( is_object( $queried_object ) && property_exists( $queried_object, 'term_taxonomy_id' ) && property_exists( $queried_object, 'taxonomy' ) && in_array( $queried_object->taxonomy, $taxonomies ) )
				$where .= $wpdb->prepare( ' AND dbtrtr.term_taxonomy_id = %d', $queried_object->term_taxonomy_id );
		}

		return $where;
	}

	/**
	 * Filter join clause used in this::get_archives
	 *
	 * @global $wpdb
	 * @param string $join
	 * @param array $args
	 * @uses apply_filters
	 * @uses wp_parse_args
	 * @uses is_category
	 * @uses is_tag
	 * @uses is_tax
	 * @filter date_based_taxonomy_archives_join
	 * @return string
	 */
	function filter_date_based_taxonomy_archives_join( $join, $args ) {
		global $wpdb;

		$args = apply_filters( 'date_based_taxonomy_archives_args', $args );
		$args = wp_parse_args( $args, $this->defaults );
		extract( $args );

		if (
			( $taxonomies == 'all' && ( is_category() || is_tag() || is_tax() ) ) ||
			( $taxonomies == 'custom' && ! is_category() && ! is_tag() && is_tax() ) ||
			is_array( $taxonomies )
		) {
			$join .= " INNER JOIN {$wpdb->term_relationships} AS dbtrtr on dbtrtr.object_id = {$wpdb->posts}.ID";

			$this->filter_archive_links = true;
		}

		return $join;
	}

	/**
	 * Filter get_archives_link output to inject taxonomy and term slugs
	 *
	 * @global $wp_rewrite
	 * @param string $link_html
	 * @uses get_queried_object
	 * @uses is_wp_error
	 * @uses path_join
	 * @uses trailingslashit
	 * @uses home_url
	 * @uses get_taxonomy
	 * @uses esc_url
	 * @uses add_query_arg
	 * @filter get_archives_link
	 * @return string
	 */
	function filter_get_archives_link( $link_html ) {
		if ( $this->filter_archive_links ) {
			global $wp_rewrite;

			$queried_object = get_queried_object();

			if ( is_object( $queried_object ) && ! is_wp_error( $queried_object ) ) {
				$exploded = explode( "'", $link_html );

				if ( $wp_rewrite->using_permalinks() && array_key_exists( $queried_object->taxonomy, $wp_rewrite->extra_permastructs ) ) {
					$term_rewrite = preg_replace( '#%[^%]+%#i', $queried_object->slug, $wp_rewrite->extra_permastructs[$queried_object->taxonomy]['struct'] );
					$term_rewrite = substr( $term_rewrite, 1 ); // Drop leading slash, otherwise path_join misinterprets request
					$term_rewrite = path_join( $term_rewrite, 'date' );

					$exploded[1] = str_replace( trailingslashit( home_url() ), trailingslashit( path_join( home_url(), $term_rewrite ) ), $exploded[1] );
				} else {
					$taxonomy = get_taxonomy( $queried_object->taxonomy );

					if ( is_object( $taxonomy ) && ! is_wp_error( $taxonomy ) )
						$exploded[1] = esc_url( add_query_arg( $taxonomy->query_var, $queried_object->slug, $exploded[1] ) );
				}

				$link_html = implode( "'", $exploded );
			}
		}

		return $link_html;
	}

	/**
	 * Add rewrite rules to support [taxonomy]/[term]/date/[year]/[month]/page/[number]
	 *
	 * @param object $wp_rewrite
	 * @uses get_taxonomies
	 * @action generate_rewrite_rules
	 * @return null
	 */
	function action_generate_rewrite_rules( $wp_rewrite ) {
		$taxonomies = get_taxonomies( null, 'objects' );

		if ( is_array( $taxonomies ) && ! empty( $taxonomies ) ) {
			$rules = array();

			foreach ( $taxonomies as $taxonomy )
				$rules[$taxonomy->rewrite['slug'] . '/(.+?)/date/([0-9]{4})/([0-9]{1,2})/?(page/?([0-9]{1,})/?)?$'] = 'index.php?' . $taxonomy->query_var . '=$matches[1]&year=$matches[2]&monthnum=$matches[3]&paged=$matches[5]';

			$wp_rewrite->rules = $rules + $wp_rewrite->rules;
		}
	}

	/**
	 * Return cache incrementor. To invalidate caches, incrementor is deleted via this::action_transition_post_status.
	 *
	 * @uses wp_cache_get
	 * @uses wp_cache_set
	 * @return int
	 */
	function get_incrementor() {
		$incrementor = wp_cache_get( $this->cache_key_incrementor, $this->cache_group );

		if ( ! is_numeric( $incrementor ) ) {
			$incrementor = time();
			wp_cache_set( $this->cache_key_incrementor, $incrementor, $this->cache_group );
		}

		return (int) $incrementor;
	}

	/**
	 * Invalidate caches when posts are published or published posts are updated
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @uses wp_cache_delete
	 * @action transition_post_status
	 * @return null
	 */
	function action_transition_post_status( $new_status, $old_status ) {
		if ( $new_status == 'publish' || $old_status == 'publish' )
			wp_cache_delete( $this->cache_key_incrementor, $this->cache_group );
	}
}
Date_Based_Taxonomy_Archives::get_instance();

/**
 * Alias global variable used to hold class prior to singleton implementation in v0.3.
 */
$GLOBALS['date_based_taxonomy_archives'] = Date_Based_Taxonomy_Archives::get_instance();

/**
 * Render unordered lists of monthly archive links grouped by year
 *
 * @param array $args
 * @uses Date_Based_Taxonomy_Archives::get_instance
 * @return string or false
 */
function date_based_taxonomy_archives( $args = array() ) {
	return Date_Based_Taxonomy_Archives::get_instance()->get_archives( $args );
}
