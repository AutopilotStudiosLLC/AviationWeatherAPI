<?php
use Staple\Main;

include_once '../vendor/autoload.php';

defined('FOLDER_ROOT')
	|| define('FOLDER_ROOT', realpath(dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR));

defined('LIBRARY_ROOT')
	|| define('LIBRARY_ROOT', FOLDER_ROOT . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR);

defined('SITE_ROOT')
	|| define('SITE_ROOT', FOLDER_ROOT . DIRECTORY_SEPARATOR . 'htdocs' . DIRECTORY_SEPARATOR);

require_once LIBRARY_ROOT . 'Staple' . DIRECTORY_SEPARATOR . 'Main.php';

//Load Environment Variables
$path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
$dotenv = Dotenv\Dotenv::createImmutable($path);
$dotenv->safeLoad();

//Run the Application
$main = Main::get();
$main->run();