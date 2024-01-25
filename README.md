# geminiapp

KevaChat Application for Gemini Protocol

## Roadmap

* [x] Multiple host support
* [x] Room list
* [ ] Room threads
* [ ] Post publication
* [ ] Media viewer
* [ ] Users auth
* [x] Error handlers

## Install

`git clone https://github.com/kevachat/geminiapp.git`
`cd geminiapp`
`composer update`

## Setup

* `mkdir host/127.0.0.1`
* `cp example/config.json host/127.0.0.1/config.json`
* `cp host/127.0.0.1`
* `openssl req -x509 -newkey rsa:4096 -keyout key.rsa -out cert.pem -days 365 -nodes -subj "/CN=127.0.0.1"`

## Start

* `php src/server.php`