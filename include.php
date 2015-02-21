<?php

ini_set('display_errors', true);
error_reporting(E_ALL);

spl_autoload_register(function ($class) {
    if (is_file($file = 'class/' . str_replace(array('_', "\0"), array('/', ''), $class) . '.php')) {
        require $file;
    } elseif (is_file($file = 'lib/' . str_replace(array('_', "\0"), array('/', ''), $class) . '.php')) {
        require $file;
    }
});
$init = Init::getInstance();



