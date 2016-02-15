<?php
namespace WDS_WP_REST_API\Storage;

use Exception;
use WDS_WP_REST_API\Storage\Transient_Interface;

class Transients implements Transient_Interface {

	protected $value = null;
	protected $key = '';
	protected $expiration = '';

	public function __construct() {
		// Can this be set outside constructor/function?
		$this->expiration = HOUR_IN_SECONDS;
	}

	/**
	 * Retrieve stored option
	 *
	 * @return mixed Value of transient requested
	 */
	public function get( $force = false ) {
		if ( null === $this->value || $force ) {
			$this->value = $this->get_from_db( $this->get_key() );
		}

		return $this->value;
	}

	/**
	 * Update the stored value
	 *
	 * @return Result of storage update/add
	 */
	public function set( $value ) {
		$this->value = $value;
		return $this->update_db( $this->get_key(), $this->value, $this->expiration );
	}

	/**
	 * Handles deleting the stored data for a connection.
	 *
	 * @return bool Result of deletion from DB.
	 */
	public function delete(){
		$this->value = null;
		return $this->delete_from_db( $this->get_key() );
	}

	/**
	 * Get transient key
	 */
	public function get_key() {
		if ( empty( $this->key ) ) {
			throw new Exception( 'WDS_WP_REST_API\Storage\Transients::$key is required.' );
		}

		return $this->key;
	}

	/**
	 * Set transient key
	 */
	public function set_key( $key ) {
		$this->key = $key;
		return $this->key;
	}

	/**
	 * Get transient expiration
	 */
	public function get_expiration() {
		return $this->expiration;
	}

	/**
	 * Set transient expiration
	 */
	public function set_expiration( $expiration ) {
		$this->expiration = $expiration;
		return $this->expiration;
	}

	protected function get_from_db() {
		return call_user_func_array( 'get_transient', func_get_args() );
	}

	protected function delete_from_db() {
		return call_user_func_array( 'delete_transient', func_get_args() );
	}

	protected function update_db() {
		return call_user_func_array( 'set_transient', func_get_args() );
	}

}
