<?php
use Staple\Model;

class ErrorLogModel extends Model
{
    public static function logError(Exception $e)
    {
        $error = new static();
        $error->error_message = $e->getMessage();
        $error->error_trace = $e->getTraceAsString();
        $error->error_time = DateTime::createFromFormat('U', time(), new DateTimeZone('UTC'));
        $error->save();
    }
}