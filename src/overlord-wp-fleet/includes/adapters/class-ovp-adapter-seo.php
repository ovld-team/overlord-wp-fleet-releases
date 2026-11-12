<?php
/**
 * SEO adapters: Yoast, RankMath, SEOPress. Key names follow each plugin's postmeta keys;
 * confirm with meta_keys on a real post before relying on a key (the keymap marks which
 * ones are verified on a live site: none until the W8 gate run records them).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-ovp-adapter-base.php';

/** Shared plumbing: only post entities, alias via `adapter` hint, rule-driven. */
abstract class OVP_Adapter_Seo_Base extends OVP_Adapter {

	public function handles( string $type, string $key, array $ctx ): bool {
		if ( 'post' !== $type ) {
			return false;
		}
		if ( isset( $this->rules()[ $key ] ) ) {
			return true;
		}
		return ( $ctx['adapter'] ?? '' ) === $this->id() && isset( $this->keymap()[ $key ] );
	}
}

final class OVP_Adapter_Yoast extends OVP_Adapter_Seo_Base {

	public function id(): string {
		return 'yoast';
	}

	public function keymap(): array {
		return array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'noindex'        => '_yoast_wpseo_meta-robots-noindex',
			'nofollow'       => '_yoast_wpseo_meta-robots-nofollow',
			'breadcrumb'     => '_yoast_wpseo_bctitle',
			'cornerstone'    => '_yoast_wpseo_is_cornerstone',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'og_image'       => '_yoast_wpseo_opengraph-image',
			'tw_title'       => '_yoast_wpseo_twitter-title',
			'tw_description' => '_yoast_wpseo_twitter-description',
			'tw_image'       => '_yoast_wpseo_twitter-image',
		);
	}

	public function rules(): array {
		return array(
			'_yoast_wpseo_title'                  => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_yoast_wpseo_metadesc'               => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_yoast_wpseo_focuskw'                => array( 't' => 'string', 'max' => 191, 'line' => true ),
			'_yoast_wpseo_canonical'              => array( 't' => 'url' ),
			'_yoast_wpseo_meta-robots-noindex'    => array( 't' => 'enum', 'values' => array( '0', '1', '2' ) ),
			'_yoast_wpseo_meta-robots-nofollow'   => array( 't' => 'enum', 'values' => array( '0', '1' ) ),
			'_yoast_wpseo_bctitle'                => array( 't' => 'string', 'max' => 191, 'line' => true ),
			'_yoast_wpseo_is_cornerstone'         => array( 't' => 'enum', 'values' => array( '0', '1' ) ),
			'_yoast_wpseo_opengraph-title'        => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_yoast_wpseo_opengraph-description'  => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_yoast_wpseo_opengraph-image'        => array( 't' => 'url' ),
			'_yoast_wpseo_twitter-title'          => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_yoast_wpseo_twitter-description'    => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_yoast_wpseo_twitter-image'          => array( 't' => 'url' ),
		);
	}
}

final class OVP_Adapter_RankMath extends OVP_Adapter_Seo_Base {

	public function id(): string {
		return 'rankmath';
	}

	public function keymap(): array {
		return array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'robots'         => 'rank_math_robots',
			'pillar'         => 'rank_math_pillar_content',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'       => 'rank_math_facebook_image',
			'tw_title'       => 'rank_math_twitter_title',
			'tw_description' => 'rank_math_twitter_description',
		);
	}

	public function rules(): array {
		return array(
			'rank_math_title'                => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'rank_math_description'          => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'rank_math_focus_keyword'        => array( 't' => 'string', 'max' => 400, 'line' => true ),
			'rank_math_canonical_url'        => array( 't' => 'url' ),
			'rank_math_robots'               => array( 't' => 'list_enum', 'values' => array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' ) ),
			'rank_math_pillar_content'       => array( 't' => 'enum', 'values' => array( 'on', '' ) ),
			'rank_math_facebook_title'       => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'rank_math_facebook_description' => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'rank_math_facebook_image'       => array( 't' => 'url' ),
			'rank_math_twitter_title'        => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'rank_math_twitter_description'  => array( 't' => 'string', 'max' => 1000, 'line' => true ),
		);
	}
}

final class OVP_Adapter_SeoPress extends OVP_Adapter_Seo_Base {

	public function id(): string {
		return 'seopress';
	}

	public function keymap(): array {
		return array(
			'title'          => '_seopress_titles_title',
			'description'    => '_seopress_titles_desc',
			'focus_keyword'  => '_seopress_analysis_target_kw',
			'canonical'      => '_seopress_robots_canonical',
			'noindex'        => '_seopress_robots_index',
			'nofollow'       => '_seopress_robots_follow',
			'og_title'       => '_seopress_social_fb_title',
			'og_description' => '_seopress_social_fb_desc',
			'og_image'       => '_seopress_social_fb_img',
			'tw_title'       => '_seopress_social_twitter_title',
			'tw_description' => '_seopress_social_twitter_desc',
			'tw_image'       => '_seopress_social_twitter_img',
		);
	}

	public function rules(): array {
		return array(
			'_seopress_titles_title'          => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_seopress_titles_desc'           => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_seopress_analysis_target_kw'    => array( 't' => 'string', 'max' => 400, 'line' => true ),
			'_seopress_robots_canonical'      => array( 't' => 'url' ),
			'_seopress_robots_index'          => array( 't' => 'yesno' ),
			'_seopress_robots_follow'         => array( 't' => 'yesno' ),
			'_seopress_social_fb_title'       => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_seopress_social_fb_desc'        => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_seopress_social_fb_img'         => array( 't' => 'url' ),
			'_seopress_social_twitter_title'  => array( 't' => 'string', 'max' => 600, 'line' => true ),
			'_seopress_social_twitter_desc'   => array( 't' => 'string', 'max' => 1000, 'line' => true ),
			'_seopress_social_twitter_img'    => array( 't' => 'url' ),
		);
	}
}
