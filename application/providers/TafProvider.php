<?php
use Staple\Controller\RestfulController;
use Staple\Exception\BadRequestException;
use Staple\Exception\ConfigurationException;
use Staple\Exception\ModelNotFoundException;
use Staple\Exception\RestException;
use Staple\Json;
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
	 * @return string|null
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
	 * @throws BadRequestException
	 */
	public function getTaf(string $identifier = 'KSEA'): Json|string
	{
		if(!ctype_alnum(str_replace(',', '', $identifier))) {
			throw new BadRequestException('Invalid station identifier');
		}
		try
		{
			try
			{
				$tafs = TafModel::select()
					->columns(['tafs.*'])
					->leftJoin(TafForecastModel::table(), 'taf_forecasts.taf_id = tafs.id')
					->leftJoin('taf_forecast_clouds', 'taf_forecast_clouds.taf_forecast_id = taf_forecasts.id')
					->whereEqual('tafs.icao_id',  $identifier)
//					->whereStatement('tafs.icao_id = \''.strtoupper($identifier).'\' AND tafs.retrieved_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)')
					->orderBy('tafs.retrieved_at DESC')
					->limit(3)
					->get()
					->toArray();

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
				return Json::success($this->formatFromDatabase($tafs));
			}
			catch (ModelNotFoundException $e)
			{
				// If we don't have a cached response, get it from the API
				$format = (string)($_GET['format'] ?? 'default');
				$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT . '/taf', [
					'format' => 'json',
					'ids' => strtoupper($identifier),
					'metar' => 'false',
				]);

				// Try to cache the response
				try
				{
					TafModel::cache($response);
				}
				catch (Exception $e) {} // Ignoreing the caching errors for the moment

				if ($format === 'json')
				{
					return Json::success($response);
				}
				else
				{
					return Json::success($this->originalFormat($response));
				}
			}
			catch (ConfigurationException $e)
			{
				throw new BadRequestException($e->getMessage());
				// Todo log the error
			}
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}

	/**
	 * Get a list of TAFs based on a list of stations.
	 * @return null|string
	 */
	public function getList()
	{
		$format = (string)($_GET['format'] ?? 'default');
		$hoursBeforeNow = (int)($_GET['hoursBeforeNow'] ?? 2);
		$stationString = (string)($_GET['stations'] ?? '');
		try
		{
			$response = Rest::get(AddsModel::HTTP_SOURCE_ROOT.'/taf', [
				'format' => 'json',
				'ids' => $stationString,
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

	protected function formatFromDatabase(array $tafs): stdClass
	{
		$json = new stdClass();
		$json->TAF = [];

		$json->results = count($tafs);
		foreach ($tafs as $taf)
		{
			$newTaf = new stdClass();
			$newTaf->raw_text = $taf->raw_text;
			$newTaf->station_id = $taf->icao_id;
			if (isset($taf->issue_time))
				$newTaf->issue_time = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->issue_time,)->format(AddsModel::DATETIME_FORMAT);
			if (isset($taf->bulletin_time))
				$newTaf->bulletin_time = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->bulletin_time,)->format(AddsModel::DATETIME_FORMAT);
			if (isset($taf->valid_time_from))
				$newTaf->valid_time_from = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->valid_time_from)->format(AddsModel::DATETIME_FORMAT);
			if (isset($taf->valid_time_to))
				$newTaf->valid_time_to = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->valid_time_to)->format(AddsModel::DATETIME_FORMAT);
			$newTaf->latitude = $taf->lat;
			$newTaf->longitude = $taf->lon;
			$newTaf->elevation_m = $taf->elevation;
			$newTaf->forecast = [];
			foreach ($taf->forecasts as $forecast)
			{
				$newCast = new stdClass();
				$newCast->fcst_time_from = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $forecast->time_from)->format(AddsModel::DATETIME_FORMAT);
				$newCast->fcst_time_to = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $forecast->time_to)->format(AddsModel::DATETIME_FORMAT);
				$newCast->change_indicator = $forecast->forecast_change;
				$newCast->wind_dir_degrees = $forecast->wind_direction;
				$newCast->wind_speed_kt = $forecast->wind_speed;
				$newCast->visibility_statute_mi = $forecast->visibility;
				$newCast->sky_condition = [];

				$sky = $forecast->clouds;
				foreach ($sky as $condition)
				{
					$newCond = new stdClass();
					$newCond->sky_cover = $condition->cloud_cover;
					$newCond->cloud_base_ft_agl = $condition->cloud_base;
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
			$json->TAF[] = $newTaf;
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
			$json->TAF[] = $newTaf;
		}
		return $json;
	}
}