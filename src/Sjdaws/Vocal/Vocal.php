<?php

namespace Sjdaws\Vocal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Str;
use Illuminate\Support\MessageBag;

/**
 * @property int $id
 */
class Vocal extends Model
{
    /**
     * The message bag instance containing validation error messages
     *
     * @var Illuminate\Support\MessageBag
     */
    protected $errors;

    /**
     * Whether to fill the model from input or not
     *
     * @var bool
     */
    protected $fillFromInput = true;

    /**
     * The fields which should be hashed automatically
     *
     * @var array
     */
    protected $hashable = array();

    /**
     * Whether to validate before save or not
     *
     * @var bool
     */
    public $validateBeforeSave = false;

    /**
     * Determine whether the model has been hydrated
     *
     * @var bool
     */
    private $_hydratedByVocal = false;

    /**
     * Determine whether the model has been validated
     *
     * @var bool
     */
    private $_validatedByVocal = false;

    /**
     * Create a new model instance
     *
     * @param  array $attributes
     * @return Sjdaws\Vocal\Vocal
     */
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);

        // Create message bag for errors
        $this->errors = new MessageBag;

        // Boot model to enable hooks
        self::boot();
    }

    /**
     * Attach a model instance to the parent model
     *
     * @param  Illuminate\Database\Eloquent\Model $model
     * @return Illuminate\Database\Eloquent\Model
     */
    public function attachToParent(Model $model)
    {
        $model->setAttribute($this->getPlainForeignKey(), $this->getParentKey());

        return $model->forceSave() ? $model : false;
    }

    /**
     * Override to boot method of each model to attach before and after hooks
     *
     * @see    Illuminate\Database\Eloquent\Model::boot()
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        $hooks    = array('before' => 'ing', 'after' => 'ed');
        $radicals = array('creat', 'delet', 'hydrat', 'sav', 'updat', 'validat');

        foreach ($radicals as $rad)
        {
            foreach ($hooks as $hook => $event)
            {
                $method = $hook . ucfirst($rad) . 'e';

                if (method_exists(get_called_class(), $method))
                {
                    self::{$rad . $event}(function($model) use ($method)
                    {
                        return $model->{$method}($model);
                    });
                }
            }
        }
    }

    /**
     * Test 'only' and 'except' lists for excluding relationships
     *
     * @param  string $model
     * @param  array  $conditions
     * @return bool
     */
    private function filterRelationshipByConditions($model, $conditions)
    {
        // Test for false and return the opposite
        return ( !
            (isset($conditions['only']) && ! in_array($model, $conditions['only'])) ||
            (isset($conditions['except']) && in_array($model, $conditions['except']))
        );
    }

    /**
     * Find or create a record if it doesn't exist
     *
     * @param  Model  $model
     * @param  string $key
     * @return Model
     */
    private function findOrCreateRecord(Model $model, $key = null)
    {
        if ($key)
        {
            $record = ($this->usesSoftDeletes()) ? $model->withTrashed()->find($key) : $model->find($key);

            // Only return a record if we found one, otherwise we will end up sending a new record back
            if ($record) return $record;
        }

        return new $model;
    }

    /**
     * Get the observable event names
     *
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(
            parent::getObservableEvents(),
            array('hydrating', 'hydrated', 'validating', 'validated')
        );
    }

    /**
     * Get data for a relationship
     *
     * @param  string $relationship
     * @param  array  $conditions
     * @param  array  $rules
     * @param  array  $messages
     * @return array
     */
    private function getRelationshipData($relationship, array $conditions, array $rules, array $messages)
    {
        return array(
            $this->getRelationshipDataFromArray($relationship, $conditions),
            $this->getRelationshipDataFromArray($relationship, $rules),
            $this->getRelationshipDataFromArray($relationship, $messages)
        );
    }

    /**
     * Extract data from a relationship array
     *
     * @param  string $relationship
     * @param  array $data
     * @return array
     */
    private function getRelationshipDataFromArray($relationship, array $data)
    {
        // Determine model class
        $modelClass = Str::camel($relationship);

        if (isset($data[$relationship]) || isset($data[$modelClass]))
        {
            return (isset($data[$relationship])) ? $data[$relationship] : $data[$modelClass];
        }

        return array();
    }

    /**
     * Determine if we're working with a one or many relationship
     *
     * @param  string $model
     * @return string
     */
    private function getRelationshipType($model)
    {
        // Poke method to check the type of instance
        $instance = $this->$model();

        return (
            $instance instanceof BelongsTo ||
            $instance instanceof HasOne ||
            $instance instanceof MorphOne ||
            $instance instanceof MorphTo
        ) ? 'one' : 'many';
    }

    /**
     * Hash any hashable attributes
     *
     * @return null
     */
    private function hashAttributes()
    {
        $hasher = new BcryptHasher;
        $filtered = array_filter($this->attributes);

        foreach ($filtered as $key => $value)
        {
            if (in_array($key, $this->hashable) && $value != $this->getOriginal($key))
            {
                $this->attributes[$key] = $hasher->make($value);
            }
        }
    }

    /**
     * Register a hydrated model event with the dispatcher
     *
     * @param  Closure|string $callback
     * @return void
     */
    public static function hydrated($callback)
    {
        static::registerModelEvent('hydrated', $callback);
    }

    /**
     * Hydrate a model from input or an array
     *
     * @param  array $data
     * @return false|null
     */
    private function hydrateModel(array $data)
    {
        // Make sure we're using fillable, and we haven't previously filled the model which may overwrite stuff
        if ( ! $this->_hydratedByVocal && $this->fillFromInput)
        {
            // Fire hydrating event
            if ($this->fireModelEvent('hydrating') === false) return false;

            // Fill from data and record we've filled it once
            $this->fill($data);
            $this->_hydratedByVocal = true;

            $this->fireModelEvent('hydrated', false);
        }
    }

    /**
     * Register a hydrating model event with the dispatcher
     *
     * @param  Closure|string $callback
     * @return void
     */
    public static function hydrating($callback)
    {
        static::registerModelEvent('hydrating', $callback);
    }

    /**
     * Determine if a string is a relationship
     *
     * @param  string $model
     * @return bool
     */
    private function isRelationship($model)
    {
        // Poke method to check the type of instance
        $instance = $this->$model();

        // Check whether the instance is a relationship
        return (
            $instance instanceof BelongsTo ||
            $instance instanceof BelongsToMany ||
            $instance instanceof HasMany ||
            $instance instanceof HasManyThrough ||
            $instance instanceof HasOne ||
            $instance instanceof MorphMany ||
            $instance instanceof MorphOne ||
            $instance instanceof MorphTo
        );
    }

    /**
     * Remove any fields which can't be submitted to the database
     *
     * @return void
     */
    private function removeInvalidAttributes()
    {
        foreach ($this->getAttributes() as $attribute => $data)
        {
            if ( ! is_null($data) && ! is_scalar($data)) unset($this->$attribute);
        }
    }

    /**
     * Remove any invalid rules before attempting to validate
     *
     * @param  array $rules
     * @return array
     */
    private function removeInvalidRules(array $rules)
    {
        foreach ($rules as $field => $rule) if (empty($rule)) unset($rules[$field]);

        return $rules;
    }

    /**
     * Save a single record
     *
     * @param  array $data
     * @param  array $rules
     * @param  array $messages
     * @return bool
     */
    public function save(array $data = array(), array $rules = array(), array $messages = array())
    {
        // Fill model attributes
        $this->hydrateModel($data);

        // Validate record before save unless we're asked not to
        $valid = ( ! $this->validateBeforeSave || $this->_validatedByVocal) ? true : $this->validate($data, $rules, $messages);

        // If record is invalid, save is unsuccessful
        if ( ! $valid) return false;

        // Hash any hashable attributes
        $this->hashAttributes();

        // Save the record
        return parent::save();
    }

    /**
     * Determine if a model is using soft deletes
     *
     * @return bool
     */
    private function usesSoftDeletes()
    {
        return ((isset($this->forceDeleting) && ! $this->forceDeleting) || isset($this->softDeletes) && ! $this->softDeletes);
    }

    /**
     * Validate a single record
     *
     * @param  array $data
     * @param  array $rules
     * @param  array $messages
     * @return bool
     */
    public function validate(array $data = array(), array $rules = null, array $messages = array())
    {
        // Fill model attributes
        $this->hydrateModel($data);

        // Remove any fields from the model which can't be submitted, such as objects and arrays
        // - This will prevent errors with bound objects being saved twice
        $this->removeInvalidAttributes();

        // Fire validating event
        if ($this->fireModelEvent('validating') === false) return false;

        $ruleset = new Ruleset($this, $rules ?: $this->rules);
        $rules = $ruleset->get();

        // If we have no rules then validation will pass
        if ( ! count($rules))
        {
            $this->fireModelEvent('validated', false);
            return true;
        }
    }

    /**
     * Register a validated model event with the dispatcher
     *
     * @param  Closure|string $callback
     * @return void
     */
    public static function validated($callback)
    {
        static::registerModelEvent('validated', $callback);
    }

    /**
     * Register a validating model event with the dispatcher
     *
     * @param  Closure|string $callback
     * @return void
     */
    public static function validating($callback)
    {
        static::registerModelEvent('validating', $callback);
    }
}
