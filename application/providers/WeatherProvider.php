<?php

use Staple\Controller\RestfulController;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

class WeatherProvider extends RestfulController
{
	const HTTP_SOURCE_ROOT = 'https://aviationweather.gov/adds/dataserver_current/httpparam';

	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getMetar($ident = 'KSEA', $hoursBeforeNow = 3)
	{
		try
		{
			$response = Rest::get(self::HTTP_SOURCE_ROOT, [
				'dataSource' => 'metars',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => strtoupper((string)$ident),
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

	public function getTaf($ident = 'KSEA', $hoursBeforeNow = 4)
	{
		try
		{
			$response = Rest::get(self::HTTP_SOURCE_ROOT, [
				'dataSource' => 'tafs',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => strtoupper((string)$ident),
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
					if(count($sky) == 0) continue;
					$unsetters = [];
					foreach($sky->attributes() as $key => $value)
					{
						$sky->addChild($key, $value);
						$unsetters[] = $key;
					}
					foreach($unsetters as $attribute)
					{
						unset($sky[$attribute]);
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
}