<?php

namespace Kevachat\Geminiapp\Controller;

class Room
{
    private $_config;
    private $_memory;

    private $_session;

    private \Kevachat\Kevacoin\Client $_kevacoin;

    public function __construct(\Yggverse\Cache\Memory $memory, $config)
    {
        // Init memory
        $this->_memory = $memory;

        // Init session
        $this->_session = rand();

        $this->_memory->set(
            $this->_session,
            time()
        );

        // Init config
        $this->_config = $config;

        // Init kevacoin
        $this->_kevacoin = new \Kevachat\Kevacoin\Client(
            $this->_config->kevacoin->server->protocol,
            $this->_config->kevacoin->server->host,
            $this->_config->kevacoin->server->port,
            $this->_config->kevacoin->server->username,
            $this->_config->kevacoin->server->password
        );
    }

    public function list(): string
    {
        // Check for cache
        if ($result = $this->_memory->get([__METHOD__]))
        {
            return $result;
        }

        // Get room list
        $namespaces = [];

        foreach ((array) $this->_kevacoin->kevaListNamespaces() as $namespace)
        {
            // Skip system namespaces
            if (str_starts_with($namespace['displayName'], '_'))
            {
                continue;
            }

            // Validate room name compatible with settings
            if (!preg_match((string) $this->_config->kevachat->room->key->regex, $namespace['displayName']))
            {
                continue;
            }

            // Calculate room totals
            $total = 0;

            foreach ((array) $this->_kevacoin->kevaFilter($namespace['namespaceId']) as $record)
            {
                // Is protocol compatible post
                if ($this->_post($namespace['namespaceId'], $record['key'], [], 'txid'))
                {
                    $total++;
                }
            }

            // Add to room list
            $namespaces[] =
            [
                'namespace' => $namespace['namespaceId'],
                'name'      => $namespace['displayName'],
                'total'     => $total
            ];
        }

        // Sort rooms by total
        array_multisort(
            array_column(
                $namespaces,
                'total'
            ),
            SORT_DESC,
            $namespaces
        );

        // Build rooms view
        $view = file_get_contents(
            __DIR__ . '/../view/room.gemini'
        );

        $rooms = [];
        foreach ($namespaces as $namespace)
        {
            $rooms[] = str_replace(
                [
                    '{name}',
                    '{total}',
                    '{link}'
                ],
                [
                    $namespace['name'],
                    $namespace['total'],
                    $this->_link(
                        sprintf(
                            '/room/%s',
                            $namespace['namespace']
                        )
                    )
                ],
                $view
            );
        }

        // Build final view and send to response
        $result = str_replace(
            [
                '{logo}',
                '{about}',
                '{rooms}'
            ],
            [
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
                ),
                implode(
                    PHP_EOL,
                    $this->_config->kevachat->about
                ),
                implode(
                    PHP_EOL,
                    $rooms
                )
            ],
            file_get_contents(
                __DIR__ . '/../view/rooms.gemini'
            )
        );

        // Cache results
        $this->_memory->set(
            [
                __METHOD__
            ],
            $result
        );

        return $result;
    }

    public function posts(string $namespace): ?string
    {
        // Get records by namespace
        if (!$records = (array) $this->_records($namespace))
        {
            return null;
        }

        // Get posts
        $posts = [];

        foreach ($records as $record)
        {
            if ($post = $this->_post($namespace, $record['key'], $records, null, $time))
            {
                $posts[$time] = $post;
            }
        }

        // Get pending posts
        foreach ($this->_pending() as $pending)
        {
            if ($post = $this->_post($namespace, $pending['key'], $records, null, $time))
            {
                $posts[$time] = $post;
            }
        }

        // Sort posts by time
        krsort($posts);

        // Build result
        return str_replace(
            [
                '{logo}',
                '{home}',
                '{post}',
                '{subject}',
                '{posts}',
                '{session}'
            ],
            [
                // logo
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
                ),

                // home
                $this->_link(),

                // post
                $this->_link( // @TODO
                    sprintf(
                        '/room/%s/{session}/post',
                        $namespace
                    )
                ),

                // subject
                $this->_namespace(
                    $namespace
                ),

                // posts
                implode(
                    PHP_EOL,
                    $posts
                ),

                // session
                $this->_session
            ],
            file_get_contents(
                __DIR__ . '/../view/posts.gemini'
            )
        );
    }

    public function post(string $namespace, ?string $txid, int $session, string $message): ?string
    {
        // Validate funds available yet
        if (1 > $this->_kevacoin->getBalance())
        {
            return null;
        }

        // Validate session exists
        if (!$this->_memory->get($session))
        {
            return null;
        }

        // Validate value format allowed in settings
        if (!preg_match((string) $this->_config->kevachat->post->value->regex, $message))
        {
            return null;
        }

        // Prepare message
        $message = trim(
            strip_tags(
                urldecode(
                    $message
                )
            )
        );

        // Append mention if provided
        if ($txid)
        {
            $message = '@' . $txid . PHP_EOL . $message;
        }

        // Validate final message length
        if (mb_strlen($message) < 1 || mb_strlen($message) > 3072)
        {
            return null;
        }

        // Send message
        if
        (
            $txid = $this->_kevacoin->kevaPut(
                $namespace,
                sprintf(
                    '%s@anon',
                    time()
                ),
                $message
            )
        )
        {
            // Cleanup session
            $this->_memory->delete(
                $session
            );

            // Reset cache
            $this->_memory->delete(
                [
                    'Kevachat\Geminiapp\Controller\Room::_pending',
                    $namespace
                ]
            );

            $this->_memory->delete(
                [
                    'Kevachat\Geminiapp\Controller\Room::_records',
                    $namespace
                ]
            );

            // Success
            return $txid;
        }

        return null;
    }

    public function sent(string $namespace, string $txid)
    {
        return str_replace(
            [
                '{logo}',
                '{txid}',
                '{room}'
            ],
            [
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
                ),
                $txid,
                $this->_link(
                    sprintf(
                        '/room/%s',
                        $namespace
                    )
                )
            ],
            file_get_contents(
                __DIR__ . '/../view/sent.gemini'
            )
        );
    }

    private function _post(string $namespace, string $key, array $posts = [], ?string $field = null, ?int &$time = 0, ?int $cache = 31104000): ?string
    {
        // Check for cache
        $result = $this->_memory->get(
            [
                __METHOD__,
                $namespace,
                $key,
                $field
            ]
        );

        $time = $this->_memory->get(
            [
                __METHOD__,
                $namespace,
                $key,
                $field,
                'time'
            ]
        );

        if ($result && $time)
        {
            return $result;
        }

        // Check record exists
        if (!$record = (array) $this->_kevacoin->kevaGet($namespace, $key))
        {
            return null;
        }

        // Skip values with meta keys
        if (str_starts_with($record['key'], '_'))
        {
            return null;
        }

        // Validate value format allowed in settings
        if (!preg_match((string) $this->_config->kevachat->post->value->regex, $record['value']))
        {
            return null;
        }

        // Validate key format allowed in settings
        if (!preg_match($this->_config->kevachat->post->key->regex, $record['key'], $matches))
        {
            return null;
        }

        // Timestamp required in key
        if (empty($matches[1]))
        {
            return null;
        }

        // Username required in key
        if (empty($matches[2]))
        {
            return null;
        }

        // Is raw field request
        if ($field)
        {
            return isset($record[$field]) ? $record[$field] : null;
        }

        // Legacy usernames backport
        if (!preg_match((string) $this->_config->kevachat->user->name->regex, $matches[2]))
        {
            $matches[2] = 'anon';
        }

        // Try to find related quote value
        $quote = null;
        if (preg_match('/^@([A-z0-9]{64})/', $record['value'], $mention))
        {
            // Message starts with mention
            if (!empty($mention[1]))
            {
                // Use original mention as quote by default
                $quote = $mention[1];

                // Try to replace with post message by txid
                foreach ($posts as $post)
                {
                    if ($post['txid'] == $quote)
                    {
                        $quote = $this->_quote(
                            $post['value'],
                            true
                        );

                        break;
                    }
                }

                // Remove mention from message
                $record['value'] = preg_replace(
                    '/^@([A-z0-9]{64})/',
                    null,
                    $this->_escape(
                        $record['value']
                    )
                );
            }
        }

        // Init links
        $links = [];

        // Generate related links
        if (preg_match('/N[A-z0-9]{33}/', $record['value'], $values))
        {
            foreach ($values as $value)
            {
                // Media attachments
                if ($name = $this->_clitoris($value))
                {
                    $links[] = $this->_link(
                        sprintf(
                            '/raw/%s',
                            $value
                        ),
                        $name,
                        true
                    );
                }

                // Namespace clickable
                else if ($name = $this->_namespace($value))
                {
                    $links[] = $this->_link(
                        sprintf(
                            '/room/%s',
                            $value
                        ),
                        $name,
                        true
                    );
                }
            }
        }

        // Reply link
        $links[] = $this->_link(
            sprintf(
                '/room/%s/%s/{session}/reply',
                $namespace,
                $record['txid']
            ),
            _('Reply'),
            true
        );

        // Return timestamp
        $time = $matches[1];

        // Build final view and save to result
        $result = preg_replace(
            [
                '/[\n\r]{1}/',
                '/[\n\r]{3,}/'
            ],
            [
                PHP_EOL,
                PHP_EOL . PHP_EOL
            ],

            // Apply macros
            str_replace(
                [
                    '{time}',
                    '{author}',
                    '{quote}',
                    '{message}',
                    '{links}'
                ],
                [
                    // time
                    $this->_ago(
                        $matches[1]
                    ),

                    // author
                    '@' . $matches[2],

                    // quote
                    $quote,

                    // message
                    $this->_escape(
                        $record['value']
                    ),

                    // links
                    implode(
                        PHP_EOL,
                        $links
                    )
                ],
                file_get_contents(
                    __DIR__ . '/../view/post.gemini'
                )
            )
        );

        // Cache results
        $this->_memory->set(
            [
                __METHOD__,
                $namespace,
                $key,
                $field
            ],
            $result,
            $cache
        );

        $this->_memory->set(
            [
                __METHOD__,
                $namespace,
                $key,
                $field,
                $time
            ],
            $time,
            $cache
        );

        return $result;
    }

    private function _ago(int $time): string
    {
        $diff = time() - $time;

        if ($diff < 1)
        {
            return _('now');
        }

        $values =
        [
            365 * 24 * 60 * 60 =>
            [
                _('year ago'),
                _('years ago'),
                _(' years ago')
            ],
            30  * 24 * 60 * 60 =>
            [
                _('month ago'),
                _('months ago'),
                _(' months ago')
            ],
            24 * 60 * 60 =>
            [
                _('day ago'),
                _('days ago'),
                _(' days ago')
            ],
            60 * 60 =>
            [
                _('hour ago'),
                _('hours ago'),
                _(' hours ago')
            ],
            60 =>
            [
                _('minute ago'),
                _('minutes ago'),
                _(' minutes ago')
            ],
            1 =>
            [
                _('second ago'),
                _('seconds ago'),
                _(' seconds ago')
            ]
        ];

        foreach ($values as $key => $value)
        {
            $result = $diff / $key;

            if ($result >= 1)
            {
                $round = round($result);

                return sprintf(
                    '%s %s',
                    $round,
                    $this->_plural(
                        $round,
                        $value
                    )
                );
            }
        }
    }

    public function _bytes(int $bytes, int $precision = 2): string
    {
        $size = [
            'B',
            'Kb',
            'Mb',
            'Gb',
            'Tb',
            'Pb',
            'Eb',
            'Zb',
            'Yb'
        ];

        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$precision}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
    }

    private function _plural(int $number, array $texts)
    {
        $cases = [2, 0, 1, 1, 1, 2];

        return $texts[(($number % 100) > 4 && ($number % 100) < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    private function _escape(string $value)
    {
        // Process each line
        $lines = [];

        foreach ((array) explode(PHP_EOL, $value) as $line)
        {
            // Trim extra separators
            $line = trim(
                $line
            );

            // Escape inline
            $line = preg_replace(
                [
                    '/[*]{1}([^*]+)[*]{1}/',
                    '/[_]{1}([^*]+)[_]{1}/',
                ],
                [
                    '[*]$1[*]',
                    '[_]$1[_]',
                ],
                $line
            );

            // Process each tag on line beginning
            foreach (
                [
                    '###',
                    '##',
                    '#',
                    '=>',
                    '>',
                    '*',
                    '```'
                ] as $tag
            )
            {
                // Escape tags
                $line = preg_replace(
                    sprintf(
                        '/^(\%s)/',
                        $tag
                    ),
                    '[$1]',
                    $line
                );
            }

            // Merge lines
            $lines[] = $line;
        }

        // Merge lines
        return trim(
            implode(
                PHP_EOL,
                $lines
            )
        );
    }

    private function _quote(string $value, ?bool $tag = false)
    {
        // Escape special chars
        $value = $this->_escape(
            $value
        );

        // Remove mention ID from quote
        $value = preg_replace(
            '/^@([A-z0-9]{64})/',
            null,
            $this->_escape(
                $value
            )
        );

        // Process each line
        $lines = [];

        foreach ((array) explode(PHP_EOL, $value) as $line)
        {
            // Skip empty lines
            if (empty($line))
            {
                continue;
            }

            // Append quote tag if requested
            if ($tag)
            {
                $line = '> ' . $line;
            }

            $lines[] = $line;
        }

        // Merge lines
        return trim(
            implode(
                PHP_EOL,
                $lines
            )
        );
    }

    private function _link(?string $path = null, ?string $name = null, ?bool $tag = false)
    {
        return
        (
            $tag ? '=> ' : null
        )
        .
        (
            $this->_config->gemini->server->port == 1965 ?
            sprintf(
                'gemini://%s%s',
                $this->_config->gemini->server->host,
                $path
            ) :
            sprintf(
                'gemini://%s:%d%s',
                $this->_config->gemini->server->host,
                $this->_config->gemini->server->port,
                $path
            )
        )
        .
        (
            $name ? ' ' . $name : null
        );
    }

    private function _clitoris(string $namespace, ?int $cache = 31104000): ?string
    {
        // Check for cache
        if ($result = $this->_memory->get([__METHOD__, $namespace]))
        {
            return $result;
        }

        // Validate namespace supported to continue
        if (preg_match('/^N[A-z0-9]{33}$/', $namespace))
        {
            // Get meta data by namespace
            if ($clitoris = $this->_kevacoin->kevaGet($namespace, '_CLITOR_IS_'))
            {
                $reader = new \ClitorIsProtocol\Kevacoin\Reader(
                    $clitoris['value']
                );

                if ($reader->valid())
                {
                    $result = sprintf(
                        '%s (%s)',
                        $reader->fileName() ? $reader->fileName() : $namespace,
                        $this->_bytes(
                            (int) $reader->fileSize()
                        )
                    );

                    $this->_memory->set(
                        [
                            __METHOD__,
                            $namespace
                        ],
                        $result,
                        $cache
                    );

                    return $result;
                }
            }
        }

        return null;
    }

    public function _namespace(string $namespace, ?int $cache = 31104000): ?string
    {
        // Check for cache
        if ($result = $this->_memory->get([__METHOD__, $namespace]))
        {
            return $result;
        }

        // Find local name
        foreach ((array) $this->_kevacoin->kevaListNamespaces() as $record)
        {
            if ($record['namespaceId'] == $namespace)
            {
                $this->_memory->set(
                    [
                        __METHOD__,
                        $namespace
                    ],
                    $record['displayName'],
                    $cache
                );

                return $record['displayName'];
            }
        }

        // Get namespace records (solution for remote nodes)
        if ($records = (array) $this->_kevacoin->kevaFilter($namespace))
        {
            foreach ($records as $record)
            {
                if ($record['key'] == '_KEVA_NS_')
                {
                    $this->_memory->set(
                        [
                            __METHOD__,
                            $namespace
                        ],
                        $record['value'],
                        $cache
                    );

                    return $record['value'];
                }
            }
        }

        return null;
    }

    private function _pending(): array
    {
        // Check for cache
        if ($result = $this->_memory->get([__METHOD__]))
        {
            return $result;
        }

        // Get pending posts
        $result = [];

        foreach ((array) $this->_kevacoin->kevaPending() as $pending)
        {
            // Ignore pending from other namespaces
            if ($pending['namespace'] != $namespace)
            {
                continue;
            }

            // Ignore everything in pending queue but keva_put
            if ($pending['op'] != 'keva_put')
            {
                continue;
            }

            // Skip meta
            if (str_starts_with($pending['key'], '_'))
            {
                continue;
            }

            $result[] = $pending;
        }

        // Save to cache
        $this->_memory->set(
            [
                __METHOD__
            ],
            $result
        );

        return $result;
    }

    private function _records(string $namespace): array
    {
        // Check for cache
        if ($result = $this->_memory->get([__METHOD__]))
        {
            return $result;
        }

        // Get namespace records
        $result = (array) $this->_kevacoin->kevaFilter(
            $namespace
        );

        // Save to cache
        $this->_memory->set(
            [
                __METHOD__
            ],
            $result
        );

        return $result;
    }
}