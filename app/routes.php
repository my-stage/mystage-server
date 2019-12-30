<?php
declare(strict_types=1);

use Slim\App;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config;

return function (App $app) {
    $container = $app->getContainer();

    $app->any("/api/v1[/{params:.*}]", function(
      Request $request,
      Response $response,
      array $args
    ) use ($container) {
      $config = new Config([
        "driver" => "mysql",
        "address" => "localhost",
        "port" => 3306,
        "username" => "mystage",
        "password" => "",
        "database" => "mystage",
        "basePath" => "/api/v1",
        "middlewares" => "cors,dbAuth",
        /*"authorization.tableHandler" => function($operation, $tableName) {
          return $tableName != "users";
        },*/
      ]);
      $api = new Api($config);
      $response = $api->handle($request);
      return $response;
    });
};
