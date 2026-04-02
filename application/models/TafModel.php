<?php
require_once('structs/TafStruct.php');

use models\structs\TafStruct;
use Staple\Exception\QueryException;
use Staple\Model;

/**
 * Taf Model
 */
class TafModel extends Model
{
    function __jsonSerialize() {
        return TafModel::toResultFormat($this);
    }

	/**
	 * @var TafForecastModel[] $forecasts
	 */
	public array $forecasts = [];

	/**
	 * @throws Exception
	 */
	function __construct(TafStruct|null $taf = null)
	{
		parent::__construct();
		if(isset($taf))
		{
			$this->import($taf);
		}
	}

	function getRawText(): string
	{
		return $this->raw_text;
	}

	/**
	 * @throws QueryException
	 * @throws Exception
	 */
	static function cache(array $tafs): void
	{
		foreach($tafs as $taf)
		{
			$tafModel = new static(TafStruct::import($taf));
			try
			{
				$tafModel->save();
				foreach($tafModel->forecasts as $forecast)
				{
					$forecast->taf_id = $tafModel->id;
					$forecast->save();
					foreach($forecast->clouds as $cloud)
					{
						$cloud->taf_forecast_id = $forecast->id;
						$cloud->save();
					}
				}
			}
			catch(QueryException|Exception $e)
			{
				throw new Exception($e->getMessage());
			}
		}
	}

	/**
	 * @throws Exception
	 */
	function import(TafStruct $taf): void
	{
		$this->icao_id = $taf->icaoId;
    	$this->bulletin_time = new DateTime($taf->bulletinTime);
    	$this->issue_time = new DateTime($taf->issueTime);
    	$this->valid_time_from = (new DateTime)->setTimestamp($taf->validTimeFrom);
    	$this->valid_time_to = (new DateTime)->setTimestamp($taf->validTimeTo);
    	$this->most_recent = $taf->mostRecent;
		$this->remarks = $taf->remarks;
    	$this->lat = $taf->lat;
    	$this->lon = $taf->lon;
    	$this->elevation = $taf->elev;
    	$this->station_name = $taf->name;
    	$this->raw_text = $taf->rawTAF;
		$this->forecasts = TafForecastModel::importArray($taf->fcsts);
	}

    public static function toResultFormat(TafModel $taf): stdClass {
        $json = new stdClass();
        $json->raw_text = $taf->raw_text;
        $json->station_id = $taf->icao_id;
        if (isset($taf->issue_time))
            $json->issue_time = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->issue_time)->format(AddsModel::DATETIME_FORMAT);
        if (isset($taf->bulletin_time))
            $json->bulletin_time = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->bulletin_time)->format(AddsModel::DATETIME_FORMAT);
        if (isset($taf->valid_time_from))
            $json->valid_time_from = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->valid_time_from)->format(AddsModel::DATETIME_FORMAT);
        if (isset($taf->valid_time_to))
            $json->valid_time_to = DateTime::createFromFormat(DATABASE_DATE_FORMAT, $taf->valid_time_to)->format(AddsModel::DATETIME_FORMAT);
        $json->latitude = $taf->lat;
        $json->longitude = $taf->lon;
        $json->elevation_m = $taf->elevation;
        $json->forecast = [];
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
            $json->forecast[] = $newCast;
        }
        $json->source = 'cached';
        return $json;
    }
}