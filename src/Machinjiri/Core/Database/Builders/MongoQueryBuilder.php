<?php

namespace Mlangeni\Machinjiri\Core\Database\Builders;

use Closure;
use Mlangeni\Machinjiri\Core\Artisans\Adapters\MongoDB\MongoAdapter;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use MongoDB\Collection;

class MongoQueryBuilder
{
    protected string $collection;
    protected array $columns = ['*'];
    protected array $where = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected string $action = 'select';
    protected array $insertData = [];
    protected array $updateData = [];
    protected ?MongoAdapter $adapter = null;
    protected ?string $database = null;

    public function __construct(string $collection, MongoAdapter|array|null $adapterOrConfig = null, ?string $database = null)
    {
        $this->collection = $collection;
        $this->database = $database;

        if ($adapterOrConfig instanceof MongoAdapter) {
            $this->adapter = $adapterOrConfig;
            return;
        }

        if (is_array($adapterOrConfig)) {
            $this->adapter = new MongoAdapter($adapterOrConfig);
            return;
        }

        throw new MachinjiriException('MongoDB Error: A MongoAdapter instance or config array is required.', 300);
    }

    public function select(array $columns = ['*']): self
    {
        $this->action = 'select';
        $this->columns = $columns;
        return $this;
    }

    public function where($column, $operator = null, $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            $this->where[] = [
                'type' => 'nested',
                'query' => $column,
                'boolean' => $boolean,
            ];
            return $this;
        }

        if ($value === null && $operator !== null && !in_array($operator, ['=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'is null'], true)) {
            $value = $operator;
            $operator = '=';
        }

        if ($value === null && $operator === null) {
            $operator = '=';
            $value = null;
        }

        $this->where[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator ?? '=',
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->addBinding($value);
        return $this;
    }

    public function orWhere(string $column, string $operator, $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];
        $this->addBinding($values);
        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC',
        ];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function insert(array $data): self
    {
        $this->action = 'insert';
        $this->insertData = $data;
        $this->addBinding(array_values($data));
        return $this;
    }

    public function update(array $data): self
    {
        $this->action = 'update';
        $this->updateData = $data;
        $this->addBinding(array_values($data));
        return $this;
    }

    public function delete(): self
    {
        $this->action = 'delete';
        return $this;
    }

    protected function addBinding($value): void
    {
        if (is_array($value)) {
            $this->bindings = array_merge($this->bindings, $value);
            return;
        }

        $this->bindings[] = $value;
    }

    public function get(): array
    {
        return $this->execute();
    }

    public function first(): ?array
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function execute(): array
    {
        try {
            return match ($this->action) {
                'select' => $this->runSelect(),
                'insert' => $this->runInsert(),
                'update' => $this->runUpdate(),
                'delete' => $this->runDelete(),
                default => throw new MachinjiriException('MongoDB Error: Invalid query action.', 304),
            };
        } finally {
            $this->reset();
        }
    }

    protected function runSelect(): array
    {
        $collection = $this->getCollection();
        $filter = $this->compileFilter();
        $options = [];

        if (!empty($this->orderBy)) {
            $options['sort'] = $this->compileSort();
        }

        if ($this->limit !== null) {
            $options['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $options['skip'] = $this->offset;
        }

        if ($this->columns !== ['*']) {
            $projection = [];
            foreach ($this->columns as $column) {
                $projection[$column] = 1;
            }
            $options['projection'] = $projection;
        }

        $cursor = $collection->find($filter, $options);
        $results = [];

        foreach ($cursor as $document) {
            $results[] = $this->columns === ['*'] ? $this->normalizeDocument($document) : $this->projectDocument($document);
        }

        return $results;
    }

    protected function runInsert(): array
    {
        $collection = $this->getCollection();
        $result = $collection->insertOne($this->insertData);

        return [
            'insertedId' => (string) $result->getInsertedId(),
            'rowCount' => 1,
        ];
    }

    protected function runUpdate(): array
    {
        $collection = $this->getCollection();
        $filter = $this->compileFilter();
        $result = $collection->updateMany($filter, ['$set' => $this->updateData]);

        return [
            'matched' => $result->getMatchedCount(),
            'modified' => $result->getModifiedCount(),
            'rowCount' => $result->getModifiedCount(),
        ];
    }

    protected function runDelete(): array
    {
        $collection = $this->getCollection();
        $filter = $this->compileFilter();
        $result = $collection->deleteMany($filter);

        return [
            'deleted' => $result->getDeletedCount(),
            'rowCount' => $result->getDeletedCount(),
        ];
    }

    protected function getCollection(): Collection
    {
        if ($this->adapter === null) {
            throw new MachinjiriException('MongoDB Error: No MongoAdapter instance is available.', 300);
        }

        return $this->adapter->selectCollection($this->collection, $this->database);
    }

    protected function compileFilter(): array
    {
        if (empty($this->where)) {
            return [];
        }

        $andConditions = [];
        $orConditions = [];

        foreach ($this->where as $condition) {
            $clause = $this->compileCondition($condition);

            if ($condition['boolean'] === 'OR') {
                $orConditions[] = $clause;
                continue;
            }

            $andConditions[] = $clause;
        }

        if (!empty($orConditions) && empty($andConditions)) {
            return ['$or' => $orConditions];
        }

        if (!empty($orConditions)) {
            return ['$and' => array_merge($andConditions, [['$or' => $orConditions]])];
        }

        if (count($andConditions) === 1) {
            return $andConditions[0];
        }

        return ['$and' => $andConditions];
    }

    protected function compileCondition(array $condition): array
    {
        return match ($condition['type']) {
            'basic' => $this->compileBasicCondition($condition),
            'in' => [$condition['column'] => ['$in' => $condition['values']]],
            'null' => ['$or' => [
                [$condition['column'] => null],
                [$condition['column'] => ['$exists' => false]],
            ]],
            'nested' => $this->compileNestedCondition($condition),
            default => [],
        };
    }

    protected function compileBasicCondition(array $condition): array
    {
        $operator = match ($condition['operator']) {
            '=' => '$eq',
            '!=' => '$ne',
            '>' => '$gt',
            '>=' => '$gte',
            '<' => '$lt',
            '<=' => '$lte',
            'like' => '$regex',
            default => '$eq',
        };

        $value = $condition['value'];
        if ($operator === '$regex') {
            $value = '/' . str_replace('/', '\\/', (string) $value) . '/';
        }

        return [$condition['column'] => [$operator => $value]];
    }

    protected function compileNestedCondition(array $condition): array
    {
        $subBuilder = clone $this;
        $subBuilder->where = [];
        $subBuilder->bindings = [];
        $subBuilder->orderBy = [];
        $subBuilder->limit = null;
        $subBuilder->offset = null;
        $subBuilder->columns = ['*'];
        $subBuilder->action = 'select';

        call_user_func($condition['query'], $subBuilder);

        return $subBuilder->compileFilter();
    }

    protected function compileSort(): array
    {
        $sort = [];
        foreach ($this->orderBy as $order) {
            $sort[$order['column']] = strtolower($order['direction']) === 'desc' ? -1 : 1;
        }

        return $sort;
    }

    protected function normalizeDocument(array $document): array
    {
        if (isset($document['_id'])) {
            $document['_id'] = (string) $document['_id'];
        }

        return $document;
    }

    protected function projectDocument(array $document): array
    {
        $projected = [];
        foreach ($this->columns as $column) {
            $projected[$column] = $document[$column] ?? null;
        }

        return $projected;
    }

    public function count(string $column = '*', bool $distinct = false): int
    {
        $collection = $this->getCollection();
        $filter = $this->compileFilter();
        $count = $collection->countDocuments($filter);

        if ($distinct && $column !== '*') {
            $docs = $collection->find($filter, ['projection' => [$column => 1], 'limit' => 10000]);
            $values = [];
            foreach ($docs as $doc) {
                $values[(string) ($doc[$column] ?? '')] = true;
            }
            return count($values);
        }

        return (int) $count;
    }

    public function whereDateRange(string $column, string $start, string $end, string $boolean = 'AND'): self
    {
        return $this->where($column, '>=', $start, $boolean)
            ->where($column, '<=', $end, 'AND');
    }

    public function whereCurrentWeek(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        return $this->whereDateRange($column, $start, $end, $boolean);
    }

    public function whereCurrentMonth(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-t 23:59:59');
        return $this->whereDateRange($column, $start, $end, $boolean);
    }

    public function whereCurrentYear(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-01-01 00:00:00');
        $end = date('Y-12-31 23:59:59');
        return $this->whereDateRange($column, $start, $end, $boolean);
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    protected function reset(): void
    {
        $this->columns = ['*'];
        $this->where = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->bindings = [];
        $this->action = 'select';
        $this->insertData = [];
        $this->updateData = [];
    }
}