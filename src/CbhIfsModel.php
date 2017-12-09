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


    /********************
     * You may have noticed that I had a bit of a rant in the section above in regard to
     * how the $fillable array works. This is where I go back on myself somewhat! :-/
     * recUpdate() does almost the same as a mass-update, taking as an argument an array
     * which matches the column names of the model. Of course, the input array has still
     * most likely originated from an end-user's HTML form, but in order to make the app
     * interactive, there's no real way to get around that. Anyway, the IfsModel{} class
     * doesn't do a direct update on the underlying table, and because we bind variables
     * to the IFS wrapper functions instead of doing direct table updates, the risk from
     * SQL injection attack is significantly reduced.
     * Data from the input array is filtered through mutators before being passed to the
     * database, giving us a final opportunity to correct it before we finally reach the
     * point of no return:
     *   >> https://laravel.com/docs/5.4/eloquent-mutators#defining-a-mutator
     */
    /*
     *public function recUpdate (array $new_rec_data) {
     *    $upd_array = array_intersect_key ($new_rec_data, array_flip($this->modifiableCols));
     *    foreach ($upd_array as $col => $new_val)
     *        $this->$col = $new_val;
     *    return $this;
     *}
     */


    /*********************
     * Functions to support the IFS Insert/Update/Delete processes
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
                    } else {
                        $val = $colval->format('Y-m-d-H.i.s');
                    }
                    break;
                case 'boolean':
                    $val = $colval ? 'true' : 'false';
                    break;
                default:
                    trigger_error("Invalid type: $datatype, cannot process these as strings!!", E_USER_ERROR);
                    break;
            }
            $attr = ($attr??'') . strtoupper($colid) . chr(31) . $val . chr(30);
        }
        return $attr;
    }

    /*********************
     * Insert, Update and Remove data in IFS with the following functions
     */
    public function ifsInsert ($chkdo ='DO') {

        $this-> isValidOrFail();

        try {

            $attr       = $this->getDirtyAttr ('i');

            $exe_string = "BEGIN {$this->appOwner}.{$this->package}.New__ (:info, :objid, :objver, :attr, :chkdo); END;";
            $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

            $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
            $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
            $stmt->bindParam (':attr',   $attr,                           PDO::PARAM_STR, 2000);
            $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
            $stmt->execute();

            session()->flash ('flash_message', 'Changes successfully saved to database');
            session()->flash ('alert_class', 'alert-success');

        } catch (Oci8Exception $e) {

            dd ($e);
            return redirect()->back()->with ([
                'error_stack'     => $e->getOciErrorStack(),
                'flash_message'   => $e->getOciErrorMsg(),
                'failing_db_call' => $e->getOriginalStatement(),
                'call_parameters' => $e->getBindings(),
                'alert_class'     => 'alert-danger',
            ]);

        }

    }

    public function ifsUpdate ($chkdo ='DO') {

        $this-> isValidOrFail();

        if (!empty($dirty_attr = $this->getDirtyAttr('u'))) {

            try {
                $exe_string = "BEGIN {$this->appOwner}.{$this->package}.Modify__ (:info, :objid, :objver, :attr, :chkdo); END;";
                $stmt       = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

                $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
                $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
                $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
                $stmt->bindParam (':attr',   $dirty_attr,                     PDO::PARAM_STR, 2000);
                $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
                $stmt->execute();

                session()->flash ('flash_message', 'Changes successfully saved to database');
                session()->flash ('alert_class', 'alert-success');

            } catch (Oci8Exception $e) {

                return redirect()->back()->with ([
                    'error_stack'     => $e->getTrace(),
                    'flash_message'   => $stmt->errorInfo()[2],
                    'alert_class'     => 'alert-danger',
                ]);

            }
        }
    }

    public function ifsDelete ($chkdo ='DO') {

        try {
            $exe_string = "BEGIN {$this->appOwner}.{$this->package}.Remove__ (:info, :objid, :objver, :chkdo); END;";
            $stmt = DB::connection($this->connection)->getPdo()->prepare ($exe_string);

            $stmt->bindParam (':info',   $this->info,                     PDO::PARAM_STR, 2000);
            $stmt->bindParam (':objid',  $this->attributes['objid'],      PDO::PARAM_STR, 200);
            $stmt->bindParam (':objver', $this->attributes['objversion'], PDO::PARAM_STR, 200);
            $stmt->bindParam (':chkdo',  $chkdo,                          PDO::PARAM_STR, 10);
            $stmt->execute();

            session()->flash ('flash_message', 'Record successfully deleted in the database');
            session()->flash ('alert_class', 'alert-success');

        } catch (Oci8Exception $e) {

            return redirect()->back()->with ([
                'error_stack'     => $e->getOciErrorStack(),
                'flash_message'   => $e->getOciErrorMsg(),
                'failing_db_call' => $e->getOriginalStatement(),
                'call_parameters' => $e->getBindings(),
                'alert_class'     => 'alert-danger',
            ]);

        }

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

    /***
     * Additional support functions
     */
    public function getPrintableAttribute ($attr =null) {
        $attr = $attr ?? $this->getDirtyAttr();
        return strtr ($attr, chr(31).chr(30), '#|');
    }
    public function getInfo() {
        return $this->info;
    }

}
