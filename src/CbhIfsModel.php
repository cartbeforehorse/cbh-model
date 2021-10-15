<?php

namespace Cartbeforehorse\DbModels;

use Cartbeforehorse\Validation\CodingError;
use Yajra\Oci8\Eloquent\OracleEloquent as YajraModel;
use \DB;
use \PDO;

class CbhIfsModel extends YajraModel {

    use tCbhModel;

    protected $connection = 'oracle';
    public    $timestamps = false;

    /***
     * 99% of the time the IFS schema owner is IFSAPP.  However, exceptions do
     * exist, and so we need to program flexibility into the solution.
     */
    private $appowner   = 'ifsapp';

    /***
     * Note that in the IFS context, the $table value will normally refer to a
     * database view.  This is a consequence of how IFS prefers to select data
     * from views rather than directly from tables.  Because Eloquent does not
     * distinguish between tables and views when building SQL, it is safer for
     * us to build on views - which automatically prevents reckless developers
     * from "accidentally" circumventing the IFS Business Logic.
     * Updates on the IFS database (using this class anyway), must be done via
     * the IFS package through functions: ifsInsert() and ifsUpdate()
     */
    protected string $package;    // package that has the IFS business logic
    protected string $cf_package; // And the corresponding CFP package


    /***
     * These arrays correspond to IFS's concept for setting columns insertalbe
     * and modifiable.  We make use of them in the ifsInsert() and ifsUpdate()
     * functions below.
     */
    protected $insertable_cols = [];
    protected $updatable_cols  = [];


    /***
     * The $info string simply holds information provided as feedback from IFS
     * when we attempt to manipulate data.
     */
    protected $info;
    /***
     * $attr stores the latest IFS-attribute string which drives IFS's New__()
     * and Modify__() functions.  Remember that IFS can change the attr string
     * itself through these methods, so what we feed in isn't necessarily what
     * we get out.
     * $cf_attr is used for IFS Custom Fields.  This PHP interface is designed
     * to make working with CFs as transparent as possible for developers, and
     * does so by allowing the columns on the model to be prefixed with 'cf__'
     * (instead of CF$_ as is the IFS standard).  By using this convention, we
     * can treat CFs like any other standard column on the Model.  There is no
     * need for a secondary Model to manage the CFT.
     */
    protected $attr;
    protected $cf_attr;


    /*********************
     * Start logic here
     */
    public function __construct (array $attributes = []) {

        foreach ($this->col_settings as $colid => $col_setup) {

            $this->cf_package = substr ($this->package, 0, -3) . 'CFP';

            // me's thinking that this code should be moved to the parent constructor
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
     * We want to prevent the framework from allowing a direct save() onto the
     * database.  In fact, there are a bunch of functions we need to catch.
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

        foreach ($attr_array as $colid => $colval) {

            $coltype = $this->getColType ($colid);
            $upcolid = strtoupper ($colid);

            switch ($coltype) {
                case 'number':
                    $val = $colval;
                    break;
                case 'string':
                    $val = "$colval";
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

            if ( substr($colid,0,4) == 'cf__' ) {
                $cf_attr = ($cf_attr??'') . 'CF$_'.substr($upcolid,4) . chr(31) . $val . chr(30);
            } else {
                $attr = ($attr??'') . $upcolid . chr(31) . $val . chr(30);
            }
        }//end foreach

        $this->attr    = $attr    ?? '';
        $this->cf_attr = $cf_attr ?? '';
        return ['attr' => $this->attr, 'cf_attr' => $this->cf_attr ];

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
        $this->getDirtyAttr('i');

        if ( $chkdo=='PREPARE' || !empty($this->attr) ) {

            $exe_string = "BEGIN {$this->appowner}.{$this->package}.New__ (:info, :objid, :objver, :attr, :chkdo); END;";
            $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

            $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
            $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
            $stmt->bindParam (':attr',   $this->attr,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
            $stmt->execute();

            if ( $this->cf_attr != '' ) {
                $exe_string = "BEGIN {$this->appowner}.{$this->cf_package}.Cf_New__ (:info, :objid, :cf_attr, '', :chkdo); END;";
                $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

                $stmt->bindParam (':info',    $this->info,                PDO::PARAM_STR, 2000);
                $stmt->bindParam (':objid',   $this->attributes['objid'], PDO::PARAM_STR, 200);
                $stmt->bindParam (':cf_attr', $this->cf_attr,             PDO::PARAM_STR, 200);
                $stmt->bindParam (':chkdo',   $chkdo,                     PDO::PARAM_STR, 10);
                $stmt->execute();
            }

            if ($update_attr) {
                $this->updateModelAttributes ($chkdo);
            }

            session()->flash ('flash_message', 'Changes successfully saved to database');
            session()->flash ('alert_class', 'alert-success');

        }
    }

    public function ifsUpdate ($chkdo ='DO', $update_attr =true) {

        $this-> isValidOrFail();

        if ( !empty($this->getDirtyAttr('u')) ) {

            $exe_string = "BEGIN {$this->appowner}.{$this->package}.Modify__ (:info, :objid, :objver, :attr, :chkdo); END;";
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

        $exe_string = "BEGIN {$this->appowner}.{$this->package}.Remove__ (:info, :objid, :objver, :chkdo); END;";
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
     * Scopes for fetching data from $table.  Remember that in the IFS context
     * we should always select from the view, because our standard users don't
     * have privileges on the underlying table
     *
     */
    public function scopeFetchByObjid ($query, $objid, $objver) {
        return $query -> select ($this->select_cols)
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
