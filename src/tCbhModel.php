<?php

namespace Cartbeforehorse\DbModels;

use Cartbeforehorse\DbModels\sqlConditions\WhereCondition;
use Cartbeforehorse\DbModels\Builders\CbhBuilder;
use Cartbeforehorse\Validation\ValidationSys;
use Cartbeforehorse\Validation\CodingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression as RawExpression;
use Watson\Validating\ValidatingTrait as tWatsonValidation;

/**
 *  CbhModel{}
 *     Extends the underlying Laravel Eloquent/Model class, and rolls-in some bug fixes
 *     (or functional improvements to the base class) together with the best extensions
 *     already out there in the Composer/Laravel ecosystem.
 *
 *  @author Osian ap Garth / CBH Software
 */
trait tCbhModel {

    /**
     * Watson's validating functionality allows us to define constraints on data that is entered in colunms
     * @see \Watson\Validating\ValidatingTrait;
     * @see https://laravel.com/docs/5.5/validation#available-validation-rules
     */
    use tWatsonValidation;

    protected $table;       // overriding that of the Eloquent model
    protected $tableAlias;  // allows us to alias a table, either explicitly or with the "as" keywork in $table

    // A new array called "col_settings" is defined here.  It is (ahem) the only option
    // that needs to be set in the Model definition file, and during _construct()ion it
    // will hydrate all of the subsequent arrays.  Of those subsequent arrays, some are
    // already a part of the Eloquent Model base classes, while others are defined anew
    // here.
    // The $col_settings array is built to have the column-name serve as the index, and
    // a string of configurable settings serve in the value.  The string may consist of
    // the following, separated by pipes:
    //    type:x   x being of the types: string, integer, real, float, double, boolean
    //             date, datetime, timestamp, object, array, collection, json
    //    select   The column should be included in the SELECT statement by default
    //    ro       The column is read-only, preventing update via update function
    //    expr:y   y being a valid database expression that can pull data, such as an
    //             Oracle API call. If not defined, then 
    //
    protected $col_settings  = [];
    protected $select_cols   = [];
    protected $visible_cols  = [];
    protected $expressions   = [];
    protected $casts         = [];


    // additional variable to store the clean user's search
    protected $usr_srch = [];

    // following variables required by WatsonValidation
    protected $rules    = [];
    protected $rulesets = [];


    /***
     * This is effectively the constructor that needs to be called from the Model
     */
    protected function _bootTrait() {

        if ( preg_match('/^\w+\s+as\s+(\w+)$/', $this->table, $out) ) {
            $this->tableAlias = $out[1];
        }

        foreach ($this->col_settings as $colid => $col_setup) {

            $col_setup = trim ($col_setup, '|') . '|';

            if ( strpos($col_setup,'pk|') !== false ) {
                $this->primaryKey[] = $colid;
            }
            if ( strpos($col_setup,'select|') !== false ) {
                $this->select_cols[] = $colid;
            }
            if ( strpos($col_setup,'vis|') !== false ) {
                $this->visible_cols[] = $colid;
            }
            $this->expressions[$colid] = preg_match('/expr:([^\|]*)\|/',$col_setup,$val) ?  new RawExpression("{$val[1]} as $colid") : $colid;
            $this->casts[$colid]       = preg_match('/type:([^\|]*)\|/',$col_setup,$val) ? $val[1] : 'string';

        }

    }


    /**
     * Override the standard Builder with my version...
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Cartbeforehorse\DbModels\Builders\CbhBuilder which extends \Illuminate\Database\Eloquent\Builder|static
     */
    public function newEloquentBuilder ($query)
    {
        return new CbhBuilder ($query, array_values(array_intersect_key($this->expressions, array_flip($this->select_cols))) );
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
    public function getKeyName() {
        if ( is_string($this->primaryKey) ) {
            return $this->primaryKey;
        } elseif ( is_array($this->primaryKey) && count($this->primaryKey)==1 ) {
            return $this->primaryKey[0];
        } else {
            return $this->primaryKey;
            //return '!!what do we do here??';
        }
    }
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


    /***
     * Simple scopes
     */
    public function scopeFetchByPk ($builder, array $key_values)
    {
        // check that the given values are indeed Primary Key
        ValidationSys::ArrayKeysEqual (array_flip($this->primaryKey), $key_values, true);

        foreach ($this->primaryKey as $key_col) {
            $builder -> where ($key_col, $key_values[$key_col]);
        }
        return $builder;
    }

    public function scopeFindWithExpressionCols ($builder, $id) {
        // parameter 2 is effectively your SELECT list
        return parent::find ($id, array_values($this->expressions));
    }

    /*******
     * scopeProcessUserSearch()
     * getColType()
     * dynamicWhere()
     *     The following section deals with user-searching capabilities.  As all us good developers
     *     well know, the only guaranteed ingredient to spoil what would otherwise be a perfect and
     *     fluent working application, is the intervention of dumb-ass end users.  They get on with
     *     their lives making it their business to enter all manner of impossible, invalid, corrupt
     *     and (quite frankly) malicious data, in what can only be described as a deliberate effort
     *     to sabotage all our development efforts.
     *     Which brings us back to the purpose of this section.  When users send us query-input for
     *     their data, we have to make sure it is tidy, and when it isn't tidy, we need to clean it
     *     up so that it is.  Starting here are the functions which bare the responsibility for all
     *     that workload, and they start quite simply, by receiving an array of filters for each of
     *     the model's columns.
     *
     *     Please always remember the following principles:
     *       -> One should keep in mind that the way we handle each search criterion depends on the
     *          data-type of the value we are rearching on.
     *       -> Above all remember the Golden Rule: never allow a direct-injection of a user search
     *          value into the SQL query string - for example through the use of DB::raw().
     *
     */
    protected function cleanSearchString ($str) {
        $str = trim ($str, ';|');
        $str = preg_replace ('/\|\|+/', '|', $str);
        $str = preg_replace ('/[\|;][\|;]+/', ';', $str);
        return $str;
    }
    protected function getColType ($col) {
        if ( !isset($this->casts[$col]) ) {
            CodingError::RaiseCodingError ("Column '$col' needs to be defined in casts array in " . get_class($this));
        } else {
            if ( ValidationSys::InArray ($this->casts[$col], ['integer','real','float','double']) )
                return 'number';
            elseif ( $this->casts[$col] == 'boolean' )
                return 'boolean';
            elseif ( ValidationSys::InArray ($this->casts[$col], ['date','datetime','timestamp']) )
                return 'date';
            elseif ( ValidationSys::InArray ($this->casts[$col], ['string']) )
                return 'string';
            elseif ( ValidationSys::InArray ($this->casts[$col], ['object','array','collection']) )
                CodingError::RaiseCodingError ("Data Type [{$this->casts[$col]}] not managed in " . __METHOD__);
            else
                CodingError::RaiseCodingError (
                    "Unknown Type [{$this->casts[$col]}] on column $col, object " . get_class($this) . ", in " . __METHOD__ .
                    "  Valid types are [integer, real, float, double, boolean, date, datetime, timestamp, string]"
                );
        }
    }

    public function scopeProcessUserSearch (Builder $builder, array $usr_srch_arr) {

        //
        // 1. Loop on each column to collect data that the user is really searching on
        // 2. Remove excessive ; and | characters
        // 3. If anything remains to search on, split on the ; and | characters
        // 4. We clean each search element, and rebuild an "executable" search string
        // 5. Using th executable search string, we build the SQL through the $builder object
        //
        foreach ($usr_srch_arr as $col => $srch) {

            $this->usr_srch[$col]['original_search'] = $srch;
            $this->usr_srch[$col]['clean_search']    = $this->cleanSearchString ($srch);
            $this->usr_srch[$col]['executed_search'] = [];

            if (ValidationSys::IsNonEmptyString ($this->usr_srch[$col]['clean_search'])) {
                // loop on each 'OR' condition
                foreach (explode (';', $this->usr_srch[$col]['clean_search']) as $nr => $col_srch) {
                    // loop on each 'AND' condition
                    foreach (explode ('|', $col_srch) as $key => $val) {
                        $this->usr_srch[$col]['srch_obj'][$nr][$key]        = new WhereCondition ($col, $this->getColType($col), explode ('..', $val));
                        $this->usr_srch[$col]['executed_search'][$nr][$key] = $this->usr_srch[$col]['srch_obj'][$nr][$key]->getCleanUserSearchString();
                    }
                }

                // implode the clean search that we are about to execute so that we can return it to the client if required
                foreach ( $this->usr_srch[$col]['executed_search'] as $ix => $and_arr ) {
                    $this->usr_srch[$col]['executed_search'][$ix] = ValidationSys::ImplodeIgnoringNulls ('|', $and_arr);
                }
                $this->usr_srch[$col]['executed_search'] = ValidationSys::ImplodeIgnoringNulls (';', $this->usr_srch[$col]['executed_search']);


                //
                // From here on, we build the actual SQL query on the $builder object
                //

                // if only AND conditions exist on the column, then no need to nest
                if ( count($this->usr_srch[$col]['srch_obj'])==1 ) {
                    foreach ($this->usr_srch[$col]['srch_obj'][0] as $obj) {
                        $this->dynamicWhere ($builder, $obj);
                    }
                }
                // else add column-search to a sub AND condition
                else {
                    $srch = $this->usr_srch[$col];
                    $builder -> where (function ($builder) use ($srch) {
                        // then loop for each OR condition
                        foreach ($srch['srch_obj'] as $l2_and_arr) {
                            // but only nest it if there are multiple conditions
                            if ( count($l2_and_arr)==1 ) {
                                $obj = $l2_and_arr[0];
                                $this->dynamicWhere ($builder, $obj, 'or');
                            } else {
                                $builder -> orWhere (function ($builder) use ($l2_and_arr) {
                                    foreach ($l2_and_arr as $obj) {
                                        $this->dynamicWhere ($builder, $obj);
                                    }
                                });
                            }
                        }
                    });
                }

            }//if
        }//foreach ($col)

        return $builder;

    }

    protected function dynamicWhere (Builder &$builder, WhereCondition $obj, $logical_join ='and') {
        if ($obj->valid) {
            $conditionFn = $obj->condition;
            switch ($obj->condition) {
                case 'whereNull':
                case 'whereNotNull':
                    $builder -> $conditionFn ($obj->column, $logical_join);
                    break;
                case 'whereBetween':
                case 'whereNotBetween':
                    $builder -> $conditionFn ($obj->column, $obj->getBetweenVals(), $logical_join);
                    break;
                default:
                    $builder -> where ($obj->column, $obj->condition, $obj->getSqlVal(), $logical_join);
                    break;
            }
        }
    }


    // getters
    /**
     * getMessageBag()
     *     I found the following function in a Watson library, but it's quite useful in general and
     *     doesn't depend on Watson's work in any way.
     */
    public function getMessageBag() {
        return $this->getErrors();
    }
    public function getUserSearch() {
        return $this->usr_srch;
    }
    public function getSelectCols() {
        return $this->select_cols;
    }

    // setters
    public function wipeUserSearch () {
        $this->usr_srch = [];
    }

}
