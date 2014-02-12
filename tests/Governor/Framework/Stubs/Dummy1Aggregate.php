<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Governor\Framework\Stubs;

use Governor\Framework\EventSourcing\AbstractEventSourcedAggregateRoot;

/**
 * Description of DummyAggregate
 *
 * @author 255196
 */
class Dummy1Aggregate extends  AbstractEventSourcedAggregateRoot
{
    private $id;   
    
    protected function getChildEntities()
    {
        return null;
    }

    public function getIdentifier()
    {
        return $this->id;
    }

}