<?php

use Staple\Controller\RestfulController;
use Staple\Exception\BadRequestException;
use Staple\Exception\ConfigurationException;
use Staple\Exception\ModelNotFoundException;
use Staple\Exception\QueryException;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Query\Query;
use Staple\Request;
use Staple\Rest\Rest;

/**
 * Created by PhpStorm.
 * User: ironpilot
 * Date: 1/3/2018
 * Time: 10:12 PM
 */

class StationProvider extends RestfulController
{
	public function _start(): void
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getIndex()
	{
		$obj = new stdClass();
		$obj->message = 'Station Resource';
		$obj->apis = [
			'info' => '/station/info/[station]',
			'list' => '/station/list?stations=KDEN,KLAX',
			'local' => '/station/local?distance=50&latitude=39&longitude=-104',
			'flight' => '/station/flight?path=KDEN;KLAX&corridor=50',
		];
		return Json::success($obj, Json::DEFAULT_SUCCESS_CODE, true);
	}

	/**
	 * @param $identifier
	 * @return Json|string|null
	 * @throws BadRequestException
	 */
	public function getInfo($identifier): Json|string|null
	{
		if(!ctype_alnum(str_replace(',', '', $identifier)))
		{
            return Json::error('Invalid station identifier');
		}
		try
		{
			$identifiers = explode(',', strtoupper($identifier));
			$foundIdentifiers = [];
			try
			{
				$stations = $this->getStationsFromCache($identifiers);
				foreach($identifiers as $ident)
				{
					if(array_find($stations, function ($station) use ($ident) {
							return strtoupper($station->icao_id) === strtoupper($ident);
						}) !== null)
					{
						$foundIdentifiers[] = strtoupper($ident);
					}
				}

				$cachedResults = $this->formatFromDatabase($stations);
				if (count($foundIdentifiers) !== count($identifiers))
				{
					$fetchIdents = implode(',', array_diff($identifiers, $foundIdentifiers));
					// If we don't have a cached response, get it from the API
					$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
						'format' => 'json',
						'ids' => $fetchIdents,
					]);

                    // Catch empty response
                    if(is_string($response) && strlen($response) === 0) {
                        return Json::error('No results found', 500);
                    }

					// Try to cache the response
					try {
						StationModel::cache($response);
					} catch (Exception $e) {
						ErrorLogModel::logError($e);
					}
					return Json::success($this->mergeCachedAndFetchedResults($stations, $this->originalFormat($response)));
				}
				else
				{
					return Json::success($cachedResults);
				}
			}
			catch (ModelNotFoundException $e)
			{
				throw new BadRequestException($e->getMessage());
			}
			catch (ConfigurationException $e)
			{
				ErrorLogModel::logError($e);
				throw new BadRequestException($e->getMessage());
			}

		}
		catch(RestException $e)
		{
			ErrorLogModel::logError($e);
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get local METAR data
	 * @return Json|string
	 */
	public function getLocal(): Json|string
	{
        $distance = isset($_GET['distance']) ? (int)$_GET['distance'] : null;
        $latitude = isset($_GET['latitude']) ? (float)$_GET['latitude'] : null;
        $longitude = isset($_GET['longitude']) ? (float)$_GET['longitude'] : null;

        if(!isset($distance) || !isset($latitude) || !isset($longitude))
        {
            return Json::error('Missing required parameters: distance, latitude, longitude');
        }

        $box = AddsModel::boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
            $stations = StationModel::getLocalStations($box);

            $foundCount = count($stations);
            $foundExpired = [];
            $validStations = [];
            foreach($stations as $station)
            {
                $expired = DateTime::createFromFormat('Y-m-d H:i:s', $station->retrieved_at)->add(new DateInterval('P1M')) < new DateTime();
                if($expired)
                {
                    $foundExpired[] = $station;
                }
                else
                {
                    $validStations[] = $station;
                }
            }
            $cachedResults = $this->formatFromDatabase($validStations);

            // Check if we need to retrieve results from the API
            if ($foundCount === 0 || count($foundExpired) >= 1)
            {
                $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
                    'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
                    'format' => 'json',
                ]);

                // Catch empty response
                if(is_string($response) && strlen($response) === 0)
                {
                    return Json::error('No results found', 500);
                }

                // Try to cache the response
                try
                {
                    StationModel::cache($response);
                }
                catch (Exception $e)
                {
                    ErrorLogModel::logError($e);
                }
                return Json::success($this->mergeCachedAndFetchedResults($validStations, $this->originalFormat($response)));
            }
            else
            {
                return Json::success($cachedResults);
            }
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get local METAR data
	 * @return Json|string
	 */
	public function getMap(): Json|string
	{
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;

		$box = $this->boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
				'format' => 'xml',
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	public function getList(?string $stationString = null): Json|string|null
	{
        $identifier = $stationString ?? (string)($_GET['stations'] ?? '');
        if(strlen($identifier) < 1)
        {
            return Json::error('No station identifiers provided');
        }
        if(!ctype_alnum(str_replace(',', '', $identifier)))
        {
            return Json::error('Invalid station identifier');
        }
        try
        {
            $identifiers = explode(',', strtoupper($identifier));
            $foundIdentifiers = [];
            try
            {
                $stations = $this->getStationsFromCache($identifiers);
                foreach($identifiers as $ident)
                {
                    if(array_find($stations, function ($station) use ($ident) {
                            return strtoupper($station->icao_id) === strtoupper($ident);
                        }) !== null)
                    {
                        $foundIdentifiers[] = strtoupper($ident);
                    }
                }

                $cachedResults = $this->formatFromDatabase($stations);
                if (count($foundIdentifiers) !== count($identifiers))
                {

                    $stationString = (string)($_GET['stations'] ?? '');
                    try
                    {
                        $fetchIdents = implode(',', array_diff($identifiers, $foundIdentifiers));
                        $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
                            'format' => 'json',
                            'ids' => $fetchIdents,
                        ]);

                        // Catch empty response
                        if(is_string($response) && strlen($response) === 0) {
                            return Json::error('No results found', 500);
                        }

                        // Try to cache the response
                        try {
                            StationModel::cache($response);
                        } catch (Exception $e) {
                            ErrorLogModel::logError($e);
                        }
                        return Json::success($this->mergeCachedAndFetchedResults($stations, $this->originalFormat($response)));
                    }
                    catch(RestException $e)
                    {
                        return Json::error($e->getMessage());
                    }
                }
                else
                {
                    return Json::success($cachedResults);
                }
            }
            catch (ModelNotFoundException $e)
            {
                throw new BadRequestException($e->getMessage());
            }
            catch (ConfigurationException $e)
            {
                ErrorLogModel::logError($e);
                throw new BadRequestException($e->getMessage());
            }
        }
        catch(RestException $e)
        {
            ErrorLogModel::logError($e);
            return Json::error($e->getMessage());
        }
	}

	/**
	 * Get stations available along specified flight path.
	 * @return Json|string|null
	 */
	public function getFlight(): Json|string|null
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$flightPath = (string)($_GET['path'] ?? '');

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

    public const array DEFAULT_REGIONS = [
        'continental_us' => [
            'minLat' => 24.0,
            'maxLat' => 50.0,
            'minLon' => -125.0,
            'maxLon' => -66.0,
        ],
        'canada' => [
            'minLat' => 41.5,
            'maxLat' => 84.0,
            'minLon' => -141.0,
            'maxLon' => -52.0,
        ],
        'alaska' => [
            'minLat' => 51.0,
            'maxLat' => 72.0,
            'minLon' => -180.0,
            'maxLon' => -129.0,
        ],
        'hawaii' => [
            'minLat' => 18.0,
            'maxLat' => 29.0,
            'minLon' => -180.0,
            'maxLon' => -154.0,
        ],
    ];

    public function getPopulateCache(?string $region = null): Json|string|null
    {
        $startTime = microtime(true);

        $regions = [self::DEFAULT_REGIONS['continental_us']];
        if ($region === null) {
            $regions = self::DEFAULT_REGIONS;
        } else {
            if (array_key_exists($region, self::DEFAULT_REGIONS)) {
                $regions = [self::DEFAULT_REGIONS[$region]];
            }
        }

        $grid = $this->generateGrid($regions);

        $stationsCachedCount = 0;
        $blocksBrokenCount = 0;
        $errors = [];
        $totalRequestTime = 0.0;
        $requestCount = 0;
        $uniqueStationIds = [];

        foreach ($grid as $box) {
            $this->processBoundingBox(
                $box,
                $stationsCachedCount,
                $blocksBrokenCount,
                $errors,
                $totalRequestTime,
                $requestCount,
                $uniqueStationIds,
                0
            );
        }

        $totalTime = microtime(true) - $startTime;
        $averageRequestTime = $requestCount > 0 ? ($totalRequestTime / $requestCount) : 0.0;

        $report = new stdClass();
        $report->stations_cached = count($uniqueStationIds) > 0 ? count($uniqueStationIds) : $stationsCachedCount;
        $report->unique_stations = count($uniqueStationIds);
        $report->total_stations_processed = $stationsCachedCount;
        $report->blocks_broken = $blocksBrokenCount;
        $report->subdivisions = $blocksBrokenCount;
        $report->errors_encountered = count($errors) > 0;
        $report->errors = $errors;
        $report->error_count = count($errors);
        $report->requests_made = $requestCount;
        $report->average_request_time = $averageRequestTime;
        $report->total_time = $totalTime;

        return Json::success($report);
    }

    /**
     * Generate an overlapping grid of bounding boxes with maximum width and height in miles across regions.
     *
     * @param array $regions
     * @param float $boxSizeMiles Maximum width/height of each bounding box in miles
     * @param float $overlapMiles Overlap in miles between adjacent bounding boxes
     * @return array
     */
    protected function generateGrid(array $regions, float $boxSizeMiles = 300.0, float $overlapMiles = 20.0): array
    {
        $stepMiles = max(10.0, $boxSizeMiles - $overlapMiles);
        $kmPerDegLat = 110.574;
        $stepLatDeg = ($stepMiles * 1.609344) / $kmPerDegLat;
        $halfBoxLatDeg = (($boxSizeMiles / 2.0) * 1.609344) / $kmPerDegLat;

        $boxes = [];

        foreach ($regions as $region) {
            $minLat = (float)$region['minLat'];
            $maxLat = (float)$region['maxLat'];
            $minLon = (float)$region['minLon'];
            $maxLon = (float)$region['maxLon'];

            $lat = $minLat + $halfBoxLatDeg;
            do {
                $currentLat = min($maxLat, max($minLat, $lat));
                $clampedLat = min(89.0, max(-89.0, $currentLat));
                $kmPerDegLon = 111.320 * cos(deg2rad($clampedLat));

                if ($kmPerDegLon < 1e-6) {
                    $stepLonDeg = 360.0;
                    $halfBoxLonDeg = 180.0;
                } else {
                    $stepLonDeg = ($stepMiles * 1.609344) / $kmPerDegLon;
                    $halfBoxLonDeg = (($boxSizeMiles / 2.0) * 1.609344) / $kmPerDegLon;
                }

                $lon = $minLon + $halfBoxLonDeg;
                do {
                    $currentLon = $this->normalizeLon($lon);
                    $box = $this->boundingBoxMiles($boxSizeMiles, $clampedLat, $currentLon);
                    $key = $box['minLat'] . ',' . $box['minLon'] . ',' . $box['maxLat'] . ',' . $box['maxLon'];
                    $boxes[$key] = $box;
                    $lon += $stepLonDeg;
                } while ($lon - $halfBoxLonDeg < $maxLon);

                $lat += $stepLatDeg;
            } while ($lat - $halfBoxLatDeg < $maxLat);
        }

        return array_values($boxes);
    }

    /**
     * Process a single bounding box, querying the API, caching stations, and subdividing if >= 400 results return.
     *
     * @param array $box
     * @param int $stationsCachedCount
     * @param int $blocksBrokenCount
     * @param array $errors
     * @param float $totalRequestTime
     * @param int $requestCount
     * @param array $uniqueStationIds
     * @param int $depth
     * @return void
     */
    protected function processBoundingBox(
        array $box,
        int &$stationsCachedCount,
        int &$blocksBrokenCount,
        array &$errors,
        float &$totalRequestTime,
        int &$requestCount,
        array &$uniqueStationIds,
        int $depth = 0
    ): void {
        // Allow 30 seconds for each request to complete
        set_time_limit(30);
        $bboxParam = $box['minLat'] . ',' . $box['minLon'] . ',' . $box['maxLat'] . ',' . $box['maxLon'];

        $reqStart = microtime(true);
        $response = null;
        try {
            $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT . '/stationinfo', [
                'bbox' => $bboxParam,
                'format' => 'json',
            ]);
        } catch (Exception $e) {
            ErrorLogModel::logError($e);
            $errors[] = $e->getMessage();
        }
        $reqDuration = microtime(true) - $reqStart;
        $totalRequestTime += $reqDuration;
        $requestCount++;

        // Normalize response
        if (is_string($response)) {
            if (strlen(trim($response)) === 0 || str_contains($response, 'No results found')) {
                $response = [];
            } else {
                $decoded = json_decode($response);
                $response = is_array($decoded) ? $decoded : ($decoded ? [$decoded] : []);
            }
        } elseif ($response instanceof stdClass) {
            $response = [$response];
        } elseif (!is_array($response)) {
            $response = [];
        }

        $resultCount = count($response);

        // If result limit of 400 or more is reached, subdivide the bounding box into smaller sections
        if ($resultCount >= 400 && $depth < 10 && ($box['maxLat'] - $box['minLat'] > 0.02 || $box['maxLon'] - $box['minLon'] > 0.02)) {
            echo '400 or more stations encountered, sub boxing...<br><br>';
            $blocksBrokenCount++;

            $midLat = floor((($box['minLat'] + $box['maxLat']) / 2.0) * 100) / 100;
            $midLon = floor((($box['minLon'] + $box['maxLon']) / 2.0) * 100) / 100;

            $subBoxes = [
                ['minLat' => $box['minLat'], 'maxLat' => $midLat, 'minLon' => $box['minLon'], 'maxLon' => $midLon],
                ['minLat' => $box['minLat'], 'maxLat' => $midLat, 'minLon' => $midLon, 'maxLon' => $box['maxLon']],
                ['minLat' => $midLat, 'maxLat' => $box['maxLat'], 'minLon' => $box['minLon'], 'maxLon' => $midLon],
                ['minLat' => $midLat, 'maxLat' => $box['maxLat'], 'minLon' => $midLon, 'maxLon' => $box['maxLon']],
            ];

            foreach ($subBoxes as $subBox) {
                $this->processBoundingBox(
                    $subBox,
                    $stationsCachedCount,
                    $blocksBrokenCount,
                    $errors,
                    $totalRequestTime,
                    $requestCount,
                    $uniqueStationIds,
                    $depth + 1
                );
            }
        } elseif ($resultCount > 0) {
            try {
                StationModel::cache($response);
                foreach ($response as $station) {
                    $id = $station->icaoId ?? $station->icao_id ?? $station->station_id ?? null;
                    if ($id) {
                        $uniqueStationIds[$id] = true;
                    }
                }
                $stationsCachedCount += $resultCount;
            } catch (Exception $e) {
                ErrorLogModel::logError($e);
                $errors[] = $e->getMessage();
            }
        }
    }

	/**
	 * Calculates a bounding box in degrees of latitude and longitude based on a distance in miles
	 * from a given geographic point.
	 *
	 * @param float $distanceMiles Distance in miles from the central point to each edge of the bounding box.
	 * @param float $latitude Latitude of the center point in decimal degrees.
	 * @param float $longitude Longitude of the center point in decimal degrees.
	 *
	 * @return array An associative array with the keys 'minLat', 'maxLat', 'minLon', and 'maxLon'
	 *               representing the bounding box coordinates.
	 */
	protected function boundingBoxMiles(float $distanceMiles, float $latitude, float $longitude): array
	{
		// Convert miles to km
		$distanceKm = $distanceMiles * 1.609344;

		// Half side length (from center to edge)
		$half = $distanceKm / 2.0;

		// 1° latitude ~ 110.574 km
		// 1° longitude ~ 111.320 * cos(latitude) km
		$kmPerDegLat = 110.574;
		$kmPerDegLon = 111.320 * cos(deg2rad($latitude));

		$deltaLat = $half / $kmPerDegLat;

		// Handle poles: if cos(lat) ~ 0, longitude spans entire range
		if (abs($kmPerDegLon) < 1e-9)
		{
			$minLon = -180.0;
			$maxLon = 180.0;
		} else
		{
			$deltaLon = $half / $kmPerDegLon;
			$minLon = $longitude - $deltaLon;
			$maxLon = $longitude + $deltaLon;
		}



		// Clamp latitude to valid range
		$minLat = floor(max(-90.0, $latitude - $deltaLat)*100)/100;
		$maxLat = floor(min(90.0, $latitude + $deltaLat)*100)/100;

		// Normalize longitudes to [-180, 180]
		$minLon = floor($this->normalizeLon($minLon)*100)/100;
		$maxLon = floor($this->normalizeLon($maxLon)*100)/100;

		return [
			'minLat' => $minLat,
			'maxLat' => $maxLat,
			'minLon' => $minLon,
			'maxLon' => $maxLon,
		];
	}

	/**
	 * Normalize a longitude value to ensure it falls within the range of [-180, 180] degrees.
	 *
	 * @param float $lon The longitude value to normalize.
	 * @return float The normalized longitude.
	 */
	protected function normalizeLon(float $lon): float
	{
		// Wrap longitude into [-180, 180]
		$lon = fmod($lon + 180.0, 360.0);
		if ($lon < 0)
		{
			$lon += 360.0;
		}
		return $lon - 180.0;
	}

	protected function originalFormat(mixed $response): stdClass
	{
		$json = new stdClass();
		$json->Station = [];

		$json->results = count($response);
		foreach ($response as $station)
		{
			$newStation = new stdClass();
			$newStation->station_id = $station->icaoId;
			$newStation->icao_id = $station->icaoId;
			$newStation->iata_id = $station->iataId;
			$newStation->wmo_id = $station->wmoId;
			$newStation->faa_id = $station->faaId;
			$newStation->latitude = $station->lat;
			$newStation->longitude = $station->lon;
			$newStation->elevation_m = $station->elev;
			$newStation->site = StationModel::normalizeSiteName($station->site);
			$newStation->state = $station->state;
			$newStation->country = $station->country;
			$newStation->site_type = $station->siteType;
			$newStation->source = 'noaa';
			$json->Station[] = $newStation;
		}
        if (count($json->Station) <= 1) {
            $json->Station = array_pop($json->Station);
        }
		return $json;
	}

	protected function formatFromDatabase(array $stations): stdClass
	{
		$json = new stdClass();
		$json->Station = [];

		$json->results = count($stations);
		foreach ($stations as $station)
		{
			$json->Station[] = StationModel::toResultFormat($station);
		}
        if (count($json->Station) <= 1) {
            $json->Station = array_pop($json->Station);
        }
		return $json;
	}

	protected function getStationsFromCache(array $identifiers)
	{
		$identString = '';
		foreach ($identifiers as $id) {
			if(!ctype_alnum($id)) {
				throw new BadRequestException('Invalid station identifier');
			}
			$identString .= "'".strtoupper($id)."',";
		}
		$identString = substr($identString, 0, -1);
		try
		{
			$stations = StationModel::query()
				->whereIn('stations.icao_id', $identifiers)
				->whereStatement('stations.retrieved_at > DATE_SUB(NOW(), INTERVAL '.StationModel::STATION_CACHING_INTERVAL.')')
				->get()
				->toArray();

			// If we didn't get at least as many Stations as we requested, throw an exception
			if (count($stations) === 0) {
				throw new ModelNotFoundException('No records found for the specified station(s)');
			}

			return $stations;
		}
		catch (ModelNotFoundException $e)
		{
			// Remove any existing cached Stations for this station
			try
			{
				Query::delete('stations')->whereStatement('icao_id IN('.$identString.')')->execute();
			}
			catch (QueryException $e) {}

			// Return empty array
			return [];
		}
	}

	private function mergeCachedAndFetchedResults(array $cachedResults, stdClass $fetchedResults): stdClass {
        $json = new stdClass();
		$json->Station = [];
		foreach ($cachedResults as $station)
		{
			$json->Station[] = StationModel::toResultFormat($station);
		}
        if (is_array($fetchedResults->Station)) {
            foreach ($fetchedResults->Station as $element) {
                $json->Station[] = $element;
            }
        } else {
            $json->Station[] = $fetchedResults->Station;
        }
        $json->results = count($json->Station);
        if (count($json->Station) <= 1) {
            $json->Station = array_pop($json->Station);
        }
		return $json;
	}
}