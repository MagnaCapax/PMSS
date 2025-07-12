# Repository structure

This project is organised into a few top level directories:

- `scripts/` – command line utilities and cron jobs. These are intended to run
  as **root** only and include monitoring scripts under `scripts/cron/` and
  helper tools under `scripts/util/`.
- `etc/` – configuration templates and skeleton files. New users are created
  from the skeleton in `etc/skel/` and configuration templates reside in
  `etc/seedbox/config/`.
- `var/` – default web content. Pages under `var/www/` are served by the
  system's web server and include basic status files.
- `docs/` – project documentation, including this file.

A typical installation places user home directories under `/home/<username>`.
Each account runs its own instance of Lighttpd and rTorrent using the settings
produced by the tools in `scripts/`.

## User web interface

New accounts receive the contents of `etc/skel/www` in their home directory.
This directory holds the PHP front end and the bundled copy of ruTorrent. Files
such as `info.php`, `stats.php` and `lighttpdRestart.php` provide a simple user
interface for monitoring and restarting services. The directory is updated during
user creation and by running `update.php`.
