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
        $password = 'milano';

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
     * @param boolean $remove
     * @return mixed
     */
    public function getSession($id, $remove = false) {
        $return = array_key_exists($id, $_SESSION) ? $_SESSION[$id] : false;

        if ($return && $remove) {
            unset($_SESSION[$id]);
        }

        return $return;
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

    /**
     * 
     * @param type $onlyActualFriends
     * @return array
     */
    public function getFriends($onlyActualFriends = false) {
        $dbconn = $this->getConn();
        $user = $this->checkLogin();
        /* retrieve friends */
        $sql = 'SELECT u.id, u.firstname, u.lastname, f.sender_id, f.sentat, f.acceptedat FROM "user" AS u
                LEFT OUTER JOIN friend AS f ON ((u.id = f.sender_id  AND f.receiver_id = $1)  OR (u.id = f.receiver_id AND f.sender_id = $1))
                WHERE u.id <> $1';

        if ($onlyActualFriends) {
            $sql .= ' AND f.sentat IS NOT NULL AND f.acceptedat IS NOT NULL';
        }


        $sql .= ' ORDER BY u.lastname, u.firstname';
        pg_prepare($dbconn, "getUser", $sql);

        $users = pg_execute($dbconn, "getUser", array($user['id']));

        return pg_fetch_all($users);
    }

    /**
     * 
     * @param type $firstUserId
     * @param type $secondUserId
     * @param type $actualFriends
     * @return boolean
     */
    public function isFriend($firstUserId, $secondUserId = null, $actualFriends = false) {
        $user_id = $this->getSession('user_id');
        if (!$secondUserId) {
            if (!$user_id) {
                return false;
            } else {
                $secondUserId = $user_id;
            }
        }

        $row = $this->getFriendship($firstUserId, $secondUserId);

        if ($actualFriends && $row) {
            return $row['acceptedat'] && $row['sentat'];
        }

        return $row ? true : false;
    }

    public function getFriendship($firstUserId, $secondUserId) {
        $sql = "SELECT * FROM friend WHERE (sender_id = $1 AND receiver_id = $2) OR (sender_id = $2 AND receiver_id = $1)";
        pg_prepare($this->getConn(), "", $sql);
        $result = pg_execute($this->getConn(), "", array($firstUserId, $secondUserId));
        $row = pg_fetch_assoc($result);

        return $row ? $row : false;
    }
    
    public function isVip() {
        $user = $this->checkLogin();
        return $user['vip'] == 1;
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
    public function pgSelect($table_name, $assoc_array = array()) {
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
