<?php

namespace Controller\Sample;

use Controller\Controller;
use Model\Sample\SampleModel;

use Exception;

class SampleController extends Controller
{
    /**
     * @return void
     * @throws Exception
     */
    public function createFoo(): void
    {
        $sample = new SampleModel();
        $result = $sample->createFoo($this->body['message']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function readFooById(): void
    {
        $sample = new SampleModel();
        $foo = $sample->readFooById($this->param['fooId']);

        echo json_encode($foo, JSON_UNESCAPED_UNICODE);

        echo '<br/>';
        echo '<br/>';

        echo json_encode([
            'class' => 'SampleController',
            'function' => 'readFooById()',
            'body-content' => $this->body,
            'parameters' => $this->param
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return void
     */
    public function readFooAll(): void
    {
        $sample = new SampleModel();
        $foo = $sample->readFooAll();

        echo json_encode($foo, JSON_UNESCAPED_UNICODE);

        echo '<br/>';
        echo '<br/>';

        echo json_encode([
            'class' => 'SampleController',
            'function' => 'readFooAll()',
            'body-content' => $this->body,
            'parameters' => $this->param
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function updateFooById(): void
    {
        $sample = new SampleModel();
        $result = $sample->updateFooById($this->body['message'], $this->param['id']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function deleteFooById(): void
    {
        $sample = new SampleModel();
        $result = $sample->deleteFooById($this->param['id']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function createBarWithFooId(): void
    {
        $sample = new SampleModel();
        $result = $sample->createBarWithFooId($this->body['comment'], $this->body['fooId']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function readBarById(): void
    {
        $sample = new SampleModel();
        $bar = $sample->readBarById($this->param['id']);

        echo json_encode($bar, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return void
     */
    public function readBarAll(): void
    {
        $sample = new SampleModel();
        $bar = $sample->readBarAll();

        echo json_encode($bar, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function updateBarById(): void
    {
        $sample = new SampleModel();
        $result = $sample->updateBarById($this->body['comment'], $this->param['id']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function deleteBarById(): void
    {
        $sample = new SampleModel();
        $result = $sample->deleteBarById($this->param['id']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function readFooBarByFooIdWithQB(): void
    {
        $sample = new SampleModel();
        $result = $sample->readFooBarByFooIdWithQB($this->param['fooId']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function createFooWithQB(): void
    {
        $sample = new SampleModel();
        $result = $sample->createFooWithQB($this->body['message']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function createFooBarWithQB(): void
    {
        $sample = new SampleModel();
        $result = $sample->createFooBarWithQB($this->body['fooMessage'], $this->body['barComment']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function deleteFooByIdWithQB(): void
    {
        $sample = new SampleModel();
        $result = $sample->deleteFooByIdWithQB($this->param['fooId']);

        echo '<pre>';
        echo '<h4>$result : </h4>';
        var_dump($result);
        echo '</pre>';
    }
}