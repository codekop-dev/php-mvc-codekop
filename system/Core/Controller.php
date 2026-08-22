<?php

declare(strict_types=1);

namespace System;

defined('BASEPATH') || exit('No direct script access allowed');

class Controller
{
    protected ?\PDO $db = null;
    protected Views $show;
    protected Session $session;
    protected Input $input;
    protected Crud $crud;
    protected Security $security;
    protected Views $load;
    protected Request $request;
    protected Response $response;

    public function __construct()
    {
        $db = new Database();
        $this->db = $db->connect();
        $this->show = new Views();
        $this->load = $this->show;
        $this->session = new Session();
        $this->input = new Input();
        $this->crud = new Crud();
        $this->session->session_on();
        $this->security = new Security();
        $this->request = new Request();
        $this->response = new Response();
    }

    public function model($model)
    {
        $requested = ltrim((string) $model, '\\');
        $class = str_starts_with($requested, 'App\\')
            ? $requested
            : 'App\\Models\\' . $requested;
        $shortName = basename(str_replace('\\', '/', $class));
        $modelPrefix = 'App\\Models\\';
        $relativeClass = str_starts_with($class, $modelPrefix)
            ? substr($class, strlen($modelPrefix))
            : $shortName;
        $file = ROOTPATH . 'app/Models/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (!is_file($file)) {
            $file = ROOTPATH . 'app/Models/' . $shortName . '.php';
        }
        require_once $file;
        if (!class_exists($class) && class_exists((string) $model)) {
            $class = (string) $model;
        }
        return new $class();
    }

}
