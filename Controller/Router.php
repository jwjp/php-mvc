<?php

namespace Controller;

use Controller\Sample\SampleController;
use Controller\Sample\SampleViewController;

class Router extends RouterImpl
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function post(): static
    {
        // $this->execute('/foo', SampleController::class, 'createFoo');
        $this->execute('/foo', SampleController::class, 'createFooWithQB');
        $this->execute('/bar', SampleController::class, 'createBarWithFooId');
        $this->execute('/foobar', SampleController::class, 'createFooBarWithQB');

        return $this;
    }

    protected function get(): static
    {
        $this->execute('/foo', SampleController::class, 'readFooAll');
        $this->execute('/foo/{fooId}', SampleController::class, 'readFooById');
        $this->execute('/bar', SampleController::class, 'readBarAll');
        $this->execute('/bar/{barId}', SampleController::class, 'readBarById');
        $this->execute('/foobar/{fooId}', SampleController::class, 'readFooBarByFooIdWithQB');

        $this->execute('/sample/view', SampleViewController::class, 'index');

        return $this;
    }

    protected function patch(): static
    {
        return $this;
    }

    protected function put(): static
    {
        $this->execute('/foo/{fooId}', SampleController::class, 'updateFooById');
        $this->execute('/bar/{barId}', SampleController::class, 'updateBarById');

        return $this;
    }

    protected function delete(): static
    {
        // $this->execute('/foo/{fooId}', SampleController::class, 'deleteFooById');
        $this->execute('/foo/{fooId}', SampleController::class, 'deleteFooByIdWithQB');
        $this->execute('/bar/{barId}', SampleController::class, 'deleteBarById');

        return $this;
    }
}