<?php

declare(strict_types=1);

// Deleting the plugin from the Plugins screen: remove its indexes, tables, options and configs.
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/vendor/autoload.php';

GafferSearch\Bootstrap::uninstall();
