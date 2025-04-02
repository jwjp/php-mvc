<?php

namespace Controller\Sample;

use Controller\Controller;
use JetBrains\PhpStorm\NoReturn;

class SampleViewController extends Controller
{
    #[NoReturn] public function index(): void
    {
        $variables = ['title' => 'Sample', 'content' => 'Sample Content'];
        $this->view('sample/index', $variables);
    }
}