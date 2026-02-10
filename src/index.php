<?php

// Temporary startup debugging for index.php failures.
// This makes PHP startup/runtime issues visible in the browser instead of a blank HTTP 500 page.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

$renderStartupDebug = static function (string $title, string $message, ?string $details = null): void {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeDetails = $details !== null ? nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8')) : '';

    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'><title>ChurchCRM Debug</title>";
    echo "<style>body{font-family:Arial,sans-serif;background:#f7f7f9;padding:24px;}";
    echo ".box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:1100px;}";
    echo "h1{margin-top:0;color:#b00020;}pre{white-space:pre-wrap;word-wrap:break-word;background:#f3f3f3;padding:12px;border-radius:6px;}</style></head><body>";
    echo "<div class='box'><h1>{$safeTitle}</h1><p>{$safeMessage}</p>";

    if ($safeDetails !== '') {
        echo "<h3>Details</h3><pre>{$safeDetails}</pre>";
    }

    echo "<h3>Environment</h3><pre>";
    echo 'PHP Version: ' . phpversion() . "\n";
    echo 'Script: ' . (__FILE__) . "\n";
    echo 'Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
    echo 'Config Exists: ' . (file_exists(__DIR__ . '/Include/Config.php') ? 'yes' : 'no') . "\n";
    echo "</pre></div></body></html>";
    exit(1);
};

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($renderStartupDebug): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $renderStartupDebug(
        'PHP Error while loading ChurchCRM',
        $message,
        "Severity: {$severity}\nFile: {$file}\nLine: {$line}"
    );
});

set_exception_handler(static function (Throwable $exception) use ($renderStartupDebug): void {
    $renderStartupDebug(
        'Unhandled Exception while loading ChurchCRM',
        $exception->getMessage(),
        "Type: " . get_class($exception) . "\nFile: " . $exception->getFile() . "\nLine: " . $exception->getLine() . "\n\nStack Trace:\n" . $exception->getTraceAsString()
    );
});

// Load composer autoloader first so we can use VersionUtils utility
require_once __DIR__ . '/vendor/autoload.php';

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Utils\MiscUtils;
use ChurchCRM\Utils\VersionUtils;

// Get required PHP version from composer.json (single source of truth)
// Throws RuntimeException if system state cannot be determined
try {
    $requiredPhp = VersionUtils::getRequiredPhpVersion();
} catch (\RuntimeException $e) {
    // System cannot determine PHP requirements - fail loudly with clear error
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Critical System Error: " . $e->getMessage() . "\n\n";
    echo "Please contact your system administrator or check your ChurchCRM installation.";
    exit(1);
}

$phpVersion = phpversion();
if (version_compare($phpVersion, $requiredPhp, '<')) {
    header('Location: php-error.php');
    exit;
}

header('CRM: would redirect');

if (file_exists('Include/Config.php')) {
    require_once __DIR__ . '/Include/Config.php';
} else {
    header('Location: setup');
    exit;
}

mb_internal_encoding('UTF-8');

// Get the current request path and convert it into a magic filename
// e.g. /list-events => /ListEvents.php
$shortName = str_replace(SystemURLs::getRootPath() . '/', '', $_SERVER['REQUEST_URI']);
$fileName = MiscUtils::dashesToCamelCase($shortName, true) . '.php';

if (!empty($_GET['location'])) {
    $_SESSION['location'] = $_GET['location'];
}

// First, ensure that the user is authenticated.
AuthenticationManager::ensureAuthentication();

if (strtolower($shortName) === 'index.php' || strtolower($fileName) === 'index.php') {
    // Index.php -> v2/dashboard
    header('Location: ' . SystemURLs::getRootPath() . '/v2/dashboard');
    exit;
} elseif (file_exists($shortName)) {
    // Try actual path
    require $shortName;
} elseif (file_exists($fileName)) {
    // Try magic filename
    require $fileName;
} elseif (strpos($_SERVER['REQUEST_URI'], 'js') || strpos($_SERVER['REQUEST_URI'], 'css')) { // if this is a CSS or JS file that we can't find, return 404
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true, 404);
    exit;
} else {
    header('Location: index.php');
    exit;
}
