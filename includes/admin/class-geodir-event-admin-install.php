<?php
/**
 * Installation related functions and actions.
 *
 * @author   AyeCode
 * @category Admin
 * @package  GeoDirectory/Classes
 * @version  2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GeoDir_Event_Admin_Install Class.
 */
class GeoDir_Event_Admin_Install {

	/** @var array DB updates and callbacks that need to be run per version */
	private static $db_updates = array(
		/*'2.0.0' => array(
			'geodir_update_200_file_paths',
			'geodir_update_200_permalinks',
		)*/
		/*'2.0.0.1-dev' => array(
			'geodir_update_2001_dev_db_version',
		),*/
	);

	private static $background_updater;

	/**
	 * Hook in tabs.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'init', array( __CLASS__, 'init_background_updater' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'install_actions' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'wpmu_drop_tables' ) );
	}

	/**
	 * Init background updates
	 */
	public static function init_background_updater() {
		if ( ! class_exists( 'GeoDir_Background_Updater' ) ) {
			include_once( GEODIRECTORY_PLUGIN_DIR . 'includes/class-geodir-background-updater.php' );
		}
		self::$background_updater = new GeoDir_Background_Updater();
	}

	/**
	 * Check GeoDirectory event manager version and run the updater as required.
	 *
	 * This check is done on all requests and runs if the versions do not match.
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && get_option( 'geodir_event_version' ) !== GEODIR_EVENT_VERSION ) {
			self::install();

			do_action( 'geodir_event_updated' );
		}
	}

	/**
	 * Install actions when a update button is clicked within the admin area.
	 *
	 * This function is hooked into admin_init to affect admin only.
	 */
	public static function install_actions() {
		if ( ! empty( $_GET['do_update_geodir_event'] ) ) {
			self::update();
		}

		if ( ! empty( $_GET['force_update_geodir_event'] ) ) {

			do_action( 'geodir_event_updater_cron' );

			wp_safe_redirect( admin_url( 'admin.php?page=gd-settings' ) );
			exit;
		}
	}

	/**
	 * Install GeoDirectory Event Manager.
	 */
	public static function install() {
		global $wpdb;

		if ( ! is_blog_installed() ) {
			return;
		}

		if ( ! defined( 'GEODIR_EVENT_INSTALLING' ) ) {
			define( 'GEODIR_EVENT_INSTALLING', true );
		}

		// Create tables
		self::create_tables();

		// Create options
		self::create_options();

		// Insert default fields
		self::insert_default_fields();

		// Register post type
		GeoDir_Event_Post_type::register_post_type();

		// Register taxonomy
		GeoDir_Event_Post_type::register_taxonomy();

		// Create uncategorized category
		self::create_uncategorized_categories();

		// Update GD version
		self::update_gd_version();

		// Update DB version
		self::maybe_update_db_version();

		// Flush rules after install
		do_action( 'geodir_flush_rewrite_rules' );

		// Trigger action
		do_action( 'geodir_event_installed' );
	}
	
	/**
	 * Is this a brand new GeoDirectory install?
	 *
	 * @since 2.0.0
	 * @return boolean
	 */
	private static function is_new_install() {
		return is_null( get_option( 'geodir_event_version', null ) ) && is_null( get_option( 'geodir_event_db_version', null ) );
	}

	/**
	 * Is a DB update needed?
	 *
	 * @since 2.0.0
	 * @return boolean
	 */
	private static function needs_db_update() {
		$current_db_version = get_option( 'geodir_event_db_version', null );
		$updates            = self::get_db_update_callbacks();

		return ! is_null( $current_db_version ) && ! empty( $updates ) && version_compare( $current_db_version, max( array_keys( $updates ) ), '<' );
	}

	/**
	 * See if we need to show or run database updates during install.
	 *
	 * @since 2.0.0
	 */
	private static function maybe_update_db_version() {
		if ( self::needs_db_update() ) {
			self::update();
		} else {
			self::update_db_version();
		}
	}

	/**
	 * Update GeoDirectory version to current.
	 */
	private static function update_gd_version() {
		delete_option( 'geodir_event_version' );
		add_option( 'geodir_event_version', GEODIR_EVENT_VERSION );
	}

	/**
	 * Get list of DB update callbacks.
	 *
	 * @since  3.0.0
	 * @return array
	 */
	public static function get_db_update_callbacks() {
		return self::$db_updates;
	}

	/**
	 * Push all needed DB updates to the queue for processing.
	 */
	private static function update() {
		$current_db_version = get_option( 'geodir_event_db_version' );
		$update_queued      = false;

		foreach ( self::get_db_update_callbacks() as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					geodir_error_log( sprintf( 'Queuing %s - %s', $version, $update_callback ) );

					self::$background_updater->push_to_queue( $update_callback );
					$update_queued = true;
				}
			}
		}

		if ( $update_queued ) {
			self::$background_updater->save()->dispatch();
		}
	}

	/**
	 * Update DB version to current.
	 * @param string $version
	 */
	public static function update_db_version( $version = null ) {
		delete_option( 'geodir_event_db_version' );
		add_option( 'geodir_event_db_version', is_null( $version ) ? GEODIR_EVENT_VERSION : $version );
	}

	/**
	 * Set up the database tables which the plugin needs to function.
	 *
	 */
	private static function create_tables() {
		global $wpdb;

		$wpdb->hide_errors();

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( self::get_schema() );
	}

	/**
	 * Default options.
	 *
	 * Sets up the default options used on the settings page.
	 */
	private static function create_options() {
		// Include settings so that we can run through defaults
		include_once( GEODIRECTORY_PLUGIN_DIR . 'includes/admin/class-geodir-admin-settings.php' );
		
		$current_settings = geodir_get_settings();

		$settings = GeoDir_Admin_Settings::get_settings_pages();

		foreach ( $settings as $section ) {
			if ( ! method_exists( $section, 'get_settings' ) ) {
				continue;
			}
			$subsections = array_unique( array_merge( array( '' ), array_keys( $section->get_sections() ) ) );

			foreach ( $subsections as $subsection ) {
				foreach ( $section->get_settings( $subsection ) as $value ) {
					if ( !isset($current_settings[$value['id']]) && isset( $value['default'] ) && isset( $value['id'] ) ) {
						//geodir_update_option($value['id'], $value['default']);
					}
				}
			}
		}
	}

	/*
	 * Insert the default field for the CPTs
	 */
	public static function insert_default_fields(){
		$fields = GeoDir_Admin_Dummy_Data::default_custom_fields( 'gd_event' );

		/**
		 * Filter the array of default custom fields DB table data.
		 *
		 * @since 1.0.0
		 * @param string $fields The default custom fields as an array.
		 */
		$fields = apply_filters( 'geodir_before_default_custom_fields_saved', $fields );

		foreach ( $fields as $field_index => $field ) {
			geodir_custom_field_save( $field );
		}
	}

	/**
	 * Create a category for each CPT.
	 *
	 * So users can start adding posts right away.
	 */
	public static function create_uncategorized_categories( $post_type = 'gd_event' ) {
		if ( ! get_option( $post_type . 'category_installed', false ) ) {
			$dummy_categories = array(
				'uncategorized' => array(
					'name'        => 'Uncategorized',
					'icon'        => GEODIRECTORY_PLUGIN_URL . '/assets/images/pin.png',
					'schema_type' => ''
				)
			);

			GeoDir_Admin_Dummy_Data::create_taxonomies( $post_type, $dummy_categories );

			update_option( $post_type . 'category_installed', true );
		}
	}

	/**
	 * Get Table schema.
	 *
	 * A note on indexes; Indexes have a maximum size of 767 bytes. Historically, we haven't need to be concerned about that.
	 * As of WordPress 4.2, however, we moved to utf8mb4, which uses 4 bytes per character. This means that an index which
	 * used to have room for floor(767/3) = 255 characters, now only has room for floor(767/4) = 191 characters.
	 *
	 * Changing indexes may cause duplicate index notices in logs due to https://core.trac.wordpress.org/ticket/34870 but dropping
	 * indexes first causes too much load on some servers/larger DB.
	 *
	 * @return string
	 */
	private static function get_schema() {
		global $wpdb, $plugin_prefix;

		/*
         * Indexes have a maximum size of 767 bytes. Historically, we haven't need to be concerned about that.
         * As of 4.2, however, we moved to utf8mb4, which uses 4 bytes per character. This means that an index which
         * used to have room for floor(767/3) = 255 characters, now only has room for floor(767/4) = 191 characters.
         */
		$max_index_length = 191;

		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		// Event detail table
		$tables = "CREATE TABLE " . GEODIR_EVENT_DETAIL_TABLE . " (
						" . implode ( ",", self::db_cpt_default_columns( false ) ) . ",
						" . implode ( ", \n", self::db_cpt_default_keys( false ) ) . " 
					) $collate; ";

		// Event schedules table
		$tables .= "CREATE TABLE " . GEODIR_EVENT_SCHEDULES_TABLE . " (
						schedule_id int(11) NOT NULL AUTO_INCREMENT,
						event_id int(11) NOT NULL,
						start_date date NOT NULL DEFAULT '0000-00-00',
						end_date date NOT NULL DEFAULT '0000-00-00',
						start_time time NOT NULL DEFAULT '00:00:00',
						end_time time NOT NULL DEFAULT '00:00:00',
						all_day TINYINT(1) NOT NULL DEFAULT '0',
						recurring TINYINT(1) NOT NULL DEFAULT '0',
						PRIMARY KEY (schedule_id)
					) $collate; ";

		return $tables;
	}

	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param	mixed $links Plugin Row Meta
	 * @param	mixed $file  Plugin Base file
	 * @return	array
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( GEODIR_EVENT_PLUGIN_BASENAME == $file ) {
			$row_meta = array();

			return array_merge( $links, $row_meta );
		}

		return (array) $links;
	}

	/**
	 * Uninstall tables when MU blog is deleted.
	 * @param  array $tables
	 * @return string[]
	 */
	public static function wpmu_drop_tables( $tables ) {
		$tables[] = GEODIR_EVENT_DETAIL_TABLE;
		$tables[] = GEODIR_EVENT_SCHEDULES_TABLE;

		return $tables;
	}

	/**
	 * Get slug from path
	 * @param  string $key
	 * @return string
	 */
	private static function format_plugin_slug( $key ) {
		$slug = explode( '/', $key );
		$slug = explode( '.', end( $slug ) );
		return $slug[0];
	}

	/**
	 * Get the Custom Post Type database default fields.
	 *
	 * @param bool $locationless Is the CPT locationless?
	 * @since 2.0.0
	 *
	 * @return array The array of default fields.
	 */
	public static function db_cpt_default_columns( $locationless = false ) {
		$columns = array();

		// Standard fields
		$columns['post_id'] = "post_id int(11) NOT NULL";
		$columns['post_title'] = "post_title text NULL DEFAULT NULL";
		$columns['post_status'] = "post_status varchar(20) NULL DEFAULT NULL";
		$columns['post_tags'] = "post_tags text NULL DEFAULT NULL";
		$columns['post_category'] = "post_category varchar(254) NULL DEFAULT NULL";
		$columns['default_category'] = "default_category INT NULL DEFAULT NULL";
		$columns['link_business'] = "link_business varchar(10) NULL DEFAULT NULL";
		$columns['featured'] = "featured tinyint(1) NOT NULL DEFAULT '0'";
		$columns['featured_image'] = "featured_image varchar( 254 ) NULL DEFAULT NULL";
		$columns['submit_ip'] = "submit_ip varchar(100) NULL DEFAULT NULL";
		$columns['overall_rating'] = "overall_rating float(11) DEFAULT '0'";
		$columns['rating_count'] = "rating_count int(11) DEFAULT '0'";
		$columns['recurring'] = "recurring TINYINT(1) DEFAULT '0'";
		$columns['event_dates'] = "event_dates TEXT NOT NULL";

		// Location fields
		if ( ! $locationless ) {
			$columns['marker_json'] = "marker_json text NULL DEFAULT NULL"; //@todo do we even still need this?
			$columns['location_id'] = "location_id int(11) NOT NULL"; //@todo do we even still need this?
			$columns['locations'] = "locations varchar( 254 ) NULL DEFAULT NULL"; //@todo do we even still need this?
			$columns['street'] = "street VARCHAR( 254 ) NULL";
			$columns['city'] = "city VARCHAR( 50 ) NULL";
			$columns['region'] = "region VARCHAR( 50 ) NULL";
			$columns['country'] = "country VARCHAR( 50 ) NULL";
			$columns['zip'] = "zip VARCHAR( 20 ) NULL";
			$columns['latitude'] = "latitude VARCHAR( 22 ) NULL";
			$columns['longitude'] = "longitude VARCHAR( 22 ) NULL";
			$columns['mapview'] = "mapview VARCHAR( 15 ) NULL";
			$columns['mapzoom'] = "mapzoom VARCHAR( 3 ) NULL";
		}

		return apply_filters( 'geodir_event_db_default_columns', $columns, $locationless );
	}

	/**
	 * Get the Custom Post Type database default keys.
	 *
	 * @param bool $locationless Is the CPT locationless?
	 * @since 2.0.0
	 *
	 * @return array The array of default fields.
	 */
	public static function db_cpt_default_keys( $locationless = false ) {

		/*
		 * Indexes have a maximum size of 767 bytes. Historically, we haven't need to be concerned about that.
		 * As of 4.2, however, we moved to utf8mb4, which uses 4 bytes per character. This means that an index which
		 * used to have room for floor(767/3) = 255 characters, now only has room for floor(767/4) = 191 characters.
		 */
		$max_index_length = 191;

		$keys = array();

		// Standard keys
		$keys['post_id'] = "PRIMARY KEY (post_id)";

		// Location keys
		if ( ! $locationless ) {
			$keys['locations'] = "KEY locations (locations($max_index_length))";
		}

		return apply_filters( 'geodir_db_event_default_keys', $keys, $locationless );
	}
}
