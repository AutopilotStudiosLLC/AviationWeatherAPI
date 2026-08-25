<?php

namespace application\providers;

use Exception;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Staple\Json;
use Staple\Query\Connection;
use stdClass;
use StationProvider;

/**
 * Mock Rest class to intercept static calls
 */
if (!class_exists('application\providers\MockRest', false)) {
    class MockRest
    {
        public static $response = null;
        public static $lastUrl = null;
        public static $lastData = null;
        public static Exception | null $throwException = null;

        public static function get($url, array $data = [], array $headers = [])
        {
            self::$lastUrl = $url;
            self::$lastData = $data;
            if (self::$throwException) {
                throw self::$throwException;
            }
            if (is_callable(self::$response)) {
                return (self::$response)($url, $data, $headers);
            }
            return self::$response;
        }
    }
}

// Attempt to alias the mock before the real Rest class is loaded.
if (!class_exists('Staple\Rest\Rest', false)) {
    class_alias(MockRest::class, 'Staple\Rest\Rest');
}

if (!class_exists('application\providers\TestSqliteConnection', false)) {
    class TestSqliteConnection extends Connection
    {
        private function rewriteMysqlSyntax(string $sql): string
        {
            $sql = (string)preg_replace_callback(
                '/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+MINUTE\s*\)/i',
                static fn(array $matches): string => "datetime('now', '-{$matches[1]} minutes')",
                $sql
            );

            if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
                $sql = preg_replace('/VALUES\s*\(\s*([a-zA-Z0-9_]+)\s*\)/i', 'excluded.$1', $sql);
                $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON CONFLICT (icao_id) DO UPDATE SET', $sql);
            }

            return $sql;
        }

        public function exec(string $statement): int|false
        {
            return parent::exec($this->rewriteMysqlSyntax($statement));
        }

        public function query(string $query, $fetchMode = PDO::FETCH_CLASS, ...$fetchModeArgs): \Staple\Query\Statement|bool
        {
            return parent::query($this->rewriteMysqlSyntax($query), $fetchMode, ...$fetchModeArgs);
        }

        public function prepare(string|\Staple\Query\IStatement $query, array $options = []): \PDOStatement|bool
        {
            return parent::prepare($this->rewriteMysqlSyntax((string)$query), $options);
        }
    }
}

class StationProviderTest extends TestCase
{
    private StationProvider $provider;
    private $sqlitePdo;

    protected function setUp(): void
    {
        // Initialize an in-memory SQLite database
        $this->sqlitePdo = new TestSqliteConnection('sqlite::memory:');
        $this->sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load schema from database.sql
        $schemaFile = __DIR__ . '/../../../application/database/database.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);

            // Remove MySQL comments
            $schema = preg_replace('/#.*$/m', '', $schema);

            // Adapt MySQL schema to SQLite
            $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`;/', '', $schema);
            $schema = preg_replace('/DROP TABLE IF EXISTS ([a-zA-Z_]+);/', 'DROP TABLE IF EXISTS "$1";', $schema);
            $schema = preg_replace('/CREATE TABLE IF NOT EXISTS ([a-zA-Z_]+)/', 'CREATE TABLE IF NOT EXISTS "$1"', $schema);
            $schema = preg_replace('/CREATE INDEX IF NOT EXISTS ([a-zA-Z_]+) ON ([a-zA-Z_]+)/', 'CREATE INDEX IF NOT EXISTS "$1" ON "$2"', $schema);

            // SQLite specific: INTEGER PRIMARY KEY AUTOINCREMENT
            $schema = preg_replace('/id INT NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
            $schema = preg_replace('/id INT NOT NULL AUTOINCREMENT PRIMARY KEY/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $schema);

            $schema = preg_replace('/DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $schema);
            $schema = preg_replace('/TIMESTAMP NOT NULL/', 'DATETIME NOT NULL', $schema);
            $schema = preg_replace('/DECIMAL\(10,7\)/', 'DOUBLE', $schema);

            // Split by semicolon and execute each statement
            $statements = explode(';', $schema);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $this->sqlitePdo->exec($statement);
                }
            }
        }

        // Use reflection to set the private PDO object inside Connection
        $connReflection = new ReflectionClass(Connection::class);

        // Inject our MockConnection into the framework's named connections
        $prop = $connReflection->getProperty('namedConnections');
        $prop->setAccessible(true);
        $connections = $prop->getValue();

        $connections['__DEFAULT__'] = $this->sqlitePdo;
        $prop->setValue(null, $connections);

        $this->provider = new StationProvider($this->createMock(\Staple\Auth\IAuthService::class));

        // Reset MockRest
        MockRest::$response = null;
        MockRest::$lastUrl = null;
        MockRest::$lastData = null;
        MockRest::$throwException = null;

        $_GET = [];
    }

    private function createMockStation(string $icaoId, float $lat = 39.0, float $lon = -104.0, string $name = 'Test Station'): stdClass
    {
        $station = new stdClass();
        $station->icaoId = $icaoId;
        $station->iataId = substr($icaoId, 1, 3);
        $station->faaId = $icaoId;
        $station->wmoId = '72469';
        $station->lat = $lat;
        $station->lon = $lon;
        $station->elev = 1600;
        $station->site = $name;
        $station->state = 'CO';
        $station->country = 'US';
        $station->priority = 5;
        $station->siteType = ['METAR', 'TAF'];
        return $station;
    }

    public function testGenerateGridCreatesOverlappingBoxesWithMax200Miles()
    {
        $regions = [
            'test_region' => [
                'minLat' => 38.0,
                'maxLat' => 42.0,
                'minLon' => -106.0,
                'maxLon' => -102.0,
            ]
        ];

        $grid = $this->provider->generateGrid($regions, 200.0, 20.0);
        $this->assertIsArray($grid);
        $this->assertNotEmpty($grid);

        foreach ($grid as $box) {
            $this->assertArrayHasKey('minLat', $box);
            $this->assertArrayHasKey('maxLat', $box);
            $this->assertArrayHasKey('minLon', $box);
            $this->assertArrayHasKey('maxLon', $box);

            // Verify height is approximately 200 miles (~2.91 degrees latitude)
            $latSpan = $box['maxLat'] - $box['minLat'];
            $this->assertLessThanOrEqual(3.0, $latSpan);
            $this->assertGreaterThan(0, $latSpan);

            // Verify width is <= 200 miles equivalent in longitude
            $lonSpan = $box['maxLon'] - $box['minLon'];
            $this->assertGreaterThan(0, $lonSpan);
        }
    }

    public function testPopulateCacheBasicSuccess()
    {
        $testStation1 = $this->createMockStation('KDEN', 39.85, -104.66, 'Denver Intl');
        $testStation2 = $this->createMockStation('KCOS', 38.80, -104.70, 'Colorado Springs');

        MockRest::$response = [$testStation1, $testStation2];

        $customRegions = [
            'test_area' => [
                'minLat' => 38.0,
                'maxLat' => 40.0,
                'minLon' => -105.0,
                'maxLon' => -104.0,
            ]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $this->assertInstanceOf(Json::class, $response);

        $data = $response->jsonSerialize();
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('stations_cached', $data);
        $this->assertObjectHasProperty('blocks_broken', $data);
        $this->assertObjectHasProperty('errors_encountered', $data);
        $this->assertObjectHasProperty('average_request_time', $data);
        $this->assertObjectHasProperty('total_time', $data);

        $this->assertFalse($data->errors_encountered);
        $this->assertEquals(2, $data->stations_cached);
        $this->assertEquals(0, $data->blocks_broken);
        $this->assertGreaterThanOrEqual(0, $data->average_request_time);
        $this->assertGreaterThanOrEqual(0, $data->total_time);

        // Verify stations were saved in SQLite
        $stmt = $this->sqlitePdo->query("SELECT COUNT(*) as cnt FROM stations");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(2, (int)$row['cnt']);
    }

    public function testPopulateCacheSubdividesWhenResultsEqualOrExceed400()
    {
        $requestedBboxes = [];

        MockRest::$response = function ($url, $data) use (&$requestedBboxes) {
            $bbox = $data['bbox'] ?? '';
            $requestedBboxes[] = $bbox;

            // First call (parent box): return 400 stations to trigger subdivision
            if (count($requestedBboxes) === 1) {
                $stations = [];
                for ($i = 0; $i < 400; $i++) {
                    $stations[] = $this->createMockStation('K' . sprintf('%03d', $i), 39.0, -104.0);
                }
                return $stations;
            }

            // Sub-box calls: return smaller sets (< 400)
            $subBoxIndex = count($requestedBboxes) - 1;
            return [
                $this->createMockStation('KS' . sprintf('%02d', $subBoxIndex), 39.1 + ($subBoxIndex * 0.1), -104.1)
            ];
        };

        $customRegions = [
            'single_box' => [
                'minLat' => 38.0,
                'maxLat' => 39.5,
                'minLon' => -105.0,
                'maxLon' => -104.0,
            ]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $this->assertInstanceOf(Json::class, $response);

        $data = $response->jsonSerialize();
        $this->assertIsObject($data);

        // 1 parent box broke down into 4 sub-boxes
        $this->assertGreaterThanOrEqual(1, $data->blocks_broken);
        $this->assertFalse($data->errors_encountered);
        $this->assertGreaterThanOrEqual(4, $data->requests_made);
    }

    public function testPopulateCacheMultipleSubdivisions()
    {
        $callCount = 0;

        MockRest::$response = function ($url, $data) use (&$callCount) {
            $callCount++;

            // Initial box returns 400 (subdivision 1)
            if ($callCount === 1) {
                $stations = [];
                for ($i = 0; $i < 400; $i++) {
                    $stations[] = $this->createMockStation('K' . sprintf('%03d', $i));
                }
                return $stations;
            }

            // First sub-box also returns 400 (subdivision 2)
            if ($callCount === 2) {
                $stations = [];
                for ($i = 0; $i < 400; $i++) {
                    $stations[] = $this->createMockStation('L' . sprintf('%03d', $i));
                }
                return $stations;
            }

            // Other sub-boxes return <400
            return [$this->createMockStation('KM' . sprintf('%02d', $callCount))];
        };

        $customRegions = [
            'test_area' => [
                'minLat' => 38.0,
                'maxLat' => 40.0,
                'minLon' => -105.0,
                'maxLon' => -103.0,
            ]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $data = $response->jsonSerialize();

        $this->assertGreaterThanOrEqual(2, $data->blocks_broken);
        $this->assertFalse($data->errors_encountered);
    }

    public function testPopulateCacheHandlesErrorsGracefully()
    {
        $callCount = 0;

        MockRest::$response = function ($url, $data) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new Exception('Connection timeout');
            }
            return [$this->createMockStation('KDEN')];
        };

        $customRegions = [
            'region1' => ['minLat' => 38.0, 'maxLat' => 39.0, 'minLon' => -105.0, 'maxLon' => -104.0],
            'region2' => ['minLat' => 40.0, 'maxLat' => 41.0, 'minLon' => -105.0, 'maxLon' => -104.0],
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $data = $response->jsonSerialize();

        $this->assertTrue($data->errors_encountered);
        $this->assertNotEmpty($data->errors);
        $this->assertGreaterThanOrEqual(1, $data->stations_cached);

        // Verify error was logged to error_logs table
        $stmt = $this->sqlitePdo->query("SELECT COUNT(*) as cnt FROM error_logs");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThanOrEqual(1, (int)$row['cnt']);
    }

    public function testGetPopulateCacheRoutesToPopulateCache()
    {
        MockRest::$response = [$this->createMockStation('KSEA')];

        $customRegions = [
            'test' => ['minLat' => 47.0, 'maxLat' => 48.0, 'minLon' => -123.0, 'maxLon' => -122.0]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertEquals(1, $data->stations_cached);
    }

    public function testDefaultRegionsCoverContinentalUsCanadaAlaskaHawaii()
    {
        $regions = StationProvider::DEFAULT_REGIONS;
        $this->assertArrayHasKey('continental_us', $regions);
        $this->assertArrayHasKey('canada', $regions);
        $this->assertArrayHasKey('alaska', $regions);
        $this->assertArrayHasKey('hawaii', $regions);

        $grid = $this->provider->generateGrid($regions);
        $this->assertNotEmpty($grid);

        foreach ($grid as $box) {
            $latSpan = $box['maxLat'] - $box['minLat'];
            // 200 miles in latitude is approximately 2.91 degrees
            $this->assertLessThanOrEqual(3.0, $latSpan);
            $this->assertGreaterThan(0, $latSpan);
        }
    }

    public function testPopulateCacheHandlesEmptyResponses()
    {
        MockRest::$response = [];

        $customRegions = [
            'ocean' => ['minLat' => 20.0, 'maxLat' => 21.0, 'minLon' => -140.0, 'maxLon' => -139.0]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $data = $response->jsonSerialize();

        $this->assertEquals(0, $data->stations_cached);
        $this->assertEquals(0, $data->blocks_broken);
        $this->assertFalse($data->errors_encountered);
    }

    public function testPopulateCacheHandlesStringResponse()
    {
        $station = $this->createMockStation('KORD', 41.97, -87.90, 'Chicago O\'Hare');
        MockRest::$response = json_encode([$station]);

        $customRegions = [
            'chicago' => ['minLat' => 41.0, 'maxLat' => 42.0, 'minLon' => -88.0, 'maxLon' => -87.0]
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $data = $response->jsonSerialize();

        $this->assertEquals(1, $data->stations_cached);
        $this->assertFalse($data->errors_encountered);
    }

    public function testPopulateCacheDeduplicatesStationsAcrossOverlappingBoxes()
    {
        $station = $this->createMockStation('KDEN', 39.85, -104.66, 'Denver');
        // Return same station for all boxes
        MockRest::$response = [$station];

        $customRegions = [
            'region1' => ['minLat' => 39.0, 'maxLat' => 40.0, 'minLon' => -105.0, 'maxLon' => -104.0],
            'region2' => ['minLat' => 39.5, 'maxLat' => 40.5, 'minLon' => -105.0, 'maxLon' => -104.0],
        ];

        $response = $this->provider->getPopulateCache($customRegions);
        $data = $response->jsonSerialize();

        $this->assertEquals(1, $data->stations_cached);
        $this->assertGreaterThanOrEqual(2, $data->total_stations_processed);
    }
}
