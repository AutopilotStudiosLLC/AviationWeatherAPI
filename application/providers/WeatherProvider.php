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
			return Json::success($response->data);
		}
		catch(RestException $e)
		{
			return Json::error($e->getMessage());
		}
	}
}