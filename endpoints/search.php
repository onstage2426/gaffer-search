<?php

declare(strict_types=1);

/**
 * The search endpoint, without WordPress: GafferSearch\Support\SearchEndpoint.
 * URL: /wp-content/plugins/<plugin folder>/endpoints/search.php?q=…&token=<signed token>
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new GafferSearch\Support\SearchEndpoint(__DIR__, $_GET, $_SERVER))->handle()->send(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public, read-only endpoint authorized by its signed token
