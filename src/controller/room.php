<?php

namespace Kevachat\Geminiapp\Controller;

class Room
{
    private $_config;

    private \Kevachat\Kevacoin\Client $_kevacoin;

    public function __construct($config)
    {
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
                if ($this->post($namespace['namespaceId'], $record['key'], [], 'txid'))
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
                    $this->_url(
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
                    $this->_config->kevachat->link->about
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
        // Get namespace records
        if (!$records = (array) $this->_kevacoin->kevaFilter($namespace))
        {
            return null;
        }

        // Get subject
        $subject = null;

        foreach ((array) $this->_kevacoin->kevaListNamespaces() as $record) // find local room name
        {
            if ($record['namespaceId'] == $namespace)
            {
                $subject = $record['displayName'];
            }
        }

        // Get posts
        $posts = [];

        foreach ($records as $record)
        {
            if ($post = $this->post($namespace, $record['key'], $records, null, $time))
            {
                $posts[$time] = $post;
            }

            if ($record['key'] == '_KEVA_NS_') // find remote room name
            {
                $subject = $record['value'];
            }
        }

        // Sort posts by time
        krsort($posts);

        // Get template
        return str_replace(
            [
                '{logo}',
                '{home}',
                '{post}',
                '{subject}',
                '{posts}'
            ],
            [
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
                ),
                $this->_url(),
                $this->_url( // @TODO
                    sprintf(
                        '/room/%s/post',
                        $namespace
                    )
                ),
                $subject ? $subject : $namespace,
                implode(
                    PHP_EOL,
                    $posts
                )
            ],
            file_get_contents(
                __DIR__ . '/../view/posts.gemini'
            )
        );
    }

    public function post(string $namespace, string $key, array $posts = [], ?string $field = null, ?int &$time = 0): ?string
    {
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
                        // Strip folding
                        $quote = '>' .
                        trim(
                            preg_replace(
                                '/^@([A-z0-9]{64}[\n\r]?)/',
                                null,
                                // Add quote after each new line
                                str_replace(
                                    PHP_EOL,
                                    PHP_EOL . '>',
                                    preg_replace(
                                        '/[\n\r]+/',
                                        PHP_EOL,
                                        // Ignore markup
                                        $this->_plain(
                                            $post['value']
                                        )
                                    )
                                )
                            ),
                            '>'
                        );

                        break;
                    }
                }

                // Remove mention from message
                $record['value'] = preg_replace(
                    '/^@([A-z0-9]{64})/',
                    null,
                    preg_replace(
                        '/[\n\r]+/',
                        PHP_EOL,
                        $this->_plain(
                            $record['value']
                        )
                    )
                );
            }
        }

        // Return timestamp
        $time = $matches[1];

        // Build final view and send to response
        return str_replace(
            [
                '{txid}',
                '{time}',
                '{author}',
                '{quote}',
                '{message}',
                '{reply}'
            ],
            [
                $record['txid'],
                $this->_ago(
                    $matches[1]
                ),
                '@' . $matches[2],
                $quote,
                preg_replace(
                    '/[\n\r]+/',
                    PHP_EOL,
                    $this->_plain(
                        $record['value']
                    )
                ),
                $this->_url( // @TODO
                    sprintf(
                        '/room/%s/reply/%s',
                        $namespace,
                        $record['txid'],
                    )
                )
            ],
            file_get_contents(
                __DIR__ . '/../view/post.gemini'
            )
        );
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

    private function _plural(int $number, array $texts)
    {
        $cases = [2, 0, 1, 1, 1, 2];

        return $texts[(($number % 100) > 4 && ($number % 100) < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    private function _plain(string $value)
    {
        return trim(
            str_replace(
                [
                    '#',
                    '##',
                    '###',
                    '=>',
                    '>',
                    '*',
                    '```'
                ],
                [
                    ' #',
                    ' ##',
                    ' ###',
                    ' =>',
                    ' >',
                    ' *',
                    ' ```'
                ],
                $value
            )
        );
    }

    private function _url(?string $path = null, ?string $name = null)
    {
        return
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
}