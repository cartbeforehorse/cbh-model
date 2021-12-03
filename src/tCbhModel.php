<?php

namespace Cartbeforehorse\DbModels;

use Cartbeforehorse\DbModels\sqlConditions\WhereCondition;
use Cartbeforehorse\DbModels\Builders\CbhBuilder;
use Cartbeforehorse\Validation\ValidationSys;
use Cartbeforehorse\Validation\CodingError;
use Illuminate\Support\Str;
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
    protected $tableAlias;  // allows us to alias a table, either explicitly or with the "as" keyword in $table

    //
    // When using this trait, "col_settings" is the only property that we need
    // to set in the eventual Eloquent model.  The trait initializer populates
    // the subsequent properties from the values we set in the first.  Some of
    // these properties are already a part of the Eloquent model, while others
    // are defined anew.
    // "$col_settings" has the column-name as its index, with a pipe-delimited
    // string to define their settings.  The settings may consist of:
    //    type:x   Must be a valid cast as in the docs:
    //               https://laravel.com/docs/8.x/eloquent-mutators#attribute-casting
    //    select   The column will be included in the model's SELECT statement
    //    ro       The column is read-only, preventing its update via update()
    //    expr:y   y is a valid database expression, like an Oracle API call
    //
    protected $col_settings = [];
    protected $select_cols  = [];
    protected $visible_cols = [];
    protected $expressions  = [];
    protected $casts        = [];


    // store the user's search - original, clean, executed
    protected $usr_srch = [];

    // following variables required by WatsonValidation
    protected $rules    = [];
    protected $rulesets = [];


    /***
     * initialize{traitName}() is like a constructor for Eloquent traits which
     * gets called automatically by the Eloquent base-class
     */
    protected function initializetCbhModel() {

        $this->incrementing = false; // why Eloquent sets this true is beyond me
        $this->primaryKey   = [];

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
            $this->expressions[$colid] = preg_match('/expr:([^\|]*)\|/',$col_setup,$val) ? new RawExpression("{$val[1]} as $colid") : $colid;
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
        return new CbhBuilder ($query, $this->getSelectExpr());
    }

    /******
     * getKey()
     * getKeyName()
     * setKeysForSaveQuery()
     * getKeyForSaveQuery()
     *   A harsh limitation of the Eloquent framework is that it doesn't allow
     *   multi-column primaryKeys.  For any real-world app, this limitation is
     *   crippling, and so the following few functions try to make good on the
     *   the otherwise farily-good Eloquent ORM.  More background at:
     *     https://stackoverflow.com/questions/36332005/laravel-model-with-two-primary-keys-update
     *     https://github.com/laravel/framework/issues/5355
     **/

    protected function stringifyPk() {
        if ( is_array($this->primaryKey) && count($this->primaryKey)==1 ) {
            return $this->primaryKey[0];
        }
        return $this->primaryKey;
    }

    /**
     * Get the PK value for a select query.  This override function knows that
     * the key can be an array.
     *
     * @return mixed
     */
    public function getKeyName ($as_array = false) {

        $pk = $this->stringifyPk();

        if ( is_array($pk) && !$as_array ) {
            $key_str = '';
            foreach ($pk as $col_id) {
                $key_str .= $col_id . '^';
            }
            return $key_str;
        }
        return $pk;
    }

    /**
     * Get the value of the model's primary key.  Again, this override version
     * is aware of composite primary keys.
     *
     * @return mixed
     */
    public function getKey ($as_array = false) {

        $pk = $this->stringifyPk();

        if ( is_array($pk) && $as_array ) {
            $key_array = [];
            foreach ($pk as $col_name) {
                $key_array[] = $this->getAttribute($col_name);
            }
            return $key_array;
        } elseif ( is_array($pk) && !$as_array ) {
            $key_str = '';
            foreach ($pk as $col_id) {
                $key_str .= $this->getAttribute($col_id) . '^';
            }
            return $key_str;
        }
        return $this->getAttribute($pk); // $pk is a string
    }

    /**
     * Set the keys for a save update query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query) {
        $keys = $this->getKeyName(true);
        if (!is_array($keys)) {
            return parent::setKeysForSaveQuery ($query);
        }
        foreach ($keys as $keyName) {
            $query -> where ($keyName, '=', $this->getKeyForSaveQuery($keyName));
        }
        return $query;
    }

    /**
     * Get the primary key value for a save query.
     *
     * @return mixed
     */
    protected function getKeyForSaveQuery ($keyName = null) {
        if (is_null($keyName)) {
            $keyName = $this->getKeyName();
        }
        return $this->original[$keyName] ?? $this->getAttribute($keyName);
    }

    /**
     * Qualify the given column name by the model's table or table-alias
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column) {
        if (Str::contains($column, '.')) {
            return $column;
        } elseif ( !empty($this->tableAlias) ) {
            return $this->tableAlias . '.' . $column;
        }
        return $this->getTable().'.'.$column;
    }

    /******
     * getQualifiedKeyName()
     *   In some cases it's useful to be able to deinfe an alias for the table
     *   named in $this->table.  It allows more powerful SQL manipulation such
     *   as nesting queries and cross-referencing of coluns.  Unfortunately as
     *   is so often the case with Eloquent, it doesn't fully-support thinking
     *   outside the box, and so we end up having to override the base classes
     *   to make it work the way we want.
     */
    public function getQualifiedKeyName ($col_id = null) {
        return $this->qualifyColumn($col_id ?? $this->getKeyName());
    }
    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @param  array|int  $ids
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQueryForRestoration ($ids) {

        $builder = $this->newQueryWithoutScopes();

        if ( is_array($ids) ) {
            foreach ($ids as $key_val) {
                $builder->orWhere (function ($builder) use ($key_val) {
                    $builder->fetchByPk (array_combine(
                        $this->primaryKey,
                        explode ('^', rtrim($key_val,'^'))
                    ));
                });
            }
        }
        return $builder;

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


    /***
     * Classifies the column's data-type into a constrained list.  Useful when
     * functionality depends on the column's data-type
     *
     * @param  string $col
     * @return string val:number|boolean|date|string
     */
    public function getColType ($col) {
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


    /*******
     * scopeProcessUserSearch()
     * stripExcessSplitters()
     * dynamicWhere()
     *   The following section deals with user-searching capabilities.  As all
     *   good developers know, the only ingredient certain to spoil what would
     *   otherwise be a perfect, fluent working application, is a dumb-ass end
     *   user.  They get on with their lives making it their business to enter
     *   all manner of impossible, invalid, corrupt and malicious data.
     *   Which brings us back to the purpose of this section.  When users send
     *   us their search-values to query their data, we have to make sure that
     *   their input is tidy, and when it isn't we have to clean it up so that
     *   it is!  Starting here are the functions which bare the responsibility
     *   for all that work, and they start quite simply, by receiving an array
     *   of filters for each of the model's columns.  The steps are:
     *     1. Strip excessive splitters (;|..)
     *         |-> this string will become the "original_search"
     *     2. Explode OR ; then AND | separators
     *     3. The WhereCondition{} object evaluates the quality of each search
     *        term after they've been split apart.  Each WhereCondition{} then
     *        declares itself to be $valid or not.
     *
     *   Please always remember the following principles:
     *     -> The way we handle each search criterion depends on the data-type
     *        of the value we are rearching on.
     *     -> Above all remember the Golden Rule: never allow direct-injection
     *        of a user search-value into the final SQL - for example, through
     *        use of DB::raw().
     *
     */

    protected function stripExcessSplitters ($str) {
        $str = trim ($str, ';|');
        $str = preg_replace ('/\|\|+/', '|', $str);
        $str = preg_replace ('/[\|;][\|;]+/', ';', $str);
        return $str;
    }

    public function scopeProcessUserSearch (Builder $builder, array $usr_srch_arr) : Builder {

        //
        // 1. Loop on each column to collect data that the user is really searching on
        // 2. Remove excessive ; and | characters
        // 3. If anything remains to search on, split on the ; and | characters
        // 4. We clean each search element, and rebuild an "executable" search string
        // 5. Using th executable search string, we build the SQL through the $builder object
        //
        foreach ($usr_srch_arr as $col => $srch) {

            $srch_clean = $this->stripExcessSplitters ($srch);

            $this->usr_srch[$col]['original_search'] = $srch;
            $this->usr_srch[$col]['clean_search']    = $srch_clean;
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


    private function filterSearchType($type) {
        $x = [];
        foreach ($this->usr_srch as $col => $srch) {
            $x[$col] = $srch[$type];
        }
        return $x;
    }

    // getters
    public function getMessageBag() {
        return $this->getErrors();
    }
    public function getUserSearch() {
        return $this->usr_srch;
    }
    public function getOriginalSearch() {
        return $this->filterSearchType('original_search');
    }
    public function getCleanSearch() {
        return $this->filterSearchType('clean_search');
    }
    public function getExecutedSearch() {
        return $this->filterSearchType('executed_search');
    }
    public function getSelectCols() {
        return $this->select_cols;
    }
    public function getSelectExpr() {
        return array_values(array_intersect_key($this->expressions, array_flip($this->select_cols)));
    }

    // setters
    public function wipeUserSearch () {
        $this->usr_srch = [];
    }

}
