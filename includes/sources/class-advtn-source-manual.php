<?php
/**
 * Curated links, presented to the ingest pipeline as a source.
 *
 * There is nothing to fetch — the links already exist in an option — but
 * running them through the normal path keeps `last_seen` moving, so the stale
 * sweep leaves them alone for as long as they are on the list.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Source_Manual extends ADVTN_Source_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Manual links', 'trending-now' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch( array $config ): ADVTN_Fetch_Result {
		unset( $config );

		$started = microtime( true );
		$items   = array();

		foreach ( advtn()->manual()->active() as $link ) {
			$item = $this->make_item(
				array(
					'url'          => (string) $link['url'],
					'title'        => (string) $link['title'],
					'excerpt'      => (string) $link['excerpt'],
					'image_url'    => (string) $link['image_url'],
					'published_at' => '' !== $link['published_at'] ? (string) $link['published_at'] : (string) $link['created_at'],
					'site_name'    => '' !== $link['site_name'] ? (string) $link['site_name'] : ADVTN_URL::host( (string) $link['url'] ),
					'source_type'  => 'manual',
				),
				// An editor pointing at their own site is a deliberate choice,
				// not the accidental self-link the rule exists to catch.
				true
			);

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$result              = ADVTN_Fetch_Result::success( $items, null, count( $items ) );
		$result->duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_config( array $config ) {
		$clean          = $this->base_config( $config );
		$clean['id']    = ADVTN_Manual::SOURCE_ID;
		$clean['label'] = __( 'Manual links', 'trending-now' );
		$clean['url']   = '';

		return $clean;
	}

	/**
	 * The synthetic source row used while any curated links exist.
	 *
	 * @return array<string,mixed>
	 */
	public static function virtual_config(): array {
		return array(
			'id'            => ADVTN_Manual::SOURCE_ID,
			'label'         => __( 'Manual links', 'trending-now' ),
			'type'          => 'manual',
			'enabled'       => true,
			'url'           => '',
			'limit'         => 200,
			'stagger_index' => 0,
		);
	}
}
