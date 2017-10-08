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

    public function fsmStateIs() {
        return $this -> stateMachine() -> getState();
    }
    public function fsmGetPossibleTransitions() {
        return $this -> stateMachine() -> getPossibleTransitions();
    }

    public function fsmTransitionAllowed ($transition) {
        return $this -> stateMachine() -> can ($transition);
    }
    public function fsmTransition ($transition, $soft =false) {
        return $this -> stateMachine() -> apply ($transition, $soft);
    }
    public function fsmTransitionAndReturn ($transition) {
        return $this -> stateMachine() -> apply ($transition, true);
    }

    /***
     *
     *  There are neat ways to log the history of FSM actions on an object. However
     *  this does require us to set up a secondary table to log the states, and who
     *  applied them.
     *  However, this is not part of the scope at the time of writing! (08/10/2017)
     *
     **/

}
