<?php

namespace application\providers;

use AddsModel;
use Exception;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Staple\Exception\BadRequestException;
use Staple\Json;
use Staple\Query\Connection;
use Staple\Query\MockConnection;
use stdClass;
use TafProvider;

// Manually require necessary files if autoloader fails
require_once __DIR__ . '/../../../application/providers/TafProvider.php';
require_once __DIR__ . '/../../../application/models/TafModel.php';
require_once __DIR__ . '/../../../application/models/AddsModel.php';
require_once __DIR__ . '/../../../application/models/TafForecastModel.php';
require_once __DIR__ . '/../../../application/models/TafForecastCloudModel.php';
require_once __DIR__ . '/../../../application/models/ErrorLogModel.php';

/**
 * Mock Rest class to intercept static calls
 */
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
        return self::$response;
    }
}

// Attempt to alias the mock before the real Rest class is loaded.
if (!class_exists('Staple\Rest\Rest', false)) {
    class_alias(MockRest::class, 'Staple\Rest\Rest');
}

class TafProviderTest extends TestCase
{
    private $provider;
    private $sqlitePdo;

    protected function setUp(): void
    {
        // Initialize an in-memory SQLite database
        $this->sqlitePdo = new PDO('sqlite::memory:');
        $this->sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Load schema from database.sql
        $schemaFile = __DIR__ . '/../../../application/database/database.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            
            // Adapt MySQL schema to SQLite
            $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`;/', '', $schema);
            $schema = preg_replace('/DROP TABLE IF EXISTS ([a-zA-Z_]+);/', 'DROP TABLE IF EXISTS "$1";', $schema);
            $schema = preg_replace('/CREATE TABLE IF NOT EXISTS ([a-zA-Z_]+)/', 'CREATE TABLE IF NOT EXISTS "$1"', $schema);
            
            // SQLite specific: INTEGER PRIMARY KEY AUTOINCREMENT
            $schema = preg_replace('/id INT NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
            $schema = preg_replace('/id INT NOT NULL AUTOINCREMENT PRIMARY KEY/', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
            
            $schema = preg_replace('/DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP/', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $schema);
            $schema = preg_replace('/TIMESTAMP NOT NULL/', 'DATETIME NOT NULL', $schema);
            $schema = preg_replace('/DECIMAL\(10,7\)/', 'DOUBLE', $schema);
            // $schema = str_replace('`', '"', $schema); // Already handled table names
            
            // Split by semicolon and execute each statement
            $statements = explode(';', $schema);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $this->sqlitePdo->exec($statement);
                }
            }

            // Define DATE_SUB for SQLite
            $this->sqlitePdo->sqliteCreateFunction('DATE_SUB', function($date, $interval) {
                // Simplified DATE_SUB for tests: it handles 'INTERVAL 3 MINUTE'
                if (preg_match('/INTERVAL (\d+) MINUTE/', $interval, $matches)) {
                    $minutes = $matches[1];
                    return date('Y-m-d H:i:s', strtotime("-$minutes minutes", strtotime($date)));
                }
                return $date;
            }, 2);
            $this->sqlitePdo->sqliteCreateFunction('NOW', function() {
                return date('Y-m-d H:i:s');
            }, 0);
        }

        // Create a MockConnection object
        $conn = new MockConnection('sqlite::memory:');
        
        // Use reflection to set the private PDO object inside Connection
        $connReflection = new ReflectionClass(Connection::class);
        
        // Inject our MockConnection into the framework's named connections
        $prop = $connReflection->getProperty('namedConnections');
        $prop->setAccessible(true);
        $connections = $prop->getValue();
        
        // When ModelNotFoundException is caught in getTafsFromCache, it tries to delete.
        // We'll set it to return something that won't error out.
        $conn->setResults(true); 
        
        $connections['__DEFAULT__'] = $conn;
        $prop->setValue(null, $connections);
        
        $this->provider = new TafProvider($this->createMock(\Staple\Auth\IAuthService::class));
        
        // Reset MockRest
        MockRest::$response = null;
        MockRest::$lastUrl = null;
        MockRest::$lastData = null;
        MockRest::$throwException = null;

        // Reset GET
        $_GET = [];
    }

    public function testGetIndex()
    {
        $response = $this->provider->getIndex();
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertEquals('TAF Resource', $data->message);
        $this->assertObjectHasProperty('apis', $data);
    }

    public function testGetRecent()
    {
        // getRecent is an alias for getTaf
        $mockApiResponse = $this->getMockTafData('KSEA');
        MockRest::$response = [$mockApiResponse];
        $_GET['format'] = 'json';

        $response = $this->provider->getRecent('KSEA');
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertIsObject($data);
        $this->assertEquals(1, $data->results);
        $this->assertCount(1, $data->TAF);
        $this->assertEquals('KSEA', $data->TAF[0]->station_id);
    }

    public function testGetTafInvalidIdentifier()
    {
        $this->expectException(BadRequestException::class);
        $this->provider->getTaf('KSEA123!');
    }

    public function testGetTafCacheMiss()
    {
        $station = 'KBOI';
        $mockApiResponse = [$this->getMockTafData($station)];
        MockRest::$response = $mockApiResponse;
        $_GET['format'] = 'json';

        $response = $this->provider->getTaf($station);
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();

        $this->assertIsObject($data);
        $this->assertEquals(1, $data->results);
        $this->assertCount(1, $data->TAF);
        $this->assertEquals($station, $data->TAF[0]->station_id);
        $this->assertEquals(AddsModel::HTTP_SOURCE_ROOT . '/taf', MockRest::$lastUrl);
    }

    public function testGetList()
    {
        $stations = 'KSEA,KPDX';
        $mockData1 = $this->getMockTafData('KSEA');
        $mockData2 = $this->getMockTafData('KPDX');
        MockRest::$response = [$mockData1, $mockData2];
        $_GET['format'] = 'json';

        $response = $this->provider->getList($stations);
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('TAF', $data);
        $this->assertObjectHasProperty('results', $data);
        $this->assertSame((int)$data->results, count($data->TAF));
        $this->assertNull(MockRest::$lastUrl);

        // Test with comma in identifier
        MockRest::$lastUrl = null;
        $response = $this->provider->getList('KSEA,KPDX');
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('TAF', $data);
        $this->assertSame((int)$data->results, count($data->TAF));
        $this->assertNull(MockRest::$lastUrl);
    }

    public function testGetListInvalid()
    {
        $this->expectException(BadRequestException::class);
        $this->provider->getList('KSEA;DROP TABLE users');
    }

    public function testGetLocal()
    {
        $_GET['distance'] = 50;
        $_GET['latitude'] = 45.0;
        $_GET['longitude'] = -122.0;
        $_GET['format'] = 'json';

        $mockData = $this->getMockTafData('KPDX');
        MockRest::$response = [$mockData];

        $response = $this->provider->getLocal();
        $data = $response->jsonSerialize();
        $this->assertCount(1, $data);
        $this->assertEquals('KPDX', $data[0]->icaoId);
        $this->assertStringContainsString('/taf', MockRest::$lastUrl);
        $this->assertArrayHasKey('bbox', MockRest::$lastData);
    }

    public function testGetFlight()
    {
        $_GET['path'] = 'KSEA;KPDX';
        $_GET['corridor'] = 50;
        
        // Mock XML response structure for getFlight
        // The controller expects a response that has a 'data' property which is a SimpleXMLElement
        $mockXml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response num_results="1"><TAF><forecast><sky_condition sky_cover="BKN" cloud_base_ft_agl="3000" /></forecast></TAF></response>');
        
        $mockResponse = new stdClass();
        $mockResponse->data = $mockXml;
        MockRest::$response = $mockResponse;

        $response = $this->provider->getFlight();
        $this->assertInstanceOf(Json::class, $response);
        $data = $response->jsonSerialize();
        $this->assertEquals(1, (string)$data->results);
        $this->assertEquals('BKN', (string)$data->TAF[0]->forecast->sky_condition->sky_cover);
    }

    private function getMockTafData($icaoId)
    {
        $taf = new stdClass();
        $taf->icaoId = $icaoId;
        $taf->rawTAF = "TAF $icaoId ...";
        $taf->issueTime = "2026-03-24T23:26:00Z";
        $taf->bulletinTime = "2026-03-24T23:26:00Z";
        $taf->validTimeFrom = time();
        $taf->validTimeTo = time() + 86400;
        $taf->lat = 45.0;
        $taf->lon = -122.0;
        $taf->elev = 100;
        $taf->mostRecent = true;
        $taf->remarks = "Remarks";
        $taf->name = "Station Name";
        $taf->dbPopTime = "2026-03-24T23:26:00Z";
        
        $fcst = new stdClass();
        $fcst->timeFrom = time();
        $fcst->timeTo = time() + 3600;
        $fcst->fcstChange = "FM";
        $fcst->probability = null;
        $fcst->wdir = 200;
        $fcst->wspd = 10;
        $fcst->wgst = 20;
        $fcst->wshearHgt = null;
        $fcst->wshearDir = null;
        $fcst->wshearSpd = null;
        $fcst->visib = "6+";
        $fcst->altim = null;
        $fcst->vertVis = null;
        $fcst->wxString = null;
        $fcst->notDecoded = null;
        
        $cloud = new stdClass();
        $cloud->base = 3000;
        $cloud->cover = "BKN";
        $cloud->type = null;
        $fcst->clouds = [$cloud];
        
        $taf->fcsts = [$fcst];
        
        return $taf;
    }
}
