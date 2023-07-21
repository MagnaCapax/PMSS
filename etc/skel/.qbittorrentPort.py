# Check user's qBittorrent webui port.
from configparser import ConfigParser
from sys import exit
from os.path import expanduser
from os import system

try:
    with open(expanduser("~/.qbittorrentPort")) as f:
        expected_port = f.read()
    _ = int(expected_port) # verify that the file contains an integer
except Exception:
    print("port file is invalid, exiting")
    exit(1)

config = ConfigParser()
config.optionxform = str # preserve case of options
config.read(expanduser("~/.config/qBittorrent/qBittorrent.conf"))

if not config.has_option("Preferences", r"WebUI\Port"):
    print("config is invalid, exiting")
    exit(1)

if config["Preferences"][r"WebUI\Port"] == expected_port:
    print("config file is ok")
    exit(0)

print("config file has incorrect port, killing qbittorrent")
system("killall -u $(whoami) -9 qbittorrent-nox")

print("writing file")
config["Preferences"][r"WebUI\Port"] = expected_port
with open(expanduser("~/.config/qBittorrent/qBittorrent.conf"), "w") as f:
    config.write(f, space_around_delimiters=False) # keep config similar to qbit
