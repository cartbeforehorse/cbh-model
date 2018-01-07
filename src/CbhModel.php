<?php

namespace Cartbeforehorse\DbModels;

use Illuminate\Contracts\Support\MessageProvider;
use Illuminate\Database\Eloquent\Model    as EloquentModel;
use Watson\Validating\ValidatingInterface as iWatsonValidation;

/**
 *  CbhModel{}
 *     Extends the underlying Laravel Eloquent/Model class, and rolls-in some bug fixes
 *     (or functional improvements to the base class) together with the best extensions
 *     already out there in the Composer/Laravel ecosystem.
 *
 *  @author Osian ap Garth / CBH Software
 */
class CbhModel extends EloquentModel implements MessageProvider, iWatsonValidation {

    /***
     * The trait of the CbhModel added here for validation and user-search purposes. We
     * must also set extending variables in the class, since doing so in the trait will
     * cause errors that PHP doesn't like.
     */
    use tCbhModel;

    public    $incrementing   = false;    // why Eloquent would ever set the default to true is beyond me
    protected $primaryKey     = [];       // CbhModel allows the extending class to define a string or an array of strings

    public function __construct (array $attributes = []) {
        $this->_bootTrait();
        parent::__construct ($attributes);
    }

}
