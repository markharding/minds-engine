<?php

require_once(dirname(__FILE__) . "/start.php");

error_reporting(E_ALL);

$router = new Minds\Core\Router();
$router->route();
