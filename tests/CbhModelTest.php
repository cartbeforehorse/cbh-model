<?php

namespace Cartbeforehorse\DbModels;

use \PHPUnit\Framework\TestCase;

/**
 *  Corresponding Class to test Cartbeforehorse\DbModels\CbhModel
 *  @author Osian ap Garth / CBH Software
 */
class CbhModelTest extends TestCase {

    /**
     * Dummy check to make sure we can create a Model Object
     */
    public function testCanCreateModel() {
        $var = new CbhModel;
        $this->assertTrue (is_object($var));
        unset($var);
    }

    /**
     * xyz
     */
    public function testMethod1() {
        $a = 1 + 3;
        $this->assertTrue (4 == $a);
        //$var = new CbhModel;
        //$this->assertTrue ($var->method1("hey") == 'Hello World');
        //unset($var);
    }

}
