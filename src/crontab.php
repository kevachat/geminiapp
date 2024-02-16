<?php

// Check arguments
if (empty($argv[1]))
{
    exit(
        sprintf(
            _('[%s] [error] Configured hostname required as argument!'),
            date('c')
        ) . PHP_EOL
    );
}

// Check host configured
if (!file_exists(__DIR__ . '/../host/' . $argv[1] . '/config.json'))
{
    exit(
        sprintf(
            _('[%s] [error] Host "%s" not configured!'),
            date('c'),
            $argv[1]
        ) . PHP_EOL
    );
}

// Prevent multi-thread execution
$semaphore = sem_get(
    crc32(__FILE__ . $argv[1]), 1
);

if (false === sem_acquire($semaphore, true))
{
    exit(
        sprintf(
            _('[%s] [warning] Process locked by another thread!'),
            date('c')
        ) . PHP_EOL
    );
}

// Init config
$config = json_decode(
    file_get_contents(
        __DIR__ . '/../host/' . $argv[1] . '/config.json'
    )
);

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Init kevacoin
try
{
    $kevacoin = new \Kevachat\Kevacoin\Client(
        $config->kevacoin->server->protocol,
        $config->kevacoin->server->host,
        $config->kevacoin->server->port,
        $config->kevacoin->server->username,
        $config->kevacoin->server->password
    );
}

catch (Exception $exception)
{
    exit(
        print_r(
            $exception,
            true
        )
    );
}

// Init database
try
{
    $database = new \PDO(
        sprintf(
            'sqlite:%s/../host/%s',
            __DIR__,
            $config->sqlite->server->name
        ),
        $config->sqlite->server->user,
        $config->sqlite->server->password
    );

    $database->setAttribute(
        \PDO::ATTR_ERRMODE,
        \PDO::ERRMODE_EXCEPTION
    );

    $database->setAttribute(
        \PDO::ATTR_DEFAULT_FETCH_MODE,
        \PDO::FETCH_OBJ
    );

    $database->setAttribute(
        \PDO::ATTR_TIMEOUT,
        $config->sqlite->server->timeout
    );

    $database->query(
        file_get_contents(
            __DIR__ . '/../data.sql'
        )
    );
}

catch (Exception $exception)
{
    exit(
        print_r(
            $exception,
            true
        )
    );
}

// Init room list
$rooms = [];

foreach ((array) $kevacoin->kevaListNamespaces() as $value)
{
    $rooms[$value['namespaceId']] = mb_strtolower($value['displayName']);
}

// Skip room lock events
if (empty($rooms))
{
    exit(
        sprintf(
            _('[%s] [error] Could not init room list!'),
            date('c')
        ) . PHP_EOL
    );
}

// Process pool queue
$total = 0;

foreach ($database->query('SELECT * FROM `pool` WHERE `sent` = 0 AND `expired` = 0')->fetchAll() as $pool)
{
    // Payment received, send to blockchain
    if ($kevacoin->getReceivedByAddress($pool->address, $config->kevachat->post->pool->confirmations) >= $pool->cost)
    {
        // Check physical wallet balance
        if ($kevacoin->getBalance() <= $pool->cost)
        {
            exit(
                sprintf(
                    _('[%s] [error] Insufficient wallet funds!'),
                    date('c')
                ) . PHP_EOL
            );
        }

        // Check namespace is valid
        if (!isset($rooms[$pool->namespace]))
        {
            print(
                sprintf(
                    _('[%s] [warning] Could not found "%s" in room list!'),
                    date('c'),
                    $pool->namespace
                ) . PHP_EOL
            );

            continue;
        }

        // Send to blockchain
        if ($txid = $kevacoin->kevaPut($pool->namespace, $pool->key, $pool->value))
        {
            // Update status
            $database->query(
                sprintf(
                    'UPDATE `pool` SET `sent` = %d WHERE `id` = %d LIMIT 1',
                    time(),
                    $pool->id
                )
            );

            print(
                sprintf(
                    _('[%s] [notice] Record ID "%d" sent to blockchain with transaction ID "%s"'),
                    date('c'),
                    $pool->id,
                    $txid
                ) . PHP_EOL
            );
        }

        else
        {
            print(
                sprintf(
                    _('[%s] [error] Could not send record ID "%d" to blockchain!'),
                    date('c'),
                    $pool->id,
                    $txid
                ) . PHP_EOL
            );
        }
    }

    // Record expired
    else if (time() >= $pool->time + $this->_config->kevachat->post->pool->timeout)
    {
        // Update status
        $database->query(
            sprintf(
                'UPDATE `pool` SET `expired` = %d WHERE `id` = %d LIMIT 1',
                time(),
                $pool->id
            )
        );

        print(
            sprintf(
                _('[%s] [notice] Record ID "%d" expired.'),
                date('c'),
                $pool->id,
                $txid
            ) . PHP_EOL
        );
    }

    // Update counter
    $total++;
}

/*
print(
    sprintf(
        _('[%s] [notice] Records processed: %d'),
        date('c'),
        $total
    ) . PHP_EOL
);
*/