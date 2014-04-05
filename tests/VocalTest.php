<?php

namespace LakeDawson\Tests;

use LakeDawson\Tests\Models\Test;
use LakeDawson\Tests\Models\TestChild;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Standardise error responses
     *
     * @param string $string
     * @param object $model
     * @return string
     */
    protected function errorResponse($string, $model)
    {
        $error = $string . ': ';

        if (is_object($model))
        {
            // If we have errors, add them in
            if (count($model->errors())) $error .= print_r($model->errors(), true);

            // Add model in so we can see where we went wrong
            $error .= print_r($model->toArray(), true);
        }
        else $error .= $model;

        return $error;
    }

    /**
     * Define environment setup
     *
     * @param Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Use package src path as base
        $app['path.base'] = __DIR__ . '/../src';

        // Set up in memory database
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', array(
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ));
    }

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        // Turn off query log
        $db = $this->app->make('db');
        $db->connection()->disableQueryLog();

        // Migrate fresh copy of database
        $artisan = $this->app->make('artisan');
        $artisan->call('migrate', array('--database' => 'testbench', '--path' => '../tests/Migrations'));
    }

    /**
     * Run tests
     *
     * @return void
     */
    public function testVocal()
    {
        $input = $this->app->make('request');

        // Create a record with some children
        $input->replace(array(
            'description' => 'Parent',
            'children'    => array(
                array('description' => 'Child 1'),
                array('description' => ''),
            )
        ));

        $test = new Test;
        $result = $test->validateRecursive();

        // Make sure validation failed due to second child not having a description
        $this->assertFalse($result, $this->errorResponse('Validation should have failed', $test));

        // Fix error and attempt to save
        $input->replace(array(
            'description' => 'Parent',
            'children'    => array(
                array('description' => 'Child 1'),
                array('description' => 'Child 2'),
            )
        ));

        $test = new Test;
        $result = $test->saveRecursive();

        // Make sure validation passed
        $this->assertTrue($result, $this->errorResponse('Record was not saved', $test));

        // Check child records
        foreach ($test->children as $child)
        {
            $this->assertTrue(strpos($child->description, 'Child') === 0, $this->errorResponse('Child record was not saved correctly', $child));
        }

        // Update second relationship and save again
        $input->replace(array(
            'id'          => 1,
            'description' => 'Parent',
            'children'    => array(
                array('id' => 1, 'description' => 'Child 1'),
                array('id' => 2, 'description' => 'Child X'),
            )
        ));

        $result = $test->saveRecursive();

        // Check save was successful
        $this->assertTrue($result, $this->errorResponse('Record was not updated', $test));

        // Make sure child was updated
        $this->assertTrue($test->children[1]->description == 'Child X', $this->errorResponse('Child record was not updated correctly', $test->children[1]));

        // Test saving non-recursive
        $input->replace(array(
            'description' => 'Non-recursive'
        ));

        $test = new Test;
        $result = $test->save();

        // Check save was successful
        $this->assertTrue($result, $this->errorResponse('Record was not saved', $test));
    }
}
