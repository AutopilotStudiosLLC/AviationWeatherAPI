<?php

use Staple\Controller\RestfulController;
use Staple\Exception\BadRequestException;
use Staple\Exception\ConfigurationException;
use Staple\Exception\ModelNotFoundException;
use Staple\Exception\QueryException;
use Staple\Exception\RestException;
use Staple\Exception\SystemException;
use Staple\Json;
use Staple\Query\Query;
use Staple\Request;
use Staple\Rest\Rest;

const DATABASE_DATE_FORMAT = 'Y-m-d H:i:s';

/**
 * Class TafProvider
 * Get information from TAF stations
 */

class TafProvider extends RestfulController
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
		$obj->message = 'TAF Resource';
		$obj->apis = [
			'recent' => '/taf/recent/[station]',
			'local' => '/taf/local?distance=50&latitude=39&longitude=-104',
			'list' => '/tat/list?stations=KDEN,KLAX',
			'flight' => '/tat/flight?corridor=60&path=KDEN;KLAX',
			'taf' => '/taf/taf/KSEA?format=json&hours=2',
		];
		return Json::success($obj, Json::DEFAULT_SUCCESS_CODE, true);
	}

	/**
	 * Alias for getTaf() method
	 *
	 * @param $identifier
	 * @return Json|string
	 */
	public function getRecent($identifier = 'KSEA')
	{
		return $this->getTaf($identifier);
	}

	/**
	 * Get recent METAR data
	 * @param string $identifier
	 * @return Json|string
	 * @throws BadRequestException|QueryException
     */
	public function getTaf(string $identifier = 'KSEA'): Json|string
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
                $tafs = $this->getTafsFromCache($identifiers);
                foreach($identifiers as $ident)
                {
                    if(array_find($tafs, function ($taf) use ($ident) {
                            return strtoupper($taf->icao_id) === strtoupper($ident);
                        }) !== null)
                    {
                        $foundIdentifiers[] = strtoupper($ident);
                    }
                }

                $cachedResults = $this->formatFromDatabase($tafs);
                if (count($foundIdentifiers) !== count($identifiers))
                {
                    $fetchIdents = implode(',', array_diff($identifiers, $foundIdentifiers));
                    // If we don't have a cached response, get it from the API
                    $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT . '/taf', [
                        'format' => 'json',
                        'ids' => $fetchIdents,
                        'metar' => 'false',
                    ]);

                    // Try to cache the response
                    try {
                        TafModel::cache($response);
                    } catch (Exception $e) {
                        ErrorLogModel::logError($e);
                    }
                    return Json::success($this->mergeCachedAndFetchedResults($tafs, $this->originalFormat($response)));
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
     * Get a list of TAFs based on a list of stations.
     * @param string|null $stations
     * @return Json|string
     * @throws BadRequestException
     * @throws SystemException
     */
	public function getList(string $stations = null): Json|string
    {
        $format = (string)($_GET['format'] ?? 'default');
        $hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
        $stationString = $stations ?? (string)($_GET['stations'] ?? '');
        if(!ctype_alnum(str_replace(',', '', $stationString))) {
            throw new BadRequestException('Invalid station identifier');
        }
		try
		{
            $identifiers = explode(',', $stationString);
            try
            {
                $tafs = $this->getTafsFromCache($identifiers);
                return Json::success($this->formatFromDatabase($tafs));
            }
            catch (ModelNotFoundException $e) {
                $response = Rest::get(AddsModel::HTTP_SOURCE_ROOT . '/taf', [
                    'format' => 'json',
                    'ids' => $stationString,
                    'hours' => $hoursBeforeNow,
                ]);

                // Try to cache the response
                try {
                    TafModel::cache($response);
                } catch (Exception $e) {
                    ErrorLogModel::logError($e);
                }

                if ($format === 'json') {
                    return Json::success($response);
                } else {
                    return Json::success($this->originalFormat($response));
                }
            }
            catch (ConfigurationException|QueryException $e) {
                ErrorLogModel::logError($e);
                throw new SystemException('An error occurred while processing your request. Please try again later.');
            } catch (BadRequestException $e) {
                throw new BadRequestException($e->getMessage());
            }
        }
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get local TAF data
	 * @return null|string
	 */
	public function getLocal()
	{
		$format = (string)($_GET['format'] ?? 'default');
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 3);
		$distance = (int)$_GET['distance'] ?? null;
		$latitude = (float)$_GET['latitude'] ?? null;
		$longitude = (float)$_GET['longitude'] ?? null;

		$box = AddsModel::boundingBoxMiles($distance, $latitude, $longitude);
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/taf', [
				'bbox' => $box['minLat'].','.$box['minLon'].','.$box['maxLat'].','.$box['maxLon'],
				'format' => 'json',
				'hours' => (int)$hoursBeforeNow
			]);
			if ($format === 'json') {
				return Json::success($response);
			}
			else
			{
				return Json::success($this->originalFormat($response));
			}
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get TAF data along a flight path
	 * @return null|string
	 */
	public function getFlight()
	{
		$corridorWidth = (float)($_GET['corridor'] ?? 60);
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$flightPath = (string)($_GET['path'] ?? '');

		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'flightPath' => $corridorWidth.';'.$flightPath,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->TAF as $taf)
			{
				foreach($taf->forecast as $forecast)
				{
					$sky = $forecast->sky_condition;
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
			}
			return Json::success($xml);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	protected function formatFromDatabase(array $tafs, string $source = 'cached'): stdClass
	{
		$json = new stdClass();
		$json->TAF = [];

		$json->results = count($tafs);
		foreach ($tafs as $taf)
		{
            $json->TAF[] = TafModel::toResultFormat($taf);
		}
		return $json;
	}

	protected function originalFormat(mixed $response): stdClass
	{
		$json = new stdClass();
		$json->TAF = [];

		$json->results = count($response);
		foreach ($response as $taf)
		{
			$newTaf = new stdClass();
			$newTaf->raw_text = $taf->rawTAF;
			$newTaf->station_id = $taf->icaoId;
			$newTaf->issue_time = $taf->issueTime;
			$newTaf->bulletin_time = $taf->bulletinTime;
			$newTaf->valid_time_from = (new DateTime())->setTimestamp($taf->validTimeFrom)->format(AddsModel::DATETIME_FORMAT);
			$newTaf->valid_time_to = (new DateTime())->setTimestamp($taf->validTimeTo)->format(AddsModel::DATETIME_FORMAT);
			$newTaf->latitude = $taf->lat;
			$newTaf->longitude = $taf->lon;
			$newTaf->elevation_m = $taf->elev;
			$newTaf->forecast = [];
			foreach ($taf->fcsts as $forecast)
			{
				$newCast = new stdClass();
				$newCast->fcst_time_from = (new DateTime())->setTimestamp($forecast->timeFrom)->format(AddsModel::DATETIME_FORMAT);
				$newCast->fcst_time_to = (new DateTime())->setTimestamp($forecast->timeTo)->format(AddsModel::DATETIME_FORMAT);
				$newCast->change_indicator = $forecast->fcstChange;
				$newCast->wind_dir_degrees = $forecast->wdir;
				$newCast->wind_speed_kt = $forecast->wspd;
				$newCast->visibility_statute_mi = $forecast->visib;
				$newCast->sky_condition = [];

				$sky = $forecast->clouds;
				foreach ($sky as $condition)
				{
					$newCond = new stdClass();
					$newCond->sky_cover = $condition->cover;
					$newCond->cloud_base_ft_agl = $condition->base;
					if (count($sky) === 1)
					{
						$newCast->sky_condition = $newCond;
					} else
					{
						$newCast->sky_condition[] = $newCond;
					}
				}
				$newTaf->forecast[] = $newCast;
			}
            $newTaf->source = 'noaa';
			$json->TAF[] = $newTaf;
		}
		return $json;
	}

    /**
     * Get TAF data for a list of stations from the database
     * @param array $identifiers
     * @return TafModel[]
     * @throws BadRequestException
     * @throws ConfigurationException
     * @throws ModelNotFoundException
     * @throws QueryException
     */
    protected function getTafsFromCache(array $identifiers): array
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
//            $tafs = TafModel::findWhereStatement('tafs.icao_id IN('.$identString.') AND tafs.retrieved_at > DATE_SUB(NOW(), INTERVAL '.AddsModel::TAF_CACHING_INTERVAL.')');
            $tafs = TafModel::query()
                ->whereIn('tafs.icao_id', $identifiers)
                ->whereStatement('tafs.retrieved_at > DATE_SUB(NOW(), INTERVAL '.AddsModel::TAF_CACHING_INTERVAL.')')
                ->get()
                ->toArray();

            // If we didn't get at least as many TAFs as we requested, throw an exception
            if (count($tafs) === 0) {
                throw new ModelNotFoundException('No TAFs found for the specified station(s)');
            }

            /** @var TafModel $taf */
            foreach ($tafs as $taf) {
                $forecasts = TafForecastModel::select()
                    ->whereEqual('taf_id', $taf->id)
                    ->get()->toArray();
                $taf->forecasts = $forecasts;
                /** @var TafForecastModel $forecast */
                foreach ($forecasts as $forecast) {
                    /** @var TafForecastCloudModel $cloud */
                    $clouds = TafForecastCloudModel::select()
                        ->whereEqual('taf_forecast_id', $forecast->id)
                        ->get()->toArray();
                    $forecast->clouds = $clouds;
                }
            }

            return $tafs;
        }
        catch (ModelNotFoundException $e)
        {
            // Remove any existing cached TAFs for this station
            try
            {
                Query::delete('tafs')->whereStatement('icao_id IN('.$identString.')')->execute();
            }
            catch (QueryException $e) {}

            // Return empty array
//            throw new ModelNotFoundException('No TAFs found for the specified station(s)');
            return [];
        }
    }

    private function mergeCachedAndFetchedResults(array $cachedResults, stdClass $fetchedResults): stdClass {
        $json = new stdClass();
        $json->TAF = [];
        foreach ($cachedResults as $taf)
        {
            $json->TAF[] = TafModel::toResultFormat($taf);
        }
        foreach($fetchedResults->TAF as $noaaTaf) {
            $json->TAF[] = $noaaTaf;
        }
        $json->results = count($json->TAF);
        return $json;
    }
}