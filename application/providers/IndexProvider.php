<?php

use Staple\Controller\RestfulController;
use Staple\Json;

class IndexProvider extends RestfulController
{
	public function getIndex()
	{
		return Json::success('Welcome to the Aviation Weather API');
	}
}