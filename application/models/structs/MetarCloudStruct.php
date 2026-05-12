<?php
namespace models\structs;
use stdClass;

class MetarCloudStruct
{
    public ?int $metar_id;
    public ?string $cover;
    public ?int $base;
    public ?string $type;

	static function importArray($clouds)
	{
		$array = [];
		foreach($clouds as $cloud)
		{
			$struct = new MetarCloudStruct();
            $struct->metar_id = $cloud->metar_id ?? null;
            $struct->cover = $cloud->cloud_cover ?? null;
            $struct->base = $cloud->cloud_base ?? null;
            $struct->type = $cloud->cloud_type ?? null;
			$array[] = $struct;
		}
		return $array;
	}
}