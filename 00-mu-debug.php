<?php

/*
  Plugin Name: Must-Use Debug
  Plugin URI:
  Description: Debug functions and error handling, merging Carbon Theme's lib/debug.php, CakePHP debug(), and homegrown functions.  Named to 00-mu-debug.php to load it first.
  Version: 1.2.0
  Author: DigiPowers, Inc.
 */

if (function_exists('dump')) {
    /**
     * Do not allow WordPress to process emojis when symfony's VarDumper is included due
     * to incompatibility between both libraries.
     */
    remove_action('wp_head', 'print_emoji_detection_script', 7);
} else {

    /**
     * This will be used only when symfony's var-dumper package is not loaded.
     */
    function dump()
    {
        $backtrace = debug_backtrace();
        for ($i = 0; $i < count($backtrace); $i++) {
            if (!empty($backtrace[$i]['file']) && !preg_match('@00-mu-debug.php$@', $backtrace[$i]['file'])) {
                break;
            }
        }
        $args = func_get_args();
        $elapsed = empty($GLOBALS['mudebug_start_time']) ? '' : sprintf("<span style='color:green'>(+ %s)</span>", round(microtime(true) - $GLOBALS['mudebug_start_time'], 2));
        echo sprintf("\n<pre style='text-align:left'><small>%s %s:%s</small>\n", $elapsed, $backtrace[$i]['file'], $backtrace[$i]['line']);
        if (empty($args)) {
            var_dump($args);
        } elseif (is_scalar($args[0])) {
            call_user_func_array('var_dump', $args);
        } else {
            foreach ($args as $arg) {
                print_r($arg);
            }
        }

        echo "</pre>";
    }
}

if (!function_exists('dd')) :

    /**
     * dump-and-die(dd): helper function that dumps the arguments and terminates the script execution.
     */
    function dd()
    {
        $args = func_get_args();

        call_user_func_array('dump', $args);
        exit;
    }

endif;

if (!function_exists('debug')) :

    function debug($args)
    {
        return dump($args);
    }

endif;

if (!function_exists('logfile')) {

    /**
     * Write a message with elapsed time, calling file, and line number.  This will go to:
     * 1) by default, the PHP-configured error_log, or 
     * 2) to a given logfile in the same directory unless error_log is a path under /proc
     *
     * @param string $message
     * @param string $name
     * @param bool $log_details         Default true
     */
    function logfile($message, $name = 'php-errors', $log_details = true)
    {
        // Get the calling file and line.
        // Works fine except for logging SQL due to the use of filters.  For that you need to use the Query Monitor plugin.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $backtrace_index = 0;
        for ($i = 0; $i < count($backtrace); $i++) {
            if (!empty($backtrace[$i]['file']) && !preg_match('@index\.php$@', $backtrace[$i]['file']) && strstr($backtrace[$i]['file'], pathinfo(__FILE__, PATHINFO_FILENAME)) == null && preg_match('@wp\-content@', $backtrace[$i]['file'])) {
                $backtrace_index = $i;
                break;
            }
        }

        $elapsed = 0;
        if (empty($GLOBALS[$name . '_start_time'])) {
            $GLOBALS[$name . '_start_time'] = microtime(true);
        } else {
            $elapsed = round(microtime(true) - $GLOBALS[$name . '_start_time'], 3);
        }
        $line = $log_details ? sprintf("\n[%s] %s:%s\n%s", $elapsed, $backtrace[$backtrace_index]['file'], $backtrace[$backtrace_index]['line'], (is_string($message) ? $message : print_r($message, true))) : $message . "\n";

        if (!preg_match('@^/proc@', ini_get('error_log')) && file_exists(ini_get('error_log')))
        {
            // The error_log is likely to be a path to an ordinary file, so write the log to a sibling in the same directory
            error_log($line, 3, dirname(ini_get('error_log')) . "/$name.log");
        }
        else {
            error_log($line);
        }
    }
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $handled = false;

    if (
    // Suppress the data decompression errors thrown by https://core.trac.wordpress.org/ticket/22952
            ($errstr == 'gzinflate(): data error')

            // https://core.trac.wordpress.org/ticket/29204
            || ($errstr == 'Non-static method WP_Feed_Cache::create() should not be called statically')

            // WooCommerce Authorize.NET AIM gateway throws this
            // https://app.asana.com/0/search/172104851133627/66906124167647
            || (preg_match('@AnetApi/xml/v1/schema/AnetApiSchema\.xsd is not absolute@', $errstr))
    ) {
        // Setting to true will bypass further error handling
        $handled = true;
    }
    return apply_filters('mudebug_suppress_errors', $handled, $errno, $errstr, $errfile, $errline);
});

if (WP_DEBUG) {

    /**
     * Log all HTTP requests, modified from https://gist.github.com/maheshwaghmare/a54a0d192be9c319fcbd180fbe32e35f
     *
     * @param array|WP_Error $response
     * @param string $context
     * @param string $class
     * @param array $parsed_args
     * @param string $url
     */
    add_action('http_api_debug', function ($response, $context, $class, $parsed_args, $url) {
        logfile(sprintf("[%s] ----------------> %s", date(DATE_ATOM), $url), 'http', false);
        logfile("class=$class \t context=$context \t parsed_args=".json_encode($parsed_args) . "\n", 'http', false);
        logfile(is_wp_error($response) ? implode(', ', $response->get_error_messages()) : $response['body'] . "\n", 'http', false);
    }, 10, 5);

    /**
     * Log SQL queries.  Not generally as good as the Query Monitor plugin for deciphering where stuff is coming from, but much
     * more quick-and-dirty. Enabled if the URL contains "sqllog".
     *
     * @param string $query
     */
    add_filter('query', function ($query) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace_sql = '';
        for ($i = 0; $i < count($backtrace); $i++) {
            // Only log the trace filenames that lie outside the WordPress core
            if (!empty($backtrace[$i]['file']) && !preg_match('@index\.php$@', $backtrace[$i]['file']) && strstr($backtrace[$i]['file'], pathinfo(__FILE__, PATHINFO_FILENAME)) == null && preg_match('@wp\-content@', $backtrace[$i]['file'])
            ) {
                $trace_sql .= sprintf("\n -- %s:%s", str_replace(ABSPATH, '', $backtrace[$i]['file']), $backtrace[$i]['line']);
            }
        }
        $query = $query . $trace_sql;

        if (preg_match('@sqllog@', $_SERVER['REQUEST_URI'])) {
            if (empty($GLOBALS['query_count'])) {
                $GLOBALS['query_count'] = 1;
            } else {
                $GLOBALS['query_count'] ++;
            }
            logfile(sprintf("%s#%s %s\n", ($GLOBALS['query_count'] == 1 ? "\n\n" : ''), $GLOBALS['query_count'], $query), 'sql');
        }
        // In case you want to quit on a particular query
        // if (preg_match('/wine/', $query)) dd($query);
        return $query;
    }, 100);
} else {
    // In production so disable all update checks as per http://www.wpoptimus.com/626/7-ways-disable-update-wordpress-notifications/
    foreach (array('pre_site_transient_update_core', 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes') as $filter) {
        add_filter($filter, function () {
            global $wp_version;
            return(object) array('last_checked' => time(), 'version_checked' => $wp_version);
        });
    }
}

// If for some reason you wanted to disable BWP Minify/Autoptimize - see  http://betterwp.net/wordpress-plugins/bwp-minify/#usage
if (defined('BWP_MINIFY_DISABLE')) {
    add_filter('bwp_minify_is_loadable', function () {
        return false;
    });

    add_filter('autoptimize_filter_noptimize', function ($flag_in) {
        return false;
    });
}

/**
 * Work around WordPress / WooCommerce bug in https://wordpress.org/support/topic/orders-page-bug-after-wordpress-5-0-2-update
 */
add_action('admin_init', function () {
    global $wp_version;

    if (version_compare($wp_version, '5.0.2', ">=")) {
        if (class_exists('WooCommerce')) {
            global $woocommerce;

            if (!version_compare($woocommerce->version, '3.5.3', ">=")) {
                add_filter('request', function ($query_args) {
                    if (isset($query_args['post_status']) &&
                        empty($query_args['post_status'])) {
                        unset($query_args['post_status']);
                    }

                    return $query_args;
                }, 1, 1);
            }
        }
    }
});

/**
 * Get resized image by image URL
 *
 * This MasterSlider function is overridden to prevent spamming the error log if the Offload S3 plugin is used for slider assets.
 *
 * @param  string   $img_url  The original image URL
 * @param  integer  $width    New image Width
 * @param  integer  $height   New image height
 * @param  bool     $crop     Whether to crop image to specified height and width or resize. Default false (soft crop).
 * @param  integer  $quality  New image quality - a number between 0 and 100
 * @return string   new image src
 */
function msp_get_the_resized_image_src($img_url = "", $width = null, $height = null, $crop = null, $quality = 100)
{
    $resized_img_url = $img_url;
    if (!class_exists('Amazon_S3_And_CloudFront')) {
        $resized_img_url = msp_aq_resize($img_url, $width, $height, $crop, $quality);
        if (empty($resized_img_url)) {
            $resized_img_url = $img_url;
        }
    }

    return apply_filters('msp_get_the_resized_image_src', $resized_img_url, $img_url);
}

/**
 * Bypass the automatic email sending via the new fatal error handler introduced in WordPress 5.2.
 **/
add_filter('recovery_mode_email', function ($email, $url) {
    $email['to'] = '';
    return $email;
}, 10, 2);

/**
 * Increase the heartbeat interval from the default 15 to 60 seconds, the maximum allowed.
 */
add_filter('heartbeat_settings', function ($settings) {
    $settings['interval'] = 60; //Anything between 15-60
    return $settings;
});

/**
 * Mangle the wc_subscriptions_site_url to force the site into Staging mode for subscriptions, if WOOCOMMERCE_SUBSCRIPTIONS_STAGING is defined.
 *
 * @param string $url
 * @param string $path
 * @param string $scheme
 * @param int $blog_id
 */
add_filter('wc_subscriptions_site_url', function ($url, $path, $scheme, $blog_id) {
    if (defined("WOOCOMMERCE_SUBSCRIPTIONS_STAGING")) {
        $url .= '-staging';
    }
    return $url;
}, 10, 4);

/**
 * Prevent Yoast from trying to generate Open Graph images, if WP Offload S3 is active.
 *
 * @param int $size
 */
add_filter('wpseo_opengraph_image_size', function ($size) {
    if (defined('WPSEO_VERSION') && class_exists('Amazon_S3_And_CloudFront')) {
        if (version_compare(WPSEO_VERSION, '15.0.0') >= 0) {
            $size = 'full';
        }
    }
    return $size;
});

/**
 * Prevent the block editor from handling Widget editing in WordPress 5.8.
 *
 * https://mainwp.com/how-to-restore-the-widget-editor-back-to-use-the-classic-editor/
 */
// Disables the block editor from managing widgets in the Gutenberg plugin.
add_filter('gutenberg_use_widgets_block_editor', '__return_false');
// Disables the block editor from managing widgets.
add_filter('use_widgets_block_editor', '__return_false');
