<?php

class Init {

    private $conn;
    private $page;
    private $user = null;
    private static $instance = null;

    private function __construct() {
        $host = '127.0.0.1';
        $port = 5432;
        $dbname = 'mydb';
        $user = 'postgres';
        $password = 'c0cc0dr1ll00';

        $conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password";
        $this->conn = pg_connect($conn_string);

        $this->page = filter_input(INPUT_GET, 'page');
        session_start();
    }

    /**
     * 
     * @return resource
     */
    public function getConn() {
        return $this->conn;
    }

    /**
     * 
     * @param mixed $id
     * @return mixed
     */
    public function getSession($id) {
        return array_key_exists($id, $_SESSION) ? $_SESSION[$id] : false;
    }

    /**
     * 
     * @param mixed $id
     * @param mixed $value
     * @return Init
     */
    public function setSession($id, $value) {
        $_SESSION[$id] = $value;
        return $this;
    }

    public function isFriend($firstUserId, $secondUserId = null) {
        $user_id = $this->getSession('user_id');
        if (!$secondUserId) {
            if (!$user_id) {
                return false;
            } else {
                $secondUserId = $user_id;
            }
        }

        $sql = "SELECT 1 AS check FROM friend WHERE (sender_id = $1 AND receiver_id = $2) OR (sender_id = $3 AND receiver_id = $4)";
        pg_prepare($this->getConn(), "getFriend", $sql);

        $result = pg_execute($this->getConn(), "getFriend", array($firstUserId, $secondUserId, $secondUserId, $firstUserId));
        $row = pg_fetch_row($result);
        
        return $row ? true : false;
    }

    public function getUser($id = null) {
        $user_id = $this->getSession('user_id');
        if (!$id) {
            if (!$user_id) {
                return false;
            } else {
                $id = $user_id;
            }
        }

        /* Avoid a SELECT */
        if ($user_id == $id && $this->user) {
            return $this->user;
        }

        $user = $this->pgSelect('user', array('id' => intval($id)));

        /* Store */
        if ($user_id == $id && $user) {
            $this->user = $user[0];
            return $this->user;
        }

        return $user ? $user[0] : false;
    }

    /**
     * 
     * @param string $table_name
     * @param array $assoc_array
     * @return boolean
     */
    public function pgSelect($table_name, $assoc_array) {
        return pg_select($this->getConn(), $table_name, $assoc_array);
    }

    /**
     * 
     * @param string $table_name
     * @param array $assoc_array
     * @return boolean
     */
    public function pgInsert($table_name, $assoc_array) {
        return pg_insert($this->getConn(), $table_name, $assoc_array);
    }

    /**
     * 
     * @return array
     */
    public function checkLogin() {
        $user = $this->getUser();
        if (!$user) {
            $this->gotoIndex();
        }
        return $user;
    }

    public function gotoIndex() {
        $this->redirect('index.php?page=index');
    }

    public function gotoHomepage() {
        $this->redirect('index.php?page=homepage');
    }

    public function redirect($url) {
        header("Location: $url");
        exit;
    }

    /**
     * 
     * @return string
     */
    public function getPage() {
        $router = new Router();
        if ($this->page && method_exists($router, $this->page . "Action")) {
            $action = $this->page . "Action";
            return $router->$action();
        } else {
            return $router->indexAction();
        }
    }

    /**
     * 
     * @return Init
     */
    public static function getInstance() {
        if (self::$instance == null) {
            $c = __CLASS__;
            self::$instance = new $c;
        }

        return self::$instance;
    }

}
