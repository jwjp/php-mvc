<?php

namespace Controller\Sample;

use Controller\Controller;

class TemporaryController extends Controller
{
    /**
     * @return void
     */
    function test(): void
    {
        var_dump($this->body);
    }
}