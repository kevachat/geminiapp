<?php

namespace Kevachat\Geminiapp\Controller;

class Error
{
    private $_config;

    public function __construct($config)
    {
        $this->_config = $config;
    }

    public function oops()
    {
        return str_replace(
            [
                '{logo}',
                '{home}'
            ],
            [
                file_get_contents(
                    __DIR__ . '/../../logo.ascii'
                ),
                (
                    $this->_config->gemini->server->port == 1965 ?
                    sprintf(
                        'gemini://%s',
                        $this->_config->gemini->server->host
                    ) :
                    sprintf(
                        'gemini://%s:%d',
                        $this->_config->gemini->server->host,
                        $this->_config->gemini->server->port
                    )
                )
            ],
            file_get_contents(
                __DIR__ . '/../view/oops.gemini'
            )
        );
    }
}