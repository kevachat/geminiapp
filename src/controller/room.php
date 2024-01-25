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

            // Calculate room totals
            $total = 0;

            foreach ((array) $this->_kevacoin->kevaFilter($namespace['namespaceId']) as $record)
            {
                // Skip values with meta keys
                if (str_starts_with($record['key'], '_'))
                {
                    continue;
                }

                // Validate value format allowed in settings
                if (!preg_match((string) $this->_config->kevachat->post->value->regex, $record['value']))
                {
                    continue;
                }

                // Validate key format allowed in settings
                if (!preg_match($this->_config->kevachat->post->key->regex, $record['key'], $matches))
                {
                    continue;
                }

                // Timestamp required in key
                if (empty($matches[1]))
                {
                    continue;
                }

                // Username required in key
                if (empty($matches[2]))
                {
                    continue;
                }

                // Legacy usernames backport (used to replace undefined names to @anon)
                /*
                if (!preg_match((string) $this->_config->kevachat->user->name->regex, $matches[2]))
                {}
                */

                $total++;
            }

            // Add to room list
            $namespaces[] =
            [
                'namespace' => $namespace['namespaceId'],
                'name'      => $namespace['displayName'],
                'total'     => $total,
                'pin'       => in_array(
                    $namespace['namespaceId'],
                    $this->_config->kevachat->room->pin
                )
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
                    (
                        $this->_config->gemini->server->port == 1965 ?
                        sprintf(
                            'gemini://%s/room/%s',
                            $this->_config->gemini->server->host,
                            $namespace['namespace']
                        ) :
                        sprintf(
                            'gemini://%s:%d/%s',
                            $this->_config->gemini->server->host,
                            $this->_config->gemini->server->port,
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
                '{rooms}'
            ],
            [
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
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
}