<?php

declare(strict_types=1);

/**
 * Plugin Name: Gaffer Search
 * Description: Fast full-text search for WordPress, powered by Fuzor.
 * Version: 0.0.0
 * Requires PHP: 8.5
 * Requires at least: 6.0
 * Text Domain: gaffer-search
 * Plugin URI: https://github.com/onstage2426/gaffer-search
 * Update URI: https://github.com/onstage2426/gaffer-search
 * Author: onstage2426
 * Author URI: https://github.com/onstage2426
 */

defined("ABSPATH") || exit();

require_once __DIR__ . "/vendor/autoload.php";

use GafferSearch\Bootstrap;
use GafferSearch\WpHubUpdater\Plugin\PluginUpdater;

$gaffer_search_updater = PluginUpdater::build("https://github.com/onstage2426/gaffer-search", __FILE__)
    // 1.0 too: the last 0.x release lets 0.x sites update to it. Full releases only: a release
    // marked as a prerelease never reaches a live site.
    ->setReleaseVersionFilter("/^[01]\./", PluginUpdater::RELEASE_FILTER_SKIP_PRERELEASE)
    ->throttleRedundantChecks(hours: 72)
    ->setCooldown(hours: 0)
    ->enableAutoUpdates();

// Releases only: a tag's or branch's source zip has no vendor/ (and no generated updater), and
// installing it would take the site down. A check that finds no release offers nothing.
add_filter("hu_vcs_update_detection_strategies-gaffer-search", static fn(array $strategies): array => array_intersect_key($strategies, ["latest_release" => true]));

$bootstrap = new Bootstrap(__FILE__);
register_activation_hook(__FILE__, $bootstrap->activate(...));
register_deactivation_hook(__FILE__, [Bootstrap::class, "deactivate"]);
add_action("plugins_loaded", fn() => $bootstrap->init(), 20);
