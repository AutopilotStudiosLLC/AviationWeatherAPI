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
            $struct->cover = $cloud->cover ?? null;
            $struct->base = $cloud->base ?? null;
            $struct->type = $cloud->type ?? null;
			$array[] = $struct;
		}
		return $array;
	}
}