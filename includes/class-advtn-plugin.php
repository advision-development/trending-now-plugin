<?php
/**
 * Plugin singleton. Wires hooks and holds the small DI container.
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ADVTN_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ADVTN_Plugin|null
	 */
	private static ?ADVTN_Plugin $instance = null;

	/**
	 * Lazily built service objects.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Guards against double-booting.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 *
	 * @return ADVTN_Plugin
	 */
	public static function instance(): ADVTN_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register every hook the plugin needs.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'on_init' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'rest_api_init', array( $this->rest(), 'register_routes' ) );

		$this->scheduler()->register_hooks();
		$this->archive()->register_hooks();
		$this->shortcode()->register_hooks();
		$this->block()->register_hooks();

		if ( is_admin() ) {
			$this->admin()->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'trending-now', 'ADVTN_CLI' );
		}
	}

	/**
	 * Late bootstrap: schema migrations and text domain.
	 *
	 * @return void
	 */
	public function on_plugins_loaded(): void {
		ADVTN_Schema::maybe_upgrade();
	}

	/**
	 * init: recurring schedule registration and deferred rewrite flush.
	 *
	 * @return void
	 */
	public function on_init(): void {
		$this->scheduler()->ensure_recurring_action();

		if ( get_option( 'advtn_flush_rewrites' ) ) {
			delete_option( 'advtn_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Settings service.
	 *
	 * @return ADVTN_Settings
	 */
	public function settings(): ADVTN_Settings {
		return $this->service( 'settings', static fn() => new ADVTN_Settings() );
	}

	/**
	 * Repository service.
	 *
	 * @return ADVTN_Repository
	 */
	public function repository(): ADVTN_Repository {
		return $this->service( 'repository', static fn() => new ADVTN_Repository() );
	}

	/**
	 * Ingest orchestrator.
	 *
	 * @return ADVTN_Ingest
	 */
	public function ingest(): ADVTN_Ingest {
		return $this->service( 'ingest', fn() => new ADVTN_Ingest( $this->settings(), $this->repository() ) );
	}

	/**
	 * Scheduler service.
	 *
	 * @return ADVTN_Scheduler
	 */
	public function scheduler(): ADVTN_Scheduler {
		return $this->service( 'scheduler', fn() => new ADVTN_Scheduler( $this->settings() ) );
	}

	/**
	 * Selector service.
	 *
	 * @return ADVTN_Selector
	 */
	public function selector(): ADVTN_Selector {
		return $this->service( 'selector', fn() => new ADVTN_Selector( $this->settings(), $this->repository() ) );
	}

	/**
	 * Renderer service.
	 *
	 * @return ADVTN_Renderer
	 */
	public function renderer(): ADVTN_Renderer {
		return $this->service( 'renderer', fn() => new ADVTN_Renderer( $this->settings(), $this->repository() ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @return ADVTN_Shortcode
	 */
	public function shortcode(): ADVTN_Shortcode {
		return $this->service( 'shortcode', fn() => new ADVTN_Shortcode( $this->renderer() ) );
	}

	/**
	 * Block handler.
	 *
	 * @return ADVTN_Block
	 */
	public function block(): ADVTN_Block {
		return $this->service( 'block', fn() => new ADVTN_Block( $this->renderer() ) );
	}

	/**
	 * Archive route handler.
	 *
	 * @return ADVTN_Archive
	 */
	public function archive(): ADVTN_Archive {
		return $this->service( 'archive', fn() => new ADVTN_Archive( $this->settings(), $this->repository() ) );
	}

	/**
	 * REST controller.
	 *
	 * @return ADVTN_REST
	 */
	public function rest(): ADVTN_REST {
		return $this->service( 'rest', fn() => new ADVTN_REST( $this->settings(), $this->repository() ) );
	}

	/**
	 * Admin controller.
	 *
	 * @return ADVTN_Admin
	 */
	public function admin(): ADVTN_Admin {
		return $this->service( 'admin', fn() => new ADVTN_Admin( $this->settings(), $this->repository() ) );
	}

	/**
	 * Build a source provider for a given type.
	 *
	 * @param string $type One of wp_rest|rss|gdelt|hub.
	 * @return ADVTN_Source_Interface|null
	 */
	public function source( string $type ): ?ADVTN_Source_Interface {
		$map = array(
			'wp_rest' => 'ADVTN_Source_WP_REST',
			'rss'     => 'ADVTN_Source_RSS',
			'gdelt'   => 'ADVTN_Source_GDELT',
			'hub'     => 'ADVTN_Source_Hub',
		);

		/**
		 * Filters the source type => class map, allowing additional providers.
		 *
		 * @param array<string,string> $map Type to class name.
		 */
		$map = apply_filters( 'advtn_source_map', $map );

		if ( ! isset( $map[ $type ] ) || ! class_exists( $map[ $type ] ) ) {
			return null;
		}

		$instance = new $map[ $type ]( $this->settings() );

		return $instance instanceof ADVTN_Source_Interface ? $instance : null;
	}

	/**
	 * Lazy service resolver.
	 *
	 * @param string   $key     Service key.
	 * @param callable $factory Factory returning the service.
	 * @return mixed
	 */
	private function service( string $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = $factory();
		}
		return $this->services[ $key ];
	}

	/**
	 * No cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * No unserializing.
	 *
	 * @throws \LogicException Always.
	 * @return void
	 */
	public function __wakeup() {
		throw new \LogicException( 'ADVTN_Plugin cannot be unserialized.' );
	}
}
