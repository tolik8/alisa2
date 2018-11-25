<?php

namespace App\controllers;

class Home 
{
    protected $twig;
    protected $db;
    protected $myUser;

    public function __construct (\App\Twig $twig, \App\QueryBuilder $db, \App\MyUser $myUser)
    {
        $this->twig = $twig;
        $this->db = $db;
        $this->myUser = $myUser;
    }

    public function index ()
    {
        $this->twig->showTemplate('index.html', ['my' => $this->myUser]);
    }

    public function about ()
    {
        $this->twig->showTemplate('about.html');
    }

}