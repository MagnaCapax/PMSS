# Pulsed Media Seedbox Software

## Description

Pulsed Media Seedbox Software, server side code. This builds and installs all the software and scripts to operate a seedbox.
Works on Debian 10/11. Deb10 is stable, Deb11 is development/experimental.

This can be used standalone fully, does not require a server from Pulsed Media. You can _freely_ use this for your own self-hosted seedbox. Some pieces will still be fetched from Pulsed Media servers, such as the latest GUI version. No commercial restrictions, you are free to provide seedbox services using this and there are even some minimalistic whitelabeling features.

More information available at https://wiki.pulsedmedia.com/index.php/PM_Software_Stack


## Documentation

You can find information for common tasks such as adding/creating/suspending users at https://wiki.pulsedmedia.com/index.php/Category:PM_Software_Stack_Guides

### Installation

Install minimal system, and run following as the root:
```
wget -O- https://github.com/MagnaCapax/PMSS/releases/latest/download/install.sh|bash
```

## Contributions

Just make a pull request OR issue. All contributions will be considered.

Some important guidelines:

 * Never break old users, ever.
 * Backwards compatibility is paramount (intermediary migration code has to be done if 100% compatibility cannot be ensured)
 * Has to be of generally beneficial for most users

 ### Rewards

 Best contributions may get rewards when implemented and test, in the form of Pulsed Media service credits.