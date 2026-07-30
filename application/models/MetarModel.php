<?php
require_once('structs/MetarStruct.php');

use models\structs\MetarStruct;
use Staple\Exception\QueryException;
use Staple\Model;

class MetarModel extends Model
{
    const METAR_CACHING_INTERVAL = '3 MINUTE';

    /**
     * @var MetarCloudModel[] $forecasts
     */
    public array $clouds = [];

    function __jsonSerialize(): stdClass
    {
        return MetarModel::toResultFormat($this);
    }

    function __construct(MetarStruct|null $metar = null)
    {
        parent::__construct();
        if(isset($metar))
        {
            $this->import($metar);
        }
    }

	function getRawText()
	{
		return $this->raw_text;
	}

    /**
     * @throws QueryException
     * @throws Exception
     */
    static function cache(array $metars): void
    {
        foreach($metars as $metar)
        {
            $metarModel = new static(MetarStruct::import($metar));
            try
            {
                $metarModel->save();
                foreach($metarModel->clouds as $cloud)
                {
                    $cloud->metar_id = $metarModel->id;
                    $cloud->save();
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
    function import(MetarStruct $metar): void
    {
        $this->icao_id = $metar->icaoId;
        $this->receipt_time = isset($metar->receiptTime) ? DateTime::createFromFormat(AddsModel::DATETIME_FORMAT, $metar->receiptTime)->format(AddsModel::DATABASE_DATE_FORMAT) : null;
        $this->observation_time = isset($metar->obsTime) ? new DateTime()->setTimestamp($metar->obsTime)->format(AddsModel::DATABASE_DATE_FORMAT) : null;
        $this->report_time = isset($metar->reportTime) ? DateTime::createFromFormat(AddsModel::DATETIME_FORMAT, $metar->reportTime)->format(AddsModel::DATABASE_DATE_FORMAT) : null;
        $this->temperature = $metar->temp ?? null;
        $this->dew_point = $metar->dewp ?? null;
        $this->wind_direction = $metar->wdir ?? null;
        $this->wind_speed = $metar->wspd ?? null;
        $this->wind_gust = $metar->wgst ?? null;
        $this->visibility = $metar->visib ?? null;
        $this->altimeter = $metar->altim ?? null;
        $this->sea_level_pressure = $metar->slp ?? null;
        $this->metar_type = $metar->metarType ?? null;
        $this->raw_text = $metar->rawOb ?? null;
        $this->latitude = $metar->lat ?? null;
        $this->longitude = $metar->lon ?? null;
        $this->elevation = $metar->elev ?? null;
        $this->station_name = StationModel::normalizeSiteName($metar->name) ?? null;
        $this->cloud_cover = $metar->cover ?? null;
        $this->clouds = MetarCloudModel::importArray($metar->clouds);
        $this->flight_category = $metar->fltCat ?? null;
    }

    public static function toResultFormat(MetarModel $metar): stdClass {
        $json = new stdClass();
        $json->station_id = $metar->icao_id;
        $json->receipt_time = DateTime::createFromFormat(AddsModel::DATABASE_DATE_FORMAT, $metar->receipt_time)->format(AddsModel::DATETIME_FORMAT);
        $json->observation_time = DateTime::createFromFormat(AddsModel::DATABASE_DATE_FORMAT, $metar->observation_time)->format(AddsModel::DATETIME_FORMAT);
        $json->report_time = DateTime::createFromFormat(AddsModel::DATABASE_DATE_FORMAT, $metar->report_time)->format(AddsModel::DATETIME_FORMAT);
        $json->temp_c = $metar->temperature;
        $json->dewpoint_c = $metar->dew_point;
        $json->wind_dir_degrees = $metar->wind_direction;
        $json->wind_speed_kt = $metar->wind_speed;
        $json->wind_gust_kt = $metar->wind_gust;
        $json->visibility_statute_mi = $metar->visibility;
        $json->altim_in_hg = MetarModel::convertToHg($metar->altimeter ?? 0);
        $json->sea_level_pressure_mb = $metar->sea_level_pressure ?? null;
        $json->quality_control_flags = new stdClass();
        $json->metar_type = "METAR";
        $json->raw_text = $metar->raw_text;
        $json->latitude = $metar->latitude;
        $json->longitude = $metar->longitude;
        $json->elevation_m = $metar->elevation;
        $json->station_name = StationModel::normalizeSiteName($metar->station_name);
        $json->sky_cover = $metar->cloud_cover ?? null;
        $json->flight_category = $metar->flight_category;
        $json->sky_condition = [];
        foreach ($metar->clouds as $cloud)
        {
            $newCloud = new stdClass();
            if(isset($cloud->cloud_cover) && strlen($cloud->cloud_cover) > 0)
                $newCloud->sky_cover = $cloud->cloud_cover;
            if(isset($cloud->cloud_base) && strlen($cloud->cloud_base) > 0)
                $newCloud->cloud_base_ft_agl = $cloud->cloud_base;
            if(isset($cloud->cloud_type) && strlen($cloud->cloud_type) > 0)
                $newCloud->cloud_type = $cloud->cloud_type;
            $json->sky_condition[] = $newCloud;
        }
        $json->source = 'cached';
        return $json;
    }

    /**
     * Convert atmospheric pressure from millibars to inches of mercury.
     *
     * @param float $millibars
     * @return float
     */
    public static function convertToHg(float $millibars): float
    {
        return round($millibars / 33.864, 2);
    }
}