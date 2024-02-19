# PMSS: Check user's deluge-web port and enforce it

import json
from sys import exit
from os.path import expanduser
from os import system

try:
    with open(expanduser("~/.delugePort")) as f:
        expected_port = int(f.read())
except:
    print("deluge: port file is invalid, exiting")
    exit(1)

try:
    with open(expanduser("~/.config/deluge/web.conf")) as f:
        data = f.read()
    decoder = json.JSONDecoder()
    data = data.lstrip()
    meta_obj, index = decoder.raw_decode(data)
    data = data[index:].lstrip()
    config = decoder.decode(data)
except:
    print("deluge-web: config file is invalid, exiting")
    exit(1)

if "file" not in meta_obj or meta_obj["format"] != 1 or "port" not in config or not isinstance(config["port"], int):
    print("deluge-web: config is invalid, exiting")
    exit(1)

if config["port"] == expected_port:
    print("deluge-web: config file is ok")
    exit(0)

print("deluge-web: Config file has incorrect port, killing deluge-web")
system("killall -u $(whoami) -9 deluge-web")

print("deluge-web: writing file")
config["port"] = expected_port
with open(expanduser("~/.config/deluge/web.conf"), "w") as f:
    f.write(json.dumps(meta_obj, indent=4, ensure_ascii=False, sort_keys=True))
    f.write(json.dumps(config, indent=4, ensure_ascii=False, sort_keys=True))
