<?php

require_once("Api.php");
require_once("vendor/autoload.php");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: *");
$api = new Api([
    "db" => [
        "host" => "localhost",
        "user" => "mystage",
        "pass" => "",
        "db" => "mystage",
    ]
]);
$api->setBasePath("/api/v1");
$api->handleRequest();