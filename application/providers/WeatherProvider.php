<?php

use Staple\Controller\RestfulController;
use Staple\Exception\RestException;
use Staple\Json;
use Staple\Request;
use Staple\Rest\Rest;

class WeatherProvider extends RestfulController
{
	public function _start()
	{
		$this->addAccessControlOrigin('*');
		$this->addAccessControlMethods([Request::METHOD_GET, Request::METHOD_OPTIONS]);
	}

	public function getMetar($ident, $hoursBeforeNow = 3)
	{
		try
		{
			$response = Rest::get('https://aviationweather.gov/adds/dataserver_current/httpparam', [
				'dataSource' => 'metars',
				'requestType' => 'retrieve',
				'format' => 'xml',
				'stationString' => (string)$ident,
				'hoursBeforeNow' => (int)$hoursBeforeNow
			]);
			/** @var SimpleXMLElement $xml */
			$xml = $response->data;
			$xml->addChild('results', $xml['num_results']);
			unset($xml['num_results']);
			foreach($xml->METAR as $metar)
			{
				$sky = $metar->sky_condition;
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
}