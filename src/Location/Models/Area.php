<?php namespace Igniter\Flame\Location\Models;

use Igniter\Flame\Database\Model;
use Igniter\Flame\Location\GeoPosition;

class Area extends Model
{
    const VERTEX = "vertex";

    const BOUNDARY = "boundary";

    const INSIDE = "inside";

    const OUTSIDE = "outside";

    /**
     * @var string The database table name
     */
    protected $table = 'location_areas';

    protected $primaryKey = 'area_id';

    public $relation = [
        'belongsTo' => [
            'location' => ['Admin\Models\Locations_model'],
        ],
    ];

    public $casts = [
        'boundaries' => 'serialize',
        'conditions' => 'serialize',
    ];

    protected $appends = ['vertices', 'circle'];

    protected static $areaColors = [
        '#F16745', '#FFC65D', '#7BC8A4', '#4CC3D9', '#93648D', '#404040',
        '#F16745', '#FFC65D', '#7BC8A4', '#4CC3D9', '#93648D', '#404040',
        '#F16745', '#FFC65D', '#7BC8A4', '#4CC3D9', '#93648D', '#404040',
        '#F16745', '#FFC65D',
    ];

    public function getChargeSummaryTrans($name)
    {
        $trans = [
            'all'   => '{amount} on all orders',
            'above' => '{amount} above {total}',
            'below' => '{amount} below {total}',
        ];

        if (is_null($name))
            return $trans;

        return $trans[$name] ?? null;
    }

    //
    // Accessors & Mutators
    //

    public function getVerticesAttribute()
    {
        return isset($this->boundaries['vertices']) ?
            json_decode($this->boundaries['vertices']) : [];
    }

    public function getCircleAttribute()
    {
        return isset($this->boundaries['circle']) ?
            json_decode($this->boundaries['circle']) : null;
    }

    //
    // Helpers
    //

    public function getLocationId()
    {
        return $this->attributes['location_id'];
    }

    public function deliveryAmount($cartTotal)
    {
        return $this->getConditionValue('amount', $cartTotal);
    }

    public function minimumOrderTotal($cartTotal)
    {
        return $this->getConditionValue('total', $cartTotal);
    }

    public function listConditions()
    {
        $conditions = [];
        if (!$this->conditions)
            return $conditions;

        foreach ($this->conditions as $condition) {
            $condition['label'] = $this->getChargeSummaryTrans($condition['type']);

            $conditions[] = $condition;
        }

        return $conditions;
    }

    public function checkBoundary(GeoPosition $position)
    {
        $boundary = ($this->type == 'polygon')
            ? $this->pointInVertices($position) : $this->pointInCircle($position);

        return $boundary;
    }

    // Check if the point is inside the polygon or on the boundary
    public function pointInVertices($position)
    {
        $vertices = $this->vertices;

        // Check if the point sits exactly on a vertex
        if ($this->isPointOnVertex($position, $vertices) === TRUE)
            return static::VERTEX;

        $intersections = 0;
        $verticesCount = count($vertices);
        for ($i = 1; $i < $verticesCount; $i++) {
            $vertex1 = $vertices[$i - 1];
            $vertex2 = $vertices[$i];

            if ($this->isPointOnBoundary($position, $vertex1, $vertex2)) return static::BOUNDARY;

            $boundary = $this->isPointInBoundary($position, $vertex1, $vertex2);

            if ($boundary === TRUE) return static::BOUNDARY;

            if ($boundary === 1) $intersections++;
        }

        // If the number of edges we passed through is odd, then it's in the polygon.
        return ($intersections % 2 != 0) ? static::INSIDE : static::OUTSIDE;
    }

    public function pointInCircle($position)
    {
        if (!$this->circle)
            return static::OUTSIDE;

        $distanceUnit = setting('distance_unit');
        $earthRadius = ($distanceUnit === 'km') ? 6371 : 3959;
        $radius = ($distanceUnit === 'km') ? $this->circle->radius / 1000 : $this->circle->radius / 1609.344;

        $dLat = deg2rad($this->circle->lat - $position->latitude);
        $dLon = deg2rad($this->circle->lng - $position->longitude);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($position->latitude)) * cos(deg2rad($this->circle->lat))
            * sin($dLon / 2) * sin($dLon / 2);

        $distance = $earthRadius * (2 * asin(sqrt($a)));

        return ($distance <= $radius) ? static::INSIDE : static::OUTSIDE;
    }

    protected function isPointInBoundary($position, $vertex1, $vertex2)
    {
        if ($position->latitude > min($vertex1->lat, $vertex2->lat)
            AND $position->latitude <= max($vertex1->lat, $vertex2->lat)
            AND $position->longitude <= max($vertex1->lng, $vertex2->lng)
            AND $vertex1->lat != $vertex2->lat
        ) {
            $xinters = ($position->latitude - $vertex1->lat)
                * ($vertex2->lng - $vertex1->lng) / ($vertex2->lat - $vertex1->lat) + $vertex1->lng;

            // Check if point is on the polygon boundary (other than horizontal)
            if ($xinters == $position->longitude) {
                return TRUE;
            }

            // Check if point is in the polygon boundary
            if ($vertex1->lng == $vertex2->lng OR $position->longitude <= $xinters) {
                return 1;
            }
        }

        return FALSE;
    }

    protected function isPointOnVertex($position, $vertices)
    {
        foreach ($vertices as $vertex) {
            if ($position->latitude == $vertex->lat AND $position->longitude == $vertex->lng) {
                return TRUE;
            }
        }

        return FALSE;
    }

    protected function isPointOnBoundary($position, $vertex1, $vertex2)
    {
        return ($vertex1->lat == $vertex2->lat AND $vertex1->lat == $position->latitude
            AND $position->longitude > min($vertex1->lng, $vertex2->lng)
            AND $position->longitude < max($vertex1->lng, $vertex2->lng));
    }

    protected function getConditionValue($type, $cartTotal)
    {
        if (!$condition = $this->filterConditionRules($type, $cartTotal))
            return null;

        $condition = (object)$condition;

        // Delivery is unavailable when delivery charge from the matched rule is -1
        if ($condition->amount < 0)
            return $type == 'total' ? $condition->total : null;

        // At this stage, minimum total is 0 when the matched condition is a below rule
        if ($type == 'total' AND $condition->type == 'below')
            return 0;

        return $condition->{$type};
    }

    protected function filterConditionRules($value = 'total', $cartTotal)
    {
        $collection = collect($this->conditions);

        if ($value == 'total')
            return $collection->sortBy($value)->first();

        return $collection->first(function ($condition) use ($cartTotal) {
            switch ($condition['type']) {
                case 'all':
                    return TRUE;
                case 'below':
                    return $cartTotal < $condition['total'];
                case 'above':
                    return $cartTotal > $condition['total'];
            }
        });
    }
}