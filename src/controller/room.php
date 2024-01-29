<?php

namespace Kevachat\Geminiapp\Controller;

class Room
{
    private $_config;

    private $_session;

    private \Kevachat\Kevacoin\Client $_kevacoin;
    private \Yggverse\Cache\Memory $_memory;

    public function __construct($config)
    {
        // Init memory
        $this->_memory = new \Yggverse\Cache\Memory(
            $config->memcached->server->host,
            $config->memcached->server->port,
            $config->memcached->server->namespace,
            $config->memcached->server->timeout
        );

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
        // Get room list
        $namespaces = [];

        // Get recorded rooms
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

            // Get totals
            $time = 0;

            $total = $this->_total(
                $namespace['namespaceId'],
                $time
            );

            // Format date
            if ($time)
            {
                $date = date(
                    'Y-m-d ·',
                    $time
                );
            }

            else
            {
                $date = null;
            }

            // Add to room list
            $namespaces[$namespace['namespaceId']] =
            [
                'name'  => $namespace['displayName'],
                'date'  => $date,
                'total' => $total
            ];
        }

        // Get rooms contain pending data
        foreach ((array) $this->_kevacoin->kevaPending() as $pending)
        {
            // Get totals
            $time = 0;

            $total = $this->_total(
                $pending['namespace'],
                $time
            );

            // Format date
            if ($time)
            {
                $date = date(
                    'Y-m-d ·',
                    $time
                );
            }

            else
            {
                $date = null;
            }

            // Add to room list
            $namespaces[$pending['namespace']] =
            [
                'name'  => $this->_namespace(
                    $pending['namespace']
                ),
                'date'  => $date,
                'total' => $total
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
        foreach ($namespaces as $namespace => $value)
        {
            $rooms[] = str_replace(
                [
                    '{name}',
                    '{date}',
                    '{total}',
                    '{link}'
                ],
                [
                    $value['name'],
                    $value['date'],
                    sprintf(
                        '%s %s',
                        $value['total'],
                        $this->_plural(
                            $value['total'],
                            [
                                _('post'),
                                _('posts'),
                                _('posts')
                            ]
                        )
                    ),
                    $this->_link(
                        sprintf(
                            '/room/%s',
                            $namespace
                        )
                    )
                ],
                $view
            );
        }

        // Build response
        return str_replace(
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
    }

    public function posts(string $namespace): ?string
    {
        $raw = [];

        // Get pending
        foreach ((array) $this->_kevacoin->kevaPending() as $pending)
        {
            // Ignore other namespaces
            if ($pending['namespace'] != $namespace)
            {
                continue;
            }

            $raw[] = $pending;
        }

        // Get records
        foreach ((array) $this->_kevacoin->kevaFilter($namespace) as $record)
        {
            $raw[] = $record;
        }

        // Get posts
        $posts = [];

        // Process
        foreach ($raw as $data)
        {
            if ($post = $this->_post($namespace, $data, $raw, null, $time))
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
            urldecode(
                $message
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

    private function _post(
        string $namespace,
        array $data,
        array $raw = [],
        ?string $field = null,
        ?int &$time = 0
    ): ?string
    {
        // Skip values with meta keys
        if (str_starts_with($data['key'], '_'))
        {
            return null;
        }

        // Validate value format allowed in settings
        if (!preg_match((string) $this->_config->kevachat->post->value->regex, $data['value']))
        {
            return null;
        }

        // Validate key format allowed in settings
        if (!preg_match($this->_config->kevachat->post->key->regex, $data['key'], $matches))
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

        // Return timestamp
        $time = $matches[1];

        // Is raw field request
        if ($field)
        {
            return isset($data[$field]) ? $data[$field] : null;
        }

        // Legacy usernames backport
        if (!preg_match((string) $this->_config->kevachat->user->name->regex, $matches[2]))
        {
            $matches[2] = 'anon';
        }

        // Try to find related quote value
        $quote = null;
        if (preg_match('/^@([A-z0-9]{64})/', $data['value'], $mention))
        {
            // Message starts with mention
            if (!empty($mention[1]))
            {
                // Use original mention as quote by default
                $quote = $mention[1];

                // Try to replace with post message by txid
                foreach ($raw as $post)
                {
                    if ($post['txid'] == $quote)
                    {
                        $quote = $this->_quote(
                            $this->_post(
                                $namespace,
                                $post,
                                $raw,
                                'value'
                            ),
                            true
                        );

                        break;
                    }
                }

                // Remove original mention from message
                $data['value'] = preg_replace(
                    '/^@([A-z0-9]{64})/',
                    null,
                    $this->_escape(
                        $data['value']
                    )
                );
            }
        }

        // Init links
        $links = [];

        // Generate related links
        if (preg_match('/N[A-z0-9]{33}/', $data['value'], $values))
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

                // Room
                else
                {
                    $links[] = $this->_link(
                        sprintf(
                            '/room/%s',
                            $value
                        ),
                        $this->_namespace(
                            $value
                        ),
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
                $data['txid']
            ),
            _('Reply'),
            true
        );

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
                        $data['value']
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
                        $cache + time()
                    );

                    return $result;
                }
            }
        }

        return null;
    }

    public function _namespace(string $namespace, ?int $cache = 31104000): string
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
                    $cache + time()
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
                        $cache + time()
                    );

                    return $record['value'];
                }
            }
        }

        return $namespace;
    }

    private function _total(string $namespace, ?int &$updated = 0): int
    {
        // Check for updated cache
        $updated = (int) $this->_memory->get(
            [
                __METHOD__,
                $namespace,
                'updated'
            ]
        );

        // Check for total cache
        if ($updated && false !== $total = $this->_memory->get([__METHOD__, $namespace])) // can be 0
        {
            return $total;
        }

        $raw = [];

        // Get pending
        foreach ((array) $this->_kevacoin->kevaPending() as $pending)
        {
            // Ignore other namespaces
            if ($pending['namespace'] != $namespace)
            {
                continue;
            }

            $raw[] = $pending;
        }

        // Get records
        foreach ((array) $this->_kevacoin->kevaFilter($namespace) as $record)
        {
            $raw[] = $record;
        }

        // Count begin
        $total = 0;

        foreach ($raw as $data)
        {
            // Is valid post
            if ($this->_post($namespace, $data, [], 'txid', $time))
            {
                // Get last post time
                if ($time && $time > $updated)
                {
                    $updated = $time;
                }

                // Increase totals
                $total++;
            }
        }

        // Cache results
        $this->_memory->set(
            [
                __METHOD__,
                $namespace
            ],
            $total
        );

        $this->_memory->set(
            [
                __METHOD__,
                $namespace,
                'updated'
            ],
            $updated
        );

        return $total;
    }
}