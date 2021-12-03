<?php

namespace Cartbeforehorse\DbModels\sqlConditions;

use Cartbeforehorse\Validation\ValidationSys;
use Carbon\Carbon;

/*********
 * WhereCondition{}
 *   An object that represents the WHERE condition of an SQL query.  It allows
 *   us to manage all the "stupid-user invalid-search" logic in one place.
 */
class WhereCondition {

    public  string $column;       // name of column
    public  string $datatype;     // datatype of the database column (number/string/date)
    public  string $condition;    // {whereBetween, whereNotBetween, whereNull, whereNotNull, =, !=, >, >=, <, <=}
    private array  $user_val;     // user's input query-values with $condition removed
    private array  $val;          // query values to be used in Eloquent's functions
    public  bool   $valid = true; // whether the condition is valid


    public function __construct ($col, $datatype, $query_values_arr) {

        //
        // Incoming values may be preceded with:
        //     ->  = (default), !, !=, <>, >, >=, <, <=
        // Also, special values are:
        //     ->  !, % (representing 'null' and 'not null')
        //
        $this->column      = $col;
        $this->datatype    = $datatype;
        $this->user_val[0] = ltrim ($query_values_arr[0], '!=<>');
        $this->condition   = preg_replace ('/^(=|!=?|>=?|<[=>]?)?.*/', '\1', $query_values_arr[0]) ?: '=';
        $this->condition   = ($this->condition=='!' || $this->condition=='<>') ? '!=' : $this->condition; // force not equal to '!='

        if ( count($query_values_arr) == 0 || count($query_values_arr) > 2 ) {
            $this->valid = false;
        }
        elseif ( count($query_values_arr) == 2 )
        {
            $this->user_val[1] = ltrim ($query_values_arr[1], '!=<>');
            $condition2_check  = $this->user_val[1] != $query_values_arr[1];
            if ( $condition2_check || in_array ($this->condition, ['>','>=','<','<=']) ) {
                $this->valid = false;
            } else {
                $this->condition = ($this->condition=='!=') ? 'whereNotBetween' : 'whereBetween';
            }
        }
        // now we can assume that only 1 value is in the array
        elseif ( in_array ($query_values_arr[0], ['!','%']) ) {
            $this->condition = $query_values_arr[0]=='%' ? 'whereNotNull' : 'whereNull';
        }
        elseif ($datatype == 'string' && ( ValidationSys::StringContains('%',$this->user_val[0]) || ValidationSys::StringContains('_',$this->user_val[0])) ) {
            $this->condition = ($this->condition=='!=') ? 'not like' : 'like';
        }

        if ( $this->valid && !in_array ($this->condition, ['whereNull','whereNotNull']) ) {
            foreach ($this->user_val as $i => $val) {
                $this->evaluateSearchVal ($i);
            }
        }
    }


    private function evaluateSearchVal ($i) {

        // By now, we are safe to assume that the value in $this->user_val[$i]
        // is a singleton, and that it is ready to be checked according to its
        // $datatype.  All the pre-processing has been done so the conditional
        // markers, semi-colons, pipes and range-markers are already stripped.
        //
        if ($this->datatype == 'string') {
            $this->val[$i] = $this->user_val[$i];
        }
        elseif ($this->datatype == 'number') {
            // should I be a little more lenient here?
            //$sanitised_expr = preg_replace ('/\s+|[^+\/*\^%\-\d\.()]/', '', $this->user_val[$i]);
            //$sanitised_expr = preg_replace ('/\.(\D)/', '$1', $sanitised_expr);
            //$sanitised_expr = rtrim ($sanitised_expr, '+/*^%-.');
            try {
                eval ($str = "\$result = {$this->user_val[$i]}");
                if ( is_numeric($result) ) {
                    $this->val[$i] = $result;
                } else {
                    $this->valid = false;
                }
            } catch (\Throwable $t) {
                $this->valid = false;
            }
        }
        elseif ($this->datatype == 'date') {
            // To parse the incoming date (and date-time) values, we shall use
            // a package called Carbon.  But with that said, we should also be
            // aware thatn Carbon, really, only piggy-backs the underlying PHP
            // class DateTime{}, and in that sense it's worth taking a look at
            // the PHP docs for function "strtotime()".  Those docs are a good
            // place to start if you are looking to understand the flexibility
            // and limitations that the user has for entering date-formats and
            // for writing dynamic queries around date/time calculations.  All
            // of the following examples are valid user inputs:
            //  - 01/03/2017
            //  - 01/03/2017 06:00
            //  - 01/03/2017 6pm
            //  - March-1 2016 6pm
            //  - 2017-03-01 + 3 minutes
            //  - today
            //  - tomorrow - 1 millisecond
            //  - next Wednesday
            //  - first day of Jan
            // In particular you should know that the DateTime{} parser takesx
            // In particular, you'll note that the DateTime{} class interprets
            // different separators as being from different international date
            // formats.  The following list illustrates the point:
            //  - 01/03/2017 >> American format meaning: 03 Jan 2017
            //  - 01-03-2017 >> European format meaning: 01 Mar 2017
            //

            $usr_date_format = 'eur';  // iso, usa also allowed; we shall fetch this from the user profile once we've built a user-profile into the system

            if ($usr_date_format == 'iso' || $usr_date_format == 'eur') {
                $query_expr = str_replace ('/', '-', $this->user_val[$i]);
            } elseif ($usr_date_format == 'usa' && !preg_match('/[a-zA-z]/',$query_expr) ) {
                $query_expr = str_replace ('-', '/', $this->user_val[$i]);
            }

            try {
                $this->val[$i] = Carbon::parse ($query_expr);
            } catch (\Exception $e) {
                $this->valid = false;
            }
        }
    }


    /****
     * getter() functions
     */
    public function getUserVal ($i) {
        return $this->user_val[$i];
    }
    public function getSqlVal ($i =0) {
        return $this->val[$i];
    }
    public function getBetweenVals() {
        return [$this->val[0], $this->val[1]];
    }
    public function getCleanUserSearchString() {

        $val0 = $this->val[0];
        $val1 = $this->val[1] ?? null;
        $val0 = (ValidationSys::IsClassOrSubclassOf ($val0, 'Carbon\Carbon')) ? $val0->format('Y-m-d H:i:s') : $val0;
        $val1 = (ValidationSys::IsClassOrSubclassOf ($val1, 'Carbon\Carbon')) ? $val1->format('Y-m-d H:i:s') : $val1;
        # see also: http://carbon.nesbot.com/docs/#api-formatting

        if ( !$this->valid ) {
            return '/**err**/';
        } elseif ($this->condition == 'whereNull') {
            return '!';
        } elseif ($this->condition == 'whereNotNull') {
            return '%';
        } elseif ($this->condition == 'whereBetween') {
            return "$val0..$val1";
        } elseif ($this->condition == 'whereNotBetween') {
            return "!=$val0..$val1";
        } else {
            // like, =, !=, >, >=, <, <=
            return ValidationSys::InArray($this->condition, ['=','like']) ? $val0 : $this->condition . $val0;
        }
    }


}
