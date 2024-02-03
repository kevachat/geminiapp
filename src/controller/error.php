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
                '/'
            ],
            file_get_contents(
                __DIR__ . '/../view/oops.gemini'
            )
        );
    }
}