<?php

require_once("SafeMySQL.php");

/*
 * Tested and working:
 *
 * - Basic Insert
 * - Basic Update
 * - Basic Select
 * - Basic Delete
 * - Authentication
 * - Authorization
 * - Token Management
 */

class Api {

    private $basePath;
    private $db;
    private $user;
    private $authorizationHandler;

    function __construct($opts) {
        $this->db = new SafeMySQL($opts["db"]);
        $requestUri = $_SERVER["REQUEST_URI"];
    }

    function setBasePath($basePath) {
        $this->basePath = trim($basePath, "/");
    }

    public function setAuthorizationHandler($func) {
        $this->authorizationHandler = $func;
    }

    function handleRequest() {
        $requestUri = $_SERVER["REDIRECT_URL"];
        $pos = strpos($requestUri, $this->basePath);
        if ($pos === -1) {
            $this->response(404, "Not found.");
        } else {
            $url = substr($requestUri, strlen($this->basePath) + 1);
            $url = trim($url, "/");
            $parts = explode("/", $url);

            if(isset($_GET["token"])) {
                $tokenRow = $this->db->getRow("SELECT * FROM auth_tokens WHERE token = ?s", $_GET["token"]);
                if(!$tokenRow) {
                    $this->response(403, "Authentication required");
                }
                $this->user = $this->getUserById(intval($tokenRow["user_id"]));
                if(!$this->user) {
                    $this->response(403, "Authentication required");
                }
                $this->handleAuthorization($parts);
            } else {
                if($parts[0] !== "login") {
                    $this->response(403, "Authentication required");
                }
            }

            if($_SERVER["REQUEST_METHOD"] === "GET") {
                $this->handleGet($parts);
            } else if($_SERVER["REQUEST_METHOD"] === "POST") {
                $this->handlePost($parts);
            } else if($_SERVER["REQUEST_METHOD"] === "DELETE") {
                $this->handleDelete($parts);
            } else if($_SERVER["REQUEST_METHOD"] === "PUT") {
                $this->handlePut($parts);
            }
        }
    }

    private function handlePut($parts) {
        $action = $parts[0];
        $data = json_decode(file_get_contents("php://input"), true);

        if($action === "records") {
            $table = $parts[1];

            $sql = "UPDATE ?n SET ";

            for($i = 0; $i < count($data); $i++) {
                if($i !== 0) {
                    $sql .= ", ";
                }
                $sql .= "?n = ?s";
            }
            $sql .= " WHERE";
            $ids = explode(",", $parts[2]);
            for($i = 0; $i < count($ids); $i++) {
                if($i !== 0) {
                    $sql .= " OR";
                }
                $sql .= " id = ?i";
            }
            $sql .= ";";

            $params = [];
            $params[] = $table;
            foreach($data as $k => $v) {
                $params[] = $k;
                $params[] = $v;
            }
            foreach($ids as $id) {
                $params[] = $id;
            }

            $this->db->query($sql, ...$params);
            $this->dataResponse($sql, $params, null);
        }
    }

    private function handleDelete($parts) {
        $action = $parts[0];

        if($action === "records") {
            $table = $parts[1];

            $ids = explode(",", $parts[2]);
            $sql = "DELETE FROM ?n WHERE ";

            for($i = 0; $i < count($ids); $i++) {
                if($i !== 0) {
                    $sql .= " OR ";
                }
                $sql .= "id = ?i";
            }

            $params = [];
            $params[] = $table;
            foreach($ids as $id) {
                $params[] = $id;
            }

            $this->db->query($sql, ...$params);
            $this->dataResponse($sql, $params, null);
        }
    }

    private function handlePost($parts) {
        $action = $parts[0];
        $data = json_decode(file_get_contents("php://input"), true);

        if($action === "records") {
            $table = $parts[1];

            $sql = "INSERT INTO ?n (";
            for($i = 0; $i < count($data); $i++) {
                if($i !== 0) {
                    $sql .= ", ";
                }
                $sql .= "?n";
            }
            $sql .= ") VALUES (";
            for($i = 0; $i < count($data); $i++) {
                if($i !== 0) {
                    $sql .= ", ";
                }
                $sql .= "?s";
            }
            $sql .= ");";

            $params = [];
            $params[] = $table;
            foreach($data as $k => $v) {
                $params[] = $k;
            }
            foreach($data as $k => $v) {
                $params[] = $v;
            }

            $this->db->query($sql, ...$params);
            $this->dataResponse($sql, $params, null);
        } else if($action === "login") {
            if(!isset($data["username"]) || !isset($data["password"])) {
                $this->response(404, "Username or Password missing");
            }

            $user = $this->db->getRow("SELECT * FROM users WHERE username = ?s", $data["username"]);
            if($user && password_verify($data["password"], $user["password"])) {
                $token = $this->generateRandomString(100);

                $this->db->query("INSERT INTO auth_tokens (user_id, token) VALUES (?s, ?s)", $user["id"], $token);

                echo json_encode([
                    "status" => 200,
                    "data" => [
                        "user" => $user,
                        "token" => $token,
                    ],
                ]);
                die();
            } else {
                $this->response(403, "Wrong username or password.");
            }
        } else if($action === "register") {
            $user = $this->db->getOne("SELECT * FROM users WHERE username=?s OR email=?s", $data["username"], $data["email"]);
            if($user) {
                $this->response(200, "Username or Email already registered.");
            }
            $sql = "INSERT INTO users (email, username, name, password, permissions) VALUES (?s, ?s, ?s, ?s, ?s)";
            $params = [
                $data["email"],
                $data["username"],
                $data["name"],
                password_hash($data["password"], PASSWORD_BCRYPT),
                json_encode([]),
            ];

            $this->db->query($sql, ...$params);
            $this->dataResponse($sql, $params, null);
        } else if($action === "logout") {
            $sql = "DELETE FROM auth_tokens WHERE token = ?s";
            $params = [$_GET["token"]];
            $this->db->query($sql, ...$params);
            $this->dataResponse($sql, $params, true);
        }
    }

    private function handleGet($parts) {
        $action = $parts[0];
        if($action === "records") {
            $table = $parts[1];

            $sql = "SELECT * FROM ?n";

            if(count($parts) > 2) {
                // only specific ids
                $ids = explode(",", $parts[2]);
                $sql .= " WHERE";
                for($i = 0; $i < count($ids); $i++) {
                    if($i !== 0) {
                        $sql .= " OR";
                    }
                    $sql .= " id = ?i";
                }
            }
            $sql .= ";";

            $params = [];
            $params[] = $table;
            if(count($parts) > 2) {
                foreach($ids as $id) {
                    $params[] = intval($id);
                }
            }

            $data = $this->db->getAll($sql, ...$params);
            $this->dataResponse($sql, $params, $data);
        } else if($action === "myuser") {
            $this->dataResponse(null, null, $this->user);
        } else if($action === "files") {
            $fileName = $parts[1];

            readfile("files/" + $fileName);
        }
    }

    private function dataResponse($sql, $params, $data) {
        $res = [
            "status" => 200,
            "data" => $data,
            "query" => [
                "sql" => $sql,
                "params" => $params,
            ]
        ];

        echo json_encode($res);
        die();
    }

    private function response($status, $message) {
        $res = [
            "status" => $status,
            "message" => $message,
        ];

        echo json_encode($res);
        die();
    }

    private function getUserById($id) {
        $user = $this->db->getRow("SELECT * FROM users WHERE id = ?i", $id);
        $user["permissions"] = json_decode($user["permissions"]);
        return $user;
    }

    private function requirePermission($permission) {
        if(!in_array($permission, $this->user["permissions"])) {
            $this->response(403, "Permission Denied");
        }
    }

    private function handleAuthorization($parts) {
        $method = $_SERVER["REQUEST_METHOD"];
        if($parts[0] === "records" && $parts[1] === "users") {
            $this->requirePermission("usermgmt");
        }
    }

    private function generateRandomString($length) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }


}