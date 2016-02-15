<?php
namespace WDS_WP_REST_API\Storage;

interface Transient_Interface {

    /**
     * Retrieve stored option
     *
     * @param  boolean $force  Force a new call to get_option
     *
     * @return mixed Value of option requested
     */
    public function get( $force = false );

    /**
     * Update the stored value
     *
     * @param  mixed $value Value to be updated
     *
     * @return Result of storage update/add
     */
    public function set( $value );

    /**
     * Handles deleting the stored data for a connection
     *
     * @param  string $option Specific option key to unset. If empty, entire object is deleted.
     *
     * @return bool           Result of deletion from DB
     */
    public function delete();

    /**
     * Get option key
     */
    public function get_key();

    /**
     * Set option key
     */
    public function set_key( $key );

    /**
     * Get transient expiration
     */
    public function get_expiration();

    /**
     * Set transient expiration
     */
    public function set_expiration( $expiration );
}
