<?php
require_once('structs/TafForecastStruct.php');

use models\structs\TafForecastStruct;
use Staple\Model;

class TafForecastModel extends Model
{
	/**
	 * @var TafForecastCloudModel[] $clouds
	 */
	public array $clouds = [];

	public static function table(): string
	{
		return (new static())->_table;
	}

	static function importArray(array $forecasts): array
	{
		$models = [];
		foreach($forecasts as $forecast)
		{
			$model = new static();
			$model->time_from = (new DateTime)->setTimestamp($forecast->timeFrom);
			$model->time_to = (new DateTime)->setTimestamp($forecast->timeTo);
			$model->forecast_change = $forecast->fcstChange;
			$model->probability = $forecast->probability;
			$model->wind_direction = $forecast->wdir;
			$model->wind_speed = $forecast->wspd;
			$model->wind_gust = $forecast->wgst;
			$model->wind_shear_height = $forecast->wshearHgt;
			$model->wind_shear_direction = $forecast->wshearDir;
			$model->wind_shear_speed = $forecast->wshearSpd;
			$model->visibility = $forecast->visib;
			$model->altimeter = $forecast->altim;
			$model->vertical_visibility = $forecast->vertVis;
			$model->weather_string = $forecast->wxString;
			$model->not_decoded = $forecast->notDecoded;
			$model->clouds = TafForecastCloudModel::importArray($forecast->clouds);
			$models[] = $model;
		}
		return $models;
	}
}