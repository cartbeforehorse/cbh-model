<?php

namespace Cartbeforehorse\DbModels;

use Illuminate\Contracts\Support\MessageProvider;
use Illuminate\Database\Eloquent\Model    as EloquentModel;
use Watson\Validating\ValidatingInterface as iWatsonValidation;

/**
 *  CbhModel{}
 *
 *  @author Osian ap Garth / CBH Software
 */
class CbhModel extends EloquentModel implements MessageProvider, iWatsonValidation {

    use tCbhModel;

}
