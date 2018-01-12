<?php

namespace Cartbeforehorse\DbModels;

use Cartbeforehorse\Validation\ValidationSys;
use Cartbeforehorse\Validation\CodingError;
use Yajra\Oci8\Eloquent\OracleEloquent as YajraModel;
use Yajra\Pdo\Oci8\Exceptions\Oci8Exception;
use \DB;
use \PDO;

class CbhIfsModel extends YajraModel {

    /***
     * The trait of the CbhModel added here for validation and user-search purposes. We
     * must also set extending variables in the class, since doing so in the trait will
     * cause errors that PHP doesn't like.
     */
    use tCbhModel;

    protected $connection     = 'oracle'; // duh
    public    $incrementing   = false;    // why Eloquent would ever set the default to true is beyond me
    protected $primaryKey     = [];       // CbhModel allows the extending class to define a string or an array of strings
    public    $timestamps     = false;    // The Eloquent model assumes CREATED_AT, UPDATED_AT columns, which we want to kill

    /***
     * 99% of the time, the Application Owner (or app-owner) in IFS is simply given the
     * name IFSAPP. However, exceptions do exist out there, and in theory the app-owner
     * can be called anything. We must therefore provide flexibility in our application
     * to allow the IFS app-owner's name to change. Defined as private since this value
     * should never be allowed to change once set.
     */
    private $appOwner   = 'ifsapp';

    /***
     * Note that in the IFS context, the $table value will normally refer to a database
     * view.  This is a knock-on effect of how IFS works, and prefers to query data via
     * a view rather than directly from a table.  In most situations in IFS, each table
     * (e.g. supplier_info_tab) has a corresponding view (supplier_info) that selects *
     * directly from the underlying table.  Because Eloquent doesn't distinguish tables
     * and views when building its SQL queries, it is safer for us to build our queries
     * on the views - which automatically prevents us from doing direct inserts against
     * IFS tables and "accidentally" circumventing the IFS Business Logic.
     * If we wish to update data in IFS, we must do so by routing our update command by
     * IFS's PL/SQL API package (supplier_info_api).  The package name is significantly
     * important that it warants its own property in the IFS Model.
     */
    protected $package;         // @str will be defined alongside the $table property


    /***
     * These arrays respond to IFS's model of setting columns insertalbe and modifiable
     * and I hope that you find them to be appropriately named.  We make use of them in
     * the ifsInsert() and ifsUpdate() functions below.
     */
    protected $insertable_cols = [];
    protected $updatable_cols  = [];


    /***
     * The $info string simply holds information provided as feedback from IFS, when we
     * attempt to manipulate data. It won't be used very often, but is worth keeping as
     * an object property for the odd occasion.
     */
    protected $info;
    /***
     * The $attr string holds the last attribute string passed to an IFS function.  The
     * IFS API functions may modify during execution of ifsInsert() and ifsUpdate(), so
     * what we put it may not necessarily be the same as what we get out.
     */
    protected $attr;


    /*********************
     * Start logic here
     */
    public function __construct (array $attributes = []) {

        $this->_bootTrait();

        foreach ($this->col_settings as $colid => $col_setup) {

            $col_setup = trim ($col_setup, '|') . '|';
            if ( strpos($col_setup,'i|') !== false ) {
                $this->insertable_cols[] = $colid;
            }
            if ( strpos($col_setup,'u|') !== false ) {
                $this->updatable_cols[] = $colid;
            }

        }

        parent::__construct ($attributes);
    }


    /***
     * We want to disable the framework from allowing a direct save() onto the database
     * by hiding the inherited function.
     * There are a whole bunch of functions which we'll need to protect in this way.
     */
    public static function create (array $attributes = []) {
        CodingError::RaiseCodingError ('Direct insert on tables is not allowed in IfsModel{}');
    }
    public function save (array $options = []) {
        CodingError::RaiseCodingError ('Direct update on tables is not allowed in IfsModel{}');
    }
    public function forceSave (array $options = []) {
        CodingError::RaiseCodingError ('Direct update on tables is not allowed in IfsModel{}');
    }
    public function fillable (array $fillable) {
        CodingError::RaiseCodingError ('$fillable array not updatable in IfsModel{}');
    }


    /********
     * Supporting functions for ifsInsert/ifsUpdate/ifsDelete
     */

    /**
     * getDirty()
     *     A function to support the IFS Insert/Update/Delete processes
     *     @param $action valid values: x|i|u
     */
    protected function getDirtyAttr ($action ='x') {

        if ($action == 'x') {
            $attr_array   = $this->getDirty();
        } else {
            $filter_array = ($action=='i') ? array_flip($this->insertable_cols) : array_flip($this->updatable_cols);
            $attr_array   = array_intersect_key ($this->getDirty(), $filter_array);
        }

        /*
         * If required, we can find the database column-type here:
         *    http://stackoverflow.com/questions/18562684/how-to-get-database-field-type-in-laravel
         * $ctype = DB::connection($this->connection)->getDoctrineColumn($this->table,$colid)
         *              ->getType()->getName();
         */
        foreach ($attr_array as $colid => $colval) {
            $coltype = $this->getColType ($colid);
            switch ($coltype) {
                case 'number':
                    $val = "$colval";
                    break;
                case 'string':
                    $val = $colval;
                    break;
                case 'date':
                    // Date format defined in IFS CLIENT_SYS: 'YYYY-MM-DD-HH24.MI.SS';
                    // Consider that $colval might be string, or Carbon\Carbon
                    if (gettype($colval) == 'string') {
                        $val = strtr ($colval, ' :', '-.');
                    } elseif (gettype($colval) == 'NULL') {
                        $val = '';
                    } else {
                        $val = $colval->format('Y-m-d-H.i.s');
                    }
                    break;
                case 'boolean':
                    $val = $colval ? 'true' : 'false';
                    break;
                default:
                    CodingError::RaiseCodingError ("Invalid type: $coltype, cannot process these as strings!!", E_USER_ERROR);
                    break;
            }
            $attr = ($attr??'') . strtoupper($colid) . chr(31) . $val . chr(30);
        }
        return $attr ?? '';
    }
    /**
     * updateModelAttributes()
     *     If $attr has been changed by ifsInsert()/ifsUpdate() then we should
     *     write the data back to the Model to keep the two in sych.
     */
    protected function updateModelAttributes ($chkdo) {
        foreach ( array_filter (explode (chr(30),$this->attr)) as $value_pair) {
            list ($key, $val) = explode (chr(31), $value_pair);
            $this->attributes[strtolower($key)] = $val;
        }
        if ($chkdo == 'DO') {
            $this->original = $this->attributes;
        }
    }

    /*********************
     * ifsInsert()
     * ifsUpdate()
     * ifsDelete()
     *     Insert, Update and Remove data into and from IFS, using the IFS API
     *     functions coded into the Oracle tier, so that we avoid skipping the
     *     IFS business logic.
     *     Note that the calling function is responsible for catching whatever
     *     Exception is thrown by the database-call.  The only exceptions that
     *     we can foresee happening, will be due to the quality of data passed
     *     to the $attr string, such that it violates the application business
     *     logic of IFS.  An Oci8Exception{} object will be thrown by PHP when
     *     anything go wrong when executing the database-code.
     */
    public function ifsInsert ($chkdo ='DO', $update_attr =true) {

        $this->isValidOrFail();
        $this->attr = $this->getDirtyAttr('i');

        if ( $chkdo=='PREPARE' || !empty($this->attr) ) {

            $exe_string = "BEGIN {$this->appOwner}.{$this->package}.New__ (:info, :objid, :objver, :attr, :chkdo); END;";
            $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

            $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
            $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
            $stmt->bindParam (':attr',   $this->attr,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
            $stmt->execute();

            if ($update_attr) {
                $this->updateModelAttributes ($chkdo);
            }

            session()->flash ('flash_message', 'Changes successfully saved to database');
            session()->flash ('alert_class', 'alert-success');

        }
    }

    public function ifsUpdate ($chkdo ='DO', $update_attr =true) {

        $this-> isValidOrFail();

        if ( !empty($this->attr = $this->getDirtyAttr('u')) ) {

            $exe_string = "BEGIN {$this->appOwner}.{$this->package}.Modify__ (:info, :objid, :objver, :attr, :chkdo); END;";
            $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

            $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
            $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
            $stmt->bindParam (':attr',   $this->attr,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
            $stmt->execute();

            if ($update_attr) {
                $this->updateModelAttributes ($chkdo);
            }

            session()->flash ('flash_message', 'Changes successfully saved to database');
            session()->flash ('alert_class', 'alert-success');

        }
    }

    public function ifsDelete ($chkdo ='DO') {

        $exe_string = "BEGIN {$this->appOwner}.{$this->package}.Remove__ (:info, :objid, :objver, :chkdo); END;";
        $stmt = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

        $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
        $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
        $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
        $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
        $stmt->execute();

        session()->flash ('flash_message', 'Record successfully deleted in the database');
        session()->flash ('alert_class', 'alert-success');

    }


    /*********************
     * Scopes for fetching data from $table
     * I'm not sure if it's possible here to also fetch from $view on occasion?
     */
    public function scopeFetchByObjid ($query, $objid, $objver) {
        return $query -> select ($this->viscols)
            -> where ('objid',      $objid)
            -> where ('objversion', $objver);
    }
    /*
     * End Scopes
     *********************/


    /***
     * Setters and Getters
     */
    public function getPrintableAttribute ($attr =null) {
        $attr = $attr ?? $this->attr ?? '';
        return strtr ($attr, chr(31).chr(30), '#|');
    }
    public function getAttrAsArray ($attr =null) {
        $attr = $attr ?? $this->attr ?? '';
        $attr = trim ($attr, chr(31).chr(30));
        foreach ( array_filter (explode (chr(30), $attr)) as $value_pair) {
            list ($key, $val) = explode (chr(31), $value_pair);
            $ret[$key] = $val;
        }
        return $ret ?? [];
    }
    public function getInfo() {
        return $this->info;
    }
    public function getAttr() {
        return $this->attr;
    }

    /***
     * Additional support functions to manage the $attr string
     */
    public function generateNewAttr ($action ='x') {
        return $this->getDirtyAttr ($action);
    }
    public function getAttrValue ($attr_key, $attr =null) {

        $attr = $attr ?? $this->attr ?? '';

        if ( $attr == '' ) {
            return '';
        }

        $r = chr(30);
        $v = chr(31);
        preg_match ("/{$r}{$attr_key}{$v}([^{$r}]*){$r}/", $r.$attr, $matches);
        return $matches[1];
    }

}
