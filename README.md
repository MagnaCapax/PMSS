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

### Support and help

You may ask our discord for guidance. Pulsed Media as a company will not provide support to use this on your own without a fee.


## Contributions

Just make a pull request OR issue. All contributions will be considered. No matter small or big. Your contribution could be as tiny as fixing a typo, or badly worded sentence and it will be much appreciated as well.

Some important guidelines:

 * Never break old users, ever.
 * Backwards compatibility is paramount (intermediary migration code has to be done if 100% compatibility cannot be ensured)
 * Has to be of generally beneficial for most users

 ### Code guidelines

 We try to stick Linux kernel development rules, in all regards. Some highlights:

  * Many small tools doing single task very well
  * camelCase
  * Line width general rule of thumb stick to ~120-180characters
  * Maximum 4 nesting/indents in single source file. Need more? Create a function/separate file/lib/class out of it.
  * Single source code file try to stick to ~150lines or so (if a lot of comments/user help, can be deviated)
  * Write open nested function calls and comment them
  * ~10 lines try to comment what is done
  * Descriptive function and variable names, functions preferrably with comment what it does and why

 ### Rewards

 Best contributions may get rewards when implemented and test, in the form of Pulsed Media service credits.