<?php

namespace Controller\Sample;

use Controller\Controller;
use Model\Sample\SampleModel;

use Exception;

class SampleController extends Controller
{
    /**
     * @return false|int
     * @throws Exception
     */
    public function createFoo(): false|int
    {
        $sample = new SampleModel();
        return $sample->createFoo($this->variables['message']);
    }

    /**
     * @return false|array
     * @throws Exception
     */
    public function readFooById(): false|array
    {
        $sample = new SampleModel();
        return $sample->readFooById($this->parameters['fooId']);
    }

    /**
     * @return array|false
     */
    public function readFooAll(): array|false
    {
        $sample = new SampleModel();
        return $sample->readFooAll();
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function updateFooById(): false|int
    {
        $sample = new SampleModel();
        return $sample->updateFooById($this->variables['message'], $this->parameters['id']);
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function deleteFooById(): false|int
    {
        $sample = new SampleModel();
        return $sample->deleteFooById($this->parameters['id']);
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function createBarWithFooId(): false|int
    {
        $sample = new SampleModel();
        return $sample->createBarWithFooId($this->variables['comment'], $this->variables['fooId']);
    }

    /**
     * @return false|array
     * @throws Exception
     */
    public function readBarById(): false|array
    {
        $sample = new SampleModel();
        return $sample->readBarById($this->parameters['id']);
    }

    /**
     * @return false|array
     */
    public function readBarAll(): false|array
    {
        $sample = new SampleModel();
        return $sample->readBarAll();
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function updateBarById(): false|int
    {
        $sample = new SampleModel();
        return $sample->updateBarById($this->variables['comment'], $this->parameters['id']);
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function deleteBarById(): false|int
    {
        $sample = new SampleModel();
        return $sample->deleteBarById($this->parameters['id']);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function readFooBarByFooIdWithQB(): array
    {
        $sample = new SampleModel();
        return $sample->readFooBarByFooIdWithQB($this->parameters['fooId']);
    }

    /**
     * @return false|int|array|string
     * @throws Exception
     */
    public function createFooWithQB(): false|int|array|string
    {
        $sample = new SampleModel();
        return $sample->createFooWithQB($this->variables['message']);
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function createFooBarWithQB(): bool
    {
        $sample = new SampleModel();
        return $sample->createFooBarWithQB($this->variables['fooMessage'], $this->variables['barComment']);
    }

    /**
     * @return false|int|array|string
     * @throws Exception
     */
    public function deleteFooByIdWithQB(): false|int|array|string
    {
        $sample = new SampleModel();
        return $sample->deleteFooByIdWithQB($this->parameters['fooId']);
    }
}