<?php

use zozlak\util\ClassLoader;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\resolver\Resolver;

require_once __DIR__ . '/vendor/autoload.php';
$cl = new ClassLoader();

RC::init('config.ini');
Resolver::$debug = filter_input(\INPUT_GET, 'debug') ? true : false;

try {
    $resolver = new Resolver($host);
    $resolver->resolve();
} catch (Exception $e) {
    header('HTTP/1.1 ' . $e->getCode() . ' ' . $e->getMessage());
    echo $e->getMessage();
}
