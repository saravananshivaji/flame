<?php namespace Igniter\Flame\ActivityLog;

use AdminAuth;
use App;
use Igniter\Flame\ActivityLog\Models\Activity;
use Igniter\Flame\Database\Model;
use Igniter\Flame\Traits\Singleton;
use Illuminate\Support\Traits\Macroable;

class ActivityLogger
{
    use Macroable;

    public $authDriver;

    /** @var \Igniter\Flame\Auth\Manager */
    protected $auth;

    protected $logName = '';

    /** @var bool */
    protected $logEnabled;

    /** @var Model */
    protected $performedOn;

    /** @var Model */
    protected $causedBy;

    /** @var \Illuminate\Support\Collection */
    protected $properties;

    public function __construct()
    {
        $this->properties = collect();
        $this->logName = 'default';
        $this->logEnabled = TRUE;
    }

    public function setAuth($eventName)
    {
        $auth = $this->logAuthDriver();
        $this->auth = $auth;
        $this->causedBy = null; // $auth->user();
        $this->useLog($this->getLogNameToUse($eventName));

        return $this;
    }

    public function getLogNameToUse($eventName)
    {
        return $eventName;
    }

    /**
     * @return \Igniter\Flame\Auth\Manager
     */
    public function logAuthDriver()
    {
        return $this->authDriver;
    }

    public function setAuthDriver($driver)
    {
        $this->authDriver = $driver;
    }

    /**
     * @param Model $model
     *
     * @return $this
     */
    public function performedOn(Model $model)
    {
        $this->performedOn = $model;

        return $this;
    }

    /**
     * @param Model $model
     *
     * @return $this
     */
    public function causedBy(Model $model)
    {
        $this->causedBy = $model;

        return $this;
    }

    /**
     * @param array|\Illuminate\Support\Collection $properties
     *
     * @return $this
     */
    public function withProperties($properties)
    {
        $this->properties = collect($properties);

        return $this;
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function withProperty($key, $value)
    {
        $this->properties->put($key, $value);

        return $this;
    }

    public function useLog($logName)
    {
        $this->logName = $logName;

        return $this;
    }

    /**
     * @param string $message
     *
     * @return null|mixed
     */
    public function log($message)
    {
        if (!$this->logEnabled) {
            return FALSE;
        }

        $activity = $this->getModelInstance();

        if ($this->performedOn) {
            $activity->subject()->associate($this->performedOn);
        }

        if ($this->causedBy) {
            $activity->causer()->associate($this->causedBy);
        }

        $activity->properties = $this->properties;
        $activity->message = $this->replacePlaceholders($message, $activity);
        $activity->log_name = strlen($this->logName) > 1 ? $this->logName : APPDIR;
        $activity->save();

        return $activity;
    }

    /**
     * @return \Igniter\Flame\ActivityLog\Models\Activity
     */
    protected function getModelInstance()
    {
        return new Activity;
    }

    protected function replacePlaceholders($message, Activity $activity)
    {
        return preg_replace_callback('/:[a-z0-9._-]+/i', function ($match) use ($activity) {
            $match = $match[0];
            preg_match('/:(.*?)\./', $match, $match2);

            if (isset($match2[1]) AND !in_array($match2[1], ['subject', 'causer', 'properties'])) {
                return $match;
            }

            $propertyName = substr($match, strpos($match, '.') + 1);
            $attributeValue = $activity->{$match2[1]};
            if (is_null($attributeValue)) {
                return $match;
            }

            $attributeValue = $attributeValue->toArray();

            return array_get($attributeValue, $propertyName, $match);
        }, $message);
    }
}