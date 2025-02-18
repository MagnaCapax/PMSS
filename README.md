# Pulsed Media Seedbox Software

## Description

Pulsed Media Seedbox Software, server side code.
This builds and installs all the software and scripts to operate a seedbox server.

Works on Debian 10/11/12. Deb10 is stable, Deb11 is in production qualification and Deb12 is development/experimental stage.

This can be used standalone fully, does not require a server from Pulsed Media. You can _freely_ use this for your own self-hosted seedbox.
No commercial restrictions, you are free to provide seedbox services using this and there are even some minimalistic whitelabeling features.
Some pieces will still be fetched from Pulsed Media servers, such as the latest GUI version.

More information available at https://wiki.pulsedmedia.com/index.php/PM_Software_Stack


## Documentation

You can find information for common tasks such as adding/creating/suspending users at https://wiki.pulsedmedia.com/index.php/Category:PM_Software_Stack_Guides

### Installation

Install minimal Debian system, and run following as root:
```
wget -q https://github.com/MagnaCapax/PMSS/raw/main/install.sh; bash install.sh
```

### Update from pre-github version

If you have older PMSS installed which is not yet based on this github version, here is how you can upgrade it:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php;  /scripts/update.php
```
with reboot using git/main ("testing") as the source instead of release:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php;  /scripts/update.php git/main:2025-02-19; reboot
```

### Debian 10 to Debian 11 Upgrade

Dist-upgrade functions.
YOLO Mostly Uattended command for the base system update:
```
export DEBIAN_FRONTEND=noninteractive; \
sudo sed -i 's/<buster>/bullseye/g' /etc/apt/sources.list; \
sudo sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.lis; \
sudo sed -i 's/<buster>/bullseye/g' /etc/apt/sources.list.d/.list; \
sudo sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.list.d/.list; \
sudo apt update;  \
sudo apt upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"; \
sudo apt full-upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"; \
sudo apt autoremove -y; \
sudo systemctl reboot
```

### Support

You may ask our discord for guidance.
Pulsed Media as a company will not provide support to use this on your own servers without a fee, unless the server is bought from Pulsed Media directly.


## Contributions

All contributions will be considered. No matter small or big. Your contribution could be as tiny as fixing a typo, or badly worded sentence and it will be much appreciated.

Some important guidelines:

 * Never break old users, ever.
 * Backwards compatibility is paramount (intermediary migration code has to be done if 100% compatibility cannot be ensured)
 * Has to be of generally beneficial for most users

 ### Code guidelines

 We try to stick Linux kernel development rules, in all regards. Some highlights:

  * Many small things doing single task very well
  * Descriptive function and variable names, functions preferrably with comment what it does and why
  * camelCase. reallyCamelCaseYourVariablesAndFunctionsAndClasses
  * Line width general rule of thumb stick to ~120-160characters
  * Maximum 4 nesting/indents in single source file. Need more? Create a function/separate file/lib/class out of it.
  * Single source code file try to stick to ~150lines or so (if a lot of comments/user help, can be deviated)
  * Write open nested function calls and comment them
  * ~10 lines try to comment what is done
  * Consider how this function/method/script will break or fail, this leads to less bugs or regressions

 ### Rewards

 Best contributions may get rewards when implemented and tested, in the form of Pulsed Media service credit.
