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

class TestSqliteConnection extends Connection
{
    private function rewriteMysqlDateSubSyntax(string $sql): string
    {
        return (string)preg_replace_callback(
            '/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+MINUTE\s*\)/i',
            static fn(array $matches): string => "datetime('now', '-{$matches[1]} minutes')",
            $sql
        );
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewriteMysqlDateSubSyntax($statement));
    }

    public function query(string $query, $fetchMode = PDO::FETCH_CLASS, ...$fetchModeArgs): \Staple\Query\Statement|bool
    {
        return parent::query($this->rewriteMysqlDateSubSyntax($query), $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string|\Staple\Query\IStatement $query, array $options = []): \PDOStatement|bool
    {
        return parent::prepare($this->rewriteMysqlDateSubSyntax((string)$query), $options);
    }
}

class TafProviderTest extends TestCase
{
    private $provider;
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

        }

        // Use reflection to set the private PDO object inside Connection
        $connReflection = new ReflectionClass(Connection::class);

        // Inject our MockConnection into the framework's named connections
        $prop = $connReflection->getProperty('namedConnections');
        $prop->setAccessible(true);
        $connections = $prop->getValue();

        $connections['__DEFAULT__'] = $this->sqlitePdo;
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

    public function testGetTafMixedCachedAndFetchedResults()
    {
        $this->seedCachedTaf('KSEA');

        MockRest::$response = [$this->getMockFetchedTaf('KATL')];
        $_GET['format'] = 'json';

        $response = $this->provider->getTaf('KSEA,KATL');
        $this->assertInstanceOf(Json::class, $response);

        $data = $response->jsonSerialize();
        $this->assertIsObject($data);
        $this->assertEquals(2, $data->results);
        $this->assertCount(2, $data->TAF);

        $this->assertEquals('KSEA', $data->TAF[0]->station_id);
        $this->assertEquals('cached', $data->TAF[0]->source);
        $this->assertIsArray($data->TAF[0]->forecast[0]->sky_condition);
        $this->assertCount(2, $data->TAF[0]->forecast[0]->sky_condition);

        $this->assertEquals('KATL', $data->TAF[1]->station_id);
        $this->assertEquals('noaa', $data->TAF[1]->source);
        $this->assertIsObject($data->TAF[1]->forecast[0]->sky_condition);

        $this->assertEquals(AddsModel::HTTP_SOURCE_ROOT . '/taf', MockRest::$lastUrl);
        $this->assertSame('KATL', MockRest::$lastData['ids']);
    }

    private function seedCachedTaf(string $icaoId): void
    {
        $tafInsert = $this->sqlitePdo->prepare('INSERT INTO tafs (id, icao_id, bulletin_time, issue_time, valid_time_from, valid_time_to, most_recent, remarks, lat, lon, elevation, station_name, raw_text, retrieved_at)
            VALUES (:id, :icao_id, :bulletin_time, :issue_time, :valid_time_from, :valid_time_to, :most_recent, :remarks, :lat, :lon, :elevation, :station_name, :raw_text, :retrieved_at)');
        $tafInsert->execute([
            'id' => 152,
            'icao_id' => $icaoId,
            'bulletin_time' => '2026-04-02 15:04:00',
            'issue_time' => '2026-04-02 15:04:00',
            'valid_time_from' => '2026-04-02 15:00:00',
            'valid_time_to' => '2026-04-03 18:00:00',
            'most_recent' => 1,
            'remarks' => ' AMD',
            'lat' => 47.44467,
            'lon' => -122.31442,
            'elevation' => 115,
            'station_name' => 'Seattle-Tacoma Intl',
            'raw_text' => 'TAF KSEA 021504Z 0215/0318 18008KT P6SM VCSH BKN012 OVC020 FM022000 21012KT P6SM VCSH BKN025 OVC040 FM030000 22010KT P6SM VCSH SCT030 BKN050 FM030800 19007KT P6SM SCT030 BKN040',
            'retrieved_at' => date('Y-m-d H:i:s'),
        ]);

        $forecastInsert = $this->sqlitePdo->prepare('INSERT INTO taf_forecasts (id, taf_id, time_from, time_to, forecast_change, probability, wind_direction, wind_speed, wind_gust, wind_shear_height, wind_shear_direction, wind_shear_speed, visibility, altimeter, vertical_visibility, weather_string, not_decoded, retrieved_at)
            VALUES (:id, :taf_id, :time_from, :time_to, :forecast_change, :probability, :wind_direction, :wind_speed, :wind_gust, :wind_shear_height, :wind_shear_direction, :wind_shear_speed, :visibility, :altimeter, :vertical_visibility, :weather_string, :not_decoded, :retrieved_at)');
        $forecastInsert->execute([
            'id' => 9001,
            'taf_id' => 152,
            'time_from' => '2026-04-02 15:00:00',
            'time_to' => '2026-04-02 20:00:00',
            'forecast_change' => null,
            'probability' => null,
            'wind_direction' => 180,
            'wind_speed' => 8,
            'wind_gust' => null,
            'wind_shear_height' => null,
            'wind_shear_direction' => null,
            'wind_shear_speed' => null,
            'visibility' => '6+',
            'altimeter' => null,
            'vertical_visibility' => null,
            'weather_string' => null,
            'not_decoded' => null,
            'retrieved_at' => date('Y-m-d H:i:s'),
        ]);

        $cloudInsert = $this->sqlitePdo->prepare('INSERT INTO taf_forecast_clouds (taf_forecast_id, cloud_base, cloud_cover, cloud_type, retrieved_at)
            VALUES (:taf_forecast_id, :cloud_base, :cloud_cover, :cloud_type, :retrieved_at)');
        $cloudInsert->execute([
            'taf_forecast_id' => 9001,
            'cloud_base' => 1200,
            'cloud_cover' => 'BKN',
            'cloud_type' => null,
            'retrieved_at' => date('Y-m-d H:i:s'),
        ]);
        $cloudInsert->execute([
            'taf_forecast_id' => 9001,
            'cloud_base' => 2000,
            'cloud_cover' => 'OVC',
            'cloud_type' => null,
            'retrieved_at' => date('Y-m-d H:i:s'),
        ]);
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


    private function getMockFetchedTaf(string $icaoId): stdClass
    {
        $taf = new stdClass();
        $taf->icaoId = $icaoId;
        $taf->dbPopTime = '2026-04-02T16:01:03.942Z';
        $taf->bulletinTime = '2026-04-02T16:00:00.000Z';
        $taf->issueTime = '2026-04-02T16:00:00.000Z';
        $taf->validTimeFrom = 1775145600;
        $taf->validTimeTo = 1775239200;
        $taf->rawTAF = 'TAF KATL 021600Z 0216/0318 16007KT P6SM FEW035 FM021800 15010KT P6SM SCT050 FM030200 13005KT P6SM FEW080 FM030800 14004KT P6SM SCT250';
        $taf->mostRecent = 1;
        $taf->remarks = ' AMD';
        $taf->lat = 33.62972;
        $taf->lon = -84.44223;
        $taf->elev = 309;
        $taf->prior = 0;
        $taf->name = 'Atlanta/Hartsfield-Jackson Intl';

        $taf->fcsts = [];

        $forecast1 = new stdClass();
        $forecast1->timeFrom = 1775145600;
        $forecast1->timeTo = 1775152800;
        $forecast1->timeBec = null;
        $forecast1->fcstChange = null;
        $forecast1->probability = null;
        $forecast1->wdir = 160;
        $forecast1->wspd = 7;
        $forecast1->wgst = null;
        $forecast1->wshearHgt = null;
        $forecast1->wshearDir = null;
        $forecast1->wshearSpd = null;
        $forecast1->visib = '6+';
        $forecast1->altim = null;
        $forecast1->vertVis = null;
        $forecast1->wxString = null;
        $forecast1->notDecoded = null;
        $cloud1 = new stdClass();
        $cloud1->cover = 'FEW';
        $cloud1->base = 3500;
        $cloud1->type = null;
        $forecast1->clouds = [$cloud1];
        $forecast1->icgTurb = [];
        $forecast1->temp = [];
        $taf->fcsts[] = $forecast1;

        $forecast2 = new stdClass();
        $forecast2->timeFrom = 1775152800;
        $forecast2->timeTo = 1775181600;
        $forecast2->timeBec = null;
        $forecast2->fcstChange = 'FM';
        $forecast2->probability = null;
        $forecast2->wdir = 150;
        $forecast2->wspd = 10;
        $forecast2->wgst = null;
        $forecast2->wshearHgt = null;
        $forecast2->wshearDir = null;
        $forecast2->wshearSpd = null;
        $forecast2->visib = '6+';
        $forecast2->altim = null;
        $forecast2->vertVis = null;
        $forecast2->wxString = null;
        $forecast2->notDecoded = null;
        $cloud2 = new stdClass();
        $cloud2->cover = 'SCT';
        $cloud2->base = 5000;
        $cloud2->type = null;
        $forecast2->clouds = [$cloud2];
        $forecast2->icgTurb = [];
        $forecast2->temp = [];
        $taf->fcsts[] = $forecast2;

        $forecast3 = new stdClass();
        $forecast3->timeFrom = 1775181600;
        $forecast3->timeTo = 1775203200;
        $forecast3->timeBec = null;
        $forecast3->fcstChange = 'FM';
        $forecast3->probability = null;
        $forecast3->wdir = 130;
        $forecast3->wspd = 5;
        $forecast3->wgst = null;
        $forecast3->wshearHgt = null;
        $forecast3->wshearDir = null;
        $forecast3->wshearSpd = null;
        $forecast3->visib = '6+';
        $forecast3->altim = null;
        $forecast3->vertVis = null;
        $forecast3->wxString = null;
        $forecast3->notDecoded = null;
        $cloud3 = new stdClass();
        $cloud3->cover = 'FEW';
        $cloud3->base = 8000;
        $cloud3->type = null;
        $forecast3->clouds = [$cloud3];
        $forecast3->icgTurb = [];
        $forecast3->temp = [];
        $taf->fcsts[] = $forecast3;

        $forecast4 = new stdClass();
        $forecast4->timeFrom = 1775203200;
        $forecast4->timeTo = 1775239200;
        $forecast4->timeBec = null;
        $forecast4->fcstChange = 'FM';
        $forecast4->probability = null;
        $forecast4->wdir = 140;
        $forecast4->wspd = 4;
        $forecast4->wgst = null;
        $forecast4->wshearHgt = null;
        $forecast4->wshearDir = null;
        $forecast4->wshearSpd = null;
        $forecast4->visib = '6+';
        $forecast4->altim = null;
        $forecast4->vertVis = null;
        $forecast4->wxString = null;
        $forecast4->notDecoded = null;
        $cloud4 = new stdClass();
        $cloud4->cover = 'SCT';
        $cloud4->base = 25000;
        $cloud4->type = null;
        $forecast4->clouds = [$cloud4];
        $forecast4->icgTurb = [];
        $forecast4->temp = [];
        $taf->fcsts[] = $forecast4;

        return $taf;
    }
}
