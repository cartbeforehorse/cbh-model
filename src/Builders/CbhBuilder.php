<?php

namespace Cartbeforehorse\DbModels\Builders;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder    as QueryBuilder;

class CbhBuilder extends EloquentBuilder {

    /**
     * Create a new Eloquent query builder instance.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $select_cols in SELECT statement => string|Illuminate\Database\Query\Expression
     * @return void
     */
    public function __construct(QueryBuilder $query, array $select_cols =[])
    {
        parent::__construct ($query);
        if ( !empty($select_cols) ) {
            $this->query->columns = $select_cols;
        }
    }

}
