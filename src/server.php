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
                case '/':

                    // Get rooms list
                    include_once __DIR__ . '/controller/room.php';

                    $room = new \Kevachat\Geminiapp\Controller\Room(
                        $config
                    );

                    $response->setContent(
                        $room->list()
                    );

                    return $response;

                // Dynamical requests
                default:

                    // Room posts by namespace
                    if (preg_match('/^\/room\/(N[A-z0-9]{33})$/', $request->getPath(), $matches))
                    {
                        if (!empty($matches[1]))
                        {
                            include_once __DIR__ . '/controller/room.php';

                            $room = new \Kevachat\Geminiapp\Controller\Room(
                                $config
                            );

                            $response->setContent(
                                $room->posts(
                                    $matches[1]
                                )
                            );

                            return $response;
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