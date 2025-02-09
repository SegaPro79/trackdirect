# APRS Track Direct

APRS Track Direct is a collection of tools that can be used to run an APRS website. You can use data from APRS-IS, CWOP-IS, OGN or any other source that uses the APRS specification.

Tools included are an APRS data collector, a websocket server, a javascript library (websocket client and more) and a website example (which can of course be used as is).

Please note that it is almost 10 years since I wrote the majority of the code, and when the code was written, it was never intended to be published ...

## What is APRS?
APRS (Automatic Packet Reporting System) is a digital communications system that uses packet radio to send real time tactical information. The APRS network is used by ham radio operators all over the world.

Information shared over the APRS network is for example coordinates, altitude, speed, heading, text messages, alerts, announcements, bulletins and weather data.

## Getting Started

These instructions will get you a copy of the project up and running on your local machine for development and testing purposes (but they are of course also valid for you who want to set up a public website).

Please note that the instructions is not intended to be something that you can follow exactly without any adaptions. See the instructions as initial tips on how the tools can be used, and read the code to get a deeper understanding.

Further down you will find some information how to install trackdirect with Docker and Docker-Compose.

### Prerequisites

What things you need to install and how to install them. These instructions are for Ubuntu 20.04

Install some ubuntu packages
```
sudo apt-get install libpq-dev postgresql-12 postgresql-client-common postgresql-client libevent-dev apache2 php libapache2-mod-php php-dom php-pgsql libmagickwand-dev imagemagick php-imagick inkscape php-gd
```
**Note that php-gd was added to these instructions during the summer of 2022, it is needed for the new heatmap generator.**

#### Install python
Unfortunately, the majority of this code was written when python 2 was still common and used, this means that the installation process needs to be adapted a bit. You might see some deprication warnings when starting the collector and websocket server.

Install python 2
```
sudo add-apt-repository universe
sudo apt update
sudo apt install python2 python2-dev
```

Install pip2 (pip for python 2)
```
curl https://bootstrap.pypa.io/pip/2.7/get-pip.py --output get-pip.py
sudo python2 get-pip.py
```

Install needed python libs
```
pip2 install psycopg2-binary wheel setuptools autobahn[twisted] twisted pympler image_slicer jsmin psutil
```

Install the python aprs lib (aprs-python)
```
git clone https://github.com/rossengeorgiev/aprs-python
cd aprs-python/
pip2 install .
```

### Set up aprsc
You should not to connect your collector and websocket server directly to a public APRS server (APRS-IS, CWOP-IS or OGN server). The collector will use a full feed connection and each websocket client will use a filtered feed connection (through the websocket server). To not cause extra load on public servers it is better to run your own aprsc server and let your collector and all websocket connections connect to that instead (will result in only one full feed connection to a public APRS server).

Note that it seems like aprsc needs to run on a server with a public ip, otherwise uplink won't work.

#### Installation
Follow the instructions found [here](http://he.fi/aprsc/INSTALLING.html).

#### Config file
You must modify the configuration file before starting aprsc.
```
sudo vi /opt/aprsc/etc/aprsc.conf
```

Uplink examples:
```
# Uplink "APRS-IS" ro tcp rotate.aprs.net 10152
# Uplink "CWOP" ro tcp cwop.aprs.net 10152
# Uplink "OGN" ro tcp aprs.glidernet.org 10152
```
Only use one of them, if you are going to use multiple sources you should set up muliple aprsc servers and run multiple collectors. That will enable you to have different settings for different sources.

#### Start aprsc server
Start aprsc
```
sudo systemctl start aprsc
```

If you run multiple aprsc instances you need to select different data och log directories (and of course different tcp ports in configuration file). Running multiple aprsc instances is only needed if you fetch data from multiple sources (like both APRS-IS and CWOP-IS).

Should be possible to start multiple aprsc instances by using something like this:
```
sudo /opt/aprsc/sbin/aprsc -u aprsc -t /opt/aprsc -c /etc/aprsc.conf -r /logs -o file -f
sudo /opt/aprsc/sbin/aprsc -u aprsc -t /opt/aprsc2 -c /etc/aprsc2.conf -r /logs2 -o file -f
```

### Installing Track Direct

Start by cloning the repository
```
git clone https://github.com/qvarforth/trackdirect
```

#### Set up database

Set up the database (connect to database using: "sudo -u postgres psql"). You need to replace "my_username".
```
CREATE DATABASE trackdirect;

CREATE USER my_username WITH PASSWORD 'foobar';
ALTER ROLE my_username WITH SUPERUSER;
GRANT ALL PRIVILEGES ON DATABASE "trackdirect" to my_username;
```

Might be good to add password to password-file:
```
vi ~/.pgpass
```

##### Increase performance
It might be a good idea to play around with some Postgresql settings to improve performance (for this application, speed is more important than minimizing the risk of data loss).

Some settings in /etc/postgresql/12/main/postgresql.conf that might improve performance:
```
shared_buffers = 2048MB              # I recommend 25% of total RAM
synchronous_commit=off               # Avoid writing to disk for every commit
commit_delay=100000                  # Will result in a 0.1s delay
```

Restart postgresql
```
sudo /etc/init.d/postgresql restart
```

##### Set up database tables
The script should be executed by the user that owns the database "trackdirect".
```
~/trackdirect/server/scripts/db_setup.sh trackdirect 5432 ~/trackdirect/misc/database/tables/
```

#### Set up OGN device data
If you are using data from OGN (Open Glider Network) it is IMPORTANT to keep the OGN data updated (the database table ogn_devices). This is important since otherwise you might show airplanes that you are not allowed to show. I recommend that you run this script at least once every hour (or more often). The script should be executed by the user that you granted access to the database "trackdirect".
```
~/trackdirect/server/scripts/ogn_devices_install.sh trackdirect 5432
```

#### Start the collectors
Before starting the collector you need to update the trackdirect configuration file (trackdirect/config/trackdirect.ini).

Start the collector by using the provided shell-script. Note that if you have configured multiple collectors (fetching from multiple aprs servers, for example both APRS-IS and CWOP-IS) you need to call the shell-script multiple times. The script should be executed by the user that you granted access to the database "trackdirect".
```
~/trackdirect/server/scripts/collector.sh trackdirect.ini 0
```

#### Start the websocket server
Before starting the websocket server you need to update the trackdirect configuration file (trackdirect/config/trackdirect.ini).

When the user interacts with the map we want it to be populated with objects from the backend. To achive good performance we avoid using background HTTP requests (also called AJAX requests), instead we use websocket communication. The included trackdirect js library (trackdirect.min.js) will connect to our websocket server and request objects for the current map view.

Start the websocket server by using the provided shell scripti, the script should be executed by the user that you granted access to the database "trackdirect".
```
~/trackdirect/server/scripts/wsserver.sh trackdirect.ini
```

If you have enabled a firewall, make sure the selected port is open (we are using port 9000 by default, can be changed in trackdirect.ini).
```
sudo ufw allow 9000
```

#### Trackdirect js library
All the map view magic is handled by the trackdirect js library, it contains functionality for rendering the map (using Google Maps API or Leaflet), functionality used to communicate with backend websocket server and much more.

If you do changes in the js library (jslib directory) you need to execute build.sh to deploy the changes to the htdocs directory.

```
~/trackdirect/jslib/build.sh
```

#### Adapt the website (htdocs)
For setting up a copy on your local machine for development and testing purposes you do not need to do anything, but for any other pupose I really recommend you to adapt the UI.

First thing to do is probably to select which map provider to use, look for stuff related to map provider in "index.php". Note that the map providers used in the demo website may not be suitable if you plan to have a public website (read their terms of use).

If you make no changes, at least add contact information to yourself, I do not want to receive questions regarding your website.


#### Set up webserver
Webserver should already be up and running (if you installed all specified ubuntu packages).

Add the following to /etc/apache2/sites-enabled/000-default.conf. You need to replace "my_username".
```
<Directory "/home/my_username/trackdirect/htdocs">
    Options SymLinksIfOwnerMatch
    AllowOverride All
    Require all granted
</Directory>
```

Change the VirtualHost DocumentRoot: (in /etc/apache2/sites-enabled/000-default.conf):
```
DocumentRoot /home/my_username/trackdirect/htdocs
```

Enable rewrite and restart apache
```
sudo a2enmod rewrite
sudo systemctl restart apache2
```

For the symbols and heatmap caches to work we need to make sure the webserver has write access (the following permission may be a little bit too generous...)
```
chmod 777 ~/trackdirect/htdocs/public/symbols
chmod 777 ~/trackdirect/htdocs/public/heatmaps
```

If you have enabled a firewall, make sure port 80 is open.
```
sudo ufw allow 80
```

## Deployment

If you want to set up a public website you should install a firewall and setup SSL certificates. For an easy solution I would use ufw to handle iptables, Nginx as a reverse proxy and use let’s encrypt for SSL certificates.

### Schedule things using cron
If you do not have infinite storage we recommend that you delete old packets, schedule the remover.sh script to be executed about once every hour. And again, if you are using OGN as data source you need to run the ogn_devices_install.sh script at least once every hour.

Note that the collector and wsserver shell scripts can be scheduled to start once every minute (nothing will happen if it is already running). I even recommend doing this as the collector and websocket server are built to shut down if something serious goes wrong (eg lost connection to database).

Crontab example (crontab for the user that owns the "trackdirect" database)
```
40 * * * * ~/trackdirect/server/scripts/remover.sh trackdirect.ini 2>&1 &
0 * * * * ~/trackdirect/server/scripts/ogn_devices_install.sh trackdirect 5432 2>&1 &
* * * * * ~/trackdirect/server/scripts/wsserver.sh trackdirect.ini 2>&1 &
* * * * * ~/trackdirect/server/scripts/collector.sh trackdirect.ini 0 2>&1 &
```

### Server Requirements
How powerful server you need depends on what type of data source you are going to use. If you, for example, receive data from the APRS-IS network, you will probably need at least a server with 4 CPUs and 8 GB of RAM, but I recommend using a server with 8 CPUs and 16 GB of RAM.


## Getting Started - Docker
There is everything prepared to run trackdirect inside of some docker containers. As there is a Docker-Compose file the setup is very simple and fast.

### Install Docker and Docker-Compose
Install [docker](https://docs.docker.com/get-docker/) and [docker-compose](https://docs.docker.com/compose/install/) from the published websites.

### Config file
Adopt your config in `config/aprsc.conf` and `config/trackdirect.ini`. In `trackdirect.ini` search for 'docker' and change the lines as described in the comments.


### Run Docker-Compose for development containers
To startup trackdirect in an development container run this docker-compose command:

```
docker-compose up
```

If you want to run the container in daemon mode add `-d` to the command.

### Run Docker-Compose for the last published docker images

@peterus is creating regular docker images from this repository. With the release Docker-Compose file you do not need to install and compile everything by your own.

```
docker-compose -f docker-compose-rel.yml up
```


## TODO
- Rewrite backend to use Python 3 instead of Python 2.
- Create a REST-API and replace the current website example with a new frontend written in Angular.

## Contribution
Contributions are welcome. Create a fork and make a pull request. Thank you!

## Disclaimer
These software tools are provided "as is" and "with all it's faults". We do not make any commitments or guarantees of any kind regarding security, suitability, errors or other harmful components of this source code. You are solely responsible for ensuring that data collected and published using these tools complies with all data protection regulations. You are also solely responsible for the protection of your equipment and the backup of your data, and we will not be liable for any damages that you may suffer in connection with the use, modification or distribution of these software tools.
