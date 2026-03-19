<?php
use Staple\Model;

class MetarModel extends Model
{
	function getRawText()
	{
		return $this->raw_text;
	}
}