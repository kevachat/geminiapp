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
* `gemini://kevachat.duckdns.org` - Internet

## Roadmap

* [x] Multiple host support
* [x] Room list
* [x] Room threads
* [x] Post publication
* [x] Post replies
* [ ] Rooms publication
* [x] Media viewer
* [ ] Users auth
* [x] Error handlers

## Install

* `apt install git composer memcached sqlite3 php-curl php-memcached php-sqlite3 php-mbstring`
* `git clone https://github.com/kevachat/geminiapp.git`
* `cd geminiapp`
* `composer update`

## Setup

* `mkdir host/127.0.0.1`
* `cp example/config.json host/127.0.0.1/config.json`
* `cd host/127.0.0.1`
* `openssl req -x509 -newkey rsa:4096 -keyout key.rsa -out cert.pem -days 365 -nodes -subj "/CN=127.0.0.1"`
* `* * * * * php src/crontab.php 127.0.0.1` - if post commission enabled

## Start

* `php src/server.php 127.0.0.1`

When launching server with systemd, just make sure that manticore service already running:

```
[Unit]
Wants=manticore.service
After=manticore.service
...
```

## See also

* [KevaChat Web Application](https://github.com/kevachat/webapp)