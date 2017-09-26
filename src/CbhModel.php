<?php

namespace Cartbeforehorse\DbModels;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Contracts\Support\MessageProvider;
use Watson\Validating\ValidatingTrait as tWatsonValidation;
use Watson\Validating\ValidatingInterface as iWatsonValidation;

/**
 *  CbhModel{}
 *     Extends the underlying Laravel Eloquent/Model class, and rolls-in some bug fixes
 *     (or functional improvements to the base class) together with the best extensions
 *     already out there in the Composer/Laravel ecosystem.
 *
 *  @author Osian ap Garth / CBH Software
 */
class CbhModel extends Eloquent implements MessageProvider, iWatsonValidation {

    /**
     * Watson's validating functionality allows us to define constraints on data that is entered in colunms
     * @see \Watson\Validating\ValidatingTrait;
     * @see https://laravel.com/docs/5.5/validation#available-validation-rules
     */
    use tWatsonValidation;

    public    $incrementing   = false;    // why Eloquent would ever set to true is beyond me
    protected $primaryKey     = [];       // CbhModel allows the extending class to define a string or an array of strings
    protected $tableAlias;                // allows us to alias a table, either explicitly or with the "as" keywork in $table

    // following variables required by WatsonValidation
    protected $rules          = [];
    protected $rulesets       = [];


    public function __construct (array $attributes = []) {
        parent::__construct ($attributes);
        if ( preg_match('/^\w+\s+as\s+(\w+)$/', $this->table, $out) ) {
            $this->tableAlias = $out[1];
        }
    }


    /******
     * setKeysForSaveQuery()
     * getKeyForSaveQuery()
     *     A tragic limitation of the base Eloquent framework is that it allows us to define only a
     *     single column as a primaryKey.  Of course for any real-world scenario this constraint is
     *     far too limiting, and we therefore extend the base class by overriding the following two
     *     functions. The original code (and more background) can be found at:
     *         https://stackoverflow.com/questions/36332005/laravel-model-with-two-primary-keys-update
     *         https://github.com/laravel/framework/issues/5355
     **/
    protected function setKeysForSaveQuery (Builder $query) {
        $keys = $this->getKeyName();
        if (!is_array($keys)) {
            return parent::setKeysForSaveQuery ($query);
        }
        foreach ($keys as $keyName) {
            $query -> where ($keyName, '=', $this->getKeyForSaveQuery($keyName));
        }
        return $query;
    }
    protected function getKeyForSaveQuery ($keyName = null) {
        if (is_null($keyName)) {
            $keyName = $this->getKeyName();
        }
        if (isset($this->original[$keyName])) {
            return $this->original[$keyName];
        }
        return $this->getAttribute ($keyName);
    }

    /******
     * getQualifiedKeyName()
     *     In some circumstances, it's very very useful to be able to deinfe an alias for the table
     *     named in the $this->table variable.  It allows us to do clever SQL manipulation, to nest
     *     SELECT queries inside one-another, and other quirky bits and bobs.  Unfortunately and as
     *     is so often the case, the base Eloquent model doesn't fully-support "thinking outside of
     *     the box", and so we overwrite the base functions to make it work the way we want.
     */
    public function getQualifiedKeyName()
    {
        if ( empty($this->tableAlias) ) {
            return $this->getTable() . '.' . $this->getKeyName();
        } else {
            return $this->tableAlias . '.' . $this->getKeyName();
        }
    }



    /**
     * getMessageBag()
     *     I found the following function in a Watson library, but it's quite useful in general and
     *     doesn't depend on Watson's work in any way.
     */
    public function getMessageBag() {
        return $this->getErrors();
    }

}
