<?php

namespace Cartbeforehorse\DbModels;

use SM\StateMachine\StateMachine;

/**
 *  tCbhModelFsm{}
 *     An FSM trait to extend the base Eloquent Model functionality.
 *     >> https://gist.github.com/iben12/7e24b695421d92cbe1fec3eb5f32fc94
 *     >> https://github.com/winzou/state-machine/blob/master/examples/simple.php
 *
 *  @author Osian ap Garth / CBH Software
 */
trait tCbhModelFsm {

    protected $_moStateMachine; // Object of type \SM\StateMachine\StateMachine
    protected $_mFsmGraph;      // Array containing Winzou-compliant graph of the FSM

    public function stateMachine() {
        if ( ! $this->_moStateMachine ) {
            $this->_moStateMachine = new StateMachine ($this, $this->_mFsmGraph);
        }
        return $this -> _moStateMachine;
    }

    public function stateIs() {
        return $this -> stateMachine() -> getState();
    }

    public function fsmTransition ($transition) {
        return $this -> stateMachine() -> apply ($transition);
    }

    public function transitionAllowed ($transition) {
        return $this -> stateMachine() -> can ($transition);
    }

    /***
     *
     *   This function auto-stamps a secondary table with a history of the states the SM was in.
     *   Not part of the scope at the time of writing! (08/10/2017)
     *
     *public function history() {
     *    return $this -> hasMany (self::HISTORY_MODEL['name']);
     *}
     **/

}
