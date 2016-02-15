<?php
namespace WDS_WP_REST_API\Storage;

interface Store_Interface {

    /**
     * Retrieve stored option
     *
     * @param  string  $option Option array key
     * @param  string  $key    Key for secondary array
     * @param  boolean $force  Force a new call to get_option
     *
     * @return mixed           Value of option requested
     */
    public function get( $option, $key = '', $force = false );

    /**
     * Update the options array
     *
     * @since  0.1.0
     *
     * @param  string  $option Option array key
     * @param  mixed   $value  Value to be updated
     * @param  string  $key    Key for secondary array
     *
     * @return                 Original $value if successful
     */
    public function update( $value, $option = '', $key = '', $set = true );

    /**
     * Handles deleting the stored data for a connection
     *
     * @param  string $option Specific option key to unset. If empty, entire object is deleted.
     *
     * @return bool           Result of deletion from DB
     */
    public function delete( $option = '' );

    /**
     * Update the stored value
     *
     * @return Result of storage update/add
     */
    public function set( $options = null );

    /**
     * Get option key
     */
    public function get_key();

    /**
     * Set option key
     */
    public function set_key( $key );
}
