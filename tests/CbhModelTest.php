<?php

namespace Cartbeforehorse\DbModels;

use \PHPUnit_Framework_TestCase;

/**
 *  Corresponding Class to test Cartbeforehorse\DbModels\CbhModel
 *  @author Osian ap Garth / CBH Software
 */
class CbhModelTest extends PHPUnit_Framework_TestCase {

    /**
     * Check if the CbhModel has no syntax error 
     *
     * This is just a simple check to make sure your library has no syntax error. This helps you troubleshoot
     * any typo before you even use this library in a real project.
     *
     */
    public function testIsThereAnySyntaxError() {
        $var = new CbhModel;
        $this->assertTrue (is_object($var));
        unset($var);
    }

    /**
     * Just check if the YourClass has no syntax error 
     *
     * This is just a simple check to make sure your library has no syntax error. This helps you troubleshoot
     * any typo before you even use this library in a real project.
     *
     */
    public function testMethod1() {
        $var = new CbhModel;
        $this->assertTrue ($var->method1("hey") == 'Hello World');
        unset($var);
    }

}
