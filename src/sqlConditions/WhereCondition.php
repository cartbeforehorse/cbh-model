<?php

namespace Cartbeforehorse\DbModels\sqlConditions;

use Cartbeforehorse\Validation\ValidationSys;
use Carbon\Carbon;

/*********
 * WhereCondition{}
 *   An object to represent the WHERE condition that we shall to amend to the final SQL query.
 *   It seems neater somehow to represent this as an object as it allows us to encapsulate all
 *   the "stupid user invalid input" logic in one self-contained class.  Trying to do all this
 *   in a function seemed to get a little unwieldy.
 */
class WhereCondition {

    public  $column;            // name of column
    public  $datatype;          // datatype of the database column (number/string/date)
    public  $condition;         // {whereBetween, whereNotBetween, whereNull, whereNotNull, =, !=, >, >=, <, <=}
    private $user_val;          // Array: user's input query-values with $condition removed
    private $val;               // Array: query values to be used in Eloquent's functions
    public  $valid = true;      // Bool: whether the condition is valid


    public function __construct ($col, $datatype, $query_values_arr) {

        //
        // For debugging purposes, remember that incoming values may be preceded with:
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
        elseif ($datatype == 'string' && ( strpos($this->user_val[0],'%') || strpos($this->user_val[0],'_')) ) {
            $this->condition = ($this->condition=='!=') ? 'not like' : 'like';
        }

        if ( $this->valid && !in_array ($this->condition, ['whereNull','whereNotNull']) ) {
            foreach ($this->user_val as $i => $val) {
                $this->evaluateSearchVal ($i);
            }
        }
    }


    private function evaluateSearchVal ($i) {

        //
        // At this point, we are safe to assume that the value in $this->user_val[$i] is
        // a perfectly clean value for checking.  We don't need to worry about condition
        // values as these have already been calculated.  We've also already removed all
        // semi-colons, pipes and double-dot key values.  The only consideration left to
        // worry about is the column's data-type.
        //
        if ($this->datatype == 'string') {
            $this->val[$i] = $this->user_val[$i];
        }
        elseif ($this->datatype == 'number') {
            $sanitised_expr = preg_replace ('/\s+|[^+\/*\^%\-\d\.()]/', '', $this->user_val[$i]);
            $sanitised_expr = preg_replace ('/\.(\D)/', '$1', $sanitised_expr);
            $sanitised_expr = rtrim ($sanitised_expr, '+/*^%-.');
            try {
                $str = "\$result = $sanitised_expr;";
                eval ($str);
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
            // We use Carbon to parse the incoming date-time values, although Carbon really only piggy-backs
            // the base PHP class DateTime{}.  See also the PHP documentation on function "strtotime()" that
            // does a fair job at explaining the flexibility allowed in the incoming date-string.  This next
            // list describes valid input values:
            //  - 01/03/2017
            //  - 01/03/2017 06:00
            //  - 01/03/2017 6pm
            //  - March-1 2016 6pm
            //  - 2017-03-01 + 3 minutes
            //  - today
            //  - tomorrow - 1 millisecond
            //  - next Wednesday
            //  - first day of Jan
            // Be particularly aware that the parsing engine recognises different separators as belonging to
            // different date-formats.  The following list illustrates the point:
            //  - 01/03/2017   >> American format meaning: 03 Jan 2017
            //  - 01-03-2017   >> European format meaning: 01 Mar 2017
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
            return '';
        } elseif ($this->condition == 'whereNull') {
            return '!';
        } elseif ($this->condition == 'whereNotNull') {
            return '%';
        } elseif ($this->condition == 'whereBetween') {
            return "$val0..$val1";
        } elseif ($this->condition == 'whereNotBetween') {
            return "!=$val0..$val1";
        } else {
            // =, !=, >, >=, <, <=
            return ($this->condition=='=') ? $val0 : $this->condition . $val0;
        }
    }


}
