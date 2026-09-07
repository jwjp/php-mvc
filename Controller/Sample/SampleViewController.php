<?php

namespace Controller\Sample;

use Controller\Controller;
use Controller\Csrf;
use Controller\Response;

class SampleViewController extends Controller
{
    public function index(): Response
    {
        return $this->view('sample/index', ['title' => 'PHP MVC', 'csrfToken' => Csrf::token()]);
    }

    public function csrfToken(): array
    {
        return ['token' => Csrf::token()];
    }
}
