<?php

namespace Kevachat\Geminiapp\Controller;

class Media
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

    public function raw(string $namespace, ?string &$mime = null): mixed
    {
        if ($clitoris = $this->_kevacoin->kevaGet($namespace, '_CLITOR_IS_'))
        {
            $reader = new \ClitorIsProtocol\Kevacoin\Reader(
                $clitoris['value']
            );

            if ($reader->valid())
            {
                if ($pieces = $this->_kevacoin->kevaFilter($namespace))
                {
                    if ($data = $reader->data($pieces))
                    {
                        $mime = $reader->fileMime();

                        return $data;
                    }
                }
            }
        }

        return null;
    }
}