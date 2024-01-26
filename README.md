# KevaChat App for Gemini Protocol

```
  _  __                ____ _           _
 | |/ /_____   ____ _ / ___| |__   __ _| |_
 | ' // _ \ \ / / _` | |   | '_ \ / _` | __|
 | . \  __/\ V / (_| | |___| | | | (_| | |_
 |_|\_\___| \_/ \__,_|\____|_| |_|\__,_|\__|

```

KevaChat is distributed chat platform for open, uncensored and privacy respectable communication with permanent data storage in blockchain.

See also: [web implementation](https://github.com/kevachat/webapp)

## Roadmap

* [x] Multiple host support
* [x] Room list
* [x] Room threads
* [ ] Post publication
* [ ] Post replies
* [ ] Rooms publication
* [x] Media viewer
* [ ] Users auth
* [x] Error handlers

## Install

* `git clone https://github.com/kevachat/geminiapp.git`
* `cd geminiapp`
* `composer update`

## Setup

* `mkdir host/127.0.0.1`
* `cp example/config.json host/127.0.0.1/config.json`
* `cp host/127.0.0.1`
* `openssl req -x509 -newkey rsa:4096 -keyout key.rsa -out cert.pem -days 365 -nodes -subj "/CN=127.0.0.1"`

## Start

* `php src/server.php`