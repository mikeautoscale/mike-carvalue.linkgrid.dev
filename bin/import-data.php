<?php
/**
 * CarValue — raw inventory importer.
 *
 * Downloads the full pipe-delimited inventory file (or uses a local copy),
 * creates the `mike-carvalue` database + `dealers`/`listings` tables if needed,
 * and streams the data in with constant memory and live progress output.
 *
 * Usage:
 *   php bin/import-data.php [options]
 *
 * Options:
 *   --source=<url|path>  Source file (default: design-doc inventory URL).
 *   --db=<name>          Target database (default: mike-carvalue).
 *   --limit=<N>          Import only the first N data rows (0 = all).
 *   --batch=<N>          Rows per INSERT batch (default: 2000).
 *   --keep               Do NOT truncate tables before importing.
 *   --force-download     Re-download even if a valid local cache exists.
 *   --help               Show this help.
 *
 * Connection is configurable via env: DB_SOCKET, DB_HOST, DB_PORT,
 * DB_USER, DB_PASS. Local default is the MariaDB unix socket as root.
 *
 * Memory budget: < 500 MB (streamed read + bounded insert batches +
 * an in-memory dealer-dedupe cache).
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');
error_reporting(E_ALL);

const SOURCE_DEFAULT  = 'https://linkgrid.com/downloads/carvalue_project/inventory-listing-2022-08-17.txt';
const EXPECTED_COLS   = 25;
const PROGRESS_EVERY  = 100000; // rows

/** Column order in the source file (and header validation). */
const HEADER = [
    'vin', 'year', 'make', 'model', 'trim',
    'dealer_name', 'dealer_street', 'dealer_city', 'dealer_state', 'dealer_zip',
    'listing_price', 'listing_mileage', 'used', 'certified', 'style',
    'driven_wheels', 'engine', 'fuel_type', 'exterior_color', 'interior_color',
    'seller_website', 'first_seen_date', 'last_seen_date',
    'dealer_vdp_last_seen_date', 'listing_status',
];

class Importer
{
    private PDO $pdo;
    private array $opts;
    private string $projectRoot;

    /** @var array<string,int> natural_key => dealer id */
    private array $dealerCache = [];

    private ?PDOStatement $batchStmt = null;
    private array $batch = [];
    private int $batchSize;

    // counters
    private int $rows = 0;
    private int $inserted = 0;
    private int $skippedMalformed = 0;
    private int $newDealers = 0;
    private float $startedAt = 0.0;

    public function __construct(array $opts)
    {
        $this->opts = $opts;
        $this->batchSize = max(1, (int) $opts['batch']);
        $this->projectRoot = dirname(__DIR__);
    }

    public function run(): void
    {
        $this->startedAt = microtime(true);
        $db = $this->opts['db'];

        $this->log("CarValue importer starting");
        $this->log("  database : {$db}");
        $this->log("  source   : {$this->opts['source']}");
        $this->log("  batch    : {$this->batchSize} rows");

        $this->connectServer();
        $this->createSchema($db);
        $this->pdo->exec('USE `' . str_replace('`', '``', $db) . '`');

        if (!$this->opts['keep']) {
            $this->log('Truncating existing tables (use --keep to preserve)...');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $this->pdo->exec('TRUNCATE TABLE listings');
            $this->pdo->exec('TRUNCATE TABLE dealers');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $path = $this->acquireFile($this->opts['source']);
        $this->import($path);

        $this->log('Building hot-path index idx_ymm (make_key, model_key, year)...');
        $this->ensureIndex();

        $this->log('Rebuilding vehicle_summary lookup rollup...');
        $this->buildSummary();

        $this->report(true);
        $this->summary();
    }

    private function connectServer(): void
    {
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

        if ($host = getenv('DB_HOST')) {
            $port = getenv('DB_PORT') ?: '3306';
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        } else {
            $sock = getenv('DB_SOCKET') ?: '/var/lib/mysql/mysql.sock';
            $dsn = "mysql:unix_socket={$sock};charset=utf8mb4";
        }

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true, // needed for large multi-row batches
        ]);
    }

    private function createSchema(string $db): void
    {
        $dbEsc = str_replace('`', '``', $db);
        $this->pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbEsc}` " .
            'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $this->pdo->exec('USE `' . $dbEsc . '`');

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS dealers (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(255) NULL,
                street      VARCHAR(255) NULL,
                city        VARCHAR(128) NULL,
                state       CHAR(2)      NULL,
                zip         VARCHAR(10)  NULL,
                natural_key CHAR(40)     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_dealer_natural (natural_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS listings (
                id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                vin                       CHAR(17)     NULL,
                `year`                    SMALLINT UNSIGNED NULL,
                make                      VARCHAR(64)  NULL,
                model                     VARCHAR(128) NULL,
                `trim`                    VARCHAR(128) NULL,
                make_key                  VARCHAR(64)  NULL,
                model_key                 VARCHAR(128) NULL,
                dealer_id                 BIGINT UNSIGNED NULL,
                listing_price             INT UNSIGNED NULL,
                listing_mileage           INT UNSIGNED NULL,
                used                      TINYINT(1)   NULL,
                certified                 TINYINT(1)   NULL,
                style                     VARCHAR(128) NULL,
                driven_wheels             VARCHAR(32)  NULL,
                engine                    VARCHAR(128) NULL,
                fuel_type                 VARCHAR(64)  NULL,
                exterior_color            VARCHAR(64)  NULL,
                interior_color            VARCHAR(64)  NULL,
                seller_website            VARCHAR(255) NULL,
                first_seen_date           DATE         NULL,
                last_seen_date            DATE         NULL,
                dealer_vdp_last_seen_date DATE         NULL,
                listing_status            VARCHAR(32)  NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_listing_dealer FOREIGN KEY (dealer_id)
                    REFERENCES dealers (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Materialized make/model/year rollup powering the fast lookup endpoints
        // (makes/models autocomplete + the YMM parser). Rebuilt after each load.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS vehicle_summary (
                make_key  VARCHAR(64)  NOT NULL,
                model_key VARCHAR(128) NOT NULL,
                `year`    SMALLINT UNSIGNED NOT NULL,
                make      VARCHAR(64)  NOT NULL,
                model     VARCHAR(128) NOT NULL,
                listings  INT UNSIGNED NOT NULL,
                PRIMARY KEY (make_key, model_key, `year`),
                KEY idx_make (make_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    /** Rebuild the make/model/year rollup from the freshly loaded listings. */
    private function buildSummary(): void
    {
        $this->pdo->exec('TRUNCATE TABLE vehicle_summary');
        $this->pdo->exec(
            'INSERT INTO vehicle_summary (make_key, model_key, `year`, make, model, listings) '
            . 'SELECT make_key, model_key, `year`, MIN(make), MIN(model), COUNT(*) '
            . 'FROM listings '
            . "WHERE make_key REGEXP '[A-Za-z]' AND model_key REGEXP '[A-Za-z]' "
            . 'AND `year` IS NOT NULL '
            . 'GROUP BY make_key, model_key, `year`'
        );
    }

    /** Download to a local cache (with progress) or use a local path directly. */
    private function acquireFile(string $source): string
    {
        if (!preg_match('#^https?://#i', $source)) {
            if (!is_readable($source)) {
                $this->fail("Source file not readable: {$source}");
            }
            return $source;
        }

        $dataDir = $this->projectRoot . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }
        $dest = $dataDir . '/' . basename(parse_url($source, PHP_URL_PATH) ?: 'inventory.txt');

        $remoteSize = $this->remoteSize($source);
        if ($remoteSize <= 0) {
            $this->fail('Could not determine remote file size (Content-Length); refusing to import a file of unknown completeness.');
        }
        if (!$this->opts['force-download']
            && is_file($dest)
            && filesize($dest) === $remoteSize
        ) {
            $this->log("Using cached download: {$dest} (" . $this->fmtBytes($remoteSize) . ')');
            return $dest;
        }
        if ($this->opts['force-download'] && is_file($dest)) {
            unlink($dest);
        }

        $this->download($source, $dest, $remoteSize);
        return $dest;
    }

    /**
     * Resilient resumable download. Connections to the source can drop mid-stream
     * and the HTTP wrapper reports that as a clean EOF, so we resume from the
     * current byte offset (HTTP Range) and retry until the local file matches the
     * server's Content-Length exactly. A short, complete file is never accepted.
     */
    private function download(string $url, string $dest, int $remoteSize): void
    {
        $this->log('Downloading ' . $this->fmtBytes($remoteSize) . " -> {$dest}");

        $maxAttempts = 50;
        $stalls = 0;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            clearstatcache(true, $dest); // filesize() is cached; stale values corrupt resume
            $got = is_file($dest) ? (int) filesize($dest) : 0;
            if ($got >= $remoteSize) {
                break;
            }

            $ctx = stream_context_create(['http' => [
                'method'  => 'GET',
                'header'  => "Range: bytes={$got}-\r\n",
                'timeout' => 60,
            ]]);
            $in = @fopen($url, 'rb', false, $ctx);
            if (!$in) {
                $this->log("  attempt {$attempt}: connection failed, retrying...");
                $stalls++;
                if ($stalls > 8) {
                    $this->fail('Too many failed download attempts.');
                }
                continue;
            }

            // A 206 resumes; a 200 means the server ignored Range -> start over.
            $code = $this->statusCode($http_response_header ?? []);
            $append = ($code === 206 && $got > 0);
            if (!$append && $got > 0) {
                $this->log("  server returned {$code} (no resume) — restarting download");
                $got = 0;
            }
            if ($append) {
                // Open without truncating, then pin the file to exactly $got bytes
                // and seek there — guards against any prior overshoot/duplication.
                $out = fopen($dest, 'c+b');
                ftruncate($out, $got);
                fseek($out, $got);
            } else {
                $out = fopen($dest, 'wb');
            }

            $before = $got;
            $lastTick = $got;
            while (!feof($in)) {
                $chunk = fread($in, 1 << 20); // 1 MB
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
                $got += strlen($chunk);
                if ($got - $lastTick >= (100 << 20)) { // every ~100 MB
                    $lastTick = $got;
                    $this->log(sprintf('  downloaded %s (%5.1f%%)',
                        $this->fmtBytes($got), $got / $remoteSize * 100));
                }
            }
            fclose($in);
            fclose($out);

            if ($got < $remoteSize) {
                $progressed = $got - $before;
                $this->log(sprintf('  connection dropped at %s (%.1f%%) — resuming',
                    $this->fmtBytes($got), $got / $remoteSize * 100));
                $stalls = ($progressed > 0) ? 0 : $stalls + 1;
                if ($stalls > 8) {
                    $this->fail('Download stalled with no progress after repeated retries.');
                }
            }
        }

        clearstatcache(true, $dest);
        $finalSize = is_file($dest) ? (int) filesize($dest) : 0;
        if ($finalSize !== $remoteSize) {
            $this->fail(sprintf('Download incomplete: got %s of %s. Re-run to resume.',
                $this->fmtBytes($finalSize), $this->fmtBytes($remoteSize)));
        }
        $this->log('Download complete: ' . $this->fmtBytes($finalSize));
    }

    private function statusCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1]; // keep last (handles redirects)
            }
        }
        return $code ?? 0;
    }

    private function remoteSize(string $url): int
    {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 30]]);
        $headers = @get_headers($url, true, $ctx);
        if (!$headers) {
            return 0;
        }
        $headers = array_change_key_case($headers);
        $len = $headers['content-length'] ?? 0;
        if (is_array($len)) {
            $len = end($len);
        }
        return (int) $len;
    }

    private function import(string $path): void
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            $this->fail("Cannot open file for reading: {$path}");
        }
        $totalBytes = (int) filesize($path);

        // Header
        $header = fgets($fh);
        if ($header === false) {
            $this->fail('Empty source file');
        }
        $cols = explode('|', rtrim($header, "\r\n"));
        if (count($cols) !== EXPECTED_COLS || strtolower($cols[0]) !== 'vin') {
            $this->fail('Unexpected header: ' . substr($header, 0, 200));
        }

        $this->log('Header validated (' . EXPECTED_COLS . ' columns). Importing...');

        $dealerStmt = $this->pdo->prepare(
            'INSERT INTO dealers (name, street, city, state, zip, natural_key) '
            . 'VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $this->prepareBatchStmt($this->batchSize);

        $this->pdo->exec('SET unique_checks=0');
        $this->pdo->exec('SET foreign_key_checks=0');
        $this->pdo->beginTransaction();

        $limit = (int) $this->opts['limit'];

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $f = explode('|', $line);
            if (count($f) < EXPECTED_COLS) {
                $this->skippedMalformed++;
                continue;
            }

            $this->rows++;
            $dealerId = $this->resolveDealer($dealerStmt, $f);
            $this->queueListing($f, $dealerId);

            if ($this->rows % PROGRESS_EVERY === 0) {
                $this->report(false, $totalBytes, ftell($fh));
            }
            if ($limit > 0 && $this->rows >= $limit) {
                break;
            }
        }

        $this->flush();
        $this->pdo->commit();
        $this->pdo->exec('SET unique_checks=1');
        $this->pdo->exec('SET foreign_key_checks=1');
        fclose($fh);
    }

    /** Insert (or look up cached) dealer, returning its id or null. */
    private function resolveDealer(PDOStatement $stmt, array $f): ?int
    {
        $name   = $this->clean($f[5], 255);
        $street = $this->clean($f[6], 255);
        $city   = $this->clean($f[7], 128);
        $state  = $this->clean($f[8], 2);
        $zip    = $this->clean($f[9], 10);

        if ($name === null && $street === null && $city === null && $zip === null) {
            return null;
        }

        $natural = sha1(
            strtoupper((string) $name) . '|' .
            strtoupper((string) $street) . '|' .
            (string) $zip
        );

        if (isset($this->dealerCache[$natural])) {
            return $this->dealerCache[$natural];
        }

        $stmt->execute([$name, $street, $city, $state, $zip, $natural]);
        $id = (int) $this->pdo->lastInsertId();
        $this->dealerCache[$natural] = $id;
        $this->newDealers++;
        return $id;
    }

    private function queueListing(array $f, ?int $dealerId): void
    {
        $make  = $this->clean($f[2], 64);
        $model = $this->clean($f[3], 128);

        $this->batch[] = [
            $this->clean($f[0], 17),                 // vin
            $this->intOrNull($f[1]),                 // year
            $make,
            $model,
            $this->clean($f[4], 128),                // trim
            $make === null ? null : mb_strtoupper($make),
            $model === null ? null : mb_strtoupper($model),
            $dealerId,
            $this->intOrNull($f[10]),                // listing_price
            $this->intOrNull($f[11]),                // listing_mileage
            $this->boolOrNull($f[12]),               // used
            $this->boolOrNull($f[13]),               // certified
            $this->clean($f[14], 128),               // style
            $this->clean($f[15], 32),                // driven_wheels
            $this->clean($f[16], 128),               // engine
            $this->clean($f[17], 64),                // fuel_type
            $this->clean($f[18], 64),                // exterior_color
            $this->clean($f[19], 64),                // interior_color
            $this->clean($f[20], 255),               // seller_website
            $this->dateOrNull($f[21]),               // first_seen_date
            $this->dateOrNull($f[22]),               // last_seen_date
            $this->dateOrNull($f[23]),               // dealer_vdp_last_seen_date
            $this->clean($f[24], 32),                // listing_status
        ];

        if (count($this->batch) >= $this->batchSize) {
            $this->flushFull();
        }
    }

    private const LISTING_COLS = 23;

    private function prepareBatchStmt(int $rows): void
    {
        $placeholder = '(' . rtrim(str_repeat('?,', self::LISTING_COLS), ',') . ')';
        $values = rtrim(str_repeat($placeholder . ',', $rows), ',');
        $this->batchStmt = $this->pdo->prepare(
            'INSERT INTO listings '
            . '(vin, `year`, make, model, `trim`, make_key, model_key, dealer_id, '
            . 'listing_price, listing_mileage, used, certified, style, driven_wheels, '
            . 'engine, fuel_type, exterior_color, interior_color, seller_website, '
            . 'first_seen_date, last_seen_date, dealer_vdp_last_seen_date, listing_status) '
            . 'VALUES ' . $values
        );
    }

    /** Flush a full-size batch using the cached prepared statement. */
    private function flushFull(): void
    {
        $flat = [];
        foreach ($this->batch as $row) {
            foreach ($row as $v) {
                $flat[] = $v;
            }
        }
        $this->batchStmt->execute($flat);
        $this->inserted += count($this->batch);
        $this->batch = [];
    }

    /** Flush any remaining (< batchSize) rows. */
    private function flush(): void
    {
        $n = count($this->batch);
        if ($n === 0) {
            return;
        }
        if ($n === $this->batchSize) {
            $this->flushFull();
            return;
        }
        $this->prepareBatchStmt($n);
        $flat = [];
        foreach ($this->batch as $row) {
            foreach ($row as $v) {
                $flat[] = $v;
            }
        }
        $this->batchStmt->execute($flat);
        $this->inserted += $n;
        $this->batch = [];
        $this->prepareBatchStmt($this->batchSize); // restore full-size stmt
    }

    private function ensureIndex(): void
    {
        $exists = $this->pdo->query(
            "SHOW INDEX FROM listings WHERE Key_name = 'idx_ymm'"
        )->fetch();
        if (!$exists) {
            $this->pdo->exec('ALTER TABLE listings ADD INDEX idx_ymm (make_key, model_key, `year`)');
        }
    }

    // ---- field coercion helpers ----

    private function clean(string $v, int $max): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            $v = mb_substr($v, 0, $max);
        }
        return $v;
    }

    private function intOrNull(string $v): ?int
    {
        $v = trim($v);
        if ($v === '' || !ctype_digit($v)) {
            return null;
        }
        return (int) $v;
    }

    private function boolOrNull(string $v): ?int
    {
        $v = strtoupper(trim($v));
        if ($v === 'TRUE')  return 1;
        if ($v === 'FALSE') return 0;
        return null;
    }

    private function dateOrNull(string $v): ?string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    // ---- progress / output ----

    private function report(bool $final, int $total = 0, int $pos = 0): void
    {
        $elapsed = max(0.001, microtime(true) - $this->startedAt);
        $rate = $this->rows / $elapsed;
        $mem = memory_get_usage(true) / (1 << 20);
        $peak = memory_get_peak_usage(true) / (1 << 20);
        $pct = ($total > 0) ? sprintf('%5.1f%%', $pos / $total * 100) : '  -- ';

        $this->log(sprintf(
            '  %s | rows %s | dealers %s | %s rows/s | mem %dMB (peak %dMB)%s',
            $pct,
            number_format($this->rows),
            number_format($this->newDealers),
            number_format($rate),
            $mem,
            $peak,
            $final ? ' | done' : ''
        ));
    }

    private function summary(): void
    {
        $elapsed = microtime(true) - $this->startedAt;
        $listings = (int) $this->pdo->query('SELECT COUNT(*) FROM listings')->fetchColumn();
        $dealers  = (int) $this->pdo->query('SELECT COUNT(*) FROM dealers')->fetchColumn();
        $priced   = (int) $this->pdo->query('SELECT COUNT(*) FROM listings WHERE listing_price IS NOT NULL')->fetchColumn();

        $this->log('----------------------------------------');
        $this->log('Import complete in ' . sprintf('%.1fs', $elapsed));
        $this->log('  rows read        : ' . number_format($this->rows));
        $this->log('  malformed skipped: ' . number_format($this->skippedMalformed));
        $this->log('  listings in db   : ' . number_format($listings));
        $this->log('    with price     : ' . number_format($priced));
        $this->log('  dealers in db    : ' . number_format($dealers));
        $this->log('  peak memory      : ' . round(memory_get_peak_usage(true) / (1 << 20)) . ' MB');
    }

    private function fmtBytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $f = (float) $b;
        while ($f >= 1024 && $i < count($u) - 1) {
            $f /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $f, $u[$i]);
    }

    private function log(string $msg): void
    {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $msg . PHP_EOL);
    }

    private function fail(string $msg): void
    {
        fwrite(STDERR, 'ERROR: ' . $msg . PHP_EOL);
        exit(1);
    }
}

// ---- CLI entrypoint ----

$defaults = [
    'source'         => SOURCE_DEFAULT,
    'db'             => 'mike-carvalue',
    'limit'          => 0,
    'batch'          => 2000,
    'keep'           => false,
    'force-download' => false,
];

$cli = getopt('', ['source:', 'db:', 'limit:', 'batch:', 'keep', 'force-download', 'help']);
if (isset($cli['help'])) {
    $doc = file_get_contents(__FILE__);
    fwrite(STDOUT, substr($doc, strpos($doc, '/**') + 4, strpos($doc, '*/') - strpos($doc, '/**') - 4));
    exit(0);
}

$opts = $defaults;
foreach (['source', 'db', 'limit', 'batch'] as $k) {
    if (isset($cli[$k])) {
        $opts[$k] = $cli[$k];
    }
}
$opts['keep'] = isset($cli['keep']);
$opts['force-download'] = isset($cli['force-download']);

(new Importer($opts))->run();
