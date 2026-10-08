<?php
/**
 * Content scanner, chunker and vector search.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; the table name is built from $wpdb->prefix and a constant string, and all user values are passed through $wpdb->prepare().

/**
 * Scanner class.
 */
class AIVA_Scanner {

	/**
	 * Get the knowledge base table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aiva_knowledge_base';
	}

	/**
	 * Whether a post may be exposed to the public AI agent.
	 * Only published posts without a password qualify.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return bool
	 */
	public static function is_indexable_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}
		return (bool) apply_filters( 'aiva_is_indexable_post', true, $post );
	}

	/**
	 * Remove every chunk that belongs to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post_chunks( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Get the IDs of all posts that already have chunks, as array keys for fast lookup.
	 *
	 * @return array
	 */
	public static function get_indexed_post_ids() {
		global $wpdb;
		$table = esc_sql( self::table() );
		$ids   = $wpdb->get_col( "SELECT DISTINCT post_id FROM $table WHERE post_id > 0" );
		return is_array( $ids ) ? array_flip( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Turn any meta value (scalar or nested array) into a flat, readable string.
	 * Values are never unserialized by us: get_post_meta() already returns them decoded.
	 *
	 * @param mixed $value Meta value.
	 * @param int   $depth Current depth.
	 * @return string
	 */
	public static function flatten_meta_value( $value, $depth = 0 ) {
		if ( is_object( $value ) || $depth > 3 ) {
			return '';
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$flat = self::flatten_meta_value( $item, $depth + 1 );
				if ( '' !== $flat ) {
					$parts[] = $flat;
				}
			}
			return implode( ', ', $parts );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'Yes' : 'No';
		}
		if ( is_scalar( $value ) ) {
			return trim( wp_strip_all_tags( (string) $value ) );
		}
		return '';
	}

	/**
	 * Normalise text before chunking: decode entities, trim lines, drop empty lines, collapse spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text  = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text  = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( preg_replace( '/[ \t]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Whether a meta key looks like it holds private or secret data.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_sensitive_meta_key( $key ) {
		$patterns  = apply_filters(
			'aiva_sensitive_meta_patterns',
			array( 'password', 'passwd', 'pass_', 'secret', 'token', 'api_key', 'apikey', 'private', 'nonce', 'hash', 'salt', 'license', 'licence', 'session', 'ip_address', 'user_ip', 'customer', 'billing', 'shipping_', 'email', 'phone' )
		);
		$key_lower = strtolower( $key );
		foreach ( (array) $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $key_lower, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete indexed database chunks for post types that are currently disabled in Content Selection settings.
	 */
	public static function purge_disabled_chunks() {
		global $wpdb;
		$table_name     = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
		$included_types = get_option( 'aiva_included_types', array( 'page', 'product' ) );

		if ( ! is_array( $included_types ) ) {
			$included_types = array( 'page', 'product' );
		}

		$allowed_types = $included_types;

		// Check Business Profile Info privacy toggles
		$include_contact  = get_option( 'aiva_privacy_include_contact', '1' );
		$include_faqs     = get_option( 'aiva_privacy_include_faqs', '1' );
		$include_policies = get_option( 'aiva_privacy_include_policies', '1' );
		if ( '1' === (string) $include_contact || '1' === (string) $include_faqs || '1' === (string) $include_policies ) {
			$allowed_types[] = 'business_info';
		}

		// Check Navigation Menu privacy toggle
		$include_menus = get_option( 'aiva_privacy_include_menus', '1' );
		if ( '1' === (string) $include_menus ) {
			$allowed_types[] = 'menu_info';
		}

		// Fetch all distinct content_types currently present in the database table
		$existing_types = $wpdb->get_col( "SELECT DISTINCT content_type FROM $table_name" );
		if ( ! empty( $existing_types ) && is_array( $existing_types ) ) {
			foreach ( $existing_types as $type ) {
				if ( ! in_array( $type, $allowed_types, true ) ) {
					$wpdb->delete( $table_name, array( 'content_type' => $type ) );
				}
			}
		}
	}

	/**
	 * Fetch list of IDs of all pages, posts, and products based on settings.
	 *
	 * @return array List of content items to index.
	 */
	public static function get_queue() {
		self::purge_disabled_chunks();

		$included_types = get_option( 'aiva_included_types', array( 'page', 'product' ) );
		if ( ! is_array( $included_types ) || empty( $included_types ) ) {
			return array();
		}

		$args = array(
			'post_type'      => $included_types,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'has_password'   => false,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Verify if a URL should be excluded based on admin settings.
	 *
	 * @param string $url The page permalink.
	 * @return bool True if excluded, false otherwise.
	 */
	public static function is_excluded( $url ) {
		$excluded_input = get_option( 'aiva_excluded_urls', '' );
		if ( empty( $excluded_input ) ) {
			return false;
		}

		$excluded_rules = array_map( 'trim', explode( "\n", str_replace( "\r", '', $excluded_input ) ) );
		$path           = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return false;
		}

		foreach ( $excluded_rules as $rule ) {
			if ( empty( $rule ) ) {
				continue;
			}

			// Handle wildcards like /private-*
			$regex = str_replace( '\*', '.*', preg_quote( $rule, '/' ) );
			if ( preg_match( '/^' . $regex . '/i', $path ) || preg_match( '/^' . $regex . '/i', $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Process a single post ID, chunk it, request vector embeddings, and save to DB.
	 *
	 * @param int $post_id Post, Page, or Product ID.
	 * @return array Array of success logs and status.
	 */
	public static function scan_item( $post_id ) {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'success' => true,
				'logs'    => array( "Skipped: Item ID $post_id no longer exists in WordPress." ),
			);
		}

		$content_type = $post->post_type;

		// Never expose drafts, private items or password-protected content to the public agent.
		if ( ! self::is_indexable_post( $post ) ) {
			$wpdb->delete( $table_name, array( 'post_id' => $post_id ) );
			return array(
				'success' => true,
				'logs'    => array( sprintf( "Skipped: '%s' is not published or is password protected.", $post->post_title ) ),
			);
		}

		// Verify that this post_type is allowed in Content Selection settings
		$included_types = get_option( 'aiva_included_types', array( 'page', 'product' ) );
		if ( ! is_array( $included_types ) || ! in_array( $content_type, $included_types, true ) ) {
			// Delete any existing indexed chunks for this item if its post type is no longer enabled
			$wpdb->delete( $table_name, array( 'post_id' => $post_id ) );
			return array(
				'success' => true,
				'logs'    => array( "Skipped: '{$post->post_title}' (type: '{$content_type}') is disabled in Content Selection." ),
			);
		}

		$url = get_permalink( $post_id );
		if ( self::is_excluded( $url ) ) {
			// Delete any existing indexed chunks for this post if it's now excluded.
			$wpdb->delete( $table_name, array( 'post_id' => $post_id ) );
			return array(
				'success' => true,
				'logs'    => array( "Skipped: '{$post->post_title}' is excluded by path settings." ),
			);
		}

		$title = $post->post_title;
		$logs  = array();

		// 1. Extract and format structured text content based on type
		$full_text = '';
		if ( 'product' === $content_type && class_exists( 'WooCommerce' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$name = $product->get_name();

				$regular_price = $product->get_regular_price();
				$sale_price    = $product->get_sale_price();
				$active_price  = $product->get_price();

				$price_text = 'Price: ' . html_entity_decode( wp_strip_all_tags( wc_price( $active_price ) ) );
				if ( ! empty( $sale_price ) ) {
					$price_text .= ' | Regular Price: ' . html_entity_decode( wp_strip_all_tags( wc_price( $regular_price ) ) ) . ' | Sale Price: ' . html_entity_decode( wp_strip_all_tags( wc_price( $sale_price ) ) ) . ' (On Sale)';
				}

				$sku   = $product->get_sku();
				$stock = $product->is_in_stock() ? 'In Stock' : 'Out of Stock';

				// Get categories list
				$cats = wc_get_product_category_list( $post_id, ', ', '', '' );
				$cats = wp_strip_all_tags( $cats );

				// Get product attributes
				$attributes_text = array();
				$attributes      = $product->get_attributes();
				foreach ( $attributes as $attr_name => $attr ) {
					if ( $attr->is_taxonomy() ) {
						$terms             = $attr->get_terms();
						$term_names        = wp_list_pluck( $terms, 'name' );
						$attributes_text[] = wc_attribute_label( $attr_name ) . ': ' . implode( ', ', $term_names );
					} else {
						$attributes_text[] = wc_attribute_label( $attr_name ) . ': ' . implode( ', ', $attr->get_options() );
					}
				}
				$attr_str = implode( ' | ', $attributes_text );

				// Handle product variations
				$variations_text = array();
				if ( $product->is_type( 'variable' ) ) {
					$variations = $product->get_available_variations();
					foreach ( $variations as $var_data ) {
						$var_attrs = array();
						foreach ( $var_data['attributes'] as $attr_key => $attr_val ) {
							$attr_label  = str_replace( 'attribute_', '', $attr_key );
							$attr_label  = wc_attribute_label( $attr_label );
							$var_attrs[] = "$attr_label: $attr_val";
						}
						$var_price = html_entity_decode( wp_strip_all_tags( wc_price( $var_data['display_price'] ) ) );
						$var_sku   = ! empty( $var_data['sku'] ) ? $var_data['sku'] : 'N/A';
						$var_stock = $var_data['is_in_stock'] ? 'In Stock' : 'Out of Stock';

						$variations_text[] = 'Variation (' . implode( ', ', $var_attrs ) . ') - Price: ' . $var_price . ' | SKU: ' . $var_sku . ' | Availability: ' . $var_stock;
					}
				}
				$var_str = implode( "\n", $variations_text );

				$short_desc = strip_shortcodes( $product->get_short_description() );
				$short_desc = wp_strip_all_tags( $short_desc );

				$desc = strip_shortcodes( $product->get_description() );
				$desc = wp_strip_all_tags( $desc );

				$full_text = 'Product Name: ' . $name . "\n"
							. $price_text . "\n"
							. 'SKU: ' . ( $sku ? $sku : 'N/A' ) . "\n"
							. 'Availability: ' . $stock . "\n"
							. 'Categories: ' . ( $cats ? $cats : 'Uncategorized' ) . "\n";

				if ( ! empty( $attr_str ) ) {
					$full_text .= 'Attributes: ' . $attr_str . "\n";
				}

				// Extra facts visitors often ask about.
				$tags = wp_strip_all_tags( wc_get_product_tag_list( $post_id, ', ' ) );
				if ( $tags ) {
					$full_text .= 'Tags: ' . html_entity_decode( $tags, ENT_QUOTES, 'UTF-8' ) . "\n";
				}
				if ( $product->get_review_count() > 0 ) {
					$full_text .= 'Customer Rating: ' . round( (float) $product->get_average_rating(), 1 ) . '/5 from ' . (int) $product->get_review_count() . " reviews\n";
				}
				if ( $product->is_featured() ) {
					$full_text .= "Featured product: Yes\n";
				}
				if ( $product->has_weight() ) {
					$full_text .= 'Weight: ' . wc_format_weight( $product->get_weight() ) . "\n";
				}
				if ( $product->has_dimensions() ) {
					$full_text .= 'Dimensions: ' . html_entity_decode( wp_strip_all_tags( wc_format_dimensions( $product->get_dimensions( false ) ) ), ENT_QUOTES, 'UTF-8' ) . "\n";
				}
				if ( ! empty( $var_str ) ) {
					$full_text .= "Product Options & Variations:\n" . $var_str . "\n";
				}
				if ( ! empty( $short_desc ) ) {
					$full_text .= 'Summary: ' . trim( $short_desc ) . "\n";
				}
				if ( ! empty( $desc ) ) {
					$full_text .= 'Detailed Description: ' . trim( $desc ) . "\n";
				}
				$full_text .= 'Link: ' . $url . "\n";
			}
		} else {
			// Standard Page/Post/Listing/Custom Post Type
			$desc = strip_shortcodes( $post->post_content );
			$desc = wp_strip_all_tags( $desc );

			// 1. Extract all attached custom taxonomies (Categories, Tags, Property Type, Property Action, City, Area, Features)
			$tax_strings = array();
			$taxonomies  = get_object_taxonomies( $content_type, 'objects' );
			if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
				foreach ( $taxonomies as $tax_slug => $tax_obj ) {
					$terms = get_the_terms( $post_id, $tax_slug );
					if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
						$term_names    = wp_list_pluck( $terms, 'name' );
						$label         = ! empty( $tax_obj->labels->singular_name ) ? $tax_obj->labels->singular_name : $tax_obj->label;
						$tax_strings[] = $label . ': ' . implode( ', ', $term_names );
					}
				}
			}
			$tax_text = ! empty( $tax_strings ) ? "Taxonomies & Attributes:\n" . implode( "\n", $tax_strings ) . "\n" : '';

			// 2. Universal Multi-Theme Key Normalizer Dictionary (WpRentals, Homey, Houzez, RealHomes, ListingPro, Listify, Custom Themes)
			$universal_key_map = array(
				'Price'        => array( 'property_price', 'homey_price', 'fave_property_price', 'listing_price', '_property_price', '_price', 'price', 'rental_price', 'nightly_price', 'daily_price' ),
				'Bedrooms'     => array( 'property_bedrooms', 'homey_bedrooms', 'fave_property_bedrooms', 'listing_bedrooms', '_property_bedrooms', '_bedrooms', 'bedrooms', 'beds' ),
				'Bathrooms'    => array( 'property_bathrooms', 'homey_baths', 'fave_property_bathrooms', 'listing_bathrooms', '_property_bathrooms', '_bathrooms', 'bathrooms', 'baths' ),
				'Guests'       => array( 'property_guests', 'homey_guests', 'fave_property_guests', 'listing_guests', '_property_guests', '_guests', 'guests', 'guest_capacity', 'capacity', 'max_guests' ),
				'Size'         => array( 'property_size', 'homey_size', 'fave_property_size', 'listing_size', '_property_size', '_size', 'size', 'area_size', 'sqft', 'square_feet' ),
				'Address'      => array( 'property_address', 'homey_address', 'fave_property_address', 'listing_address', '_property_address', '_address', 'address', 'full_address', 'location_address' ),
				'City'         => array( 'property_city', 'homey_city', 'fave_property_city', 'listing_city', '_property_city', '_city', 'city' ),
				'Country'      => array( 'property_country', 'homey_country', 'fave_property_country', 'listing_country', '_property_country', '_country', 'country' ),
				'Zip / Postal' => array( 'property_zip', 'homey_zip', 'fave_property_zip', 'listing_zip', '_property_zip', '_zip', 'zip', 'postal_code' ),
				'Latitude'     => array( 'property_latitude', 'homey_latitude', 'fave_property_lat', 'listing_lat', '_property_latitude', '_latitude', 'latitude', 'lat', 'geolocation_lat' ),
				'Longitude'    => array( 'property_longitude', 'homey_longitude', 'fave_property_lng', 'listing_lng', '_property_longitude', '_longitude', 'longitude', 'lng', 'geolocation_lng' ),
				'Check-in'     => array( 'property_checkin', 'homey_checkin', 'checkin_time', '_checkin_time' ),
				'Check-out'    => array( 'property_checkout', 'homey_checkout', 'checkout_time', '_checkout_time' ),
			);

			$extracted_normalized = array();
			$processed_keys       = array();

			// First pass: Match universal dictionary keys across all themes
			foreach ( $universal_key_map as $label => $candidate_keys ) {
				foreach ( $candidate_keys as $key ) {
					$meta_val = get_post_meta( $post_id, $key, true );
					if ( '' !== $meta_val && null !== $meta_val && ! is_array( $meta_val ) ) {
						$val_str = trim( wp_strip_all_tags( (string) $meta_val ) );
						if ( '' !== $val_str ) {
							$extracted_normalized[] = "$label: " . $val_str;
							$processed_keys[]       = $key;
							$processed_keys[]       = '_' . $key;
							break; // Stop at first match for this normalized label
						}
					}
				}
			}

			// Second pass: Universal Dynamic Fallback for ANY Custom Post Type (Events, Jobs, Vehicles, Courses, ACF, Directories)
			$system_blacklist = array(
				'_edit_lock',
				'_edit_last',
				'_wp_page_template',
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_elementor_data',
				'_elementor_controls_usage',
				'_elementor_css',
				'_elementor_edit_mode',
				'_elementor_template_type',
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'_rank_math_title',
				'_rank_math_description',
				'_thumbnail_id',
				'_wp_old_slug',
				'_pingme',
				'_encloseme',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
			);

			// Only public meta keys are read here: WordPress treats "_"-prefixed keys as protected/internal,
			// and keys that look like secrets or personal data are skipped. Themes' "_"-prefixed listing keys
			// are already covered by the universal dictionary above.
			$meta_keys = get_post_custom_keys( $post_id );
			if ( ! empty( $meta_keys ) && is_array( $meta_keys ) ) {
				foreach ( $meta_keys as $key ) {
					if ( in_array( $key, $processed_keys, true ) || in_array( $key, $system_blacklist, true ) || 0 === strpos( $key, '_aiva' ) ) {
						continue;
					}
					if ( is_protected_meta( $key, 'post' ) || self::is_sensitive_meta_key( $key ) ) {
						continue;
					}
					if ( ! apply_filters( 'aiva_index_meta_key', true, $key, $post_id ) ) {
						continue;
					}

					$val_str = self::flatten_meta_value( get_post_meta( $post_id, $key, true ) );
					if ( '' !== $val_str && strlen( $val_str ) < 400 && ! is_numeric( $key ) ) {
						$clean_key = ucwords( str_replace( array( '-', '_', 'property', 'homey', 'fave', 'listing', 'job', 'event' ), ' ', $key ) );
						$clean_key = trim( preg_replace( '/\s+/', ' ', $clean_key ) );
						if ( ! empty( $clean_key ) ) {
							$extracted_normalized[] = "$clean_key: " . $val_str;
						}
					}
				}
			}
			$meta_text = ! empty( $extracted_normalized ) ? "Listing Details & Specifications:\n" . implode( "\n", array_unique( $extracted_normalized ) ) . "\n" : '';

			$full_text = 'Title: ' . $title . "\n"
						. 'Type: ' . ucfirst( $content_type ) . "\n"
						. $tax_text
						. $meta_text
						. 'Content & Overview: ' . trim( $desc ) . "\n"
						. 'Link: ' . $url . "\n";
		}

		if ( empty( trim( $full_text ) ) ) {
			return array(
				'success' => true,
				'logs'    => array( "Skipped: '{$title}' has no text content." ),
			);
		}

		// 2. Chunking Content
		// Remove the empty lines and HTML entities left behind by the block editor, so each 800-character
		// chunk is filled with real content instead of blank space.
		$full_text = self::clean_text( $full_text );

		$chunks = self::chunk_text( $full_text );

		// Every chunk after the first repeats which item it belongs to, so a matched chunk such as
		// "…made from sterling silver…" still tells the AI it is about "Silver Cufflinks".
		$type_obj   = get_post_type_object( $content_type );
		$type_label = $type_obj ? $type_obj->labels->singular_name : ucfirst( $content_type );
		$item_title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		foreach ( $chunks as $index => $chunk_content ) {
			if ( $index > 0 && '' !== $item_title ) {
				$chunks[ $index ] = '[' . $type_label . ': ' . $item_title . ' (continued)]' . "\n" . $chunk_content;
			}
		}

		// 3. Clear out old database chunks for this specific item before inserting new ones
		$wpdb->delete( $table_name, array( 'post_id' => $post_id ) );

		// 4. Save and index chunks
		$success_count = 0;
		foreach ( $chunks as $index => $chunk_content ) {
			$chunk_hash = md5( $chunk_content );

			// Get vector embedding via Gemini API
			$embedding_values = AIVA_API::get_embedding( $chunk_content, 'RETRIEVAL_DOCUMENT' );
			if ( is_wp_error( $embedding_values ) ) {
				return array(
					'success' => false,
					'error'   => $embedding_values->get_error_message(),
					'logs'    => array_merge( $logs, array( "Fatal: Embedding failed for '{$title}': " . $embedding_values->get_error_message() ) ),
				);
			}

			// Insert chunk & vector to DB
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'post_id'      => $post_id,
					'content_type' => $content_type,
					'source_url'   => $url,
					'chunk_text'   => $chunk_content,
					'chunk_hash'   => $chunk_hash,
					'embedding'    => wp_json_encode( $embedding_values ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				++$success_count;
			}
		}

		if ( $success_count > 0 ) {
			$logs[] = "Indexed: '{$title}' ({$success_count} text chunk(s) stored).";
		}

		return array(
			'success' => true,
			'logs'    => $logs,
		);
	}

	/**
	 * Chunk text using sliding character windows while respecting paragraph/sentence boundary markers.
	 */
	public static function chunk_text( $text, $max_len = 800, $overlap = 150 ) {
		$chunks  = array();
		$text    = (string) $text;
		$len     = mb_strlen( $text );
		$max_len = max( 100, (int) $max_len );
		$overlap = max( 0, min( (int) $overlap, (int) floor( $max_len / 2 ) ) );

		if ( $len <= $max_len ) {
			return array( $text );
		}

		$start = 0;
		while ( $start < $len ) {
			$end = min( $start + $max_len, $len );

			if ( $end < $len ) {
				$window = mb_substr( $text, $start, $max_len );

				// Prefer ending at a sentence, then at a space, but only if the break keeps the
				// chunk longer than the overlap. Otherwise hard-cut so the loop always moves forward.
				$break_point = mb_strrpos( $window, '.' );
				if ( false === $break_point || $break_point < ( $max_len / 2 ) ) {
					$break_point = mb_strrpos( $window, ' ' );
				}
				if ( false !== $break_point && $break_point > $overlap ) {
					$end = $start + $break_point + 1;
				}
			}

			$chunk = trim( mb_substr( $text, $start, $end - $start ) );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}

			if ( $end >= $len ) {
				break;
			}

			// Guaranteed progress: the next window always starts after the current one did.
			$start = max( $end - $overlap, $start + 1 );
		}

		return $chunks;
	}

	/**
	 * Calculate cosine similarity between two float vectors (dot product normalized).
	 */
	public static function cosine_similarity( $vec1, $vec2 ) {
		$dot   = 0.0;
		$norm1 = 0.0;
		$norm2 = 0.0;
		$count = count( $vec1 );

		for ( $i = 0; $i < $count; $i++ ) {
			$dot   += $vec1[ $i ] * $vec2[ $i ];
			$norm1 += $vec1[ $i ] * $vec1[ $i ];
			$norm2 += $vec2[ $i ] * $vec2[ $i ];
		}

		if ( 0.0 === $norm1 || 0.0 === $norm2 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $norm1 ) * sqrt( $norm2 ) );
	}

	/**
	 * Query the local database for chunks similar to the query vector.
	 *
	 * @param array $query_vector The float embedding array from the user query.
	 * @param int   $limit Number of top matching chunks to fetch.
	 * @return array Matched chunks list.
	 */
	public static function similarity_search( $query_vector, $limit = 4 ) {
		global $wpdb;
		$table_name = esc_sql( self::table() );

		if ( ! is_array( $query_vector ) || empty( $query_vector ) ) {
			return array();
		}

		$threshold = (float) apply_filters( 'aiva_similarity_threshold', 0.40 );
		$dims      = count( $query_vector );
		$batch     = 200;
		$last_id   = 0;
		$top       = array();

		// Read embeddings in small batches so memory stays flat no matter how large the index grows.
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, content_type, source_url, chunk_text, embedding FROM $table_name WHERE embedding IS NOT NULL AND id > %d ORDER BY id ASC LIMIT %d",
					$last_id,
					$batch
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id   = (int) $row->id;
				$db_vector = json_decode( $row->embedding, true );
				if ( ! is_array( $db_vector ) || count( $db_vector ) !== $dims ) {
					continue;
				}

				$score = self::cosine_similarity( $query_vector, $db_vector );
				unset( $db_vector );

				if ( $score > $threshold ) {
					$top[] = array(
						'post_id'      => (int) $row->post_id,
						'content_type' => $row->content_type,
						'source_url'   => $row->source_url,
						'chunk_text'   => $row->chunk_text,
						'score'        => $score,
					);
					// Keep only the best matches in memory.
					if ( count( $top ) > $limit * 3 ) {
						usort( $top, array( __CLASS__, 'sort_by_score' ) );
						$top = array_slice( $top, 0, $limit );
					}
				}
			}
			$fetched = count( $rows );
			unset( $rows );
		} while ( $fetched === $batch );

		usort( $top, array( __CLASS__, 'sort_by_score' ) );
		return array_slice( $top, 0, $limit );
	}

	/**
	 * Sort callback: highest score first.
	 *
	 * @param array $a First item.
	 * @param array $b Second item.
	 * @return int
	 */
	public static function sort_by_score( $a, $b ) {
		return $b['score'] <=> $a['score'];
	}

	/**
	 * Keyword fallback search used when embeddings are unavailable (quota, network, dimension change).
	 *
	 * @param string $query Search text.
	 * @param int    $limit Max results.
	 * @return array
	 */
	public static function keyword_search( $query, $limit = 4 ) {
		global $wpdb;
		$table_name = esc_sql( self::table() );

		$stop_words = array( 'what', 'where', 'when', 'which', 'who', 'how', 'can', 'you', 'the', 'and', 'for', 'this', 'that', 'with', 'your', 'our', 'about', 'have', 'does', 'are', 'there', 'any', 'please', 'tell', 'show', 'give', 'hello', 'thanks' );
		$words      = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $query ), -1, PREG_SPLIT_NO_EMPTY );
		$keywords   = array();
		foreach ( (array) $words as $word ) {
			if ( mb_strlen( $word ) >= 3 && ! in_array( $word, $stop_words, true ) ) {
				$keywords[] = $word;
			}
		}
		$keywords = array_slice( array_unique( $keywords ), 0, 6 );
		if ( empty( $keywords ) ) {
			return array();
		}

		// One simple prepared query per keyword (max 6), merged in PHP.
		$rows = array();
		foreach ( $keywords as $kw ) {
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, content_type, source_url, chunk_text FROM $table_name WHERE chunk_text LIKE %s ORDER BY id DESC LIMIT %d",
					'%' . $wpdb->esc_like( $kw ) . '%',
					20
				),
				ARRAY_A
			);
			foreach ( (array) $found as $row ) {
				$rows[ (int) $row['id'] ] = $row;
			}
		}
		$rows = array_values( $rows );

		if ( empty( $rows ) ) {
			return array();
		}

		// Rank rows by how many keywords they contain.
		foreach ( $rows as &$row ) {
			$haystack     = mb_strtolower( $row['chunk_text'] );
			$row['score'] = 0;
			foreach ( $keywords as $kw ) {
				if ( false !== mb_strpos( $haystack, $kw ) ) {
					++$row['score'];
				}
			}
			$row['post_id'] = (int) $row['post_id'];
		}
		unset( $row );

		usort( $rows, array( __CLASS__, 'sort_by_score' ) );
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Scan active site navigation menus and index their structure.
	 */
	public static function scan_menus() {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );

		// Clear existing menus chunks first.
		$wpdb->delete( $table_name, array( 'content_type' => 'menu_info' ) );

		// Get all registered menu locations
		$locations = get_nav_menu_locations();
		if ( empty( $locations ) ) {
			return array(
				'success' => true,
				'logs'    => array( 'No menu locations found.' ),
			);
		}

		$menu_text = "Website Navigation Menus:\n";
		foreach ( $locations as $location_name => $menu_id ) {
			$menu_object = wp_get_nav_menu_object( $menu_id );
			if ( ! $menu_object ) {
				continue;
			}

			$menu_items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $menu_items ) ) {
				continue;
			}

			$menu_text .= 'Menu Location: ' . ucwords( str_replace( '_', ' ', $location_name ) ) . ' (Menu Name: ' . $menu_object->name . "):\n";
			foreach ( $menu_items as $item ) {
				$menu_text .= "- Navigation Link: '" . $item->title . "' pointing to URL " . $item->url . "\n";
			}
			$menu_text .= "\n";
		}

		$chunks      = self::chunk_text( $menu_text, 800, 150 );
		$all_success = true;
		$logs        = array();

		foreach ( $chunks as $index => $chunk ) {
			$embedding = AIVA_API::get_embedding( $chunk, 'RETRIEVAL_DOCUMENT' );
			if ( is_wp_error( $embedding ) ) {
				$all_success = false;
				$logs[]      = 'Menu indexing failed: ' . $embedding->get_error_message();
				continue;
			}

			$wpdb->insert(
				$table_name,
				array(
					'post_id'      => 0,
					'content_type' => 'menu_info',
					'chunk_text'   => $chunk,
					'chunk_hash'   => md5( $chunk ),
					'embedding'    => wp_json_encode( $embedding ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		if ( $all_success ) {
			$logs[] = 'Indexed site navigation menus successfully.';
			return array(
				'success' => true,
				'logs'    => $logs,
			);
		} else {
			return array(
				'success' => false,
				'error'   => 'Failed to generate embeddings for menus.',
				'logs'    => $logs,
			);
		}
	}
}
