<?php

namespace Cartbeforehorse\DbModels;

use Illuminate\Database\Eloquent\Model as EloquentModel;

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
    protected $tableAlias;                // allows us to alias a table, either explicitly or with the "as" keywork in $table

    public function __construct (array $attributes = []) {
        $this->_bootTrait();
        parent::__construct ($attributes);
    }

}
