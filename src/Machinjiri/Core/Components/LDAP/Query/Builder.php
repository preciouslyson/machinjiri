<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP\Query;

use Mlangeni\Machinjiri\Core\Components\LDAP\Connection;
use Mlangeni\Machinjiri\Core\Components\LDAP\Entry;
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class Builder
{
    protected Connection $connection;
    protected string $baseDn;
    protected array $wheres = [];
    protected array $attributes = [];
    protected int $sizeLimit = 0;
    protected int $timeLimit = 0;
    protected int $deref = 0;
    protected int $scope = 2;
    protected int $pageSize = 0;
    protected bool $cacheResults = false;
    protected int $cacheTtl = 300;
    protected Logger $logger;
    protected EventListener $events;
    protected ?CacheManager $cache = null;

    public function __construct(
        Connection $connection,
        ?Logger $logger = null,
        ?EventListener $events = null,
        ?CacheManager $cache = null
    ) {
        $this->connection = $connection;
        $this->baseDn = $connection->getBaseDn();
        $this->logger = $logger ?? resolve(Logger::class);
        $this->events = $events ?? resolve(EventListener::class);
        $this->cache = $cache ?? resolve(CacheManager::class);
    }

    public function baseDn(string $dn): self
    {
        $this->baseDn = $dn;
        return $this;
    }

    public function where(string $attribute, string $operator, $value): self
    {
        $this->wheres[] = ['type' => 'basic', 'attribute' => $attribute, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    public function orWhere(string $attribute, string $operator, $value): self
    {
        $this->wheres[] = ['type' => 'or', 'attribute' => $attribute, 'operator' => $operator, 'value' => $value];
        return $this;
    }

    /**
     * Nested conditions (closure)
     */
    public function whereNested(callable $callback, string $boolean = 'and'): self
    {
        $nested = new static($this->connection, $this->logger, $this->events, $this->cache);
        $callback($nested);
        $this->wheres[] = ['type' => 'nested', 'query' => $nested, 'boolean' => $boolean];
        return $this;
    }

    public function rawFilter(string $filter): self
    {
        $this->wheres[] = ['type' => 'raw', 'filter' => $filter];
        return $this;
    }

    public function whereMemberOf(string $groupDn): self
    {
        $escaped = ldap_escape($groupDn, '', LDAP_ESCAPE_FILTER);
        return $this->rawFilter("(memberOf={$escaped})");
    }

    public function select(array $attributes = []): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    public function sizeLimit(int $limit): self
    {
        $this->sizeLimit = $limit;
        return $this;
    }

    public function timeLimit(int $seconds): self
    {
        $this->timeLimit = $seconds;
        return $this;
    }

    public function scope(int $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function paginate(int $pageSize = 1000): self
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function cache(int $ttl = 300): self
    {
        $this->cacheResults = true;
        $this->cacheTtl = $ttl;
        return $this;
    }

    public function get(): array
    {
        $this->events->trigger('ldap.query.before', $this);

        $filter = $this->compileFilter();
        $cacheKey = $this->buildCacheKey($filter);

        if ($this->cacheResults && $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->debug('LDAP query cache hit', ['key' => $cacheKey]);
                return $cached;
            }
        }

        $link = $this->connection->getLink();
        $entries = [];

        // Use modern paging control if supported (PHP >= 7.3)
        if ($this->pageSize > 0) {
            $cookie = '';
            $controls = [
                [
                    'oid' => LDAP_CONTROL_PAGEDRESULTS,
                    'value' => ['size' => $this->pageSize, 'cookie' => ''],
                    'iscritical' => true,
                ]
            ];
            do {
                $result = ldap_search(
                    $link,
                    $this->baseDn,
                    $filter,
                    $this->attributes,
                    0,
                    $this->sizeLimit,
                    $this->timeLimit,
                    $this->deref,
                    $controls
                );
                if (!$result) {
                    throw new LdapException('LDAP search failed: ' . ldap_error($link), 500, null, ['filter' => $filter]);
                }
                $entries = array_merge($entries, ldap_get_entries($link, $result));
                ldap_parse_result($link, $result, $errCode, $matchedDN, $errMsg, $referrals, $controls);
                $cookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? null;
            } while ($cookie && $cookie != '');
        } else {
            $result = ldap_search(
                $link,
                $this->baseDn,
                $filter,
                $this->attributes,
                0,
                $this->sizeLimit,
                $this->timeLimit,
                $this->deref
            );
            if (!$result) {
                throw new LdapException('LDAP search failed: ' . ldap_error($link), 500, null, ['filter' => $filter]);
            }
            $entries = ldap_get_entries($link, $result);
        }

        $parsed = $this->parseEntries($entries);

        $this->logger->debug('LDAP query executed', ['filter' => $filter, 'count' => count($parsed)]);

        if ($this->cacheResults && $this->cache) {
            $this->cache->set($cacheKey, $parsed, $this->cacheTtl);
        }

        $this->events->trigger('ldap.query.after', ['entries' => $parsed, 'filter' => $filter]);
        return $parsed;
    }

    public function first(): ?Entry
    {
        $results = $this->get();
        return $results[0] ?? null;
    }

    protected function compileFilter(): string
    {
        if (empty($this->wheres)) {
            return '(objectClass=*)';
        }

        $conditions = [];
        $orGroups = [];

        foreach ($this->wheres as $where) {
            if ($where['type'] === 'raw') {
                $conditions[] = $where['filter'];
            } elseif ($where['type'] === 'nested') {
                $subFilter = $where['query']->compileFilter();
                if ($where['boolean'] === 'and') {
                    $conditions[] = $subFilter;
                } else {
                    $orGroups[] = $subFilter;
                }
            } else {
                $escaped = ldap_escape($where['value'], '', LDAP_ESCAPE_FILTER);
                $attr = $where['attribute'];
                switch ($where['operator']) {
                    case '=':
                        $cond = "({$attr}={$escaped})";
                        break;
                    case '!=':
                        $cond = "(!({$attr}={$escaped}))";
                        break;
                    case 'contains':
                        $cond = "({$attr}=*{$escaped}*)";
                        break;
                    case 'starts_with':
                        $cond = "({$attr}={$escaped}*)";
                        break;
                    case 'ends_with':
                        $cond = "({$attr}=*{$escaped})";
                        break;
                    default:
                        throw new LdapException("Unsupported operator: {$where['operator']}");
                }
                if ($where['type'] === 'or') {
                    $orGroups[] = $cond;
                } else {
                    $conditions[] = $cond;
                }
            }
        }

        // Combine OR groups
        if (!empty($orGroups)) {
            $orFilter = count($orGroups) > 1 ? '(|' . implode('', $orGroups) . ')' : $orGroups[0];
            $conditions[] = $orFilter;
        }

        if (count($conditions) === 1) {
            return $conditions[0];
        }

        return '(&' . implode('', $conditions) . ')';
    }

    protected function parseEntries(array $rawEntries): array
    {
        $entries = [];
        if (isset($rawEntries['count'])) {
            for ($i = 0; $i < $rawEntries['count']; $i++) {
                $entries[] = new Entry($rawEntries[$i], $this->connection->getBaseDn());
            }
        }
        return $entries;
    }

    protected function buildCacheKey(string $filter): string
    {
        $key = 'ldap:' . md5($filter . '|' . $this->baseDn . '|' . implode(',', $this->attributes));
        return $key;
    }
}