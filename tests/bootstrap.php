<?php
/**
 * PHPUnit bootstrap + shared integration-test helpers.
 *
 * Endpoint tests exercise the real public/api entry points (file -> controller
 * -> repository -> DB -> JSON) against the imported `mike-carvalue` database,
 * asserting robust properties rather than exact dollar values. Estimator tests
 * drive CarValue\ValueEstimator directly with crafted comparable sets.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

define('CARVALUE_ROOT', dirname(__DIR__));
define('CARVALUE_TEST_HOST', '127.0.0.1');
define('CARVALUE_TEST_PORT', (int) (getenv('CARVALUE_TEST_PORT') ?: 8077));
define('CARVALUE_BASE_URL', 'http://' . CARVALUE_TEST_HOST . ':' . CARVALUE_TEST_PORT);

/**
 * Boot a real PHP built-in server over the public/ docroot so endpoint tests
 * observe true HTTP status codes (the in-process headers_sent() quirk under
 * PHPUnit makes status codes unobservable otherwise).
 */
(static function (): void {
    $cmd = sprintf(
        'php -S %s:%d -t %s >/dev/null 2>&1 & echo $!',
        CARVALUE_TEST_HOST,
        CARVALUE_TEST_PORT,
        escapeshellarg(CARVALUE_ROOT . '/public')
    );
    $pid = (int) shell_exec($cmd);

    // Wait until the server accepts connections.
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen(CARVALUE_TEST_HOST, CARVALUE_TEST_PORT, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            $ready = true;
            break;
        }
        usleep(100000); // 100ms
    }
    if (!$ready) {
        fwrite(STDERR, "Failed to start test server on port " . CARVALUE_TEST_PORT . "\n");
        exit(1);
    }

    register_shutdown_function(static function () use ($pid): void {
        if ($pid > 0) {
            exec('kill ' . $pid . ' 2>/dev/null');
        }
    });
})();

/** Load application config. */
function carvalue_config(): array
{
    return require CARVALUE_ROOT . '/config/config.php';
}

/** PDO to a given database (defaults to the configured app database). */
function carvalue_pdo(?string $dbName = null): PDO
{
    $cfg = carvalue_config()['db'];
    if ($dbName !== null) {
        $cfg['name'] = $dbName;
    }
    return CarValue\Database::connect($cfg);
}

/**
 * Hit an API entry point over real HTTP and capture status + JSON body.
 *
 * @return array{status:int,json:mixed,raw:string}
 */
function call_api(string $script, array $query): array
{
    $url = CARVALUE_BASE_URL . '/api/' . $script;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'ignore_errors' => true, // capture 4xx bodies instead of failing
        'timeout'       => 30,
    ]]);
    $raw = file_get_contents($url, false, $ctx);

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1]; // last status line (handles any redirects)
        }
    }

    return [
        'status' => $status,
        'json'   => json_decode((string) $raw, true),
        'raw'    => (string) $raw,
    ];
}

/** Run the importer CLI into a scratch DB; returns [exitCode, output]. */
function run_importer(string $db, string $source): array
{
    $cmd = sprintf(
        'php %s --db=%s --source=%s 2>&1',
        escapeshellarg(CARVALUE_ROOT . '/bin/import-data.php'),
        escapeshellarg($db),
        escapeshellarg($source)
    );
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}
