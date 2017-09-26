<?php

namespace Cartbeforehorse\DbModels;

/**
 *  CbhModel{}
 *     Extends the underlying Laravel Eloquent/Model class, and rolls-in some bug fixes
 *     (or functional improvements to the base class) together with the best extensions
 *     already out there in the Composer/Laravel ecosystem.
 *
 *  @author Osian ap Garth / CBH Software
 */
class CbhModel {

    /**  @var string $m_SampleProperty define here what this variable is for, do this for every instance variable */
    private $m_SampleProperty = '';

    /**
     * May this comment say something useful, rather than just take up vertical space and
     * render the actual code unreadable.
     *
     * @param string $param1 A string containing the parameter, do this for each parameter to the function, make sure to make it descriptive
     * @return string
     *
     */
    public function method1 ($param1) {
        return "Hello World";
    }
}
