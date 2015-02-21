<?php

class Router {

    const MFPOST_STATO = "1";
    const MFPOST_FOTO = "2";
    const MFPOST_LUOGO = "3";

    private $twig;
    private $init;

    public function __construct() {
        $loader = new Twig_Loader_Filesystem('templates');
        $this->twig = new Twig_Environment($loader);
        $this->init = Init::getInstance();
    }

    private function render($text, $params) {
        return $this->twig->render($text, $params);
    }

    private function register($register) {
        $registerErrors = array();
        $registerOkay = false;

        if (!trim($register['firstname'])) {
            $registerErrors['firstname'] = 'Campo obbigliatorio';
        }

        if (!trim($register['lastname'])) {
            $registerErrors['lastname'] = 'Campo obbigliatorio';
        }

        if (!trim($register['email'])) {
            $registerErrors['email'] = 'Campo obbigliatorio';
        } else {
            $email = trim(strtolower($register['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $registerErrors['email'] = 'Email non valida';
            }

            $checkEmail = $this->init->pgSelect('user', array('email' => $register['email']));
            if ($checkEmail !== false) {
                $registerErrors['email'] = 'Email già registrata';
            } else {
                $register['email'] = $email;
            }
        }

        if (!$register['password']) {
            $registerErrors['password'] = 'Campo obbigliatorio';
        } else {
            $password = $register['password'];
            if (preg_match('/[^a-z_\-0-9]/i', $password)) {
                $registerErrors['password'] = 'La password può contenere solo numeri e lettere';
            } elseif (strlen($password) < 6) {
                $registerErrors['password'] = 'La password deve essere lunga almeno 6 caratteri';
            } else {
                $register['password'] = md5($password);
            }
        }

        /* No errors, so far */
        if (!$registerErrors) {
            $return = $this->init->pgInsert('user', $register);
            if ($return !== true) {
                $registerErrors['generic'] = pg_last_error($this->init->getConn());
            } else {
                $registerOkay = true;
                $register = array();
            }
        }

        return array($register, $registerErrors, $registerOkay);
    }

    private function login($email, $password) {
        $loginErrors = array();
        if (!$email) {
            $loginErrors['email'] = 'Inserire l\'indirizzo email di registrazione';
        }

        if (!$password) {
            $loginErrors['password'] = 'Inserire la password email di registrazione';
        }

        /* No errors, so far */
        if (!$loginErrors) {
            $return = $this->init->pgSelect('user', array('email' => $email, 'password' => md5($password)));
            if ($return && array_key_exists('id', $return[0]) && $return[0]['id']) { /* L'utente esiste */
                $this->init->setSession('user_id', $return[0]['id']);
                $this->init->gotoHomepage();
                return;
            } else {
                $loginErrors['generic'] = 'Email o password non corretti';
            }
        }

        return $loginErrors;
    }

    public function indexAction() {
        $user = $this->init->getUser();
        if ($user) {
            $this->init->gotoHomepage();
        }

        $register = array('firstname' => '', 'lastname' => '', 'email' => '');
        $registerErrors = array();
        $registerOkay = false;

        $login = array('email' => '', 'password' => '');
        $loginErrors = array();

        if ($this->isPost()) {
            $post = filter_input_array(INPUT_POST);
            $index_form = (string) $post['index_form'];
            switch ($index_form) {
                case 'register':
                    $registerMerge = array_merge($register, (array) $post['register']);
                    list($register, $registerErrors, $registerOkay) = $this->register($registerMerge);
                    break;
                case 'login':
                    $email = trim($post['login']['email']);
                    $password = trim($post['login']['password']);
                    $loginErrors = $this->login($email, $password);
                    break;
            }
        }
        return $this->render('index.html.twig', array(
                    'register' => $register,
                    'registerErrors' => $registerErrors,
                    'registerOkay' => $registerOkay,
                    'login' => $login,
                    'loginErrors' => $loginErrors
        ));
    }

    private function insertPost($mfpost, $type) {
        $dbconn = $this->init->getConn();
        $errors = array();
        $okay = false;

        $sql = "SELECT MAX(id) AS maxId FROM post WHERE user_id = $1";
        pg_prepare($dbconn, "getId", $sql);

        $user = $this->init->getUser();
        $result = pg_execute($dbconn, "getId", array($user['id']));

        $row = pg_fetch_row($result);
        $newId = array_key_exists(0, $row) ? ($row[0] + 1) : 1;

        $insertParam = array_merge(array('id' => $newId, 'user_id' => $user['id'], 'type' => $type, 'createdat' => date('Y-m-d H:i:s')), $mfpost);

        $return = $this->init->pgInsert('post', $insertParam);
        if ($return !== true) {
            $errors['generic'] = pg_last_error($dbconn);
        } else {
            $okay = true;
        }

        return array($errors, $okay);
    }

    public function homepageAction() {
        $user = $this->init->checkLogin();
        $show = 'stato';

        $stato = array('main_text' => '', 'second_text' => '');
        $statoErrors = array();
        $statoOkay = false;

        $foto = array('main_text' => '', 'second_text' => '');
        $fotoErrors = array();
        $fotoOkay = false;

        $luogo = array('main_text' => '', 'latitude' => '', 'longitude' => '');
        $luogoErrors = array();
        $luogoOkay = false;

        if ($this->isPost()) {
            $post = filter_input_array(INPUT_POST);
            $mfpost_form = (string) $post['mfpost_form'];
            $show = $mfpost_form;
            switch ($mfpost_form) {
                case 'stato':
                    $stato = array_merge($stato, (array) $post['mfbpost']);
                    $main_text = trim($stato['main_text']);
                    if (!$main_text) {
                        $statoErrors['stato'] = 'Non è possibile inserire uno stato vuoto';
                    }

                    if (!$statoErrors) {
                        $stato['main_text'] = $main_text;
                        list($statoErrors, $statoOkay) = $this->insertPost($stato, self::MFPOST_STATO);
                    }

                    break;

                case 'foto':
                    $foto = array_merge($foto, (array) $post['mfbpost']);
                    $main_text = trim($foto['main_text']);
                    $second_text = trim($foto['second_text']);
                    if (!$main_text) {
                        $fotoErrors['foto'] = "Inserire l'url di una foto";
                    } elseif (!filter_var($main_text, FILTER_VALIDATE_URL)) {
                        $fotoErrors['foto'] = "Inserire una url valida (es. http://wwww.example.com/image.png)";
                    }
                    if (!$second_text) {
                        $fotoErrors['foto_desc'] = "Inserire una descrizione per la foto";
                    }

                    if (!$fotoErrors) {
                        $foto['main_text'] = $main_text;
                        $foto['second_text'] = $second_text;
                        list($fotoErrors, $fotoOkay) = $this->insertPost($foto, self::MFPOST_FOTO);
                    }

                    break;

                case 'luogo':
                    $luogo = array_merge($luogo, (array) $post['mfbpost']);
                    $main_text = trim($luogo['main_text']);
                    $latitude = trim($luogo['latitude']);
                    $longitude = trim($luogo['longitude']);
                    if (!$main_text) {
                        $luogoErrors['luogo'] = "Inserire il nome del luogo";
                    }

                    if ($latitude === "") {
                        $luogoErrors['latitude'] = "Inserire la latitudine";
                    } else {
                        $latitude = str_replace(',', '.', $latitude);
                        if (strpos('.', $latitude) === false) {
                            $latitude .= '.0';
                        }
                        if (!preg_match("/^-?([1-8]?[1-9]|[1-9]0)\.{1}\d{1,6}$/", $latitude)) {
                            $luogoErrors['latitude'] = "Inserire una latitudine valida (±85 fino a 6 cifre decimali)";
                        }
                    }

                    if ($longitude === "") {
                        $luogoErrors['longitude'] = "Inserire la longitudine";
                    } else {
                        $longitude = str_replace(',', '.', $longitude);
                        if (strpos('.', $longitude) === false) {
                            $longitude .= '.0';
                        }
                        if (!preg_match("/^-?([1]?[1-7][1-9]|[1]?[1-8][0]|[1-9]?[0-9])\.{1}\d{1,6}$/", $longitude)) {
                            $luogoErrors['longitude'] = "Inserire una longitudine valida (±180 fino a 6 cifre decimali)";
                        }
                    }

                    if (!$luogoErrors) {
                        $luogo['main_text'] = $main_text;
                        $luogo['latitude'] = $latitude;
                        $luogo['longitude'] = $longitude;
                        list($luogoErrors, $luogoOkay) = $this->insertPost($luogo, self::MFPOST_LUOGO);
                    }

                    break;
            }

            if ($statoOkay || $fotoOkay || $luogoOkay) {
                return $this->init->gotoHomepage();
            }
        }

        /* retrieve posts */
        $sql = 'SELECT * FROM (
                SELECT u.firstname, u.lastname, p.* FROM post AS p JOIN "user" AS u ON u.id = p.user_id
                JOIN friend AS f ON ((u.id = f.sender_id  AND f.receiver_id = $1) OR (u.id = f.receiver_id AND f.sender_id = $1)) AND sentat IS NOT NULL AND acceptedat IS NOT NULL
                
                UNION
                
                SELECT u.firstname, u.lastname, p.* FROM post AS p JOIN "user" AS u ON u.id = p.user_id
                WHERE u.id = $1
                ) AS posts ORDER BY createdat DESC
                ';
        pg_prepare($this->init->getConn(), "getPost", $sql);

        $posts = pg_execute($this->init->getConn(), "getPost", array($user['id']));
        $postsRows = pg_fetch_all($posts);


        $friends = $this->getFriends(true);

        return $this->render('homepage.html.twig', array(
                    'user' => $user,
                    'stato' => $stato,
                    'statoErrors' => $statoErrors,
                    'foto' => $foto,
                    'fotoErrors' => $fotoErrors,
                    'luogo' => $luogo,
                    'luogoErrors' => $luogoErrors,
                    'show' => $show,
                    'posts' => $postsRows,
                    'friends' => $friends
        ));
    }

    private function getFriends($onlyActualFriends = false) {
        $dbconn = $this->init->getConn();
        $user = $this->init->checkLogin();
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

    public function searchAction() {
        $user = $this->init->checkLogin();

        $friend = filter_input(INPUT_GET, 'friend');
        if ($friend) {
            $userFriend = $this->init->getUser($friend);
            if ($userFriend && !$this->init->isFriend($userFriend['id'])) {
                $this->init->pgInsert('friend', array('sender_id' => $user['id'], 'receiver_id' => $userFriend['id'], 'sentat' => date('Y-m-d H:i:s')));
            }
        }

        $usersRows = $this->getFriends();
        return $this->render('search.html.twig', array('user' => $user, 'usersRows' => $usersRows));
    }

    public function friendrequestAction() {
        $user = $this->init->checkLogin();
        $dbconn = $this->init->getConn();

        $accept = filter_input(INPUT_GET, 'accept');

        if ($accept) {
            $userFriend = $this->init->getUser($accept);
            if ($userFriend && $this->init->isFriend($userFriend['id'])) {
                $sql = "UPDATE friend SET acceptedat = $1 WHERE sender_id = $2 AND receiver_id = $3";
                pg_prepare($dbconn, "updUser", $sql);
                pg_execute($dbconn, "updUser", array(date('Y-m-d H:i:s'), $userFriend['id'], $user['id']));
            }
        }

        /* retrieve posts */
        $sql = 'SELECT u.id, u.firstname, u.lastname FROM "user" AS u
                JOIN friend AS f ON u.id = f.sender_id AND f.receiver_id = $1
                WHERE acceptedat IS NULL
                ORDER BY u.lastname, u.firstname';
        pg_prepare($dbconn, "getUser", $sql);

        $users = pg_execute($dbconn, "getUser", array($user['id']));

        $usersRows = pg_fetch_all($users);

        return $this->render('friendrequest.html.twig', array('user' => $user, 'usersRows' => $usersRows));
    }

    public function myuserAction() {
        $user = $this->init->checkLogin();
        return $this->render('myuser.html.twig', array('user' => $user));
    }

    public function logoutAction() {
        session_destroy();
        $this->init->setSession('user_id', null);
        $this->init->gotoIndex();
    }

    private function isPost() {
        $request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD');
        return $request_method === 'POST';
    }

}
