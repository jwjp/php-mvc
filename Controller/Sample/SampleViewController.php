<?php

namespace Controller\Sample;

use Controller\Controller;

class SampleViewController extends Controller
{
    public function index(): void
    {
        $variables = ['title' => 'Sample', 'content' => 'Sample Content'];
        $this->view('sample/index', $variables);
    }
}