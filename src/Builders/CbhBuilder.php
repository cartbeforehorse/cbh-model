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

    /**
     * Override the get function to return only colums in $this->query->columns
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns =[]) {
        if ( !empty($columns) ) {
            return parent::get($columns);
        } elseif ( isset($this->query->columns) ) {
            return parent::get ($this->query->columns);
        } else {
            return parent::get();
        }
    }

}
