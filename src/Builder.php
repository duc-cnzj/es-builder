<?php

namespace DucCnzj\EsBuilder;

use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use DucCnzj\EsBuilder\Contracts\BuilderInterface;

/**
 * Class Builder
 * @package DucCnzj\EsBuilder
 */
class Builder implements BuilderInterface
{
    use Macroable;

    /**
     * @var string
     */
    protected $nullableDate = '1970-01-01T00:00:00+08:00';

    /**
     * @var array
     */
    protected $operators = [
        '>'  => 'gt',
        '>=' => 'gte',
        '<'  => 'lt',
        '<=' => 'lte',
    ];

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var array
     */
    protected $with = [];

    /**
     * @var array
     */
    protected $where = [];

    /**
     * @var array
     */
    protected $whereNot = [];

    /**
     * @var array
     */
    protected $sort = [];

    /**
     * @var bool
     */
    protected $withTrash = false;

    /**
     * @var array
     */
    protected $source = [];

    /**
     * @var array
     */
    protected $range = [];

    /**
     * @var array
     */
    protected $regexp = [];

    /**
     * @var
     */
    protected $perPage;

    /**
     * @var int
     */
    protected $page = 1;

    /**
     * @var bool
     */
    protected $paginate = true;

    /**
     * @var int
     */
    protected $from = 0;

    /**
     * @var int
     */
    protected $size = 15;

    /**
     * Builder constructor.
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * @param $relations
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function with($relations)
    {
        $this->with = is_array($relations) ? $relations : func_get_args();

        return $this;
    }

    /**
     * @return $this
     *
     * @author duc <1025434218@qq.com>
     */
    public function withTrashed()
    {
        if (in_array(SoftDeletes::class, trait_uses_recursive($this->model))) {
            $this->withTrash = true;
        }

        return $this;
    }

    /**
     * @param string $field
     * @param null $operator
     * @param $value
     * @return BuilderInterface
     *
     * @author duc <1025434218@qq.com>
     */
    public function where(string $field, $operator = null, $value = null)
    {
        if (func_num_args() == 2) {
            $this->where = array_merge_recursive($this->where, [$field => is_array($operator) ? $operator : [$operator]]);

            return $this;
        }

        if ($this->invalidOperator($operator)) {
            $this->range[] = ['field' => $field, 'operator' => $operator, 'value' => Carbon::parse($value)->toIso8601String()];

            return $this;
        }

        if ($operator == '=') {
            $this->where = array_merge_recursive($this->where, [$field => is_array($value) ? $value : [$value]]);

            return $this;
        }

        if (in_array($operator, ['!=', '<>'])) {
            $this->whereNotIn($field, [$value]);

            return $this;
        }

        if (strtolower($operator) == 'like') {
            $value = str_replace('%', '.*', $value);
            $this->regexp[] = ['field' => $field, 'value' => $value];

            return $this;
        }

        return $this;
    }

    /**
     * @param string $field
     * @param array $value
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function whereNotIn(string $field, array $value)
    {
        $this->whereNot = array_merge_recursive($this->whereNot, [$field => $value]);

        return $this;
    }

    /**
     * @param string $field
     * @param array $value
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function whereIn(string $field, array $value)
    {
        $this->where = array_merge_recursive($this->where, [$field => $value]);

        return $this;
    }

    /**
     * @param string $field
     * @param string $direction
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function orderBy(string $field, $direction = 'asc')
    {
        $this->sort[] = [$field => $direction];

        return $this;
    }

    /**
     * @param array $columns
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function first(array $columns = ['*'])
    {
        return $this->get($columns)->first();
    }

    /**
     * @param array $columns
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    public function get(array $columns = ['*'])
    {
        $this->paginate = false;

        $this->prepareAttributes();
        if ($columns != ['*']) {
            $this->source = $columns;
        }

        $res = $this->engine()->search($this->buildEsParams());
        $data = data_get($res, 'hits.hits.*._source', []);
        $data = $this->newModelCollection($data);

        return $this->with ? $data->load($this->with) : $data;
    }

    /**
     * @param int $perPage
     * @param array $columns
     * @param int $page
     * @return LengthAwarePaginator
     *
     * @author duc <1025434218@qq.com>
     */
    public function paginate(int $perPage = 15, $columns = ['*'], $page = 1)
    {
        $this->perPage = $perPage;
        $this->page = $page;
        $this->prepareAttributes();
        if ($columns != ['*']) {
            $this->source = $columns;
        }

        $res = $this->engine()->search($this->buildEsParams());

        $items = data_get($res, 'hits.hits.*._source', []);
        $total = data_get($res, 'hits.total.value', 0);

        $items = $this->newModelCollection($items);
        $items = $this->with ? $items->load($this->with) : $items;

        return (new LengthAwarePaginator($items, $total, $this->perPage, $this->page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]));
    }

    /**
     * @return mixed
     *
     * @author duc <1025434218@qq.com>
     */
    protected function engine()
    {
        return $this->model->searchableUsing();
    }

    /**
     * @param string $operator
     * @return bool
     *
     * @author duc <1025434218@qq.com>
     */
    protected function invalidOperator(string $operator)
    {
        return in_array($operator, array_keys($this->operators));
    }

    /**
     * @return array
     *
     * @author duc <1025434218@qq.com>
     */
    protected function buildEsParams()
    {
        if (! $this->paginate) {
            $this->from = 0;
            $this->size = config('es.track_total_hits', 10000);
        }

        if (method_exists($this->model, 'esIndex')) {
            $index = $this->model->esIndex();
        } else {
            $index = $this->model->getTable();
        }

        $params = [
            'index' => $index,
            'type'  => '_doc',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must'     => array_merge($this->where, $this->range, $this->regexp),
                        'must_not' => $this->whereNot,
                    ],
                ],
                'sort'  => $this->sort,
            ],
            'size'  => $this->size,
            'from'  => $this->from,
        ];

        if ($this->source) {
            $params['_source'] = $this->source;
        }

        return $params;
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepareAttributes()
    {
        $this->ensureTrash();
        $this->prepareWhere();
        $this->prepareWhereNot();
        $this->prepareOffset();
        $this->prepareRange();
        $this->prepareRegexp();
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepareWhere()
    {
        $this->where = $this->prepare($this->where);
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepareOffset()
    {
        if (! $this->paginate) {
            return;
        }

        $this->size = $this->perPage;
        $this->from = $this->size *
            (
            $this->page > 1
                ? $this->page - 1
                : 0
            );
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepareWhereNot()
    {
        $this->whereNot = $this->prepare($this->whereNot);
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepareRange()
    {
        $ranges = collect($this->range ?? [])
            ->reduce(function ($carry, $item) {
                $data = [
                    $item['field'] => [
                        $this->operators[$item['operator']] => $item['value'],
                    ],
                ];

                return array_merge_recursive($carry ?? [], $data);
            });
        $this->range = collect($ranges)
            ->map(function ($item, $key) {
                return [
                    'range' => [$key => $item],
                ];
            })->values()->toArray();
    }

    /**
     *
     * @author 神符 <1025434218@qq.com>
     */
    protected function prepareRegexp()
    {
        $this->regexp = collect($this->regexp)
            ->map(function ($item) {
                return
                    [
                        'regexp' => [
                            $item['field'] => [
                                'value' => $item['value'],
                            ],
                        ],
                    ];
            })
            ->values()
            ->toArray();
    }

    /**
     *
     * @author duc <1025434218@qq.com>
     */
    protected function ensureTrash()
    {
        if (! $this->withTrash) {
            $this->where['deleted_at'] = [
                $this->nullableDate(),
            ];
        } else {
            unset($this->where['deleted_at']);
        }
    }

    /**
     * @return string
     *
     * @author duc <1025434218@qq.com>
     */
    protected function nullableDate()
    {
        if (method_exists($this->model, 'nullableDate')) {
            return $this->model->nullableDate();
        }

        return $this->nullableDate;
    }

    /**
     * @param array $attributes
     * @return array
     *
     * @author duc <1025434218@qq.com>
     */
    protected function prepare(array $attributes)
    {
        return collect($attributes)
            ->map(function ($filter, $key) {
                $filter = array_unique($filter);
                if (count($filter) == 1) {
                    return [
                        'term' => [
                            $key => $filter[0],
                        ],
                    ];
                }

                if (count($filter) > 1) {
                    return [
                        'terms' => [
                            $key => $filter,
                        ],
                    ];
                }

                return [];
            })
            ->values()
            ->toArray();
    }

    /**
     * @param $data
     * @return mixed
     *
     * @author 神符 <1025434218@qq.com>
     */
    protected function newModelCollection($data)
    {
        $class = get_class($this->model);
        $model = new $class;
        $dates = $model->getDates();

        $collectData = $this->transformDate($data, $dates);

        $data = $model->hydrate($collectData);

        return $data;
    }

    /**
     * @param $items
     * @param array $dates
     * @return array
     *
     * @author 神符 <1025434218@qq.com>
     */
    protected function transformDate($items, array $dates): array
    {
        $collectData = collect($items)->map(function ($data) use ($dates) {
            foreach ($dates as $date) {
                if (isset($data[$date])) {
                    $data[$date] = Carbon::parse($data[$date]);
                }
            }

            return $data;
        })->toArray();

        return $collectData;
    }
}
