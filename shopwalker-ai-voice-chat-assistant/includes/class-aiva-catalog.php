<?php
/**
 * Live WooCommerce catalog lookup.
 *
 * Vector search (RAG) is good at "what is your returns policy?" but it only returns the 4 closest
 * text chunks, so it cannot list a whole catalogue, filter by a price range or browse a category.
 * This class answers those shopping questions straight from WooCommerce, so products work the moment
 * they are published (no indexing needed) and prices/stock are always current.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Catalog helper.
 */
class AIVA_Catalog {

	/**
	 * Max products passed to the AI / shown as cards.
	 */
	const MAX_RESULTS = 8;

	/**
	 * Whether live catalog lookups are available (WooCommerce active and products allowed in settings).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$included = get_option( 'aiva_included_types', array( 'page', 'product' ) );
		$enabled  = is_array( $included ) && in_array( 'product', $included, true );

		/**
		 * Filter whether the live WooCommerce catalog lookup is used.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'aiva_live_catalog_enabled', $enabled );
	}

	/**
	 * Normalise a word for loose matching: lowercase, no spaces/hyphens, simple singular.
	 * "Cuff Links", "cuff-links" and "cufflinks" all become "cufflink".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = mb_strtolower( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );
		if ( mb_strlen( $text ) > 3 ) {
			if ( 'ies' === mb_substr( $text, -3 ) ) {
				$text = mb_substr( $text, 0, -3 ) . 'y';
			} elseif ( 'es' === mb_substr( $text, -2 ) && preg_match( '/(ch|sh|x|ss)es$/u', $text ) ) {
				$text = mb_substr( $text, 0, -2 );
			} elseif ( 's' === mb_substr( $text, -1 ) && 'ss' !== mb_substr( $text, -2 ) ) {
				$text = mb_substr( $text, 0, -1 );
			}
		}
		return $text;
	}

	/**
	 * Extract a price range from a question.
	 * Handles "between 100 and 300", "100 to 300", "100-300", "under 200", "below 50", "over 100",
	 * "more than 100", "less than 300", "around 200", "for 200".
	 *
	 * @param string $text Question.
	 * @return array|null array( 'min' => float|null, 'max' => float|null ) or null.
	 */
	public static function parse_price_range( $text ) {
		$t   = mb_strtolower( str_replace( ',', '', (string) $text ) );
		$num = '(?:[^\d\s]{0,3})?\s*(\d+(?:\.\d+)?)\s*(?:k\b)?';

		if ( preg_match( '/(?:between|from)?\s*' . $num . '\s*(?:-|–|to|and|till|until)\s*' . $num . '/u', $t, $m ) ) {
			$a = (float) $m[1];
			$b = (float) $m[2];
			if ( $a > 0 || $b > 0 ) {
				return array(
					'min' => min( $a, $b ),
					'max' => max( $a, $b ),
				);
			}
		}
		if ( preg_match( '/(?:under|below|less than|cheaper than|up to|upto|max(?:imum)?|within|no more than)\s*' . $num . '/u', $t, $m ) ) {
			return array(
				'min' => null,
				'max' => (float) $m[1],
			);
		}
		if ( preg_match( '/(?:over|above|more than|greater than|at least|min(?:imum)?|starting (?:at|from))\s*' . $num . '/u', $t, $m ) ) {
			return array(
				'min' => (float) $m[1],
				'max' => null,
			);
		}
		if ( preg_match( '/(?:around|about|approx(?:imately)?|roughly|near)\s*' . $num . '/u', $t, $m ) ) {
			$v = (float) $m[1];
			return array(
				'min' => $v * 0.8,
				'max' => $v * 1.2,
			);
		}
		return null;
	}

	/**
	 * Whether the question is about browsing / finding / pricing products.
	 *
	 * @param string $text Question.
	 * @return bool
	 */
	public static function has_shopping_intent( $text ) {
		return (bool) preg_match(
			'/\b(product|products|item|items|catalog|catalogue|category|categories|collection|collections|range|price|prices|priced|pricing|cost|costs|cheap|cheaper|cheapest|pricier|expensive|affordable|budget|buy|purchase|order|shop|store|stock|available|availability|sell|selling|sale|offer|offers|recommend|recommended|suggest|gift|gifts|show|list|browse|find|looking|search|have|got|new|newest|latest|arrivals?|popular|popularity|trending|best|bestseller|bestsellers|best-?selling|top|rated|rating|reviews?|discount|discounts|deal|deals|clearance|featured|sold)\b/i',
			(string) $text
		);
	}

	/**
	 * Get visible product categories with product counts.
	 *
	 * @return array List of WP_Term.
	 */
	public static function get_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 50,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$uncategorized = (int) get_option( 'default_product_cat', 0 );
		$out           = array();
		foreach ( $terms as $term ) {
			if ( (int) $term->term_id === $uncategorized && 'uncategorized' === $term->slug ) {
				continue;
			}
			$out[] = $term;
		}
		return $out;
	}

	/**
	 * Find categories named in the question (loose match: "cufflinks" finds "Cuff Links").
	 *
	 * @param string $text       Question.
	 * @param array  $categories Categories from get_categories().
	 * @return array Matching term IDs.
	 */
	public static function match_categories( $text, $categories ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$grams = array();
		$count = count( $words );
		for ( $i = 0; $i < $count; $i++ ) {
			$grams[] = self::normalize( $words[ $i ] );
			if ( $i + 1 < $count ) {
				$grams[] = self::normalize( $words[ $i ] . $words[ $i + 1 ] );
			}
			if ( $i + 2 < $count ) {
				$grams[] = self::normalize( $words[ $i ] . $words[ $i + 1 ] . $words[ $i + 2 ] );
			}
		}
		$grams = array_filter(
			array_unique( $grams ),
			function ( $g ) {
				return mb_strlen( $g ) >= 3;
			}
		);

		$ids = array();
		foreach ( $categories as $term ) {
			$name = self::normalize( $term->name );
			$slug = self::normalize( $term->slug );
			if ( in_array( $name, $grams, true ) || in_array( $slug, $grams, true ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * Meaningful search words from the question.
	 *
	 * @param string $text Question.
	 * @return array
	 */
	public static function keywords( $text ) {
		$stop  = array( 'the', 'and', 'for', 'you', 'your', 'our', 'are', 'can', 'could', 'would', 'please', 'show', 'find', 'help', 'looking', 'look', 'want', 'need', 'have', 'got', 'any', 'some', 'all', 'this', 'that', 'these', 'those', 'what', 'which', 'with', 'from', 'about', 'site', 'website', 'store', 'shop', 'product', 'products', 'item', 'items', 'price', 'prices', 'range', 'between', 'under', 'over', 'above', 'below', 'less', 'more', 'than', 'cheap', 'cheapest', 'expensive', 'available', 'buy', 'sell', 'major', 'interested', 'interesting', 'in', 'tell', 'me', 'give', 'list', 'category', 'categories', 'okay', 'yes', 'sure', 'there', 'here', 'something', 'anything', 'thing', 'things', 'good', 'best', 'new', 'latest', 'much', 'many', 'how', 'does', 'did', 'will', 'just', 'like', 'also', 'budget', 'around', 'about', 'costs', 'cost', 'ship', 'shipping', 'popular', 'popularity', 'bestseller', 'bestsellers', 'seller', 'sellers', 'selling', 'sold', 'top', 'rated', 'rating', 'ratings', 'review', 'reviews', 'reviewed', 'newest', 'arrival', 'arrivals', 'sale', 'sales', 'discount', 'discounts', 'discounted', 'deal', 'deals', 'offer', 'offers', 'featured', 'recommend', 'recommended', 'most', 'trending', 'favorite', 'favourite', 'one', 'ones', 'customers', 'people', 'buying', 'bought', 'right', 'now', 'currently', 'today', 'week', 'month', 'highest', 'lowest', 'number' );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $words as $w ) {
			if ( mb_strlen( $w ) >= 3 && ! is_numeric( $w ) && ! in_array( $w, $stop, true ) ) {
				$out[] = $w;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/**
	 * Base query args for visible, published products.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private static function base_args( $limit ) {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'ignore_sticky_posts'    => true,
		);

		// Respect WooCommerce catalog visibility ("Hidden" / "Search results only" etc.).
		$hidden = get_term_by( 'slug', 'exclude-from-catalog', 'product_visibility' );
		if ( $hidden ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Needed to honour catalog visibility.
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( (int) $hidden->term_taxonomy_id ),
					'operator' => 'NOT IN',
				),
			);
		}
		return $args;
	}

	/**
	 * Search the live catalog.
	 *
	 * @param string $question   Visitor question.
	 * @param string $extra_text Extra context (e.g. recent conversation) used for category/keyword matching.
	 * @return array array( 'triggered' => bool, 'product_ids' => int[], 'categories' => WP_Term[], 'range' => array|null, 'matched_categories' => int[], 'total_in_range' => int )
	 */
	public static function search( $question, $extra_text = '' ) {
		$result = array(
			'triggered'          => false,
			'product_ids'        => array(),
			'categories'         => array(),
			'range'              => null,
			'matched_categories' => array(),
			'keywords'           => array(),
		);

		if ( ! self::is_enabled() ) {
			return $result;
		}

		$categories = self::get_categories();
		$range      = self::parse_price_range( $question );
		$cat_ids    = self::match_categories( $question, $categories );
		$keywords   = self::keywords( $question );
		$intent     = self::has_shopping_intent( $question );

		$product_ids = array();

		// 1. Keyword match on product titles/SKU (via WooCommerce's own search), plus loose normalised title match.
		if ( ! empty( $keywords ) ) {
			$product_ids = self::search_by_keywords( $keywords, $intent );
		}

		// Only treat it as a catalog question when the CURRENT question gives a real signal.
		if ( ! $intent && null === $range && empty( $cat_ids ) && empty( $product_ids ) ) {
			return $result;
		}

		// Follow-ups that point back ("which of these is cheaper?", "show me them") reuse the category
		// from the last turns. Questions asking for everything ("all products") never do.
		$wants_everything = (bool) preg_match( '/\b(all|every|everything|whole|entire|other|else|any|anything)\b/i', $question );
		$points_back      = (bool) preg_match( '/\b(these|those|them|they|ones|it|cheaper|cheapest|pricier|similar|same)\b/i', $question );
		if ( empty( $product_ids ) && '' !== $extra_text && $points_back && ! $wants_everything ) {
			if ( empty( $cat_ids ) ) {
				$cat_ids = self::match_categories( $extra_text, $categories );
			}
			if ( null === $range ) {
				$range = self::parse_price_range( $extra_text );
			}
		}

		// Sorting / filtering intents: price, popularity (real sales), rating, newest, on sale, featured.
		$modes       = self::detect_modes( $question );
		$sort        = $modes['sort'];
		$superlative = $modes['superlative'];
		$on_sale     = $modes['on_sale'];
		$featured    = $modes['featured'];

		$result['triggered']          = true;
		$result['categories']         = $categories;
		$result['range']              = $range;
		$result['matched_categories'] = $cat_ids;
		$result['keywords']           = $keywords;

		// 2. Category / price browse.
		if ( ! empty( $cat_ids ) || null !== $range || empty( $product_ids ) || $on_sale || $featured ) {
			$args = self::base_args( self::MAX_RESULTS * 3 );

			if ( $on_sale || $featured ) {
				$ids = null;
				if ( $on_sale ) {
					$ids = array_map( 'intval', wc_get_product_ids_on_sale() );
				}
				if ( $featured ) {
					$feat = array_map( 'intval', wc_get_featured_product_ids() );
					$ids  = null === $ids ? $feat : array_values( array_intersect( $ids, $feat ) );
				}
				$args['post__in'] = ! empty( $ids ) ? $ids : array( 0 );
			}

			if ( ! empty( $cat_ids ) ) {
				$args['tax_query']   = isset( $args['tax_query'] ) ? $args['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $cat_ids,
					'include_children' => true,
				);
				if ( count( $args['tax_query'] ) > 1 ) {
					$args['tax_query']['relation'] = 'AND';
				}
			}

			if ( null !== $range ) {
				$meta = array(
					'key'  => '_price',
					'type' => 'DECIMAL(12,2)',
				);
				if ( null !== $range['min'] && null !== $range['max'] ) {
					$meta['value']   = array( $range['min'], $range['max'] );
					$meta['compare'] = 'BETWEEN';
				} elseif ( null !== $range['max'] ) {
					$meta['value']   = $range['max'];
					$meta['compare'] = '<=';
				} else {
					$meta['value']   = $range['min'];
					$meta['compare'] = '>=';
				}
				$args['meta_query'] = array( $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Price filter.
			}

			$order_meta = array(
				'price_asc'  => array( '_price', 'ASC' ),
				'price_desc' => array( '_price', 'DESC' ),
				'popular'    => array( 'total_sales', 'DESC' ),
				'rating'     => array( '_wc_average_rating', 'DESC' ),
			);
			if ( isset( $order_meta[ $sort ] ) ) {
				$args['meta_key'] = $order_meta[ $sort ][0]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting.
				$args['orderby']  = 'meta_value_num';
				$args['order']    = $order_meta[ $sort ][1];
			} elseif ( 'newest' === $sort ) {
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
			} elseif ( null !== $range ) {
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting.
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
			} else {
				$args['orderby'] = array(
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				);
			}

			$browse = get_posts( $args );

			// When keywords also matched, keep the overlap first, then the rest.
			if ( ! empty( $product_ids ) && ( null !== $range || ! empty( $cat_ids ) ) ) {
				$overlap = array_values( array_intersect( $product_ids, $browse ) );
				// If the keyword didn't narrow anything inside the range/category, show the browse results.
				$product_ids = ! empty( $overlap ) ? $overlap : $browse;
			} elseif ( empty( $product_ids ) ) {
				$product_ids = $browse;
			}
		}

		// 3. Final visibility / price check with real product objects.
		$final = array();
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			if ( ( $on_sale && ! $product->is_on_sale() ) || ( $featured && ! $product->is_featured() ) ) {
				continue;
			}
			if ( null !== $range ) {
				$price = (float) $product->get_price();
				if ( ( null !== $range['min'] && $price < $range['min'] ) || ( null !== $range['max'] && $price > $range['max'] ) ) {
					continue;
				}
			}
			$final[] = $pid;
			if ( count( $final ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		if ( '' !== $sort && count( $final ) > 1 ) {
			$final = self::sort_products( $final, $sort );
		}
		if ( $superlative && count( $final ) > 3 ) {
			$final = array_slice( $final, 0, 3 );
		}

		$result['on_sale']     = $on_sale;
		$result['featured']    = $featured;
		$result['range']       = $range;
		$result['sort']        = $sort;
		$result['product_ids'] = $final;
		return $result;
	}

	/**
	 * Detect sorting and filtering intents in a question.
	 *
	 * @param string $question Question.
	 * @return array array( 'sort' => string, 'superlative' => bool, 'on_sale' => bool, 'featured' => bool )
	 */
	public static function detect_modes( $question ) {
		$q    = mb_strtolower( (string) $question );
		$sort = '';

		if ( preg_match( '/\b(cheapest|cheaper|lowest[- ]priced?|least expensive|most affordable|budget)\b/u', $q ) ) {
			$sort = 'price_asc';
		} elseif ( preg_match( '/\b(most expensive|priciest|pricier|highest[- ]priced?|premium|luxury)\b/u', $q ) ) {
			$sort = 'price_desc';
		} elseif ( preg_match( '/\b(top[- ]?rated|best[- ]?rated|highest[- ]?rated|best reviews?|most reviewed|best reviewed|rating|ratings|stars?)\b/u', $q ) ) {
			$sort = 'rating';
		} elseif ( preg_match( '/\b(popular|popularity|best[- ]?sell(?:er|ers|ing)|top[- ]?sell(?:er|ers|ing)|most (?:sold|bought|ordered|purchased|wanted|loved)|trending|hot|favou?rites?|people (?:buy|like|love)|customers (?:buy|like|love))\b/u', $q ) ) {
			$sort = 'popular';
		} elseif ( preg_match( '/\b(new|newest|latest|recent|just arrived|new arrivals?|arrivals?)\b/u', $q ) ) {
			$sort = 'newest';
		} elseif ( preg_match( '/\bbest\b/u', $q ) ) {
			$sort = 'popular';
		}

		$on_sale  = (bool) preg_match( '/\b(on sale|sale|discount(?:s|ed)?|deals?|offers?|clearance|reduced|promotion|promo)\b/u', $q );
		$featured = (bool) preg_match( '/\b(featured|staff picks?|editor\'?s? picks?)\b/u', $q );

		// "which product is most popular" wants a short answer; "show me popular products" wants a list.
		$superlative = (bool) preg_match( '/\b(most|cheapest|priciest|top|best|number one|no\.? ?1|#1|highest|lowest|newest|latest)\b/u', $q )
			&& ! preg_match( '/\b(products|items|ones|list|all|some|few)\b/u', $q )
			&& '' !== $sort;

		return array(
			'sort'        => $sort,
			'superlative' => $superlative,
			'on_sale'     => $on_sale,
			'featured'    => $featured,
		);
	}

	/**
	 * Sort product IDs by a mode.
	 *
	 * @param int[]  $ids  Product IDs.
	 * @param string $sort Mode.
	 * @return int[]
	 */
	public static function sort_products( $ids, $sort ) {
		$values = array();
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			switch ( $sort ) {
				case 'price_asc':
				case 'price_desc':
					$values[ $pid ] = (float) $p->get_price();
					break;
				case 'popular':
					// Sales first, then rating and reviews as tie-breakers.
					$values[ $pid ] = ( (int) $p->get_total_sales() * 1000000 ) + ( (float) $p->get_average_rating() * 10000 ) + (int) $p->get_review_count();
					break;
				case 'rating':
					$values[ $pid ] = ( (float) $p->get_average_rating() * 100000 ) + (int) $p->get_review_count();
					break;
				case 'newest':
					$created        = $p->get_date_created();
					$values[ $pid ] = $created ? $created->getTimestamp() : 0;
					break;
				default:
					$values[ $pid ] = 0;
			}
		}
		if ( 'price_asc' === $sort ) {
			asort( $values );
		} else {
			arsort( $values );
		}
		return array_keys( $values );
	}

	/**
	 * Find product IDs whose title or SKU loosely matches the keywords.
	 * With shopping intent, matches in the product description also count.
	 *
	 * @param array $keywords Keywords.
	 * @param bool  $intent   Whether the question clearly is about shopping.
	 * @return int[]
	 */
	private static function search_by_keywords( $keywords, $intent ) {
		global $wpdb;
		$ids = array();

		foreach ( $keywords as $kw ) {
			$norm = self::normalize( $kw );
			if ( mb_strlen( $norm ) < 3 ) {
				continue;
			}

			// WordPress search (title, excerpt, content).
			foreach ( array_unique( array( $kw, $norm ) ) as $term ) {
				$args      = self::base_args( self::MAX_RESULTS * 2 );
				$args['s'] = $term;
				foreach ( get_posts( $args ) as $pid ) {
					if ( $intent || false !== mb_strpos( self::normalize( get_the_title( $pid ) ), $norm ) ) {
						$ids[] = (int) $pid;
					}
				}
			}

			// Space-insensitive title match: "cufflinks" also finds "Cuff Links Set".
			$like  = '%' . $wpdb->esc_like( $norm ) . '%';
			$found = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight title lookup, results are re-validated with wc_get_product().
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_password = '' AND REPLACE(REPLACE(LOWER(post_title), ' ', ''), '-', '') LIKE %s LIMIT %d",
					$like,
					self::MAX_RESULTS * 2
				)
			);
			$ids   = array_merge( $ids, array_map( 'intval', (array) $found ) );

			// SKU match.
			$sku_id = wc_get_product_id_by_sku( $kw );
			if ( $sku_id ) {
				$ids[] = (int) $sku_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Build the text block given to the AI.
	 *
	 * @param array $result Result from search().
	 * @return string
	 */
	public static function build_context( $result ) {
		if ( empty( $result['triggered'] ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = 'LIVE STORE CATALOG (real-time data from the shop database — this is authoritative; use these exact names and prices):';

		if ( ! empty( $result['categories'] ) ) {
			$cats = array();
			foreach ( $result['categories'] as $term ) {
				$cats[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) . ' (' . (int) $term->count . ')';
			}
			$lines[] = 'Product categories in this store: ' . implode( ', ', $cats ) . '.';
		}

		$total   = (int) wp_count_posts( 'product' )->publish;
		$lines[] = 'Total published products: ' . $total . '.';

		$filter = array();
		if ( ! empty( $result['matched_categories'] ) ) {
			$names = array();
			foreach ( $result['matched_categories'] as $tid ) {
				$t = get_term( $tid, 'product_cat' );
				if ( $t && ! is_wp_error( $t ) ) {
					$names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			if ( $names ) {
				$filter[] = 'category ' . implode( ' / ', $names );
			}
		}
		if ( ! empty( $result['range'] ) ) {
			$min      = null !== $result['range']['min'] ? self::plain_price( $result['range']['min'] ) : '';
			$max      = null !== $result['range']['max'] ? self::plain_price( $result['range']['max'] ) : '';
			$filter[] = 'price ' . ( $min && $max ? "$min to $max" : ( $max ? "up to $max" : "from $min" ) );
		}

		if ( empty( $result['product_ids'] ) ) {
			$lines[] = 'Matching products' . ( $filter ? ' (' . implode( ', ', $filter ) . ')' : '' ) . ': NONE found. Say so honestly and suggest the categories above.';
			return implode( "\n", $lines ) . "\n\n";
		}

		$sort_labels = array(
			'price_asc'  => 'sorted from cheapest',
			'price_desc' => 'sorted from most expensive',
			'popular'    => 'sorted by popularity (real sales, most sold first)',
			'rating'     => 'sorted by customer rating',
			'newest'     => 'sorted newest first',
		);
		if ( ! empty( $result['sort'] ) && isset( $sort_labels[ $result['sort'] ] ) ) {
			$filter[] = $sort_labels[ $result['sort'] ];
		}
		if ( ! empty( $result['on_sale'] ) ) {
			$filter[] = 'on sale only';
		}
		if ( ! empty( $result['featured'] ) ) {
			$filter[] = 'featured only';
		}

		$lines[] = 'Matching products' . ( $filter ? ' (' . implode( ', ', $filter ) . ')' : '' ) . ':';

		$products  = array();
		$max_sales = 0;
		foreach ( $result['product_ids'] as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				$products[] = $product;
				$max_sales  = max( $max_sales, (int) $product->get_total_sales() );
			}
		}

		$i = 0;
		foreach ( $products as $product ) {
			++$i;
			$lines[] = $i . '. ' . self::product_summary_line( $product, ( 'popular' === $result['sort'] && $max_sales > 0 ) ? $i : 0 );
		}

		if ( ! empty( $result['sort'] ) && 'popular' === $result['sort'] ) {
			if ( 0 === $max_sales ) {
				$lines[] = 'Note: no sales have been recorded for these products yet, so there is no best-seller. Say that honestly, then suggest the featured or best-rated items above (or the newest ones).';
			} else {
				$lines[] = 'Note: popularity comes from real store sales. Do not quote exact sales numbers; just say which items are the most popular.';
			}
		}

		// Extra detail for short result lists (e.g. "tell me about the silver cufflinks", "which one is most popular?").
		if ( count( $products ) <= 3 ) {
			foreach ( array_slice( $products, 0, 2 ) as $product ) {
				$detail = self::product_detail_block( $product );
				if ( '' !== $detail ) {
					$lines[] = $detail;
				}
			}
		}

		return implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * One rich line describing a product for the AI.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $rank    Best-seller rank (0 = not shown).
	 * @return string
	 */
	public static function product_summary_line( $product, $rank = 0 ) {
		$pid   = $product->get_id();
		$parts = array( $product->get_name() );

		// Price, with sale details.
		$price = self::product_price_text( $product );
		if ( $product->is_on_sale() && $product->is_type( 'simple' ) && $product->get_regular_price() ) {
			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();
			$pct     = $regular > 0 ? round( ( 1 - ( $sale / $regular ) ) * 100 ) : 0;
			$price   = 'ON SALE ' . self::plain_price( $sale ) . ' (was ' . self::plain_price( $regular ) . ( $pct > 0 ? ', ' . $pct . '% off' : '' ) . ')';
		} elseif ( $product->is_on_sale() ) {
			$price = 'ON SALE ' . $price;
		}
		$parts[] = $price;

		$cats = wp_strip_all_tags( wc_get_product_category_list( $pid, ', ' ) );
		if ( $cats ) {
			$parts[] = 'Category: ' . html_entity_decode( $cats, ENT_QUOTES, 'UTF-8' );
		}

		$parts[] = self::stock_text( $product );

		if ( $rank > 0 ) {
			$parts[] = 'Best-seller rank #' . $rank;
		}
		if ( $product->get_review_count() > 0 ) {
			$parts[] = 'Rated ' . round( (float) $product->get_average_rating(), 1 ) . '/5 from ' . (int) $product->get_review_count() . ' review' . ( 1 === (int) $product->get_review_count() ? '' : 's' );
		}
		if ( $product->is_featured() ) {
			$parts[] = 'Featured by the store';
		}

		$attrs = self::attributes_text( $product, 4 );
		if ( '' !== $attrs ) {
			$parts[] = $attrs;
		}

		$tags = wp_strip_all_tags( wc_get_product_tag_list( $pid, ', ' ) );
		if ( $tags ) {
			$parts[] = 'Tags: ' . html_entity_decode( $tags, ENT_QUOTES, 'UTF-8' );
		}

		$short = trim( wp_strip_all_tags( strip_shortcodes( $product->get_short_description() ) ) );
		if ( '' === $short ) {
			$short = trim( wp_strip_all_tags( strip_shortcodes( $product->get_description() ) ) );
		}
		if ( '' !== $short ) {
			$parts[] = mb_strimwidth( preg_replace( '/\s+/', ' ', $short ), 0, 180, '…' );
		}

		return implode( ' — ', $parts );
	}

	/**
	 * Detailed block for one product: description, options, size, shipping and recent reviews.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function product_detail_block( $product ) {
		$out = array( 'Details for "' . $product->get_name() . '":' );

		$desc = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $product->get_description() ) ) ) );
		if ( '' !== $desc ) {
			$out[] = '- Description: ' . mb_strimwidth( $desc, 0, 600, '…' );
		}

		$attrs = self::attributes_text( $product, 10 );
		if ( '' !== $attrs ) {
			$out[] = '- ' . $attrs;
		}

		if ( $product->is_type( 'variable' ) ) {
			$out[] = '- Comes in several options (choose on the product page); price ' . self::product_price_text( $product ) . '.';
		}

		if ( $product->get_sku() ) {
			$out[] = '- SKU: ' . $product->get_sku();
		}

		if ( $product->has_weight() ) {
			$out[] = '- Weight: ' . wc_format_weight( $product->get_weight() );
		}
		if ( $product->has_dimensions() ) {
			$out[] = '- Dimensions: ' . html_entity_decode( wp_strip_all_tags( wc_format_dimensions( $product->get_dimensions( false ) ) ), ENT_QUOTES, 'UTF-8' );
		}

		$shipping_class = $product->get_shipping_class();
		if ( $shipping_class ) {
			$term = get_term_by( 'slug', $shipping_class, 'product_shipping_class' );
			if ( $term ) {
				$out[] = '- Shipping class: ' . $term->name;
			}
		}

		if ( $product->get_reviews_allowed() && $product->get_review_count() > 0 ) {
			$reviews = get_comments(
				array(
					'post_id' => $product->get_id(),
					'status'  => 'approve',
					'type'    => 'review',
					'number'  => 2,
					'orderby' => 'comment_date_gmt',
					'order'   => 'DESC',
				)
			);
			foreach ( $reviews as $review ) {
				$stars = (int) get_comment_meta( $review->comment_ID, 'rating', true );
				$text  = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $review->comment_content ) ) );
				if ( '' !== $text ) {
					$out[] = '- Customer review' . ( $stars ? ' (' . $stars . '/5)' : '' ) . ': "' . mb_strimwidth( $text, 0, 160, '…' ) . '"';
				}
			}
		}

		return count( $out ) > 1 ? implode( "\n", $out ) : '';
	}

	/**
	 * Visible product attributes as text, e.g. "Material: Silver; Colour: Gold, Silver".
	 *
	 * @param WC_Product $product Product.
	 * @param int        $max     Max attributes.
	 * @return string
	 */
	public static function attributes_text( $product, $max = 4 ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}
			$values = array_filter( array_map( 'trim', (array) $values ) );
			if ( empty( $values ) ) {
				continue;
			}
			$out[] = wc_attribute_label( $attribute->get_name(), $product ) . ': ' . implode( ', ', array_slice( $values, 0, 8 ) );
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return implode( '; ', $out );
	}

	/**
	 * Stock text that follows the store's own stock display setting.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function stock_text( $product ) {
		$availability = $product->get_availability();
		$text         = isset( $availability['availability'] ) ? trim( wp_strip_all_tags( $availability['availability'] ) ) : '';
		if ( '' === $text ) {
			$text = $product->is_in_stock() ? 'In stock' : 'Out of stock';
		}
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Price as plain text (e.g. "$200.00" or "$20.00 – $40.00").
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function product_price_text( $product ) {
		$html = $product->get_price_html();
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $text ) {
			$text = self::plain_price( $product->get_price() );
		}
		return preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Format a number as plain-text store price.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function plain_price( $amount ) {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}
