<?php

/*
Plugin Name: Intralinks
Description: Links between posts
Version: 1.02
Author: Evan Solomon
Author URI: http://evansolomon.me
License: GPLv2 or later
*/

class WPCOM_Intralinks {
	public $cache_key;

	const cache_group = 'intralinks';
	const cache_time  = 3600; // 1 hour
	const version     = '1.0';

	function __construct() {
		include( dirname( __FILE__ ) . '/includes/tlc-transients.php' );
		add_filter( 'the_content', array( $this, 'show_intralinks' ) );
	}

	public function get_intralinks( $post ) {
		// Use the post ID as the cache key
		$this->set_cache_key( $post->ID );

		// Return whatever's in the TLC transients cache
		return apply_filters( 'wpcom_intralinks_get', $this->get_cached_intralinks() );
	}

	public function show_intralinks( $content ) {
		global $post;

		if ( ! $post )
			return $content;

		if ( ! apply_filters( 'wpcom_intralinks_show_intralinks', true, $content ) )
			return $content;

		// If there are results, load the static assets
		$cached_intralinks = $this->get_intralinks( $post );
		if ( $cached_intralinks )
			$this->load_assets();

		return $content . $cached_intralinks;
	}

	public function generate_intralinks( $post ) {
		// Get URL's to query
		$urls = $this->get_urls( $post );
		if ( ! $urls )
			return '';

		// Query for this post's URL's
		$results = $this->query( $urls );

		// Bail if there aren't any results
		if ( ! $results )
			return '';

		// Get the relevant data from our results
		$inbound_links = $this->parse_query_results( $results );

		// Check our filtered results to make sure there are some left
		if ( ! $inbound_links )
			return '';

		// HTML to output
		return $this->html_output( $inbound_links, $post );
	}

	private function set_cache_key( $key ) {
		return $this->cache_key = self::cache_group . $key;
	}

	private function load_assets() {
		wp_enqueue_style( 'wpcom-intralinks', plugin_dir_url( __FILE__ ) . 'intralinks.css', array(), self::version );
		wp_enqueue_script( 'wpcom-intralinks', plugin_dir_url( __FILE__ ) . 'intralinks.js', array( 'jquery' ), self::version );
	}

	private function get_cached_intralinks() {
		global $post;

		$intralinks = tlc_transient( $this->cache_key )
			->expires_in( apply_filters( 'wpcom_intralinks_cache_time', self::cache_time, $post ) )
			->background_only()
			->updates_with( array( $this, 'generate_intralinks'), array( $post ) )
			->get();

		// Fallback for empty cache
		if ( ! $intralinks )
			return '';

		return $intralinks;
	}

	private function get_urls( $post ) {
		// Remove schemes for search-friendly URL's
		$post_permalink = preg_replace( '/^https?:\/\//', '', get_permalink( $post->ID ) );
		$post_shortlink = preg_replace( '/^https?:\/\//', '', wp_get_shortlink( $post->ID ) );

		$urls = array();
		if ( $post_permalink )
			$urls['permalink'] = $post_permalink;

		if ( $post_shortlink )
			$urls['shortlink'] = $post_shortlink;

		return apply_filters( 'wpcom_intralinks_get_urls', $urls, $post );
	}

	private function query( $urls ) {
		global $wpdb;

		$results = array();

		// get_blog_list() can be very slow, but will only ever be called async
		$blogs = apply_filters( 'wpcom_intralinks_query_blog_list', get_blog_list( 0, 'all' ) );
		foreach ( $blogs as $blog ) {
			switch_to_blog( $blog['blog_id'] );

			$query = $wpdb->prepare(
				$this->get_query_sql( $urls ),
				"%{$urls['permalink']}%",
				"%{$urls['shortlink']}%"
			);

			foreach ( $wpdb->get_results( $query ) as $result )
				$results[] = $result;

			restore_current_blog();
		}

		return $results;
	}

	private function get_query_sql( $urls ) {
		global $wpdb;

		$select = "SELECT * ";
		$from   = "FROM {$wpdb->posts}";
		$where  = "WHERE post_status = 'publish' AND";

		if ( 1 == count( $urls ) )
			$where .= " post_content LIKE %s";
		else
			$where .= " ( post_content LIKE %s OR post_content LIKE %s )";

		$orderby = "ORDER BY post_date ASC";

		return "{$select} {$from} {$where} {$orderby}";
	}

	private function parse_query_results( $results ) {
		$inbound_links = array();

		foreach ( $results as $result ) {
			$inbound_link = array();

			$inbound_link['user_email'] = $this->get_result_author_email( $result );
			$inbound_link['title']      = $this->standardize_post_title( $result );
			$inbound_link['content']    = $result->post_content;
			$inbound_link['date']       = $result->post_date;
			$inbound_link['url']        = get_permalink( $result->ID );

			$inbound_links[] = apply_filters( 'wpcom_intralinks_parse_query_result', $inbound_link, $result );
		}

		return apply_filters( 'wpcom_intralinks_parse_query_results', $inbound_links, $results );
	}

	private function get_result_author_email( $post ) {
		$user_id = $post->post_author;
		$user    = get_user_by( 'id', $user_id );

		return apply_filters( 'wpcom_intralinks_get_result_author_email', $user->data->user_email, $post );
	}

	private function standardize_post_title( $post ) {
		$title = $post->post_title;

		// Some posts end up title-less, in that case use the content but remove markup
		if ( empty( $title ) )
			$title = wp_trim_words( $result_data['content'] );

		// Avoid line breaks
		$title_length_limit = apply_filters( 'wpcom_intralinks_title_length', 80 );
		if ( $title_length_limit && (int) $title_length_limit < strlen( $title ) )
			$title = trim( substr( $title, 0, $title_length_limit - 3 ) ) . '&hellip;';

		return apply_filters( 'wpcom_intralinks_standardize_post_title', $title, $post );
	}

	private function html_output( $inbound_links, $post ) {
		$output  = $this->html_output_start( $inbound_links, $post );
		$output .= $this->html_output_links( $inbound_links );
		$output .= $this->html_output_end();

		return apply_filters( 'wpcom_intralinks_html_output', $output, $post );
	}

	private function html_output_start( $inbound_links, $post ) {
		$results_count = count( (array) $inbound_links );
		$post_type     = get_post_type_object( $post->post_type );

		$output  = "<div class='intralinks'>";
		$output .= "<p class='intralinks-count'>";

		$output .= esc_html( sprintf(
			_x( '%1$d %2$s to this %3$s', 'used to describe the number of links to the current post/page' ),
			number_format_i18n( $results_count ),
			_nx( 'link', 'links', $results_count, 'hyperlinks to the current post' ),
			strtolower( $post_type->labels->singular_name )
		) );

		$output .= '</p>';
		$output .= '<ul>';

		return apply_filters( 'wpcom_intralinks_html_output_start', $output, $inbound_links, $post );
	}

	private function html_output_links( $inbound_links ) {
		$output = '';

		foreach ( $inbound_links as $key => $details ) {
			$link_output = '';

			$date = date( 'M j', strtotime( $details['date'] ) );
			$year_suffix = date( ', Y', strtotime( $details['date'] ) );

			//Don't clutter the date with the year if it's this year
			if ( $year_suffix != date( ', Y' ) ) {
				$date .= $year_suffix;
				$results_have_years = true;
			}
			else {
				$results_have_years = false;
			}

			//If any results need a year, add a class to make all the li's wider
			//This is only evaluated on the first result for each post because all elements need the same classes
			if ( ! isset( $li_class ) )
				$li_class = ( $results_have_years ) ? 'intralink-to-thread intralink-dates-with-years' : 'intralink-to-thread';

			//Build this result's list item
			$link_output .= "<li class='$li_class'>";
			$link_output .= get_avatar( $details['user_email'], 20 );
			$link_output .= "<img height='12' width='12' class='intralink-blavatar' src='" . esc_url( $this->get_favicon_url( $details['url'] ) ) ."'> ";
			$link_output .= "<span class='intralink-date'>" . esc_html( $date ) . "</span> ";
			$link_output .= "<a href='#' class='intralink-content-preview'>" . esc_html_x( 'Preview', "read a post's content" ) . "</a> ";

			$link_output .= "<a href='" . esc_url( $details['url'] ) . "'>";
			$link_output .= esc_html( $details['title'] );
			$link_output .= "</a>";

			$link_output .= "<div class='intralink-content'>";

			remove_filter( 'the_content', array( $this, 'show_intralinks' ) );
			$link_output .= apply_filters( 'the_content', balanceTags( $details['content'], true ) );
			add_filter( 'the_content', array( $this, 'show_intralinks' ) );

			$link_output .= "</div>";

			$link_output .= "</li>";

			// Append it to the list
			$output .= apply_filters( 'wpcom_intralinks_html_output_link', $link_output );
		}

		return apply_filters( 'wpcom_intralinks_html_output_links', $output );
	}

	private function html_output_end() {
		$output  = "</ul>";
		$output .= "</div>";

		return apply_filters( 'wpcom_intralinks_html_output_end', $output );
	}

	private function get_favicon_url( $url ) {
		$url = parse_url( esc_url( $url ), PHP_URL_HOST );

		// Uses a third party API from Google
		return apply_filters( 'wpcom_intralinks_favicon_url', 'http://www.google.com/s2/favicons?domain=' . $url );
	}
}

new WPCOM_Intralinks;
