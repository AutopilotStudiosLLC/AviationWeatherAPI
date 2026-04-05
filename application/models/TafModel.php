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
	const TAF_CACHING_INTERVAL = '3 MINUTE';

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

    public static function toResultFormat(TafModel $station): stdClass {
		echo 'Converting Cache...<br>';
        $json = new stdClass();
        $json->station_id = $station->icao_id;
	  	$json->icao_id = $station->icao_id;
	  	$json->iata_id = $station->iata_id;
	  	$json->wmo_id = $station->wmo_id;
	  	$json->latitude = $station->latitude;
	  	$json->longitude = $station->longitude;
	  	$json->elevation_m = $station->elevation;
	  	$json->site = $station->site_name;
	  	$json->state = $station->state;
	  	$json->country = $station->country;
	  	$json->site_type = explode(',', $station->site_types);
        $json->source = 'cached';
        return $json;
    }
}