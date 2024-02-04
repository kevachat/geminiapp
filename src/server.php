<?php

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Check arguments
if (empty($argv[1]))
{
    exit(_('Configured hostname required as argument!') . PHP_EOL);
}

// Check cert exists
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/cert.pem'))
{
    exit(
        sprintf(
            _('Certificate for host "%s" not found!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Check key exists
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/key.rsa'))
{
    exit(
        sprintf(
            _('Key for host "%s" not found!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Check host configured
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/config.json'))
{
    exit(
        sprintf(
            _('Host "%s" not configured!') . PHP_EOL,
            $argv[1]
        )
    );
}

// Init config
$config = json_decode(
    file_get_contents(
        __DIR__ . '/../host/' . $argv[1] . '/config.json'
    )
);

// Init server
$server = new \Yggverse\TitanII\Server();

$server->setCert(
    __DIR__ . '/../host/' . $argv[1] . '/cert.pem'
);

$server->setKey(
    __DIR__ . '/../host/' . $argv[1] . '/key.rsa'
);

$server->setHandler(
    function (\Yggverse\TitanII\Request $request): \Yggverse\TitanII\Response
    {
        global $config;

        $response = new \Yggverse\TitanII\Response();

        $response->setCode(
            $config->gemini->response->default->code
        );

        $response->setMeta(
            $config->gemini->response->default->meta
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
                                $config
                            );

                            // Success, redirect to this room page
                            if ($txid = $room->post($matches[1], null, $matches[2], $request->getQuery()))
                            {
                                if ($result = $room->sent($matches[1], $txid))
                                {
                                    $response->setContent(
                                        $result
                                    );

                                    return $response;
                                }
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
                                $config
                            );

                            // Success, redirect to this room page
                            if ($txid = $room->post($matches[1], $matches[2], $matches[3], $request->getQuery()))
                            {
                                if ($result = $room->sent($matches[1], $txid))
                                {
                                    $response->setContent(
                                        $result
                                    );

                                    return $response;
                                }
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
    $argv[1],
    $config->gemini->server->host,
    $config->gemini->server->port
);

$server->start(
    $config->gemini->server->host,
    $config->gemini->server->port
);