<?php

namespace Igniter\Flame\Location;

use Igniter\Flame\Location\Models\Location;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\Store;

/**
 * Location Manager Class
 * @package        Igniter\Flame\Location\Manager.php
 */
class Manager
{
    protected $sessionKey = 'local_info';

    /**
     * @var \Igniter\Flame\Location\Models\Location
     */
    protected $model;

    protected $defaultLocation;

    protected $locationModel = 'Igniter\Flame\Location\Models\Location';

    protected $loaded;

    public function __construct(Store $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;
    }

    /**
     * @return mixed
     */
    public function getDefaultLocation()
    {
        return $this->defaultLocation;
    }

    /**
     * @param mixed $defaultLocation
     */
    public function setDefaultLocation($defaultLocation)
    {
        $this->defaultLocation = $defaultLocation;
    }

    public function getDefault()
    {
        return $this->getById($this->getDefaultLocation());
    }

    public function current()
    {
        if (!is_null($this->model))
            return $this->model;

        if (!$id = $this->getSession('id'))
            $id = $this->defaultLocation;

        if (!is_null($id) AND $model = $this->getById($id)) {
            $this->setCurrent($model);
        }

        return $this->model;
    }

    /**
     * @param \Igniter\Flame\Location\Models\Location $locationModel
     */
    public function setCurrent(Location $locationModel)
    {
        $this->setModel($locationModel);

        $this->putSession('id', $locationModel->getKey());

        $this->fireEvent('current.updated', $locationModel);
    }

    public function getModel()
    {
        if (is_null($this->model)) {
            $this->current();
        }

        return $this->model;
    }

    public function setModel(Location $model)
    {
        $this->model = $model;

        return $this;
    }

    public function setModelById($id)
    {
        $this->model = $this->getById($id);

        return $this;
    }

    /**
     * Creates a new instance of the location model
     * @return \Igniter\Flame\Location\Models\Location
     */
    public function createLocationModel()
    {
        $class = '\\'.ltrim($this->locationModel, '\\');
        $model = new $class();

        return $model;
    }

    /**
     * Prepares a query derived from the location model.
     */
    protected function createLocationModelQuery()
    {
        $model = $this->createLocationModel();
        $query = $model->newQuery();
        $this->extendLocationQuery($query);

        return $query;
    }

    /**
     * Extend the query used for finding the location.
     *
     * @param \Igniter\Flame\Database\Builder $query
     *
     * @return void
     */
    public function extendLocationQuery($query)
    {
    }

    /**
     * Retrieve a location by their unique identifier.
     *
     * @param  mixed $identifier
     *
     * @return \Igniter\Flame\Location\Models\Location|null
     */
    public function getById($identifier)
    {
        $query = $this->createLocationModelQuery();
        $location = $query->find($identifier);

        return $location ?: null;
    }

    /**
     * Retrieve a location by their unique slug.
     *
     * @param  string $slug
     *
     * @return \Igniter\Flame\Location\Models\Location|null
     */
    public function getBySlug($slug)
    {
        $model = $this->createLocationModel();
        $query = $this->createLocationModelQuery();
        $location = $query->where($model->getSlugKeyName(), $slug)->first();

        return $location ?: null;
    }

    public function searchByCoordinates(array $coordinates)
    {
        if (!isset($coordinates['latitude']) OR !isset($coordinates['longitude']))
            return null;

        $query = $this->createLocationModelQuery();
        $query->select('*')->selectDistance(
            $coordinates['latitude'], $coordinates['longitude']
        );

        return $query->isEnabled()->get();
    }

    //
    // Session
    //

    /**
     * Retrieves key/value pair from session data.
     *
     * @param string $key Unique key for the data store.
     * @param string $default A default value to use when value is not found.
     *
     * @return mixed
     */
    public function getSession($key = null, $default = null)
    {
        $sessionData = $this->session->get($this->sessionKey);

        return is_null($key) ? $sessionData : array_get($sessionData, $key, $default);
    }

    public function putSession($key, $value)
    {
        $sessionData = $this->getSession();
        $sessionData[$key] = $value;

        $this->session->put($this->sessionKey, $sessionData);
    }

    public function forgetSession($key)
    {
        $sessionData = $this->getSession();
        unset($sessionData[$key]);

        $this->session->put($this->sessionKey, $sessionData);
    }

    //
    // Event
    //

    protected function fireEvent($name, $payload = null, $halt = FALSE)
    {
        if (is_null($payload))
            return $this->events->fire('cart.'.$name, [$this]);

        $this->events->fire("location.{$name}", [$payload, $this], $halt);
    }
}