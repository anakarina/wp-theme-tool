<?php

wp_plugin_directory_constants();

function get_plugins($plugin_folder = '', $plugin_root) {

	$wp_plugins = array();
	
	if ( !empty($plugin_folder) )
		$plugin_root .= $plugin_folder;		
		
	// Files in wp-content/plugins directory
	$plugins_dir = @ opendir( $plugin_root);
	
	$plugin_files = array();
	
	if ( $plugins_dir ) {
		while (($file = readdir( $plugins_dir ) ) !== false ) {
			if ( substr($file, 0, 1) == '.' )
				continue;
			if ( is_dir( $plugin_root.'/'.$file ) ) {
				$plugins_subdir = @ opendir( $plugin_root.'/'.$file );
				if ( $plugins_subdir ) {
					while (($subfile = readdir( $plugins_subdir ) ) !== false ) {
						if ( substr($subfile, 0, 1) == '.' )
							continue;
						if ( substr($subfile, -4) == '.php' )
							$plugin_files[] = "$file/$subfile";
					}
					closedir( $plugins_subdir );
				}
			} else {
				if ( substr($file, -4) == '.php' )
					$plugin_files[] = $file;
			}
		}
		closedir( $plugins_dir );
	}

	if ( empty($plugin_files) )
		return $wp_plugins;

	foreach ( $plugin_files as $plugin_file ) {
		if ( !is_readable( "$plugin_root/$plugin_file" ) )
			continue;

		$plugin_data = get_plugin_data( "$plugin_root/$plugin_file", false, false ); //Do not apply markup/translate as it'll be cached.

		if ( empty ( $plugin_data['Name'] ) )
			continue;

		$wp_plugins[plugin_basename( $plugin_file )] = $plugin_data;
	}

	//uasort( $wp_plugins, '_sort_uname_callback' );
	
	return $wp_plugins;
}

function get_plugin_data( $plugin_file, $markup = true, $translate = true ) {

	$default_headers = array(
		'Name' => 'Plugin Name',
		'PluginURI' => 'Plugin URI',
		'Version' => 'Version',
		'Description' => 'Description',
		'Author' => 'Author',
		'AuthorURI' => 'Author URI',
		'TextDomain' => 'Text Domain',
		'DomainPath' => 'Domain Path',
		'Network' => 'Network',
		// Site Wide Only is deprecated in favor of Network.
		'_sitewide' => 'Site Wide Only',
	);

	$plugin_data = get_file_data( $plugin_file, $default_headers, 'plugin' );

	// Site Wide Only is the old header for Network
	if ( ! $plugin_data['Network'] && $plugin_data['_sitewide'] ) {
		_deprecated_argument( __FUNCTION__, '3.0', sprintf( __( 'The <code>%1$s</code> plugin header is deprecated. Use <code>%2$s</code> instead.' ), 'Site Wide Only: true', 'Network: true' ) );
		$plugin_data['Network'] = $plugin_data['_sitewide'];
	}
	$plugin_data['Network'] = ( 'true' == strtolower( $plugin_data['Network'] ) );
	unset( $plugin_data['_sitewide'] );

	if ( $markup || $translate ) {
		$plugin_data = _get_plugin_data_markup_translate( $plugin_file, $plugin_data, $markup, $translate );
	} else {
		$plugin_data['Title']      = $plugin_data['Name'];
		$plugin_data['AuthorName'] = $plugin_data['Author'];
	}

	return $plugin_data;
}

//wp-includes/functions.php
/**
 * Retrieve metadata from a file.
 *
 * Searches for metadata in the first 8kiB of a file, such as a plugin or theme.
 * Each piece of metadata must be on its own line. Fields can not span multiple
 * lines, the value will get cut at the end of the first line.
 *
 * If the file data is not within that first 8kiB, then the author should correct
 * their plugin file and move the data headers to the top.
 *
 * @see http://codex.wordpress.org/File_Header
 *
 * @since 2.9.0
 * @param string $file Path to the file
 * @param array $default_headers List of headers, in the format array('HeaderKey' => 'Header Name')
 * @param string $context If specified adds filter hook "extra_{$context}_headers"
 */
function get_file_data( $file, $default_headers, $context = '' ) {
	// We don't need to write to the file, so just open for reading.
	$fp = fopen( $file, 'r' );

	// Pull only the first 8kiB of the file in.
	$file_data = fread( $fp, 8192 );

	// PHP will close file handle, but we are good citizens.
	fclose( $fp );

	// Make sure we catch CR-only line endings.
	$file_data = str_replace( "\r", "\n", $file_data );

	if ( $context && $extra_headers = apply_filters( "extra_{$context}_headers", array() ) ) {
		$extra_headers = array_combine( $extra_headers, $extra_headers ); // keys equal values
		$all_headers = array_merge( $extra_headers, (array) $default_headers );
	} else {
		$all_headers = $default_headers;
	}

	foreach ( $all_headers as $field => $regex ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] )
			$all_headers[ $field ] = _cleanup_header_comment( $match[1] );
		else
			$all_headers[ $field ] = '';
	}

	return $all_headers;
}

/**
 * Call the functions added to a filter hook.
 *
 * The callback functions attached to filter hook $tag are invoked by calling
 * this function. This function can be used to create a new filter hook by
 * simply calling this function with the name of the new hook specified using
 * the $tag parameter.
 *
 * The function allows for additional arguments to be added and passed to hooks.
 * <code>
 * function example_hook($string, $arg1, $arg2)
 * {
 *              //Do stuff
 *              return $string;
 * }
 * $value = apply_filters('example_filter', 'filter me', 'arg1', 'arg2');
 * </code>
 *
 * @package WordPress
 * @subpackage Plugin
 * @since 0.71
 * @global array $wp_filter Stores all of the filters
 * @global array $merged_filters Merges the filter hooks using this function.
 * @global array $wp_current_filter stores the list of current filters with the current one last
 *
 * @param string $tag The name of the filter hook.
 * @param mixed $value The value on which the filters hooked to <tt>$tag</tt> are applied on.
 * @param mixed $var,... Additional variables passed to the functions hooked to <tt>$tag</tt>.
 * @return mixed The filtered value after all hooked functions are applied to it.
 */
function apply_filters($tag, $value) {
        global $wp_filter, $merged_filters, $wp_current_filter;

        $args = array();

        // Do 'all' actions first
        if ( isset($wp_filter['all']) ) {
                $wp_current_filter[] = $tag;
                $args = func_get_args();
                _wp_call_all_hook($args);
        }

        if ( !isset($wp_filter[$tag]) ) {
                if ( isset($wp_filter['all']) )
                        array_pop($wp_current_filter);
                return $value;
        }

        if ( !isset($wp_filter['all']) )
                $wp_current_filter[] = $tag;

        // Sort
        if ( !isset( $merged_filters[ $tag ] ) ) {
                ksort($wp_filter[$tag]);
                $merged_filters[ $tag ] = true;
        }

        reset( $wp_filter[ $tag ] );

        if ( empty($args) )
                $args = func_get_args();

        do {
                foreach( (array) current($wp_filter[$tag]) as $the_ )
                        if ( !is_null($the_['function']) ){
                                $args[1] = $value;
                                $value = call_user_func_array($the_['function'], array_slice($args, 1, (int) $the_['accepted_args']));
                        }

        } while ( next($wp_filter[$tag]) !== false );

        array_pop( $wp_current_filter );
	
	        return $value;
}

/**
 * Strip close comment and close php tags from file headers used by WP.
 * See http://core.trac.wordpress.org/ticket/8497
 *
 * @since 2.8.0
 *
 * @param string $str
 * @return string
 */
function _cleanup_header_comment($str) {
	return trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $str));
}

/**
 * Gets the basename of a plugin.
 *
 * This method extracts the name of a plugin from its filename.
 *
 * @package WordPress
 * @subpackage Plugin
 * @since 1.5
 *
 * @access private
 *
 * @param string $file The filename of plugin.
 * @return string The name of a plugin.
 * @uses WP_PLUGIN_DIR
 */
function plugin_basename($file) {
        $file = str_replace('\\','/',$file); // sanitize for Win32 installs
        $file = preg_replace('|/+|','/', $file); // remove any duplicate slash
        $plugin_dir = str_replace('\\','/',WP_PLUGIN_DIR); // sanitize for Win32 installs
        $plugin_dir = preg_replace('|/+|','/', $plugin_dir); // remove any duplicate slash
        $mu_plugin_dir = str_replace('\\','/',WPMU_PLUGIN_DIR); // sanitize for Win32 installs
        $mu_plugin_dir = preg_replace('|/+|','/', $mu_plugin_dir); // remove any duplicate slash
        $file = preg_replace('#^' . preg_quote($plugin_dir, '#') . '/|^' . preg_quote($mu_plugin_dir, '#') . '/#','',$file); // get relative path from plugins dir
        $file = trim($file, '/');
        return $file;
}

/**
 * Defines plugin directory WordPress constants
 *
 * Defines must-use plugin directory constants, which may be overridden in the sunrise.php drop-in
 *
 * @since 3.0.0
 */
function wp_plugin_directory_constants( ) {

if ( !defined('ABSPATH') ){
// definir aqui o diretorio padrao do wordpress
	define('ABSPATH', dirname(__FILE__) . '/');
	}

if ( !defined('WP_CONTENT_DIR') )
		define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' ); // no trailing slash, full paths only - WP_CONTENT_URL is defined further down

	/**
	 * Allows for the plugins directory to be moved from the default location.
	 *
	 * @since 2.6.0
	 */
	if ( !defined('WP_PLUGIN_DIR') )
		define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' ); // full path, no trailing slash

	/**
	 * Allows for the plugins directory to be moved from the default location.
	 *
	 * @since 2.1.0
	 * @deprecated
	 */
	if ( !defined('PLUGINDIR') )
		define( 'PLUGINDIR', 'wp-content/plugins' ); // Relative to ABSPATH. For back compat.

	/**
	 * Allows for the mu-plugins directory to be moved from the default location.
	 *
	 * @since 2.8.0
	 */
	if ( !defined('WPMU_PLUGIN_DIR') )
		define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' ); // full path, no trailing slash

	/**
	 * Allows for the mu-plugins directory to be moved from the default location.
	 *
	 * @since 2.8.0
	 * @deprecated
	 */
	if ( !defined( 'MUPLUGINDIR' ) )
		define( 'MUPLUGINDIR', 'wp-content/mu-plugins' ); // Relative to ABSPATH. For back compat.
}

/**
 * Retrieve option value based on name of option.
 *
 * If the option does not exist or does not have a value, then the return value
 * will be false. This is useful to check whether you need to install an option
 * and is commonly used during installation of plugin options and to test
 * whether upgrading is required.
 *
 * If the option was serialized then it will be unserialized when it is returned.
 *
 * @since 1.5.0
 * @package WordPress
 * @subpackage Option
 * @uses apply_filters() Calls 'pre_option_$option' before checking the option.
 * 	Any value other than false will "short-circuit" the retrieval of the option
 *	and return the returned value. You should not try to override special options,
 * 	but you will not be prevented from doing so.
 * @uses apply_filters() Calls 'option_$option', after checking the option, with
 * 	the option value.
 *
 * @param string $option Name of option to retrieve. Expected to not be SQL-escaped.
 * @param mixed $default Optional. Default value to return if the option does not exist.
 * @return mixed Value set for the option.
 */
function get_option( $option, $default = false ) {
	global $wpdb;

	$value = '';
	// Allow plugins to short-circuit options.
	$pre = apply_filters( 'pre_option_' . $option, false );
	if ( false !== $pre )
		return $pre;

	$option = trim($option);
	if ( empty($option) )
		return false;

	if ( defined( 'WP_SETUP_CONFIG' ) )
		return false;

	
	if ( ! defined( 'WP_INSTALLING' ) ) {
		// prevent non-existent options from triggering multiple queries
		//$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( isset( $notoptions[$option] ) )
			return apply_filters( 'default_option_' . $option, $default );

		//$alloptions = wp_load_alloptions();

		if ( isset( $alloptions[$option] ) ) {
			$value = $alloptions[$option];
		} else {
			//$value = wp_cache_get( $option, 'options' );

			if ( false === $value ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $option ) );

				// Has to be get_row instead of get_var because of funkiness with 0, false, null values
				if ( is_object( $row ) ) {
					$value = $row->option_value;
					//wp_cache_add( $option, $value, 'options' );
				} else { // option does not exist, so we must cache its non-existence
					$notoptions[$option] = true;
					//wp_cache_set( 'notoptions', $notoptions, 'options' );
					return apply_filters( 'default_option_' . $option, $default );
				}
			}
		}
	} else { 
	
		$suppress = $wpdb->suppress_errors();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $option ) );
		$wpdb->suppress_errors( $suppress );
		if ( is_object( $row ) )
			$value = $row->option_value;
		else
			return apply_filters( 'default_option_' . $option, $default );
	} 

	// If home is not set use siteurl.
	if ( 'home' == $option && '' == $value )
		return get_option( 'siteurl' );

	if ( in_array( $option, array('siteurl', 'home', 'category_base', 'tag_base') ) )
		$value = untrailingslashit( $value );

	return apply_filters( 'option_' . $option, maybe_unserialize( $value ) );
}

/**
 * Removes trailing slash if it exists.
 *
 * The primary use of this is for paths and thus should be used for paths. It is
 * not restricted to paths and offers no specific path support.
 *
 * @since 2.2.0
 *
 * @param string $string What to remove the trailing slash from.
 * @return string String without the trailing slash.
 */
function untrailingslashit($string) {
	return rtrim($string, '/');
}

/**
 * Unserialize value only if it was serialized.
 *
 * @since 2.0.0
 *
 * @param string $original Maybe unserialized original, if is needed.
 * @return mixed Unserialized data can be any type.
 */
function maybe_unserialize( $original ) {
	if ( is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
		return @unserialize( $original );
	return $original;
}

/**
 * Check value to find if it was serialized.
 *
 * If $data is not an string, then returned value will always be false.
 * Serialized data is always a string.
 *
 * @since 2.0.5
 *
 * @param mixed $data Value to check to see if was serialized.
 * @return bool False if not serialized and true if it was.
 */
function is_serialized( $data ) {
	// if it isn't a string, it isn't serialized
	if ( ! is_string( $data ) )
		return false;
	$data = trim( $data );
 	if ( 'N;' == $data )
		return true;
	$length = strlen( $data );
	if ( $length < 4 )
		return false;
	if ( ':' !== $data[1] )
		return false;
	$lastc = $data[$length-1];
	if ( ';' !== $lastc && '}' !== $lastc )
		return false;
	$token = $data[0];
	switch ( $token ) {
		case 's' :
			if ( '"' !== $data[$length-2] )
				return false;
		case 'a' :
		case 'O' :
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b' :
		case 'i' :
		case 'd' :
			return (bool) preg_match( "/^{$token}:[0-9.E-]+;\$/", $data );
	}
	return false;
}

/**
* Retrieve the site url for the current site.
*
* Returns the 'site_url' option with the appropriate protocol, 'https' if
* is_ssl() and 'http' otherwise. If $scheme is 'http' or 'https', is_ssl() is
* overridden.
*
* @package WordPress
* @since 2.6.0
*
* @uses get_site_url()
*
* @param string $path Optional. Path relative to the site url.
* @param string $scheme Optional. Scheme to give the site url context. See set_url_scheme().
* @return string Site url link with optional path appended.
*/
function site_url( $path = '', $scheme = null ) {
	        return get_site_url( null, $path, $scheme );
}

/**
 * Retrieve the site url for a given site.
 *
 * Returns the 'site_url' option with the appropriate protocol, 'https' if
 * is_ssl() and 'http' otherwise. If $scheme is 'http' or 'https', is_ssl() is
 * overridden.
 *
 * @package WordPress
 * @since 3.0.0
 *
 * @param int $blog_id (optional) Blog ID. Defaults to current blog.
 * @param string $path Optional. Path relative to the site url.
 * @param string $scheme Optional. Scheme to give the site url context. Currently 'http', 'https', 'login', 'login_post', 'admin', or 'relative'.
 * @return string Site url link with optional path appended.
*/
function get_site_url( $blog_id = null, $path = '', $scheme = null ) {
	// should the list of allowed schemes be maintained elsewhere?
	$orig_scheme = $scheme;
	if ( !in_array( $scheme, array( 'http', 'https', 'relative' ) ) ) {
		if ( ( 'login_post' == $scheme || 'rpc' == $scheme ) && ( force_ssl_login() || force_ssl_admin() ) )
			$scheme = 'https';
		elseif ( ( 'login' == $scheme ) && force_ssl_admin() )
			$scheme = 'https';
		elseif ( ( 'admin' == $scheme ) && force_ssl_admin() )
			$scheme = 'https';
		else
			$scheme = ( is_ssl() ? 'https' : 'http' );
	}

	if ( empty( $blog_id ) || !is_multisite() )
		$url = get_option( 'siteurl' );
	else
		$url = get_blog_option( $blog_id, 'siteurl' );

	if ( 'relative' == $scheme )
		$url = preg_replace( '#^.+://[^/]*#', '', $url );
	elseif ( 'http' != $scheme )
		$url = str_replace( 'http://', "{$scheme}://", $url );

	if ( !empty( $path ) && is_string( $path ) && strpos( $path, '..' ) === false )
		$url .= '/' . ltrim( $path, '/' );

	return apply_filters( 'site_url', $url, $path, $orig_scheme, $blog_id );
}

/**
 * Determine if SSL is used.
 *
 * @since 2.6.0
 *
 * @return bool True if SSL, false if not used.
 */
function is_ssl() {
	if ( isset($_SERVER['HTTPS']) ) {
		if ( 'on' == strtolower($_SERVER['HTTPS']) )
			return true;
		if ( '1' == $_SERVER['HTTPS'] )
			return true;
	} elseif ( isset($_SERVER['SERVER_PORT']) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
		return true;
	}
	return false;
}

?>