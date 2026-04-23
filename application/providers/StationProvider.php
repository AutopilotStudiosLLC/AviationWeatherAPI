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
			throw new BadRequestException('Invalid station identifier');
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

	public function getList(): Json|string|null
	{
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/stationinfo', [
				'format' => 'xml',
				'ids' => $stationString,
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
			$newStation->site = $station->site;
			$newStation->state = $station->state;
			$newStation->country = $station->country;
			$newStation->site_type = $station->siteType;
			$newStation->source = 'noaa';
			$json->Station[] = $newStation;
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

			// If we didn't get at least as many TAFs as we requested, throw an exception
			if (count($stations) === 0) {
				throw new ModelNotFoundException('No TAFs found for the specified station(s)');
			}

			return $stations;
		}
		catch (ModelNotFoundException $e)
		{
			// Remove any existing cached TAFs for this station
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
			$json->Station[] = TafModel::toResultFormat($station);
		}
		foreach($fetchedResults->Station as $element) {
			$json->Station[] = $element;
		}
		$json->results = count($json->Station);
		return $json;
	}
}