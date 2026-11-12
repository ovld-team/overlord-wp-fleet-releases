<?php
/**
 * Live page-builder + theme/plugin catalog so wordpress-mode can design against
 * whatever is actually installed on this site (Elementor widgets, Gutenberg blocks,
 * WPBakery shortcodes, plus presence of Bricks / Beaver / Divi / Oxygen).
 *
 * @package Overlord_WP_Fleet
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ovp_Module_Catalog extends Ovp_Module_Base {

	public function register_routes(): void {
		$this->route( 'GET', 'catalog/overview', array( $this, 'overview' ) );
		$this->route( 'GET', 'catalog/widgets', array( $this, 'list_widgets' ) );
		$this->route( 'GET', 'catalog/widget', array( $this, 'get_widget' ) );
	}

	public function overview(): WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$theme = wp_get_theme();
		$parent = $theme->parent();

		return $this->success(
			array(
				'plugin_name' => OVP_FLEET_PLUGIN_NAME,
				'theme'       => array(
					'slug'    => $theme->get_stylesheet(),
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'parent'  => $parent ? $parent->get_stylesheet() : '',
				),
				'plugins'     => $this->plugin_rows(),
				'builders'    => $this->detect_builders(),
			)
		);
	}

	public function list_widgets( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$builder = strtolower( (string) $request->get_param( 'builder' ) );
		$include = $this->truthy( $request->get_param( 'include_controls' ) );
		$cursor  = (string) $request->get_param( 'cursor' );
		$max     = Ovp_Chunk::max_items( $request->get_param( 'max_items' ), $include ? 12 : 60 );

		if ( '' === $builder ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide builder=elementor|gutenberg|wpbakery|bricks|beaver|divi.', 400 );
		}

		$items = $this->widgets_for( $builder, $include );
		if ( null === $items ) {
			return $this->error( 'mcp_bridge_builder_absent', 'Builder is not active on this site.', 404 );
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$ka = (string) ( $a['widgetType'] ?? $a['blockName'] ?? $a['shortcodeTag'] ?? $a['id'] ?? '' );
				$kb = (string) ( $b['widgetType'] ?? $b['blockName'] ?? $b['shortcodeTag'] ?? $b['id'] ?? '' );
				return strcmp( $ka, $kb );
			}
		);

		[ $page, $next ] = Ovp_Chunk::slice_by_key(
			$items,
			$cursor,
			$max,
			static function ( array $item ): string {
				return (string) ( $item['widgetType'] ?? $item['blockName'] ?? $item['shortcodeTag'] ?? $item['id'] ?? '' );
			}
		);

		return $this->success(
			Ovp_Chunk::envelope(
				array(
					'builder' => $builder,
					'count'   => count( $items ),
					'items'   => $page,
				),
				$next
			)
		);
	}

	public function get_widget( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$builder = strtolower( (string) $request->get_param( 'builder' ) );
		$id      = (string) $request->get_param( 'id' );

		if ( '' === $builder || '' === $id ) {
			return $this->error( 'mcp_bridge_invalid_payload', 'Provide builder and id.', 400 );
		}

		$items = $this->widgets_for( $builder, true );
		if ( null === $items ) {
			return $this->error( 'mcp_bridge_builder_absent', 'Builder is not active on this site.', 404 );
		}

		foreach ( $items as $item ) {
			$key = (string) ( $item['widgetType'] ?? $item['blockName'] ?? $item['shortcodeTag'] ?? $item['id'] ?? '' );
			if ( $key === $id ) {
				return $this->success( $item );
			}
		}

		return $this->error( 'mcp_bridge_not_found', 'Widget / block / shortcode not found.', 404 );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function plugin_rows(): array {
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$rows   = array();
		foreach ( $all as $file => $data ) {
			$rows[] = array(
				'file'    => $file,
				'name'    => $data['Name'] ?? $file,
				'version' => $data['Version'] ?? '',
				'active'  => in_array( $file, $active, true ),
			);
		}
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function detect_builders(): array {
		$elementor = $this->elementor_present();
		$wpbakery  = $this->wpbakery_present();
		$bricks    = defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Elements' );
		$beaver    = class_exists( 'FLBuilder' );
		$divi      = defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Element' );
		$oxygen    = defined( 'CT_VERSION' );

		$elementor_count = $elementor ? count( $this->elementor_widgets( false ) ) : 0;
		$gutenberg_count = count( $this->gutenberg_blocks( false ) );
		$wpbakery_count  = $wpbakery ? count( $this->wpbakery_shortcodes( false ) ) : 0;

		return array(
			$this->builder_row( 'elementor', $elementor, defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '', $elementor_count, 'widgets' ),
			$this->builder_row( 'gutenberg', true, (string) get_bloginfo( 'version' ), $gutenberg_count, 'blocks' ),
			$this->builder_row( 'wpbakery', $wpbakery, defined( 'WPB_VC_VERSION' ) ? (string) WPB_VC_VERSION : '', $wpbakery_count, 'shortcodes' ),
			$this->builder_row( 'bricks', $bricks, defined( 'BRICKS_VERSION' ) ? (string) BRICKS_VERSION : '', 0, 'elements' ),
			$this->builder_row( 'beaver', $beaver, defined( 'FL_BUILDER_VERSION' ) ? (string) FL_BUILDER_VERSION : '', 0, 'modules' ),
			$this->builder_row( 'divi', $divi, defined( 'ET_BUILDER_VERSION' ) ? (string) ET_BUILDER_VERSION : '', 0, 'modules' ),
			$this->builder_row( 'oxygen', $oxygen, defined( 'CT_VERSION' ) ? (string) CT_VERSION : '', 0, 'elements' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function builder_row( string $id, bool $present, string $version, int $count, string $unit ): array {
		return array(
			'id'      => $id,
			'present' => $present,
			'version' => $version,
			'count'   => $count,
			'unit'    => $unit,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private function widgets_for( string $builder, bool $include_controls ): ?array {
		return match ( $builder ) {
			'elementor' => $this->elementor_present() ? $this->elementor_widgets( $include_controls ) : null,
			'gutenberg' => $this->gutenberg_blocks( $include_controls ),
			'wpbakery'  => $this->wpbakery_present() ? $this->wpbakery_shortcodes( $include_controls ) : null,
			'bricks'    => $this->bricks_present() ? $this->bricks_elements() : null,
			'beaver'    => class_exists( 'FLBuilder' ) ? $this->beaver_modules() : null,
			'divi'      => $this->divi_present() ? $this->divi_modules() : null,
			'oxygen'    => defined( 'CT_VERSION' ) ? array() : null,
			default     => null,
		};
	}

	private function elementor_present(): bool {
		return class_exists( '\Elementor\Plugin' );
	}

	private function wpbakery_present(): bool {
		return class_exists( 'WPBMap' ) || defined( 'WPB_VC_VERSION' );
	}

	private function bricks_present(): bool {
		return class_exists( '\Bricks\Elements' ) || defined( 'BRICKS_VERSION' );
	}

	private function divi_present(): bool {
		return class_exists( 'ET_Builder_Element' ) || defined( 'ET_BUILDER_VERSION' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function elementor_widgets( bool $include_controls ): array {
		if ( ! $this->elementor_present() ) {
			return array();
		}

		$plugin = \Elementor\Plugin::$instance;
		if ( ! is_object( $plugin ) || ! isset( $plugin->widgets_manager ) ) {
			return array();
		}

		$manager = $plugin->widgets_manager;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			return array();
		}

		$types = $manager->get_widget_types();
		if ( ( ! is_array( $types ) || $types === array() ) && function_exists( 'do_action' ) ) {
			do_action( 'elementor/widgets/register', $manager );
			$types = $manager->get_widget_types();
		}
		if ( ! is_array( $types ) ) {
			return array();
		}

		$rows = array();
		foreach ( $types as $name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}
			$row = array(
				'widgetType' => (string) $name,
				'title'      => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : (string) $name,
				'icon'       => method_exists( $widget, 'get_icon' ) ? (string) $widget->get_icon() : '',
				'categories' => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : array(),
				'class'      => $widget::class,
				'plugin'     => $this->class_plugin_guess( $widget::class ),
				'confidence' => 'live',
				'controls'   => array(),
			);
			if ( $include_controls ) {
				try {
					$row['controls'] = $this->elementor_controls( $widget );
				} catch ( \Throwable $e ) {
					$row['controls'] = array();
					$row['controls_error'] = $e->getMessage();
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function elementor_controls( object $widget ): array {
		$controls = array();
		if ( method_exists( $widget, 'get_stack' ) ) {
			$stack = $widget->get_stack();
			if ( is_array( $stack ) && isset( $stack['controls'] ) && is_array( $stack['controls'] ) ) {
				$controls = $stack['controls'];
			}
		}
		if ( $controls === array() && method_exists( $widget, 'get_controls' ) ) {
			$got = $widget->get_controls();
			if ( is_array( $got ) ) {
				$controls = $got;
			}
		}

		$out = array();
		foreach ( $controls as $name => $ctrl ) {
			if ( ! is_array( $ctrl ) ) {
				continue;
			}
			$type = $ctrl['type'] ?? '';
			$type = is_string( $type ) ? $type : '';
			if ( in_array( $type, array( 'section', 'tab', 'tabs', 'popover_toggle', 'divider', 'heading' ), true ) ) {
				continue;
			}
			$out[] = array(
				'name'       => (string) $name,
				'type'       => $type,
				'responsive' => ! empty( $ctrl['responsive'] ),
				'default'    => $ctrl['default'] ?? null,
				'section'    => isset( $ctrl['section'] ) && is_string( $ctrl['section'] ) ? $ctrl['section'] : null,
				'tab'        => isset( $ctrl['tab'] ) && is_string( $ctrl['tab'] ) ? $ctrl['tab'] : null,
			);
		}
		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function gutenberg_blocks( bool $include_controls ): array {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return array();
		}
		$registry = WP_Block_Type_Registry::get_instance();
		$rows     = array();
		foreach ( $registry->get_all_registered() as $name => $block ) {
			if ( ! $block instanceof WP_Block_Type ) {
				continue;
			}
			$attrs = is_array( $block->attributes ) ? $block->attributes : array();
			$row   = array(
				'blockName'               => (string) $name,
				'title'                   => (string) ( $block->title ?? $name ),
				'category'                => (string) ( $block->category ?? '' ),
				'plugin'                  => $this->block_plugin_guess( (string) $name ),
				'usable_in_page_content'  => true,
				'confidence'              => 'live',
				'attributes'              => array(),
			);
			if ( $include_controls ) {
				$shaped = array();
				foreach ( $attrs as $attr_name => $schema ) {
					$shaped[] = array(
						'name'    => (string) $attr_name,
						'type'    => is_array( $schema ) && isset( $schema['type'] ) ? $schema['type'] : null,
						'default' => is_array( $schema ) ? ( $schema['default'] ?? null ) : null,
					);
				}
				$row['attributes'] = $shaped;
			} else {
				$row['attributes'] = array_map( 'strval', array_keys( $attrs ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function wpbakery_shortcodes( bool $include_controls ): array {
		if ( ! class_exists( 'WPBMap' ) ) {
			return array();
		}
		$map = array();
		if ( method_exists( 'WPBMap', 'getAllShortCodes' ) ) {
			$got = WPBMap::getAllShortCodes();
			if ( is_array( $got ) ) {
				$map = $got;
			}
		} elseif ( method_exists( 'WPBMap', 'getShortCodes' ) ) {
			$got = WPBMap::getShortCodes();
			if ( is_array( $got ) ) {
				$map = $got;
			}
		}

		$rows = array();
		foreach ( $map as $tag => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$row = array(
				'shortcodeTag' => (string) ( $data['base'] ?? $tag ),
				'title'        => (string) ( $data['name'] ?? $tag ),
				'category'     => (string) ( $data['category'] ?? '' ),
				'confidence'   => 'live',
				'params'       => array(),
			);
			if ( $include_controls && isset( $data['params'] ) && is_array( $data['params'] ) ) {
				foreach ( $data['params'] as $param ) {
					if ( ! is_array( $param ) ) {
						continue;
					}
					$value = $param['std'] ?? $param['value'] ?? null;
					if ( is_array( $value ) ) {
						$value = null;
					}
					$row['params'][] = array(
						'name'    => (string) ( $param['param_name'] ?? '' ),
						'type'    => (string) ( $param['type'] ?? '' ),
						'default' => $value,
					);
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function bricks_elements(): array {
		if ( ! class_exists( '\Bricks\Elements' ) || ! isset( \Bricks\Elements::$elements ) || ! is_array( \Bricks\Elements::$elements ) ) {
			return array();
		}
		$rows = array();
		foreach ( \Bricks\Elements::$elements as $id => $data ) {
			$rows[] = array(
				'id'         => (string) $id,
				'title'      => is_array( $data ) ? (string) ( $data['label'] ?? $id ) : (string) $id,
				'confidence' => 'live',
			);
		}
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function beaver_modules(): array {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_enabled_modules' ) ) {
			return array();
		}
		$mods = FLBuilderModel::get_enabled_modules();
		if ( ! is_array( $mods ) ) {
			return array();
		}
		$rows = array();
		foreach ( $mods as $id ) {
			$rows[] = array(
				'id'         => (string) $id,
				'title'      => (string) $id,
				'confidence' => 'live',
			);
		}
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function divi_modules(): array {
		if ( ! class_exists( 'ET_Builder_Element' ) || ! method_exists( 'ET_Builder_Element', 'get_modules' ) ) {
			return array();
		}
		$mods = ET_Builder_Element::get_modules();
		if ( ! is_array( $mods ) ) {
			return array();
		}
		$rows = array();
		foreach ( $mods as $id => $mod ) {
			$rows[] = array(
				'id'         => (string) $id,
				'title'      => is_object( $mod ) && isset( $mod->name ) ? (string) $mod->name : (string) $id,
				'confidence' => 'live',
			);
		}
		return $rows;
	}

	private function class_plugin_guess( string $class ): string {
		if ( str_starts_with( $class, 'ElementorPro\\' ) ) {
			return 'elementor-pro';
		}
		if ( str_starts_with( $class, 'Elementor\\' ) ) {
			return 'elementor';
		}
		if ( str_contains( strtolower( $class ), 'qiaddons' ) || str_contains( strtolower( $class ), 'qi_addons' ) ) {
			return 'qi-addons-for-elementor';
		}
		return '';
	}

	private function block_plugin_guess( string $name ): string {
		if ( str_starts_with( $name, 'core/' ) ) {
			return 'wordpress';
		}
		$ns = explode( '/', $name )[0];
		return $ns;
	}

	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return false;
	}
}
