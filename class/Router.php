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

                /* CHECK VIP */
                $friends = $this->init->getFriends(true);

                if ($friends && count($friends) >= 3) {
                    pg_update($this->init->getConn(), 'user', array('vip' => true), array('id' => $return[0]['id']));
                } else {
                    pg_update($this->init->getConn(), 'user', array('vip' => false, 'favorite_place_post_id' => null, 'favorite_place_post_user_id' => null), array('id' => $return[0]['id']));
                }

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

        if ($type == self::MFPOST_FOTO) {
            $post = filter_input_array(INPUT_POST);
            $mfbtag = (array) $post['mfbtag'];
            if ($mfbtag) {
                $this->insertTag($mfbtag, $newId, $user['id']);
            }
        }


        return array($errors, $okay);
    }

    private function insertTag($mfbtag, $post_id, $post_user_id) {
        foreach ($mfbtag as $tag) {
            if ($this->init->isFriend($tag, $post_user_id, true)) {
                $this->init->pgInsert('tag', array('tagged_id' => $tag, 'post_id' => $post_id, 'post_user_id' => $post_user_id));
            }
        }
    }

    public function sendcommentAction() {
        $user = $this->init->checkLogin();

        if (!$this->init->isVip()) {
            return $this->init->gotoHomepage();
        }
        $post_id = filter_input(INPUT_POST, 'post_id');
        $post_user_id = filter_input(INPUT_POST, 'post_user_id');
        $comment = filter_input(INPUT_POST, 'comment');

        if ($post_user_id != $user['id']) {
            $postOwner = $this->init->getUser($post_user_id);
            if (!$postOwner) {
                return $this->init->gotoHomepage();
            }

            if (!$this->init->isFriend($post_user_id, $user['id'], true)) {
                return $this->init->gotoHomepage();
            }
        }

        $comment = trim($comment);
        if (!$comment) {
            return $this->init->redirect("index.php?page=homepage#stato" . $post_id . "_" . $post_user_id);
        }

        $posts = $this->init->pgSelect('post', array('id' => $post_id, 'user_id' => $post_user_id, 'type' => self::MFPOST_STATO));
        if ($posts) {
            $this->init->pgInsert('comment', array('responder_id' => $user['id'], 'post_id' => $post_id, 'post_user_id' => $post_user_id, 'content' => $comment, 'createdat' => date('Y-m-d H:i:s')));
            return $this->init->redirect("index.php?page=homepage#stato" . $post_id . "_" . $post_user_id);
        } else {
            return $this->init->gotoHomepage();
        }
    }

    public function homepageAction() {
        $user = $this->init->checkLogin();
        $isVip = $this->init->isVip();
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
                        if (strpos($latitude, '.') === false) {
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
                        if (strpos($longitude, '.') === false) {
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

        $tags = array();
        $tagsWhere = array();
        $commentsWhere = array();
        $comments = array();

        if ($postsRows) {
            foreach ($postsRows as $pRow) {
                if ($pRow['type'] == self::MFPOST_FOTO) {
                    $tagsWhere[] = '(t.post_id = ' . $pRow['id'] . ' AND t.post_user_id = ' . $pRow['user_id'] . ')';
                } elseif ($pRow['type'] == self::MFPOST_STATO) {
                    $commentsWhere[] = '(c.post_id = ' . $pRow['id'] . ' AND c.post_user_id = ' . $pRow['user_id'] . ')';
                }
            }

            /* retrieve tag */
            if ($tagsWhere) {
                $sqlTag = 'SELECT u.firstname, t.tagged_id, t.post_id, t.post_user_id FROM tag AS t JOIN "user" AS u ON t.tagged_id = u.id WHERE ' . implode(' OR ', $tagsWhere);
                $tagsResult = pg_query($this->init->getConn(), $sqlTag);
                $tags = pg_fetch_all($tagsResult);
            }

            /* retrieve comment */
            if ($commentsWhere) {
                $sqlComment = 'SELECT u.firstname, u.lastname, c.* FROM comment AS c JOIN "user" AS u ON c.responder_id = u.id WHERE ' . implode(' OR ', $commentsWhere) . ' ORDER BY c.createdat ASC';
                $commentsResult = pg_query($this->init->getConn(), $sqlComment);
                $comments = pg_fetch_all($commentsResult);
            }
        }
        $friends = $this->init->getFriends(true);

        return $this->render('homepage.html.twig', array(
                    'user' => $user,
                    'isVip' => $isVip,
                    'stato' => $stato,
                    'statoErrors' => $statoErrors,
                    'foto' => $foto,
                    'fotoErrors' => $fotoErrors,
                    'luogo' => $luogo,
                    'luogoErrors' => $luogoErrors,
                    'show' => $show,
                    'posts' => $postsRows,
                    'tags' => $tags,
                    'comments' => $comments,
                    'friends' => $friends
        ));
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

        $usersRows = $this->init->getFriends();
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
        $isVip = $this->init->isVip();
        $formOrig = array('birthdate' => '', 'birthplace' => '', 'gender' => '', 'domicile_city' => '', 'domicile_province' => '', 'domicile_state' => '');

        $places = array();
        if ($isVip) {
            $places = $this->init->pgSelect('post', array('type' => self::MFPOST_LUOGO));
        }

        $okay = $this->init->getSession('myuser_okay', true);
        $form = $formOrig;
        $errors = array();
        foreach ($form as $k => $v) {
            if ($user[$k]) {
                $tmp = $user[$k];
                if ($k == 'birthdate') {
                    $date = \DateTime::createFromFormat('Y-m-d', $tmp);
                    $tmp = $date->format('d/m/Y');
                }
                $form[$k] = $tmp;
            }
        }

        if ($this->isPost()) {
            $post = filter_input_array(INPUT_POST);
            $formMerge = array_merge($form, (array) $post['myuser']);
            $toUpdate = array();

            foreach ($formMerge as $k => $v) {
                if (array_key_exists($k, $formOrig) && $v != $user[$k]) {
                    if ($k == 'birthdate') {
                        $date = \DateTime::createFromFormat('d/m/Y', $v);
                        $v = $date->format('Y-m-d');
                    }

                    $toUpdate[$k] = $v;
                }
            }

            if ($isVip) {
                $favorite_place = filter_input(INPUT_POST, 'favorite_place');

                if ($favorite_place) {
                    list($post_id, $post_user_id) = explode('_', $favorite_place);
                    $check = $this->init->pgSelect('post', array('id' => $post_id, 'user_id' => $post_user_id, 'type' => self::MFPOST_LUOGO));
                } else {
                    $check = true;
                    $post_id = null;
                    $post_user_id = null;
                }
                if ($check) {
                    $toUpdate['favorite_place_post_id'] = $post_id;
                    $toUpdate['favorite_place_post_user_id'] = $post_user_id;
                }
            }

            if ($toUpdate) {
                pg_update($this->init->getConn(), 'user', $toUpdate, array('id' => $user['id']));

                $this->init->setSession('myuser_okay', true);

                return $this->init->redirect('index.php?page=myuser');
            }
        }

        return $this->render('myuser.html.twig', array('user' => $user, 'form' => $form, 'okay' => $okay, 'errors' => $errors, 'isVip' => $isVip, 'places' => $places));
    }

    public function removefriendshipAction() {
        $user = $this->init->checkLogin();

        $friend_id = filter_input(INPUT_GET, 'friend_id');
        if (!$friend_id || $friend_id == $user['id']) {
            return $this->init->gotoHomepage();
        }

        $profileUser = $this->init->getUser($friend_id);
        if (!$profileUser) {
            return $this->init->gotoHomepage();
        }

        $friendship = $this->init->getFriendship($friend_id, $user['id']);

        if (!$friendship) {
            return $this->init->redirect('index.php?page=profile&user_id=' . $friend_id);
        }

        pg_delete($this->init->getConn(), 'friend', $friendship);

        $back = filter_input(INPUT_GET, 'back');
        if ($back) {
            return $this->init->redirect("index.php?page=$back");
        }

        return $this->init->redirect("index.php?page=profile&user_id=$friend_id");
    }

    public function profileAction() {
        $user = $this->init->checkLogin();
        $user_id = filter_input(INPUT_GET, 'user_id');
        if (!$user_id || $user_id == $user['id']) {
            return $this->init->gotoHomepage();
        }

        $profileUser = $this->init->getUser($user_id);
        if (!$profileUser) {
            return $this->init->gotoHomepage();
        }
        $favorite_place = null;
        if ($profileUser['vip'] == "t" && $profileUser['favorite_place_post_id'] && $profileUser['favorite_place_post_user_id']) {
            $favorite_placeResult = $this->init->pgSelect('post', array('id' => $profileUser['favorite_place_post_id'], 'user_id' => $profileUser['favorite_place_post_user_id'], 'type' => self::MFPOST_LUOGO));
            if ($favorite_placeResult) {
                $favorite_place = $favorite_placeResult[0];
            }
        }

        $ask = filter_input(INPUT_GET, 'ask');
        if ($ask) {
            if (!$this->init->isFriend($profileUser['id'])) {
                $this->init->pgInsert('friend', array('sender_id' => $user['id'], 'receiver_id' => $profileUser['id'], 'sentat' => date('Y-m-d H:i:s')));
            }
        }

        $friendship = $this->init->getFriendship($user_id, $user['id']);

        $sql = 'SELECT s.*, us.year_begin, us.year_end FROM user_school AS us JOIN school s ON us.school_id = s.id WHERE us.user_id = $1 ORDER BY us.year_begin DESC, us.year_end DESC, s."name" ASC';
        pg_prepare($this->init->getConn(), "", $sql);
        $schoolsresult = pg_execute($this->init->getConn(), "", array($user_id));
        $schools = pg_fetch_all($schoolsresult);

        return $this->render('profile.html.twig', array('user' => $user, 'profileUser' => $profileUser, 'friendship' => $friendship, 'schools' => $schools, 'favorite_place' => $favorite_place));
    }

    public function addschoolAction() {
        $user = $this->init->checkLogin();

        $school = array('name' => '', 'address' => '', 'city' => '');
        $okay = $this->init->getSession('school_okay', true);
        $errors = array();

        if ($this->isPost()) {
            $post = filter_input_array(INPUT_POST);
            $school = array_merge($school, (array) $post['school']);

            $name = trim($school['name']);
            if ($name === "") {
                $errors['name'] = 'Campo obbligatorio';
            } else {
                $school['name'] = $name;
            }

            $address = trim($school['address']);
            if ($address === "") {
                $errors['address'] = 'Campo obbligatorio';
            } else {
                $school['address'] = $address;
            }

            $city = trim($school['city']);
            if ($city === "") {
                $errors['city'] = 'Campo obbligatorio';
            } else {
                $school['city'] = $city;
            }

            if (!$errors) {
                $check = $this->init->pgSelect('school', $school);

                if ($check === false) {
                    $this->init->pgInsert('school', $school);
                    $this->init->setSession('school_okay', true);

                    return $this->init->redirect('index.php?page=addschool');
                } else {
                    $errors['generic'] = 'Esiste già una scuola con gli stessi dati.';
                }
            }
        }

        return $this->render('addschool.html.twig', array('user' => $user, 'school' => $school, 'okay' => $okay, 'errors' => $errors));
    }

    public function myschoolAction() {
        $user = $this->init->checkLogin();

        $schoolrestult = pg_query($this->init->getConn(), 'SELECT * FROM school ORDER BY name ASC');
        $schools = pg_fetch_all($schoolrestult);

        $form = array('school_id' => '', 'year_begin' => '', 'year_end' => '');
        $okay = $this->init->getSession('myschool_okay', true);
        $errors = array();

        $remove = filter_input(INPUT_GET, 'remove');

        if ($remove) {
            pg_delete($this->init->getConn(), 'user_school', array('id' => $remove, 'user_id' => $user['id']));
        }

        $sql = 'SELECT s.*, us.id AS removeid, us.year_begin, us.year_end FROM user_school AS us JOIN school s ON us.school_id = s.id WHERE us.user_id = $1 ORDER BY us.year_begin DESC, us.year_end DESC, s."name" ASC';
        pg_prepare($this->init->getConn(), "", $sql);
        $myschoolsresult = pg_execute($this->init->getConn(), "", array($user['id']));

        $myschools = pg_fetch_all($myschoolsresult);

        if ($this->isPost()) {
            $post = filter_input_array(INPUT_POST);
            $form = array_merge($form, (array) $post['myschool']);

            if (!$form['school_id']) {
                $errors['school_id'] = 'Campo obbligatorio';
            }

            $year_begin = trim($form['year_begin']);
            if ($year_begin === "") {
                $errors['year_begin'] = 'Campo obbligatorio';
            } else {
                $year_begin = (int) $year_begin;

                $year_end = trim($form['year_end']);
                if ($year_end) {
                    if ((int) $year_end < $year_begin) {
                        $errors['year_end'] = "L'anno di fine non può essere minore dell'anno di inizio";
                    } else {
                        $form['year_end'] = (int) $year_end;
                    }
                }
            }

            if (!$errors) {
                $checkSchool = $form;
                $checkSchool['user_id'] = $user['id'];
                $check = $this->init->pgSelect('user_school', $checkSchool);
                if ($check === false) {
                    $this->init->pgInsert('user_school', $checkSchool);
                    $this->init->setSession('myschool_okay', true);

                    return $this->init->redirect('index.php?page=myschool');
                } else {
                    $errors['generic'] = 'Hai già frequentato questa scuola in questo stesso periodo';
                }
            }
        }

        return $this->render('myschool.html.twig', array('user' => $user, 'schools' => $schools, 'form' => $form, 'errors' => $errors, 'okay' => $okay, 'myschools' => $myschools));
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
