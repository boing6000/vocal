<?php

namespace Sjdaws\Vocal;

class Vocal extends SuperModel
{
    /**
     * Validate a single record
     *
     * @param  array $data
     * @param  array $rules
     * @param  array $messages
     * @return bool
     */
    public function validate(array $data = array(), array $rules = array(), array $messages = array())
    {
        // Fill model attributes
        $this->hydrateModel($data);

        $this->validator = new Validation($this, $rules, $messages);
        return $this->validator->getResult();
    }

    /**
     * Recursively validate a record and all it's relationships
     *
     * @param  array $data
     * @param  array $conditions
     * @param  array $rules
     * @param  array $messages
     * @return bool
     */
    public function validateRecursive(array $data = array(), array $conditions = array(), array $rules = array(), array $messages = array())
    {

    }
}
