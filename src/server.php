<?php

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Scan host
foreach ((array) scandir(__DIR__ . '/../host') as $host)
{
    // Skip meta
    if ($host == '.' || $host == '..' || is_file($host))
    {
        continue;
    }

    // Check host configured
    if (!file_exists(__DIR__ . '/../host/' . $host . '/config.json'))
    {
        echo sprintf(
            _('Host "%s" not configured!') . PHP_EOL,
            $host
        );

        continue;
    }

    // Check cert exists
    if (!file_exists(__DIR__ . '/../host/' . $host . '/cert.pem'))
    {
        echo sprintf(
            _('Certificate for host "%s" not found!') . PHP_EOL,
            $host
        );

        continue;
    }

    // Check key exists
    if (!file_exists(__DIR__ . '/../host/' . $host . '/key.rsa'))
    {
        echo sprintf(
            _('Key for host "%s" not found!') . PHP_EOL,
            $host
        );

        continue;
    }

    // Init config
    $config = json_decode(
        file_get_contents(
            __DIR__ . '/../host/' . $host . '/config.json'
        )
    );

    // Init memory
    $memory = new \Yggverse\Cache\Memory(
        $config->memcached->server->host,
        $config->memcached->server->port,
        $config->memcached->server->namespace,
        $config->memcached->server->timeout
    );

    // Init server
    $server = new \Yggverse\TitanII\Server();

    $server->setCert(
        __DIR__ . '/../host/' . $host . '/cert.pem'
    );

    $server->setKey(
        __DIR__ . '/../host/' . $host . '/key.rsa'
    );

    $server->setHandler(
        function (\Yggverse\TitanII\Request $request): \Yggverse\TitanII\Response
        {
            global $memory;
            global $config;

            $response = new \Yggverse\TitanII\Response();

            $response->setCode(
                20
            );

            $response->setMeta(
                'text/gemini'
            );

            // Route begin
            switch ($request->getPath())
            {
                // Home page
                case null:
                case '/':

                    // Get rooms list
                    include_once __DIR__ . '/controller/room.php';

                    $room = new \Kevachat\Geminiapp\Controller\Room(
                        $memory,
                        $config
                    );

                    if ($list = $room->list())
                    {
                        $response->setContent(
                            $list
                        );

                        return $response;
                    }

                // Dynamical requests
                default:

                    // room|raw request
                    if (preg_match('/^\/([A-z]+)\/(N[A-z0-9]{33})$/', $request->getPath(), $matches))
                    {
                        if (!empty($matches[1]) && !empty($matches[2]))
                        {
                            switch ($matches[1])
                            {
                                case 'room':

                                    include_once __DIR__ . '/controller/room.php';

                                    $room = new \Kevachat\Geminiapp\Controller\Room(
                                        $memory,
                                        $config
                                    );

                                    if ($posts = $room->posts($matches[2]))
                                    {
                                        $response->setContent(
                                            $posts
                                        );

                                        return $response;
                                    }

                                break;

                                case 'raw':

                                    include_once __DIR__ . '/controller/media.php';

                                    $media = new \Kevachat\Geminiapp\Controller\Media(
                                        $config
                                    );

                                    if ($data = $media->raw($matches[2], $mime))
                                    {
                                        $response->setMeta(
                                            $mime
                                        );

                                        $response->setContent(
                                            $data
                                        );

                                        return $response;
                                    }

                                break;
                            }
                        }
                    }

                    // New publication request
                    else if (preg_match('/^\/room\/(N[A-z0-9]{33})\/([\d]+)\/post$/', $request->getPath(), $matches))
                    {
                        if (!empty($matches[1]))
                        {
                            // Request post message
                            if (empty($request->getQuery()))
                            {
                                $response->setMeta(
                                    'text/plain'
                                );

                                $response->setCode(
                                    10
                                );

                                return $response;
                            }

                            // Message sent, save to blockchain
                            else
                            {
                                include_once __DIR__ . '/controller/room.php';

                                $room = new \Kevachat\Geminiapp\Controller\Room(
                                    $memory,
                                    $config
                                );

                                // Success, redirect to this room page
                                if ($room->post($matches[1], null, $matches[2], $request->getQuery()))
                                {
                                    $response->setCode(
                                        30
                                    );

                                    $response->setMeta(
                                        sprintf(
                                            '/room/%s',
                                            $matches[1]
                                        )
                                    );

                                    return $response;
                                }
                            }
                        }
                    }

                    // New post reply request
                    else if (preg_match('/^\/room\/(N[A-z0-9]{33})\/([A-z0-9]{64})\/([\d]+)\/reply$/', $request->getPath(), $matches))
                    {
                        if (!empty($matches[1]) && !empty($matches[2]) && !empty($matches[3]))
                        {
                            // Request post message
                            if (empty($request->getQuery()))
                            {
                                $response->setMeta(
                                    'text/plain'
                                );

                                $response->setCode(
                                    10
                                );

                                return $response;
                            }

                            // Message sent, save to blockchain
                            else
                            {
                                include_once __DIR__ . '/controller/room.php';

                                $room = new \Kevachat\Geminiapp\Controller\Room(
                                    $memory,
                                    $config
                                );

                                // Success, redirect to this room page
                                if ($room->post($matches[1], $matches[2], $matches[3], $request->getQuery()))
                                {
                                    $response->setCode(
                                        30
                                    );

                                    $response->setMeta(
                                        sprintf(
                                            '/room/%s',
                                            $matches[1]
                                        )
                                    );

                                    return $response;
                                }
                            }
                        }
                    }
            }

            // Set default response
            include_once __DIR__ . '/controller/error.php';

            $error = new \Kevachat\Geminiapp\Controller\Error(
                $config
            );

            $response->setContent(
                $error->oops()
            );

            return $response;
        }
    );

    // Start server
    echo sprintf(
        _('Server "%s" started on %s:%d') . PHP_EOL,
        $host,
        $config->gemini->server->host,
        $config->gemini->server->port
    );

    $server->start(
        $config->gemini->server->host,
        $config->gemini->server->port
    );
}