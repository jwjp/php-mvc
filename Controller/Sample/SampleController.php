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
        $sample = new SampleModel($this->environments);
        return $sample->createFoo($this->stringInput('message'));
    }

    /**
     * @return false|array
     * @throws Exception
     */
    public function readFooById(): false|array
    {
        $sample = new SampleModel($this->environments);
        return $sample->readFooById($this->parameter('fooId'));
    }

    /**
     * @return array|false
     */
    public function readFooAll(): array|false
    {
        $sample = new SampleModel($this->environments);
        return $sample->readFooAll();
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function updateFooById(): false|int
    {
        $sample = new SampleModel($this->environments);
        return $sample->updateFooById($this->stringInput('message'), $this->parameter('fooId'));
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function deleteFooById(): false|int
    {
        $sample = new SampleModel($this->environments);
        return $sample->deleteFooById($this->parameter('fooId'));
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function createBarWithFooId(): false|int
    {
        $sample = new SampleModel($this->environments);
        return $sample->createBarWithFooId($this->stringInput('comment'), $this->integerInput('fooId'));
    }

    /**
     * @return false|array
     * @throws Exception
     */
    public function readBarById(): false|array
    {
        $sample = new SampleModel($this->environments);
        return $sample->readBarById($this->parameter('barId'));
    }

    /**
     * @return false|array
     */
    public function readBarAll(): false|array
    {
        $sample = new SampleModel($this->environments);
        return $sample->readBarAll();
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function updateBarById(): false|int
    {
        $sample = new SampleModel($this->environments);
        return $sample->updateBarById($this->stringInput('comment'), $this->parameter('barId'));
    }

    /**
     * @return false|int
     * @throws Exception
     */
    public function deleteBarById(): false|int
    {
        $sample = new SampleModel($this->environments);
        return $sample->deleteBarById($this->parameter('barId'));
    }

    /**
     * @return array
     * @throws Exception
     */
    public function readFooBarByFooIdWithQB(): array
    {
        $sample = new SampleModel($this->environments);
        return $sample->readFooBarByFooIdWithQB($this->parameter('fooId'));
    }

    /**
     * @return false|int|array|string
     * @throws Exception
     */
    public function createFooWithQB(): false|int|array|string
    {
        $sample = new SampleModel($this->environments);
        return $sample->createFooWithQB($this->stringInput('message'));
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function createFooBarWithQB(): bool
    {
        $sample = new SampleModel($this->environments);
        return $sample->createFooBarWithQB($this->stringInput('fooMessage'), $this->stringInput('barComment'));
    }

    /**
     * @return false|int|array|string
     * @throws Exception
     */
    public function deleteFooByIdWithQB(): false|int|array|string
    {
        $sample = new SampleModel($this->environments);
        return $sample->deleteFooByIdWithQB($this->parameter('fooId'));
    }
}
