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
 * Class MetarProvider
 * Get data from METAR stations
 */

class MetarProvider extends RestfulController
{
	public function _start(): void
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	/**
	 * @return Json|string
	 */
	public function getIndex(): Json|string
	{
		$obj = new stdClass();
		$obj->message = 'METAR Resource';
		$obj->apis = [
			'recent' => '/metar/recent/[station]',
			'local' => '/metar/local?distance=50&latitude=39&longitude=-104',
			'list' => '/metar/list?stations=KDEN,KLAX',
			'flight' => '/metar/flight?corridor=60&path=KDEN;KLAX',
		];
		return Json::success($obj, Json::DEFAULT_SUCCESS_CODE, true);
	}

	/**
	 * Get local METAR data
	 * @return Json|string|null
	 */
	public function getLocal(): Json|string|null
	{
		$hoursBeforeNow = (float)($_GET['hoursBeforeNow'] ?? 3);
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;

		$box = $this->boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
				'format' => 'xml',
				'hours' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				$unsetters = [];
				foreach($sky->attributes() as $key=>$value)
				{
					$sky->addChild($key, $value);
					$unsetters[] = $key;
				}
				foreach($unsetters as $attribute)
				{
					unset($sky[$attribute]);
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

    /**
     * Get recent METAR data
     * @param string $identifier
     * @param float|null $hoursBeforeNow
     * @return Json|string|null
     * @throws BadRequestException|QueryException
     */
	public function getRecent(string $identifier = 'KSEA', ?float $hoursBeforeNow = 3): Json|string|null
	{
		if(!ctype_alnum(str_replace(',', '', $identifier))) {
			throw new BadRequestException();
		}

		try
		{
            $identifiers = explode(',', strtoupper($identifier));
            $foundIdentifiers = [];
            try
            {
                $metars = $this->getMetarsFromCache($identifiers);
                foreach($identifiers as $ident)
                {
                    if(array_find($metars, function ($metar) use ($ident) {
                            return strtoupper($metar->icao_id) === strtoupper($ident);
                        }) !== null)
                    {
                        $foundIdentifiers[] = strtoupper($ident);
                    }
                }

                $cachedResults = $this->formatFromDatabase($metars);
                if (count($foundIdentifiers) !== count($identifiers))
                {
                    $fetchIdents = implode(',', array_diff($identifiers, $foundIdentifiers));
                    /* @var array $response */
                    $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
                        'format' => 'json',
                        'taf' => 'false',
                        'ids' => strtoupper($fetchIdents),
                        'hours' => (float)$hoursBeforeNow ?? 1.5,
                    ]);

                    // Try to cache the response
                    try {
                        MetarModel::cache($response);
                    } catch (Exception $e) {
                        ErrorLogModel::logError($e);
                        throw new BadRequestException($e->getMessage());
                    }
                    return Json::success($this->mergeCachedAndFetchedResults($metars, $this->originalFormat($response)));
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
	 * Get a list of Metars based on a list of stations.
	 * @return Json|string|null
	 */
	public function getList(): Json|string|null
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'format' => 'xml',
				'ids' => $stationString,
				'hours' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				foreach($sky as $condition)
				{
					$unsetters = [];
					foreach($condition->attributes() as $key => $value)
					{
						$condition->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($condition[$attribute]);
					}
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get a list of Metars based on a list of stations.
	 * @return Json|string|null
	 */
	public function getFlight(): Json|string|null
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$flightPath = (string)($_GET['path'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/metar', [
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
				if(count($sky) == 0) continue;
				foreach($sky as $condition)
				{
					$unsetters = [];
					foreach($condition->attributes() as $key => $value)
					{
						$condition->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($condition[$attribute]);
					}
				}
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
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
        $json->METAR = [];

        $json->results = count($response);
        foreach ($response as $metar)
        {
            $newMetar = new stdClass();
            $newMetar->station_id = $metar->icaoId;
            $newMetar->receipt_time = $metar->receiptTime ?? null;
            $newMetar->observation_time = new DateTime()->setTimestamp($metar->obsTime)->format(AddsModel::DATETIME_FORMAT);
            $newMetar->report_time = $metar->reportTime ?? null;
            $newMetar->temp_c = $metar->temp ?? null;
            $newMetar->dewpoint_c = $metar->dewp ?? null;
            $newMetar->wind_dir_degrees = $metar->wdir ?? null;
            $newMetar->wind_speed_kt = $metar->wspd ?? null;
            $newMetar->wind_gust_kt = $metar->wgst ?? null;
            $newMetar->visibility_statute_mi = $metar->visib ?? null;
            $newMetar->altim_in_hg = MetarModel::convertToHg($metar->altim);
            $newMetar->sea_level_pressure_mb = $metar->slp ?? null;
            $newMetar->quality_control_flags = new stdClass();
            $newMetar->metar_type = "METAR";
            $newMetar->raw_text = $metar->rawOb;
            $newMetar->latitude = $metar->lat ?? null;
            $newMetar->longitude = $metar->lon ?? null;
            $newMetar->elevation_m = $metar->elev ?? null;
            $newMetar->station_name = StationModel::normalizeSiteName($metar->name);
            $newMetar->sky_cover = $metar->cover ?? null;
            $newMetar->flight_category = $metar->fltCat ?? null;
            $newMetar->sky_condition = [];
            if(isset($metar->clouds)) {
                foreach ($metar->clouds as $cloud) {
                    $newCast = new stdClass();
                    if (isset($cloud->cover))
                        $newCast->sky_cover = $cloud->cover;
                    if (isset($cloud->base))
                        $newCast->cloud_base_ft_agl = $cloud->base;
                    if (isset($cloud->type))
                        $newCast->cloud_type = $cloud->type;
                    $newMetar->sky_condition[] = $newCast;
                }
            }
            $newMetar->source = 'noaa';
            $json->METAR[] = $newMetar;
        }
        return $json;
    }

    protected function formatFromDatabase(array $metars): stdClass
    {
        $json = new stdClass();
        $json->METAR = [];

        $json->results = count($metars);
        foreach ($metars as $metar)
        {
            $json->METAR[] = MetarModel::toResultFormat($metar);
        }
        return $json;
    }

    /**
     * Get METAR data for a list of stations from the database
     * @param array $identifiers
     * @return MetarModel[]
     * @throws BadRequestException
     * @throws ConfigurationException
     * @throws QueryException
     */
    protected function getMetarsFromCache(array $identifiers): array
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
            $metars = MetarModel::query()
                ->whereIn('metars.icao_id', $identifiers)
                ->whereStatement('metars.retrieved_at > DATE_SUB(NOW(), INTERVAL '.MetarModel::METAR_CACHING_INTERVAL.')')
                ->get()
                ->toArray();

            // If we didn't get at least as many METARs as we requested, throw an exception
            if (count($metars) === 0) {
                throw new ModelNotFoundException('No METARs found for the specified station(s)');
            }

            /** @var MetarModel $metar */
            foreach ($metars as $metar) {
                $clouds = MetarCloudModel::select()
                    ->whereEqual('metar_id', $metar->id)
                    ->get()->toArray();
                $metar->clouds = $clouds;
            }

            return $metars;
        }
        catch (ModelNotFoundException $e)
        {
            // Remove any existing cached METARs for this station
            try
            {
                Query::delete('metars')->whereStatement('icao_id IN('.$identString.')')->execute();
            }
            catch (QueryException $e) {}

            // Return empty array
            return [];
        }
    }

    /**
     * Get METAR data within a bounding box from the database
     * @param array $boundingBox
     * @return MetarModel[]
     * @throws ConfigurationException
     * @throws QueryException
     */
    protected function getLocalStationsFromCache(array $boundingBox): array
    {
        try
        {
            $stations = StationModel::getLocalStations($boundingBox);

            $metarStations = array_filter($stations, function ($station) {
                if(is_array($station->types)) {
                    return array_find($station->types, function ($type) {
                        return $type === 'METAR';
                    });
                }
                return false;
            });

            //Grab the required Identifiers
            $identifiers = [];
            foreach($metarStations as $station)
            {
                $identifiers[] = $station->icao_id;
            }
            return $identifiers;
        }
        catch (ModelNotFoundException $e)
        {
            // Return empty array
            return [];
        }
    }

    private function mergeCachedAndFetchedResults(array $cachedResults, stdClass $fetchedResults): stdClass {
        $json = new stdClass();
        $json->METAR = [];
        foreach ($cachedResults as $metar)
        {
            $json->METAR[] = MetarModel::toResultFormat($metar);
        }
        foreach($fetchedResults->METAR as $noaaMetar) {
            $json->METAR[] = $noaaMetar;
        }
        $json->results = count($json->METAR);
        return $json;
    }
}