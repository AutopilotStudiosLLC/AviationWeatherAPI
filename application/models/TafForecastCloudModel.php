<?php

use Staple\Model;

class TafForecastCloudModel extends Model
{
	static function importArray(array $clouds): array
	{
		$models = [];
		foreach($clouds as $cloud)
		{
			$model = new static();
			$model->cloud_base = $cloud->base;
			$model->cloud_cover = $cloud->cover;
			$model->cloud_type = $cloud->type;
			$models[] = $model;
		}
		return $models;
	}
}