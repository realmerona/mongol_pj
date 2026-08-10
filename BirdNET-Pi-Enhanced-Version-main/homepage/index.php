<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($requestUri, '/api/v1/') === 0) {
  include_once 'scripts/api.php';
  die();
}

// The UI shell renders directly. The legacy full-page iframe wrapper was removed
// so URLs, browser history, bookmarks, and deep links behave like a normal site.
include 'views.php';
