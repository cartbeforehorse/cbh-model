<?php

namespace Cartbeforehorse\DbModels;

use Illuminate\Contracts\Support\MessageProvider;
use Watson\Validating\ValidatingInterface as iWatsonValidation;

/**
 *  CbhModelFsm{}
 *     Extends the base CbmModel{} class, but adds in the Finite State Machine
 *     trait which was also created (with a little bit of opensource community
 *     help) by CBH Software
 *
 *  @author Osian ap Garth / CBH Software
 */
class CbhModelFsm extends CbhModel implements MessageProvider, iWatsonValidation {

    /***
     * Here we add in the trait so that other classes can extend from this one
     */
    use tCbhModelFsm;

    /*
     * And that's it folks!
     ***/

}
