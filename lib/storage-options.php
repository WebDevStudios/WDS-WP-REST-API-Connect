<?php
namespace WDS_WP_REST_API\Storage;

use Exception;
use WDS_WP_REST_API\Storage\Store_Interface;

class Options implements Store_Interface {

	protected $is_array_option = true;
	protected $do_autoload = false;
	protected $option = array();
	protected $key = '';

	public function __construct( $is_array_option = true, $do_autoload = false ) {
		$this->is_array_option = (bool) $is_array_option;
		$this->do_autoload = (bool) $do_autoload;
	}

	/**
	 * Retrieve stored option
	 *
	 * @param  string  $option Option array key
	 * @param  string  $key    Key for secondary array
	 * @param  boolean $force  Force a new call to get_option
	 *
	 * @return mixed           Value of option requested
	 */
	public function get( $option = 'all', $key = '', $force = false ) {

		if ( empty( $this->option ) || $force ) {
			$this->option = $this->get_from_db( $this->get_key() );

			if ( $this->is_array_option && ! is_array( $this->option ) ) {
				$this->option = array();
			}
		}

		if ( 'all' == $option || ! $this->is_array_option ) {
			return $this->option;
		}

		if ( ! array_key_exists( $option, $this->option ) ) {
			return false;
		}

		if ( ! $key ) {
			return $this->option[ $option ];
		}

		if ( ! array_key_exists( $key, $this->option[ $option ] ) ) {
			return false;
		}

		return $this->option[ $option ][ $key ];
	}

	/**
	 * Handles deleting the stored data for a connection.
	 *
	 * @param  string $option Specific option key to unset. If empty, entire object is deleted.
	 *
	 * @return bool           Result of deletion from DB.
	 */
	public function delete( $option = '' ){
		if ( $option ) {
			return $this->delete_partial( $option );
		}

		$this->option = array();
		return $this->delete_from_db( $this->get_key() );
	}

	/**
	 * Handles unsetting part of the option array.
	 *
	 * @param  mixed  $option Array key or array of array keys to unset.
	 *
	 * @return bool           Whether partial delete was successful.
	 */
	protected function delete_partial( $option ) {
		if ( ! $this->is_array_option ) {
			return false;
		}

		if ( is_array( $option ) ) {
			foreach ( $option as $_option ) {
				if ( array_key_exists( $_option, $this->option ) ) {
					unset( $this->option[ $_option ] );
				}
			}
		} elseif ( array_key_exists( $option, $this->option ) ) {
			unset( $this->option[ $option ] );
		}

		if ( $this->set() ) {
			return true;
		}

		$this->get( 'all', '', true );
		return false;
	}

	/**
	 * Update the options array
	 *
	 * @since  0.1.0
	 *
	 * @param  mixed   $value  Value to be updated
	 * @param  string  $option Option array key
	 * @param  string  $key    Key for secondary array
	 * @param  boolean $set    Whether to set the updated value in the DB.
	 *
	 * @return                 Original $value if successful
	 */
	public function update( $value, $option = '', $key = '', $set = true ) {
		if ( ! $this->is_array_option ) {
			$this->option = $value;
		} else {
			$this->get( 'all' );

			if ( $key ) {
				$this->option[ $option ][ $key ] = $value;
			} else {
				$this->option[ $option ] = $value;
			}
		}

		if ( $set ) {
			return $this->set() ? $value : false;
		}

		return $value;
	}

	/**
	 * Update the stored value
	 *
	 * @return Result of storage update/add
	 */
	public function set( $options = null ) {
		$this->option = null !== $options ? $options : $this->option;

		$isset = $this->get_from_db( $this->get_key() );

		if ( ! empty( $isset ) ) {
			$updated = $this->update_db( $this->get_key(), $this->option );
		} else {
			// May want this to be true if using these connections on most page-loads.
			// But likely authenticated requests are used sparingly
			if ( apply_filters( 'wds_rest_connect_autoload_options', $this->do_autoload, $this->get_key() ) ) {
				$updated = $this->update_db( $this->get_key(), $this->option );
			} else {
				$updated = $this->add_db( $this->get_key(), $this->option, '', 'no' );
			}
		}

		return $updated;
	}

	/**
	 * Get option key
	 */
	public function get_key() {
		if ( empty( $this->key ) ) {
			throw new Exception( 'WDS_WP_REST_API\Storage\Options::$key is required.' );
		}

		return $this->key;
	}

	/**
	 * Set option key
	 */
	public function set_key( $key ) {
		$this->key = $key;
		return $this->key;
	}

	protected function get_from_db() {
		return call_user_func_array( 'get_option', func_get_args() );
	}

	protected function delete_from_db() {
		return call_user_func_array( 'delete_option', func_get_args() );
	}

	protected function update_db() {
		return call_user_func_array( 'update_option', func_get_args() );
	}

	protected function add_db() {
		return call_user_func_array( 'add_option', func_get_args() );
	}
}
