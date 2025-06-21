# 00-mu-debug.php

This Must-Use plugin is designed to handle a few common development needs, including:

1. Add `dump()`, `dd()`, and `debug()` functions for variable output
1. Add `logfile()` function to output to a standard error log
1. Allow for handling errors via a filter
1. If `WP_DEBUG` is true, add SQL query logging to a separate file. Otherwise bypass all core and plugin update checking.
1. Allow managing some plugins via a `define`:
   1. bwp-minify and autoptimize can be disabled
   1. woocommerce-subscriptions can be put into staging mode
1. Work around a few plugin compatibility bugs:
   1. WordPress 5.0.2 and WooCommerce prior to 3.5.3 [have a display bug](https://wordpress.org/support/topic/orders-page-bug-after-wordpress-5-0-2-update) on the Orders page
   1. MasterSlider will spam the error log if slide assets are deployed via WP Offload S3
   1. Prevent Yoast from trying to generate images if WP Offload S3 is active
