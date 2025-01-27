<?php

namespace Moloquent\Query;

use Closure;
use DateTime;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Moloquent\Connection;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

class Builder extends BaseBuilder
{
    /**
     * The database collection.
     *
     * @var MongoCollection
     */
    protected $collection;

    /**
     * The column projections.
     *
     * @var array
     */
    public $projections;

    /**
     * The cursor timeout value.
     *
     * @var int
     */
    public $timeout;

    /**
     * The cursor hint value.
     *
     * @var int
     */
    public $hint;

    /**
     * Custom options to add to the query.
     *
     * @var array
     */
    public $options = [];

    /**
     * Indicate if we are executing a pagination query.
     *
     * @var bool
     */
    public $paginating = false;

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    public $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'between', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        'exists', 'type', 'mod', 'where', 'all', 'size', 'regex', 'text', 'slice', 'elemmatch',
        'geowithin', 'geointersects', 'near', 'nearsphere', 'geometry',
        'maxdistance', 'center', 'centersphere', 'box', 'polygon', 'uniquedocs',
    ];

    /**
     * Operator conversion.
     *
     * @var array
     */
    protected $conversion = [
        '='  => '=',
        '!=' => '$ne',
        '<>' => '$ne',
        '<'  => '$lt',
        '<=' => '$lte',
        '>'  => '$gt',
        '>=' => '$gte',
    ];

    /**
     * Check if we need to return Collections instead of plain arrays (laravel >= 5.3 ).
     *
     * @var bool
     */
    protected $useCollections;

    /**
     * Create a new query builder instance.
     *
     * @param Connection $connection
     * @param Processor  $processor
     */
    public function __construct(Connection $connection, Processor $processor)
    {
        $this->grammar = new Grammar();
        $this->connection = $connection;
        $this->processor = $processor;
        $this->useCollections = $this->shouldUseCollections();
    }

    /**
     * Returns true if Laravel or Lumen >= 5.3.
     *
     * @return bool
     */
    protected function shouldUseCollections()
    {
        if (function_exists('app')) {
            $version = app()->version();
            $version = filter_var(explode(')', $version)[0], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION); // lumen
            return version_compare($version, '5.3', '>=');
        } else {
            $connection = $this->getConnection();
            return $connection->getConfig('use_collection');
        }
    }

    /**
     * Set the projections.
     *
     * @param array $columns
     *
     * @return $this
     */
    public function project($columns)
    {
        $this->projections = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Set the cursor timeout in seconds.
     *
     * @param int $seconds
     *
     * @return $this
     */
    public function timeout($seconds)
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the cursor hint.
     *
     * @param mixed $index
     *
     * @return $this
     */
    public function hint($index)
    {
        $this->hint = $index;

        return $this;
    }

    /**
     * Execute a query for a single record by ID.
     *
     * @param mixed $id
     * @param array $columns
     *
     * @return mixed
     */
    public function find($id, $columns = [])
    {
        return $this->where('_id', '=', $this->convertKey($id))->first($columns);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array $columns
     *
     * @return array|static[]|Collection
     */
    public function get($columns = [])
    {
        return $this->getFresh($columns);
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param array $columns
     *
     * @return array|static[]|Collection
     */
    public function getFresh($columns = [])
    {
        // If no columns have been specified for the select statement, we will set them
        // here to either the passed columns, or the standard default of retrieving
        // all of the columns on the table using the "wildcard" column character.
        if (is_null($this->columns)) {
            $this->columns = $columns;
        }

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $this->columns)) {
            $this->columns = [];
        }

        // Compile wheres
        $wheres = $this->compileWheres();

        // Use MongoDB's aggregation framework when using grouping or aggregation functions.
        if ($this->groups or $this->aggregate or $this->paginating) {
            $group = [];
            $unwinds = [];

            // Add grouping columns to the $group part of the aggregation pipeline.
            if ($this->groups) {
                foreach ($this->groups as $column) {
                    $group['_id'][$column] = '$'.$column;

                    // When grouping, also add the $last operator to each grouped field,
                    // this mimics MySQL's behaviour a bit.
                    $group[$column] = ['$last' => '$'.$column];
                }

                // Do the same for other columns that are selected.
                foreach ($this->columns as $column) {
                    $key = str_replace('.', '_', $column);

                    $group[$key] = ['$last' => '$'.$column];
                }
            }

            // Add aggregation functions to the $group part of the aggregation pipeline,
            // these may override previous aggregations.
            if ($this->aggregate) {
                $function = $this->aggregate['function'];

                foreach ($this->aggregate['columns'] as $column) {
                    // Add unwind if a subdocument array should be aggregated
                    // column: subarray.price => {$unwind: '$subarray'}
                    if (count($splitColumns = explode('.*.', $column)) == 2) {
                        $unwinds[] = $splitColumns[0];
                        $column = implode('.', $splitColumns);
                    }

                    // Translate count into sum.
                    if ($function == 'count') {
                        $group['aggregate'] = ['$sum' => 1];
                    }
                    // Pass other functions directly.
                    else {
                        $group['aggregate'] = ['$'.$function => '$'.$column];
                    }
                }
            }

            // When using pagination, we limit the number of returned columns
            // by adding a projection.
            if ($this->paginating) {
                foreach ($this->columns as $column) {
                    $this->projections[$column] = 1;
                }
            }

            // The _id field is mandatory when using grouping.
            if ($group and empty($group['_id'])) {
                $group['_id'] = null;
            }

            // Build the aggregation pipeline.
            $pipeline = [];
            if ($wheres) {
                $pipeline[] = ['$match' => $wheres];
            }

            // apply unwinds for subdocument array aggregation
            foreach ($unwinds as $unwind) {
                $pipeline[] = ['$unwind' => '$'.$unwind];
            }

            if ($group) {
                $pipeline[] = ['$group' => $group];
            }

            // Apply order and limit
            if ($this->orders) {
                $pipeline[] = ['$sort' => $this->orders];
            }
            if ($this->offset) {
                $pipeline[] = ['$skip' => $this->offset];
            }
            if ($this->limit) {
                $pipeline[] = ['$limit' => $this->limit];
            }
            if ($this->projections) {
                $pipeline[] = ['$project' => $this->projections];
            }

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            // Execute aggregation
            $results = iterator_to_array($this->collection->aggregate($pipeline, $options));

            // Return results
            return $this->useCollections ? new Collection($results) : $results;
        }

        // Distinct query
        elseif ($this->distinct) {
            // Return distinct results directly
            $column = isset($this->columns[0]) ? $this->columns[0] : '_id';

            // Execute distinct
            if ($wheres) {
                $result = $this->collection->distinct($column, $wheres);
            } else {
                $result = $this->collection->distinct($column);
            }

            return $this->useCollections ? new Collection($result) : $result;
        }

        // Normal query
        else {
            $columns = [];

            // Convert select columns to simple projections.
            foreach ($this->columns as $column) {
                $columns[$column] = true;
            }

            // Add custom projections.
            if ($this->projections) {
                $columns = array_merge($columns, $this->projections);
            }
            $options = [];

            // Apply order, offset, limit and projection
            if ($this->timeout) {
                $options['maxTimeMS'] = $this->timeout;
            }
            if ($this->orders) {
                $options['sort'] = $this->orders;
            }
            if ($this->offset) {
                $options['skip'] = $this->offset;
            }
            if ($this->limit) {
                $options['limit'] = $this->limit;
            }
            if ($columns) {
                $options['projection'] = $columns;
            }
            // if ($this->hint)    $cursor->hint($this->hint);

            // Fix for legacy support, converts the results to arrays instead of objects.
            $options['typeMap'] = ['root' => 'array', 'document' => 'array'];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            // Execute query and get MongoCursor
            $cursor = $this->collection->find($wheres, $options);

            // Return results as an array with numeric keys
            $results = iterator_to_array($cursor, false);

            return $this->useCollections ? new Collection($results) : $results;
        }
    }

    /**
     * Generate the unique cache key for the current query.
     *
     * @return string
     */
    public function generateCacheKey()
    {
        $key = [
            'connection' => $this->collection->getDatabaseName(),
            'collection' => $this->collection->getCollectionName(),
            'wheres'     => $this->wheres,
            'columns'    => $this->columns,
            'groups'     => $this->groups,
            'orders'     => $this->orders,
            'offset'     => $this->offset,
            'limit'      => $this->limit,
            'aggregate'  => $this->aggregate,
        ];

        return md5(serialize(array_values($key)));
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param string $function
     * @param array  $columns
     *
     * @return mixed
     */
    public function aggregate($function, $columns = [])
    {
        $this->aggregate = compact('function', 'columns');

        $previousColumns = $this->columns;
        
        // We will also back up the select bindings since the select clause will be
        // removed when performing the aggregate function. Once the query is run
        // we will add the bindings back onto this query so they can get used.
        $previousSelectBindings = $this->bindings['select'];

        $this->bindings['select'] = [];

        $results = $this->get($columns);

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate = null;
        $this->columns = $previousColumns;
        $this->bindings['select'] = $previousSelectBindings;

        if (isset($results[0])) {
            $result = (array) $results[0];

            return $result['aggregate'];
        }
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        return !is_null($this->first());
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return Builder
     */
    public function distinct($column = false)
    {
        $this->distinct = true;

        if ($column) {
            $this->columns = [$column];
        }

        return $this;
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return Builder
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (is_string($direction)) {
            $direction = (strtolower($direction) == 'asc' ? 1 : -1);
        }

        if ($column == 'natural') {
            $this->orders['$natural'] = $direction;
        } else {
            $this->orders[$column] = $direction;
        }

        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param string $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return Builder
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        $this->wheres[] = compact('column', 'type', 'boolean', 'values', 'not');

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param int $page
     * @param int $perPage
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function forPage($page, $perPage = 15)
    {
        $this->paginating = true;

        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $values
     *
     * @return bool
     */
    public function insert(array $values)
    {
        // Since every insert gets treated like a batch insert, we will have to detect
        // if the user is inserting a single document or an array of documents.
        $batch = true;

        foreach ($values as $value) {
            // As soon as we find a value that is not an array we assume the user is
            // inserting a single document.
            if (!is_array($value)) {
                $batch = false;
                break;
            }
        }

        if (!$batch) {
            $values = [$values];
        }

        // Batch insert
        $result = $this->collection->insertMany($values);

        return 1 == (int) $result->isAcknowledged();
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param array  $values
     * @param string $sequence
     *
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $result = $this->collection->insertOne($values);

        if (1 == (int) $result->isAcknowledged()) {
            if (is_null($sequence)) {
                $sequence = '_id';
            }

            // Return id
            return $sequence == '_id' ? $result->getInsertedId() : $values[$sequence];
        }
    }

    /**
     * Update a record in the database.
     *
     * @param array $values
     * @param array $options
     *
     * @return int
     */
    public function update(array $values, array $options = [])
    {
        // Use $set as default operator.
        if (!starts_with(key($values), '$')) {
            $values = ['$set' => $values];
        }

        return $this->performUpdate($values, $options);
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param string $column
     * @param int    $amount
     * @param array  $extra
     *
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [], array $options = [])
    {
        $query = ['$inc' => [$column => $amount]];

        if (!empty($extra)) {
            $query['$set'] = $extra;
        }

        // Protect
        $this->where(function ($query) use ($column) {
            $query->where($column, 'exists', false);

            $query->orWhereNotNull($column);
        });

        return $this->performUpdate($query, $options);
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param string $column
     * @param int    $amount
     * @param array  $extra
     *
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [], array $options = [])
    {
        return $this->increment($column, -1 * $amount, $extra, $options);
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param string      $column
     * @param string|null $key
     *
     * @return array
     */
    public function pluck($column, $key = null)
    {
        $results = $this->get(is_null($key) ? [$column] : [$column, $key]);

        // Convert ObjectID's to strings
        if ($key == '_id') {
            $results = $results->map(function ($item) {
                $item['_id'] = (string) $item['_id'];

                return $item;
            });
        }

        $p = Arr::pluck($results, $column, $key);

        return $this->useCollections ? new Collection($p) : $p;
    }

    /**
     * Delete a record from the database.
     *
     * @param mixed $id
     *
     * @return int
     */
    public function delete($id = null)
    {
        $wheres = $this->compileWheres();
        $result = $this->collection->DeleteMany($wheres);
        if (1 == (int) $result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    /**
     * Set the collection which the query is targeting.
     *
     * @param string $collection
     *
     * @return Builder
     */
    public function from($collection,$as = NULL)
    {
        if ($collection) {
            $this->collection = $this->connection->getCollection($collection);
        }

        return $this;
    }

    /**
     * Run a truncate statement on the table.
     */
    public function truncate()
    {
        $result = $this->collection->drop();

        return 1 == (int) $result->ok;
    }

    /**
     * Get an array with the values of a given column.
     *
     * @deprecated
     *
     * @param string $column
     * @param string $key
     *
     * @return array
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column, $key);
    }

    /**
     * Create a raw database expression.
     *
     * @param closure $expression
     *
     * @return mixed
     */
    public function raw($expression = null)
    {
        // Execute the closure on the mongodb collection
        if ($expression instanceof Closure) {
            return call_user_func($expression, $this->collection);
        }

        // Create an expression for the given value
        elseif (!is_null($expression)) {
            return new Expression($expression);
        }

        // Quick access to the mongodb collection
        return $this->collection;
    }

    /**
     * Append one or more values to an array.
     *
     * @param mixed $column
     * @param mixed $value
     *
     * @return int
     */
    public function push($column, $value = null, $unique = false)
    {
        // Use the addToSet operator in case we only want unique items.
        $operator = $unique ? '$addToSet' : '$push';

        // Check if we are pushing multiple values.
        $batch = (is_array($value) and array_keys($value) === range(0, count($value) - 1));

        if (is_array($column)) {
            $query = [$operator => $column];
        } elseif ($batch) {
            $query = [$operator => [$column => ['$each' => $value]]];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more values from an array.
     *
     * @param mixed $column
     * @param mixed $value
     *
     * @return int
     */
    public function pull($column, $value = null)
    {
        // Check if we passed an associative array.
        $batch = (is_array($value) and array_keys($value) === range(0, count($value) - 1));

        // If we are pulling multiple values, we need to use $pullAll.
        $operator = $batch ? '$pullAll' : '$pull';

        if (is_array($column)) {
            $query = [$operator => $column];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more fields.
     *
     * @param mixed $columns
     *
     * @return int
     */
    public function drop($columns)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $fields = [];

        foreach ($columns as $column) {
            $fields[$column] = 1;
        }

        $query = ['$unset' => $fields];

        return $this->performUpdate($query);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        return new self($this->connection, $this->processor);
    }

    /**
     * Perform an update query.
     *
     * @param array $query
     * @param array $options
     *
     * @return int
     */
    protected function performUpdate($query, array $options = [])
    {
        // Update multiple items by default.
        if (!array_key_exists('multiple', $options)) {
            $options['multiple'] = true;
        }

        $wheres = $this->compileWheres();
        $result = $this->collection->UpdateMany($wheres, $query, $options);
        if (1 == (int) $result->isAcknowledged()) {
            return $result->getModifiedCount() ? $result->getModifiedCount() : $result->getUpsertedCount();
        }

        return 0;
    }

    /**
     * Convert a key to ObjectID if needed.
     *
     * @param mixed $id
     *
     * @return mixed
     */
    public function convertKey($id)
    {
        if (is_string($id) and strlen($id) === 24 and ctype_xdigit($id)) {
            return new ObjectID($id);
        }

        return $id;
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @param string $boolean
     *
     * @throws \InvalidArgumentException
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $params = func_get_args();

        // Remove the leading $ from operators.
        if (func_num_args() == 3) {
            $operator = &$params[1];

            if (starts_with($operator, '$')) {
                $operator = substr($operator, 1);
            }
        }

        return call_user_func_array('parent::where', $params);
    }

    /**
     * Compile the where array.
     *
     * @return array
     */
    protected function compileWheres()
    {
        // The wheres to compile.
        $wheres = $this->wheres ?: [];

        // We will add all compiled wheres to this array.
        $compiled = [];

        foreach ($wheres as $i => &$where) {
            // Make sure the operator is in lowercase.
            if (isset($where['operator'])) {
                $where['operator'] = strtolower($where['operator']);

                // Operator conversions
                $convert = [
                    'regexp'        => 'regex',
                    'elemmatch'     => 'elemMatch',
                    'geointersects' => 'geoIntersects',
                    'geowithin'     => 'geoWithin',
                    'nearsphere'    => 'nearSphere',
                    'maxdistance'   => 'maxDistance',
                    'centersphere'  => 'centerSphere',
                    'uniquedocs'    => 'uniqueDocs',
                ];

                if (array_key_exists($where['operator'], $convert)) {
                    $where['operator'] = $convert[$where['operator']];
                }
            }

            // Convert id's.
            if (isset($where['column']) and ($where['column'] == '_id' or ends_with($where['column'], '._id'))) {
                // Multiple values.
                if (isset($where['values'])) {
                    foreach ($where['values'] as &$value) {
                        $value = $this->convertKey($value);
                    }
                }

                // Single value.
                elseif (isset($where['value'])) {
                    $where['value'] = $this->convertKey($where['value']);
                }
            }

            // Convert DateTime values to UTCDateTime.
            if (isset($where['value']) and $where['value'] instanceof DateTime) {
                $where['value'] = $this->dateTimeConvertion($where['value']);
            }

            // Convert DateTime values to UTCDateTime in $where['values'] key.
            if (array_key_exists('values', $where)) {
                if (is_array($where['values']) && !empty($where['values'])) {
                    foreach ($where['values'] as $keyWhere => $valueWhere) {
                        if ($valueWhere instanceof DateTime) {
                            $where['values'][$keyWhere] = $this->dateTimeConvertion($valueWhere);
                        }
                    }
                }
            }

            // The next item in a "chain" of wheres devices the boolean of the
            // first item. So if we see that there are multiple wheres, we will
            // use the operator of the next where.
            if ($i == 0 and count($wheres) > 1 and $where['boolean'] == 'and') {
                $where['boolean'] = $wheres[$i + 1]['boolean'];
            }

            // We use different methods to compile different wheres.
            $method = "compileWhere{$where['type']}";
            $result = $this->{$method}($where);

            // Wrap the where with an $or operator.
            if ($where['boolean'] == 'or') {
                $result = ['$or' => [$result]];
            }

            // If there are multiple wheres, we will wrap it with $and. This is needed
            // to make nested wheres work.
            elseif (count($wheres) > 1) {
                $result = ['$and' => [$result]];
            }

            // Merge the compiled where with the others.
            $compiled = array_merge_recursive($compiled, $result);
        }

        return $compiled;
    }

    protected function compileWhereBasic($where)
    {
        extract($where);

        // Replace like with a Regex instance.
        if ($operator == 'like') {
            $operator = '=';

            // Convert to regular expression.
            $regex = preg_replace('#(^|[^\\\])%#', '$1.*', preg_quote($value));

            // Convert like to regular expression.
            if (!starts_with($value, '%')) {
                $regex = '^'.$regex;
            }
            if (!ends_with($value, '%')) {
                $regex = $regex.'$';
            }

            $value = new Regex($regex, 'i');
        }

        // Manipulate regexp operations.
        elseif (in_array($operator, ['regexp', 'not regexp', 'regex', 'not regex'])) {
            // Automatically convert regular expression strings to Regex objects.
            if (!$value instanceof Regex) {
                $e = explode('/', $value);
                $flag = end($e);
                $regstr = substr($value, 1, -(strlen($flag) + 1));
                $value = new Regex($regstr, $flag);
            }

            // For inverse regexp operations, we can just use the $not operator
            // and pass it a Regex instence.
            if (starts_with($operator, 'not')) {
                $operator = 'not';
            }
        }

        if (!isset($operator) or $operator == '=') {
            $query = [$column => $value];
        } elseif (array_key_exists($operator, $this->conversion)) {
            $query = [$column => [$this->conversion[$operator] => $value]];
        } else {
            $query = [$column => ['$'.$operator => $value]];
        }

        return $query;
    }

    protected function compileWhereNested($where)
    {
        extract($where);

        return $query->compileWheres();
    }

    protected function compileWhereIn($where)
    {
        extract($where);

        return [$column => ['$in' => array_values($values)]];
    }

    protected function compileWhereNotIn($where)
    {
        extract($where);

        return [$column => ['$nin' => array_values($values)]];
    }

    protected function compileWhereNull($where)
    {
        $where['operator'] = '=';
        $where['value'] = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereNotNull($where)
    {
        $where['operator'] = '!=';
        $where['value'] = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereBetween($where)
    {
        extract($where);

        if ($not) {
            return [
                '$or' => [
                    [
                        $column => [
                            '$lte' => $values[0],
                        ],
                    ],
                    [
                        $column => [
                            '$gte' => $values[1],
                        ],
                    ],
                ],
            ];
        } else {
            return [
                $column => [
                    '$gte' => $values[0],
                    '$lte' => $values[1],
                ],
            ];
        }
    }

    protected function compileWhereRaw($where)
    {
        return $where['sql'];
    }

    /**
     * Convert to MongoDB\BSON\UTCDateTime if $value is a DateTime object.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function dateTimeConvertion($value)
    {
        if ($value instanceof DateTime) {
            return new UTCDateTime($value->getTimestamp() * 1000);
        }

        return $value;
    }

    /**
     * Set custom options for the query.
     *
     * @param array $options
     *
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }
}
