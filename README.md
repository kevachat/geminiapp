# KevaChat App for Gemini Protocol

```
  _  __                ____ _           _
 | |/ /_____   ____ _ / ___| |__   __ _| |_
 | ' // _ \ \ / / _` | |   | '_ \ / _` | __|
 | . \  __/\ V / (_| | |___| | | | (_| | |_
 |_|\_\___| \_/ \__,_|\____|_| |_|\__,_|\__|

```

KevaChat is distributed chat platform for open, uncensored and privacy respectable communication with permanent data storage in blockchain.

## Example

* `gemini://[301:23b4:991a:634d::1965]` - Yggdrasil
* `gemini://kevachat.ygg` - Yggdrasil / Alfis DNS

## Roadmap

* [x] Multiple host support
* [x] Room list
* [x] Room threads
* [x] Post publication
* [x] Post replies
* [ ] Rooms publication
* [x] Media viewer
* [ ] Users auth
* [ ] RSS
* [x] Error handlers

## Install

* `git clone https://github.com/kevachat/geminiapp.git`
* `cd geminiapp`
* `composer update`

## Setup

* `mkdir host/127.0.0.1`
* `cp example/config.json host/127.0.0.1/config.json`
* `cd host/127.0.0.1`
* `openssl req -x509 -newkey rsa:4096 -keyout key.rsa -out cert.pem -days 365 -nodes -subj "/CN=127.0.0.1"`

## Start

* `php src/server.php 127.0.0.1`

## See also

* [KevaChat Web Application](https://github.com/kevachat/webapp)