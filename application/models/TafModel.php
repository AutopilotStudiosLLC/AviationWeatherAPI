<?php
require_once('structs/TafStruct.php');
use Staple\Exception\QueryException;
use Staple\Model;
use models\structs\TafStruct;

/**
 * Taf Model
 */
class TafModel extends Model
{
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
}