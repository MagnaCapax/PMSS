<?php
/** Source: https://api.linuxserver.io/api/v1/images?include_config=false; snapshot: 2026-10-14. */
return array_map(static function (array $app): array {
    $app['image'] = 'lscr.io/linuxserver/'.$app['name'];
    $app['guide'] = 'https://docs.linuxserver.io/images/docker-'.$app['name'].'/';
    return $app;
},array (
  0 =>
  array (
    'name' => 'blender',
    'title' => 'Blender',
    'description' => 'Blender is a free and open-source 3D computer graphics software toolset used for creating animated films, visual effects',
    'category' => '3D Modeling',
  ),
  1 =>
  array (
    'name' => 'freecad',
    'title' => 'FreeCAD',
    'description' => 'FreeCAD is a general-purpose parametric 3D computer-aided design (CAD) modeler and a building information modeling (BIM)',
    'category' => '3D Modeling',
  ),
  2 =>
  array (
    'name' => 'inkscape',
    'title' => 'Inkscape',
    'description' => 'Inkscape is professional quality vector graphics software which runs on Linux, Mac OS X and Windows desktop computers.',
    'category' => '3D Modeling',
  ),
  3 =>
  array (
    'name' => 'kicad',
    'title' => 'KiCad',
    'description' => 'KiCad - A Cross Platform and Open Source Electronics Design Automation Suite.',
    'category' => '3D Modeling',
  ),
  4 =>
  array (
    'name' => 'bambustudio',
    'title' => 'Bambu Studio',
    'description' => 'Bambu Studio is an open-source, cutting-edge, feature-rich slicing software.',
    'category' => '3D Printing',
  ),
  5 =>
  array (
    'name' => 'manyfold',
    'title' => 'manyfold',
    'description' => 'manyfold is an open source, self-hosted web application for managing a collection of 3D models, particularly focused on',
    'category' => '3D Printing',
  ),
  6 =>
  array (
    'name' => 'orcaslicer',
    'title' => 'Orca Slicer',
    'description' => 'Orca Slicer is an open source slicer for FDM printers.',
    'category' => '3D Printing',
  ),
  7 =>
  array (
    'name' => 'cura',
    'title' => 'UltiMaker Cura',
    'description' => 'UltiMaker Cura is free, easy-to-use 3D printing software trusted by millions of users.',
    'category' => '3D Printing',
  ),
  8 =>
  array (
    'name' => 'hishtory-server',
    'title' => 'hiSHtory',
    'description' => 'hiSHtory is a better shell history.',
    'category' => 'Administration',
  ),
  9 =>
  array (
    'name' => 'openssh-server',
    'title' => 'openssh-server',
    'description' => 'openssh-server is a sandboxed environment that allows ssh access without giving keys to the entire server.',
    'category' => 'Administration',
  ),
  10 =>
  array (
    'name' => 'netbox',
    'title' => 'netbox',
    'description' => 'netbox is an IP address management (IPAM) and data center infrastructure management (DCIM) tool.',
    'category' => 'Administration,Business',
  ),
  11 =>
  array (
    'name' => 'ldap-auth',
    'title' => 'ldap-auth',
    'description' => 'ldap-auth software is for authenticating users who request protected resources from servers proxied by nginx.',
    'category' => 'Administration,Security',
  ),
  12 =>
  array (
    'name' => 'doublecommander',
    'title' => 'Double Commander',
    'description' => 'Double Commander is a free cross platform open source file manager with two panels side by side.',
    'category' => 'Administration,Storage',
  ),
  13 =>
  array (
    'name' => 'ardour',
    'title' => 'Ardour',
    'description' => 'Ardour is an open source, collaborative effort of a worldwide team including musicians, programmers, and professional re',
    'category' => 'Audio Processing',
  ),
  14 =>
  array (
    'name' => 'audacity',
    'title' => 'Audacity',
    'description' => 'Audacity is an easy-to-use, multi-track audio editor and recorder.',
    'category' => 'Audio Processing',
  ),
  15 =>
  array (
    'name' => 'duplicati',
    'title' => 'duplicati',
    'description' => 'duplicati is a backup client that securely stores encrypted, incremental, compressed backups on local storage, cloud sto',
    'category' => 'Backup',
  ),
  16 =>
  array (
    'name' => 'rsnapshot',
    'title' => 'rsnapshot',
    'description' => 'rsnapshot is a filesystem snapshot utility based on rsync.',
    'category' => 'Backup',
  ),
  17 =>
  array (
    'name' => 'syncthing',
    'title' => 'syncthing',
    'description' => 'syncthing replaces proprietary sync and cloud services with something open, trustworthy and decentralized.',
    'category' => 'Backup',
  ),
  18 =>
  array (
    'name' => 'calibre',
    'title' => 'calibre',
    'description' => 'calibre is a powerful and easy to use e-book manager.',
    'category' => 'Books',
  ),
  19 =>
  array (
    'name' => 'calibre-web',
    'title' => 'calibre-web',
    'description' => 'calibre-web is a web app providing a clean interface for browsing, reading and downloading eBooks using an existing Cali',
    'category' => 'Books',
  ),
  20 =>
  array (
    'name' => 'cops',
    'title' => 'cops',
    'description' => 'cops by Sébastien Lucas, now maintained by MikesPub, stands for Calibre OPDS (and HTML) Php Server.',
    'category' => 'Books',
  ),
  21 =>
  array (
    'name' => 'kavita',
    'title' => 'kavita',
    'description' => 'kavita is a fast, feature rich, cross platform reading server.',
    'category' => 'Books',
  ),
  22 =>
  array (
    'name' => 'lazylibrarian',
    'title' => 'lazylibrarian',
    'description' => 'lazylibrarian is a program to follow authors and grab metadata for all your digital reading needs.',
    'category' => 'Books',
  ),
  23 =>
  array (
    'name' => 'ubooquity',
    'title' => 'ubooquity',
    'description' => 'ubooquity is a free, lightweight and easy-to-use home server for your comics and ebooks.',
    'category' => 'Books',
  ),
  24 =>
  array (
    'name' => 'mylar3',
    'title' => 'mylar3',
    'description' => 'mylar3 is an automated Comic Book downloader (cbr/cbz) for use with NZB and torrents written in python.',
    'category' => 'Books,Media Management',
  ),
  25 =>
  array (
    'name' => 'altus',
    'title' => 'Altus',
    'description' => 'Altus is an Electron-based WhatsApp client with themes and multiple account support.',
    'category' => 'Chat',
  ),
  26 =>
  array (
    'name' => 'signal',
    'title' => 'Signal',
    'description' => 'Signal is a messaging app with privacy at its core.',
    'category' => 'Chat',
  ),
  27 =>
  array (
    'name' => 'telegram',
    'title' => 'Telegram',
    'description' => 'Telegram is a cloud-based mobile and desktop messaging app.',
    'category' => 'Chat',
  ),
  28 =>
  array (
    'name' => 'webcord',
    'title' => 'WebCord',
    'description' => 'WebCord can be summarized as a pack of security and privacy hardenings, Discord features reimplementations, Electron / C',
    'category' => 'Chat',
  ),
  29 =>
  array (
    'name' => 'weixin',
    'title' => 'Weixin',
    'description' => 'Weixin (WeChat) is an instant messaging, social media, and mobile payment app developed by Tencent.',
    'category' => 'Chat',
  ),
  30 =>
  array (
    'name' => 'ferdium',
    'title' => 'Ferdium',
    'description' => 'Ferdium is a desktop app that helps you organize how you use your favourite apps by combining them into one application.',
    'category' => 'Chat,Social',
  ),
  31 =>
  array (
    'name' => 'nextcloud',
    'title' => 'nextcloud',
    'description' => 'nextcloud gives you access to all your files wherever you are.',
    'category' => 'Cloud,Documents',
  ),
  32 =>
  array (
    'name' => 'bookstack',
    'title' => 'bookstack',
    'description' => 'bookstack is a free and open source Wiki designed for creating beautiful documentation.',
    'category' => 'Content Management',
  ),
  33 =>
  array (
    'name' => 'dokuwiki',
    'title' => 'dokuwiki',
    'description' => 'dokuwiki is a simple to use and highly versatile Open Source wiki software that doesn\'t require a database.',
    'category' => 'Content Management',
  ),
  34 =>
  array (
    'name' => 'grav',
    'title' => 'grav',
    'description' => 'grav is a Fast, Simple, and Flexible, file-based Web-platform.',
    'category' => 'Content Management',
  ),
  35 =>
  array (
    'name' => 'hedgedoc',
    'title' => 'HedgeDoc',
    'description' => 'HedgeDoc gives you access to all your files wherever you are.',
    'category' => 'Content Management',
  ),
  36 =>
  array (
    'name' => 'joplin',
    'title' => 'Joplin',
    'description' => 'Joplin is a free, open source note taking and to-do application, which can handle a large number of notes organised into',
    'category' => 'Content Management',
  ),
  37 =>
  array (
    'name' => 'obsidian',
    'title' => 'Obsidian',
    'description' => 'Obsidian is a note-taking app that lets you create, link, and organize your notes on your device, with hundreds of plugi',
    'category' => 'Content Management',
  ),
  38 =>
  array (
    'name' => 'raneto',
    'title' => 'raneto',
    'description' => 'raneto - is an open source Knowledgebase platform that uses static Markdown files to power your Knowledgebase.',
    'category' => 'Content Management',
  ),
  39 =>
  array (
    'name' => 'wikijs',
    'title' => 'wikijs',
    'description' => 'wikijs A modern, lightweight and powerful wiki app built on NodeJS.',
    'category' => 'Content Management',
  ),
  40 =>
  array (
    'name' => 'planka',
    'title' => 'planka',
    'description' => 'planka is an elegant open source project tracking tool.',
    'category' => 'Content Management,Business',
  ),
  41 =>
  array (
    'name' => 'heimdall',
    'title' => 'heimdall',
    'description' => 'heimdall is a way to organise all those links to your most used web sites and web applications in a simple way.',
    'category' => 'Dashboard',
  ),
  42 =>
  array (
    'name' => 'sqlitebrowser',
    'title' => 'DB Browser for SQLite',
    'description' => 'DB Browser for SQLite is a high quality, visual, open source tool to create, design, and edit database files compatible',
    'category' => 'Databases',
  ),
  43 =>
  array (
    'name' => 'mysql-workbench',
    'title' => 'MySQL Workbench',
    'description' => 'MySQL Workbench is a unified visual tool for database architects, developers, and DBAs.',
    'category' => 'Databases',
  ),
  44 =>
  array (
    'name' => 'modmanager',
    'title' => 'modmanager',
    'description' => 'Modmanager is a centralised tool for downloading and updating docker mods for all your other Linuxserver containers.',
    'category' => 'Docker',
  ),
  45 =>
  array (
    'name' => 'qemu-static',
    'title' => 'qemu-static',
    'description' => 'Run multiple architectures of Docker containers on an x8664 or aarch64 host using QEMU static.',
    'category' => 'Docker',
  ),
  46 =>
  array (
    'name' => 'socket-proxy',
    'title' => 'socket-proxy',
    'description' => 'The Socket Proxy is a security-enhanced proxy which allows you to apply access rules to the Docker socket, limiting the',
    'category' => 'Docker',
  ),
  47 =>
  array (
    'name' => 'calligra',
    'title' => 'Calligra',
    'description' => 'Calligra is an office and graphic art suite by KDE.',
    'category' => 'Documents',
  ),
  48 =>
  array (
    'name' => 'libreoffice',
    'title' => 'LibreOffice',
    'description' => 'LibreOffice is a free and powerful office suite, and a successor to OpenOffice.org (commonly known as OpenOffice).',
    'category' => 'Documents',
  ),
  49 =>
  array (
    'name' => 'onlyoffice',
    'title' => 'ONLYOFFICE',
    'description' => 'ONLYOFFICE provides a full range of tools to create, edit and collaborate on text documents, spreadsheets, presentations',
    'category' => 'Documents',
  ),
  50 =>
  array (
    'name' => 'wps-office',
    'title' => 'WPS Office',
    'description' => 'WPS Office is a lightweight, feature-rich comprehensive office suite with high compatibility.',
    'category' => 'Documents',
  ),
  51 =>
  array (
    'name' => 'zotero',
    'title' => 'Zotero',
    'description' => 'Zotero is a free, easy-to-use tool to help you collect, organize, annotate, cite, and share research.',
    'category' => 'Documents',
  ),
  52 =>
  array (
    'name' => 'flexget',
    'title' => 'flexget',
    'description' => 'flexget is a multipurpose automation tool for all of your media.',
    'category' => 'Downloaders',
  ),
  53 =>
  array (
    'name' => 'nzbget',
    'title' => 'nzbget',
    'description' => 'nzbget is a usenet downloader, written in C++ and designed with performance in mind to achieve maximum download speed by',
    'category' => 'Downloaders',
  ),
  54 =>
  array (
    'name' => 'pyload-ng',
    'title' => 'pyLoad',
    'description' => 'pyLoad is a Free and Open Source download manager written in Python and designed to be extremely lightweight, easily ext',
    'category' => 'Downloaders',
  ),
  55 =>
  array (
    'name' => 'transmission',
    'title' => 'transmission',
    'description' => 'transmission is designed for easy, powerful use.',
    'category' => 'Downloaders',
  ),
  56 =>
  array (
    'name' => 'thunderbird',
    'title' => 'Thunderbird',
    'description' => 'Thunderbird is a free and open-source personal information manager primarily used as an e-mail client with a calendar an',
    'category' => 'Email',
  ),
  57 =>
  array (
    'name' => 'babybuddy',
    'title' => 'babybuddy',
    'description' => 'babybuddy is a buddy for babies!',
    'category' => 'Family',
  ),
  58 =>
  array (
    'name' => 'pairdrop',
    'title' => 'PairDrop',
    'description' => 'PairDrop is a sublime alternative to AirDrop that works on all platforms.',
    'category' => 'File Sharing',
  ),
  59 =>
  array (
    'name' => 'projectsend',
    'title' => 'projectsend',
    'description' => 'projectsend is a self-hosted application that lets you upload files and assign them to specific clients that you create',
    'category' => 'File Sharing',
  ),
  60 =>
  array (
    'name' => 'pydio-cells',
    'title' => 'pydio-cells',
    'description' => 'pydio-cells is the nextgen file sharing platform for organizations.',
    'category' => 'File Sharing',
  ),
  61 =>
  array (
    'name' => 'xbackbone',
    'title' => 'xbackbone',
    'description' => 'xbackbone is a simple, self-hosted, lightweight PHP file manager that support the instant sharing tool ShareX and NIX sy',
    'category' => 'File Sharing',
  ),
  62 =>
  array (
    'name' => 'bitcoin-knots',
    'title' => 'Bitcoin Knots',
    'description' => 'Bitcoin Knots can be used as a desktop client for regular payments or as a full node server utility for merchants and ot',
    'category' => 'Finance',
  ),
  63 =>
  array (
    'name' => 'budge',
    'title' => 'budge',
    'description' => 'budge is an open source \'budgeting with envelopes\' personal finance app.',
    'category' => 'Finance',
  ),
  64 =>
  array (
    'name' => 'kimai',
    'title' => 'kimai',
    'description' => 'kimai is a professional grade time-tracking application, free and open-source.',
    'category' => 'Finance,Business',
  ),
  65 =>
  array (
    'name' => 'davos',
    'title' => 'davos',
    'description' => 'davos is an FTP automation tool that periodically scans given host locations for new files.',
    'category' => 'FTP',
  ),
  66 =>
  array (
    'name' => 'filezilla',
    'title' => 'FIleZilla',
    'description' => 'FIleZilla Client is a fast and reliable cross-platform FTP, FTPS and SFTP client with lots of useful features and an int',
    'category' => 'FTP',
  ),
  67 =>
  array (
    'name' => 'azahar',
    'title' => 'Azahar',
    'description' => 'Azahar is an open-source 3DS emulator based on Citra.',
    'category' => 'Games',
  ),
  68 =>
  array (
    'name' => 'dogwalk',
    'title' => 'DOGWALK',
    'description' => 'DOGWALK is Blender Studio\'s long awaited second game project, focused on creating a bite-sized interactive storytelling',
    'category' => 'Games',
  ),
  69 =>
  array (
    'name' => 'dolphin',
    'title' => 'Dolphin Emulator',
    'description' => 'Dolphin Emulator lets you play GameCube and Wii games with various graphical enhancements and other features are availab',
    'category' => 'Games',
  ),
  70 =>
  array (
    'name' => 'dosbox-staging',
    'title' => 'DOSBox Staging',
    'description' => 'DOSBox Staging is a modern continuation of DOSBox a free and open-source emulator that enables the execution of MS-DOS s',
    'category' => 'Games',
  ),
  71 =>
  array (
    'name' => 'duckstation',
    'title' => 'DuckStation',
    'description' => 'DuckStation is a PS1 Emulator aiming for the best accuracy and game support.',
    'category' => 'Games',
  ),
  72 =>
  array (
    'name' => 'eden',
    'title' => 'Eden',
    'description' => 'Eden is an experimental open-source emulator for the Nintendo Switch, built with performance and stability in mind.',
    'category' => 'Games',
  ),
  73 =>
  array (
    'name' => 'flycast',
    'title' => 'Flycast',
    'description' => 'Flycast is a multi-platform Sega Dreamcast, Naomi, Naomi 2, and Atomiswave emulator derived from reicast.',
    'category' => 'Games',
  ),
  74 =>
  array (
    'name' => 'gzdoom',
    'title' => 'GZDoom',
    'description' => 'GZDoom is a feature centric port for all Doom engine games, based on ZDoom, adding an OpenGL renderer and powerful scrip',
    'category' => 'Games',
  ),
  75 =>
  array (
    'name' => 'luanti',
    'title' => 'luanti',
    'description' => 'luanti (formerly Minetest) is an open source voxel game-creation platform with easy modding and game creation',
    'category' => 'Games',
  ),
  76 =>
  array (
    'name' => 'mame',
    'title' => 'MAME',
    'description' => 'MAME is a free and open-source emulator designed to emulate the hardware of arcade games, video game consoles, old compu',
    'category' => 'Games',
  ),
  77 =>
  array (
    'name' => 'melonds',
    'title' => 'melonDS',
    'description' => 'melonDS aims at providing fast and accurate Nintendo DS emulation.',
    'category' => 'Games',
  ),
  78 =>
  array (
    'name' => 'modrinth',
    'title' => 'Modrinth App',
    'description' => 'Modrinth App is a unique, open source launcher that allows you to play your favorite mods, and keep them up to date, all',
    'category' => 'Games',
  ),
  79 =>
  array (
    'name' => 'pcsx2',
    'title' => 'PCSX2',
    'description' => 'PCSX2 is an open source PS2 Emulator.',
    'category' => 'Games',
  ),
  80 =>
  array (
    'name' => 'ppsspp',
    'title' => 'PPSSPP',
    'description' => 'PPSSPP is a free and open-source PSP emulator for Windows, macOS, Linux, iOS, Android, Nintendo Wii U, Nintendo Switch',
    'category' => 'Games',
  ),
  81 =>
  array (
    'name' => 'retroarch',
    'title' => 'RetroArch',
    'description' => 'RetroArch is a frontend for emulators, game engines and media players.',
    'category' => 'Games',
  ),
  82 =>
  array (
    'name' => 'rpcs3',
    'title' => 'RPCS3',
    'description' => 'RPCS3 is a multi-platform open-source Sony PlayStation 3 emulator and debugger written in C++ for Windows, Linux, macOS',
    'category' => 'Games',
  ),
  83 =>
  array (
    'name' => 'scummvm',
    'title' => 'ScummVM',
    'description' => 'ScummVM is a program which allows you to run certain classic graphical adventure and role-playing games, provided you al',
    'category' => 'Games',
  ),
  84 =>
  array (
    'name' => 'shadps4',
    'title' => 'shadPS4',
    'description' => 'shadPS4 is an early PlayStation 4 emulator for Windows, Linux and macOS written in C++.',
    'category' => 'Games',
  ),
  85 =>
  array (
    'name' => 'steam',
    'title' => 'Steam',
    'description' => 'Steam is the ultimate destination for playing, discussing, and creating games.',
    'category' => 'Games',
  ),
  86 =>
  array (
    'name' => 'webstation',
    'title' => 'Webstation',
    'description' => 'Webstation is a web native emulation focused LXQt desktop based on Ubuntu.',
    'category' => 'Games',
  ),
  87 =>
  array (
    'name' => 'blade-of-agony',
    'title' => 'Wolfenstein: Blade of Agony',
    'description' => 'Wolfenstein: Blade of Agony is a story-driven WWII shooter inspired by Wolfenstein and Doom.',
    'category' => 'Games',
  ),
  88 =>
  array (
    'name' => 'xemu',
    'title' => 'xemu',
    'description' => 'xemu is a free and open-source application that emulates the original Microsoft Xbox game console, enabling people to pl',
    'category' => 'Games',
  ),
  89 =>
  array (
    'name' => 'winegui',
    'title' => 'WineGUI',
    'description' => 'WineGUI is a user-interface friendly Wine manager that provides a graphical frontend for creating and managing Wine bott',
    'category' => 'Games,Business',
  ),
  90 =>
  array (
    'name' => 'habridge',
    'title' => 'habridge',
    'description' => 'habridge emulates Philips Hue API to other home automation gateways such as an Amazon Echo/Dot Gen 1 (gen 2 has issues d',
    'category' => 'Home Automation',
  ),
  91 =>
  array (
    'name' => 'homeassistant',
    'title' => 'Home Assistant Core',
    'description' => 'Home Assistant Core - Open source home automation that puts local control and privacy first.',
    'category' => 'Home Automation',
  ),
  92 =>
  array (
    'name' => 'gimp',
    'title' => 'GIMP',
    'description' => 'GIMP is a free and open-source raster graphics editor used for image manipulation (retouching) and image editing, free-f',
    'category' => 'Image Editor',
  ),
  93 =>
  array (
    'name' => 'krita',
    'title' => 'Krita',
    'description' => 'Krita is a professional FREE and open source painting program.',
    'category' => 'Image Editor',
  ),
  94 =>
  array (
    'name' => 'rawtherapee',
    'title' => 'RawTherapee',
    'description' => 'RawTherapee is a free, cross-platform raw image processing program!',
    'category' => 'Image Editor',
  ),
  95 =>
  array (
    'name' => 'jackett',
    'title' => 'jackett',
    'description' => 'jackett works as a proxy server: it translates queries from apps (Sonarr, SickRage, CouchPotato, Mylar, etc) into tracke',
    'category' => 'Indexers',
  ),
  96 =>
  array (
    'name' => 'nzbhydra2',
    'title' => 'nzbhydra2',
    'description' => 'nzbhydra2 is a meta search application for NZB indexers, the spiritual successor to NZBmegasearcH, and an evolution of t',
    'category' => 'Indexers',
  ),
  97 =>
  array (
    'name' => 'limnoria',
    'title' => 'limnoria',
    'description' => 'limnoria A robust, full-featured, and user/programmer-friendly Python IRC bot, with many existing plugins.',
    'category' => 'IRC',
  ),
  98 =>
  array (
    'name' => 'ngircd',
    'title' => 'ngircd',
    'description' => 'ngircd is a free, portable and lightweight Internet Relay Chat server for small or private networks, developed under the',
    'category' => 'IRC',
  ),
  99 =>
  array (
    'name' => 'znc',
    'title' => 'znc',
    'description' => 'znc is an IRC network bouncer or BNC.',
    'category' => 'IRC',
  ),
  100 =>
  array (
    'name' => 'pidgin',
    'title' => 'Pidgin',
    'description' => 'Pidgin is a chat program which lets you log into accounts on multiple chat networks simultaneously.',
    'category' => 'IRC,Chat',
  ),
  101 =>
  array (
    'name' => 'thelounge',
    'title' => 'thelounge',
    'description' => 'thelounge (a fork of shoutIRC) is a web IRC client that you host on your own server.',
    'category' => 'IRC,Chat',
  ),
  102 =>
  array (
    'name' => 'faster-whisper',
    'title' => 'faster-whisper',
    'description' => 'faster-whisper is a reimplementation of OpenAI\'s Whisper model using CTranslate2, which is a fast inference engine for T',
    'category' => 'Machine Learning',
  ),
  103 =>
  array (
    'name' => 'piper',
    'title' => 'piper',
    'description' => 'piper is a fast, local neural text to speech system that sounds great and is optimized for the Raspberry Pi 4.',
    'category' => 'Machine Learning',
  ),
  104 =>
  array (
    'name' => 'bazarr',
    'title' => 'bazarr',
    'description' => 'bazarr is a companion application to Sonarr and Radarr.',
    'category' => 'Media Management',
  ),
  105 =>
  array (
    'name' => 'handbrake',
    'title' => 'HandBrake',
    'description' => 'HandBrake is an open-source tool, built by volunteers, for converting video from nearly any format to a selection of mod',
    'category' => 'Media Management',
  ),
  106 =>
  array (
    'name' => 'kometa',
    'title' => 'kometa',
    'description' => 'kometa is a powerful tool designed to give you complete control over your media libraries.',
    'category' => 'Media Management',
  ),
  107 =>
  array (
    'name' => 'mediaelch',
    'title' => 'MediaElch',
    'description' => 'MediaElch is a MediaManager for Kodi.',
    'category' => 'Media Management',
  ),
  108 =>
  array (
    'name' => 'medusa',
    'title' => 'medusa',
    'description' => 'medusa is an automatic Video Library Manager for TV Shows.',
    'category' => 'Media Management',
  ),
  109 =>
  array (
    'name' => 'sickgear',
    'title' => 'SickGear',
    'description' => 'SickGear provides management of TV shows and/or Anime, it detects new episodes, links downloader apps, and more..',
    'category' => 'Media Management',
  ),
  110 =>
  array (
    'name' => 'vlc',
    'title' => 'VLC Media Player',
    'description' => 'VLC Media Player is a free and open source cross-platform multimedia player and framework that delivers dependable perfo',
    'category' => 'Media Management',
  ),
  111 =>
  array (
    'name' => 'doplarr_rs',
    'title' => 'doplarr_rs',
    'description' => 'doplarrrs is a Discord bot for requesting media through arr backends, written in Rust.',
    'category' => 'Media Requesters',
  ),
  112 =>
  array (
    'name' => 'ombi',
    'title' => 'ombi',
    'description' => 'ombi allows you to host your own Plex Request and user management system.',
    'category' => 'Media Requesters',
  ),
  113 =>
  array (
    'name' => 'airsonic-advanced',
    'title' => 'airsonic-advanced',
    'description' => 'airsonic-advanced is a free, web-based media streamer, providing ubiquitious access to your music.',
    'category' => 'Media Servers,Music',
  ),
  114 =>
  array (
    'name' => 'mstream',
    'title' => 'mstream',
    'description' => 'mstream is a personal music streaming server.',
    'category' => 'Media Servers,Music',
  ),
  115 =>
  array (
    'name' => 'emby',
    'title' => 'emby',
    'description' => 'emby organizes video, music, live TV, and photos from personal media libraries and streams them to smart TVs, streaming',
    'category' => 'Media Servers,Music,Audiobooks',
  ),
  116 =>
  array (
    'name' => 'plex',
    'title' => 'plex',
    'description' => 'plex organizes video, music and photos from personal media libraries and streams them to smart TVs, streaming boxes and',
    'category' => 'Media Servers,Music,Audiobooks',
  ),
  117 =>
  array (
    'name' => 'ffmpeg',
    'title' => 'ffmpeg',
    'description' => 'A complete, cross-platform solution to record, convert and stream audio and video.',
    'category' => 'Media Tools',
  ),
  118 =>
  array (
    'name' => 'htpcmanager',
    'title' => 'htpcmanager',
    'description' => 'htpcmanager is a front end for many htpc related applications.',
    'category' => 'Media Tools',
  ),
  119 =>
  array (
    'name' => 'minisatip',
    'title' => 'minisatip',
    'description' => 'minisatip is a multi-threaded satip server version 1.2 that runs under Linux and it was tested with DVB-S, DVB-S2, DVB-T',
    'category' => 'Media Tools',
  ),
  120 =>
  array (
    'name' => 'synclounge',
    'title' => 'synclounge',
    'description' => 'synclounge is a third party tool that allows you to watch Plex in sync with your friends/family, wherever you are.',
    'category' => 'Media Tools',
  ),
  121 =>
  array (
    'name' => 'tautulli',
    'title' => 'tautulli',
    'description' => 'tautulli is a python based web application for monitoring, analytics and notifications for Plex Media Server.',
    'category' => 'Media Tools',
  ),
  122 =>
  array (
    'name' => 'tvheadend',
    'title' => 'tvheadend',
    'description' => 'tvheadend works as a proxy server: is a TV streaming server and recorder for Linux, FreeBSD and Android supporting DVB-S',
    'category' => 'Media Tools',
  ),
  123 =>
  array (
    'name' => 'webgrabplus',
    'title' => 'webgrabplus',
    'description' => 'webgrabplus is a multi-site incremental xmltv epg grabber.',
    'category' => 'Media Tools',
  ),
  124 =>
  array (
    'name' => 'apprise-api',
    'title' => 'apprise-api',
    'description' => 'apprise-api Takes advantage of Apprise through your network with a user-friendly API.',
    'category' => 'Monitoring',
  ),
  125 =>
  array (
    'name' => 'healthchecks',
    'title' => 'healthchecks',
    'description' => 'healthchecks is a watchdog for your cron jobs.',
    'category' => 'Monitoring',
  ),
  126 =>
  array (
    'name' => 'librespeed',
    'title' => 'librespeed',
    'description' => 'librespeed is a very lightweight Speedtest implemented in Javascript, using XMLHttpRequest and Web Workers.',
    'category' => 'Monitoring',
  ),
  127 =>
  array (
    'name' => 'smokeping',
    'title' => 'smokeping',
    'description' => 'smokeping keeps track of your network latency.',
    'category' => 'Monitoring',
  ),
  128 =>
  array (
    'name' => 'speedtest-tracker',
    'title' => 'speedtest-tracker',
    'description' => 'speedtest-tracker is a self-hosted internet performance tracking application that runs speedtest checks against Ookla\'s',
    'category' => 'Monitoring',
  ),
  129 =>
  array (
    'name' => 'syslog-ng',
    'title' => 'syslog-ng',
    'description' => 'syslog-ng allows you to flexibly collect, parse, classify, rewrite and correlate logs from across your infrastructure an',
    'category' => 'Monitoring',
  ),
  130 =>
  array (
    'name' => 'beets',
    'title' => 'beets',
    'description' => 'beets is a music library manager and not, for the most part, a music player.',
    'category' => 'Music',
  ),
  131 =>
  array (
    'name' => 'lollypop',
    'title' => 'Lollypop',
    'description' => 'Lollypop is a lightweight modern music player designed to work excellently on the GNOME desktop environment.',
    'category' => 'Music',
  ),
  132 =>
  array (
    'name' => 'spotube',
    'title' => 'Spotube',
    'description' => 'Spotube is an open source, cross-platform Spotify client compatible across multiple platforms utilizing Spotify\'s data A',
    'category' => 'Music',
  ),
  133 =>
  array (
    'name' => 'your_spotify',
    'title' => 'your_spotify',
    'description' => 'yourspotify is a self-hosted application that tracks what you listen and offers you a dashboard to explore statistics ab',
    'category' => 'Music',
  ),
  134 =>
  array (
    'name' => 'unifi-network-application',
    'title' => 'unifi-network-application',
    'description' => 'The unifi-network-application software is a powerful, enterprise wireless software engine ideal for high-density client',
    'category' => 'Network',
  ),
  135 =>
  array (
    'name' => 'wireshark',
    'title' => 'Wireshark',
    'description' => 'Wireshark is the world’s foremost and widely-used network protocol analyzer.',
    'category' => 'Network',
  ),
  136 =>
  array (
    'name' => 'adguardhome-sync',
    'title' => 'adguardhome-sync',
    'description' => 'adguardhome-sync is a tool to synchronize AdGuardHome config to replica instances.',
    'category' => 'Network,DNS',
  ),
  137 =>
  array (
    'name' => 'ddclient',
    'title' => 'ddclient',
    'description' => 'ddclient is a Perl client used to update dynamic DNS entries for accounts on Dynamic DNS Network Service Provider.',
    'category' => 'Network,DNS',
  ),
  138 =>
  array (
    'name' => 'duckdns',
    'title' => 'duckdns',
    'description' => 'duckdns is a free service which will point a DNS (sub domains of duckdns.org) to an IP of your choice.',
    'category' => 'Network,DNS',
  ),
  139 =>
  array (
    'name' => 'fail2ban',
    'title' => 'fail2ban',
    'description' => 'fail2ban is a daemon to ban hosts that cause multiple authentication errors.',
    'category' => 'Network,Security',
  ),
  140 =>
  array (
    'name' => 'keepassxc',
    'title' => 'KeePassXC',
    'description' => 'KeePassXC is a free and open-source password manager.',
    'category' => 'Password Manager',
  ),
  141 =>
  array (
    'name' => 'darktable',
    'title' => 'darktable',
    'description' => 'darktable is an open source photography workflow application and raw developer.',
    'category' => 'Photos',
  ),
  142 =>
  array (
    'name' => 'digikam',
    'title' => 'digiKam',
    'description' => 'digiKam: Professional Photo Management with the Power of Open Source',
    'category' => 'Photos',
  ),
  143 =>
  array (
    'name' => 'lychee',
    'title' => 'lychee',
    'description' => 'lychee is a free photo-management tool, which runs on your server or web-space.',
    'category' => 'Photos',
  ),
  144 =>
  array (
    'name' => 'piwigo',
    'title' => 'piwigo',
    'description' => 'piwigo is a photo gallery software for the web that comes with powerful features to publish and manage your collection o',
    'category' => 'Photos',
  ),
  145 =>
  array (
    'name' => 'code-server',
    'title' => 'code-server',
    'description' => 'code-server is VS Code running on a remote server, accessible through the browser.',
    'category' => 'Programming',
  ),
  146 =>
  array (
    'name' => 'github-desktop',
    'title' => 'Github Desktop',
    'description' => 'Github Desktop is an open source Electron-based GitHub app.',
    'category' => 'Programming',
  ),
  147 =>
  array (
    'name' => 'gitqlient',
    'title' => 'GitQlient',
    'description' => 'GitQlient is a multi-platform Git client originally forked from QGit.',
    'category' => 'Programming',
  ),
  148 =>
  array (
    'name' => 'intellij-idea',
    'title' => 'IntelliJ IDEA',
    'description' => 'IntelliJ IDEA helps you write code faster with tools that eliminate tedious tasks and let you focus on what matters – bu',
    'category' => 'Programming',
  ),
  149 =>
  array (
    'name' => 'pycharm',
    'title' => 'PyCharm',
    'description' => 'PyCharm offers out-of-the-box support for Python, databases, Jupyter, Git, Conda, PyTorch, TensorFlow, Hugging Face, Dja',
    'category' => 'Programming',
  ),
  150 =>
  array (
    'name' => 'vscode',
    'title' => 'VS Code',
    'description' => 'VS Code is an integrated development environment developed by Microsoft.',
    'category' => 'Programming',
  ),
  151 =>
  array (
    'name' => 'vscodium',
    'title' => 'VSCodium',
    'description' => 'VSCodium is a community-driven, freely-licensed binary distribution of Microsoft’s editor VS Code.',
    'category' => 'Programming',
  ),
  152 =>
  array (
    'name' => 'vscodium-web',
    'title' => 'vscodium-web',
    'description' => 'vscodium-web is a community-driven, freely-licensed binary distribution of the remote host web component of Microsoft\'s',
    'category' => 'Programming',
  ),
  153 =>
  array (
    'name' => 'yaak',
    'title' => 'yaak',
    'description' => 'yaak is a desktop API client for organizing and executing REST, GraphQL, and gRPC requests.',
    'category' => 'Programming',
  ),
  154 =>
  array (
    'name' => 'grocy',
    'title' => 'grocy',
    'description' => 'grocy is an ERP system for your kitchen!',
    'category' => 'Recipes',
  ),
  155 =>
  array (
    'name' => 'remmina',
    'title' => 'Remmina',
    'description' => 'Remmina is a remote desktop client written in GTK, aiming to be useful for system administrators and travellers, who nee',
    'category' => 'Remote Desktop',
  ),
  156 =>
  array (
    'name' => 'rustdesk',
    'title' => 'RustDesk',
    'description' => 'RustDesk is a full-featured open source remote control alternative for self-hosting and security with minimal configurat',
    'category' => 'Remote Desktop',
  ),
  157 =>
  array (
    'name' => 'webtop',
    'title' => 'webtop',
    'description' => 'webtop - Alpine, Ubuntu, Fedora, and Arch based containers containing full desktop environments in officially supported',
    'category' => 'Remote Desktop',
  ),
  158 =>
  array (
    'name' => 'sealskin',
    'title' => 'Sealskin',
    'description' => 'Sealskin is a self-hosted, client-server platform that enables users to run powerful, containerized desktop applications',
    'category' => 'Remote Desktop, Business',
  ),
  159 =>
  array (
    'name' => 'lm-studio',
    'title' => 'LM Studio',
    'description' => 'LM Studio can run local AI models like gpt-oss, Llama, Gemma, Qwen, and DeepSeek privately on your computer.',
    'category' => 'Remote Desktop,AI',
  ),
  160 =>
  array (
    'name' => 'pelorus',
    'title' => 'Pelorus',
    'description' => 'Pelorus is an AI navigator for Selkies-powered Linux desktops.',
    'category' => 'Remote Desktop,AI',
  ),
  161 =>
  array (
    'name' => 'kasm',
    'title' => 'kasm',
    'description' => 'kasm Workspaces is a docker container streaming platform for delivering browser-based access to desktops, applications',
    'category' => 'Remote Desktop,Business',
  ),
  162 =>
  array (
    'name' => 'kali-linux',
    'title' => 'kali-linux',
    'description' => 'kali-linux - is an Advanced Penetration Testing Linux distribution used for Penetration Testing, Ethical Hacking and net',
    'category' => 'Remote Desktop,Security',
  ),
  163 =>
  array (
    'name' => 'nginx',
    'title' => 'nginx',
    'description' => 'nginx is an HTTP web server, reverse proxy, content cache, load balancer, TCP/UDP proxy server, and mail proxy server.',
    'category' => 'Reverse Proxy',
  ),
  164 =>
  array (
    'name' => 'swag',
    'title' => 'swag',
    'description' => 'SWAG - Secure Web Application Gateway (formerly known as letsencrypt, no relation to Let\'s Encrypt™) sets up an Nginx we',
    'category' => 'Reverse Proxy',
  ),
  165 =>
  array (
    'name' => 'freshrss',
    'title' => 'freshrss',
    'description' => 'freshrss is a free, self-hostable aggregator for rss feeds.',
    'category' => 'RSS',
  ),
  166 =>
  array (
    'name' => 'mastodon',
    'title' => 'mastodon',
    'description' => 'mastodon is a free, open-source social network server based on ActivityPub where users can follow friends and discover n',
    'category' => 'Social',
  ),
  167 =>
  array (
    'name' => 'qdirstat',
    'title' => 'QDirStat',
    'description' => 'QDirStat Qt-based directory statistics: KDirStat without any KDE -- from the author of the original KDirStat.',
    'category' => 'Storage',
  ),
  168 =>
  array (
    'name' => 'diskover',
    'title' => 'diskover',
    'description' => 'diskover is an open source file system indexer that uses Elasticsearch to index and manage data across heterogeneous sto',
    'category' => 'Storage,Monitoring',
  ),
  169 =>
  array (
    'name' => 'kdenlive',
    'title' => 'Kdenlive',
    'description' => 'Kdenlive is a powerful free and open source cross-platform video editing program made by the KDE community.',
    'category' => 'Video Editor',
  ),
  170 =>
  array (
    'name' => 'openshot',
    'title' => 'OpenShot',
    'description' => 'OpenShot Video Editor is an award-winning free and open-source video editor for Linux, Mac, and Windows, and is dedicate',
    'category' => 'Video Editor',
  ),
  171 =>
  array (
    'name' => 'shotcut',
    'title' => 'shotcut',
    'description' => 'shotcut is a free, open source, cross-platform video editor.',
    'category' => 'Video Editor',
  ),
  172 =>
  array (
    'name' => 'chrome',
    'title' => 'Chrome',
    'description' => 'Chrome is the official web browser from Google, built to be fast, secure, and customizable.',
    'category' => 'Web Browser',
  ),
  173 =>
  array (
    'name' => 'chromium',
    'title' => 'Chromium',
    'description' => 'Chromium is an open-source browser project that aims to build a safer, faster, and more stable way for all users to expe',
    'category' => 'Web Browser',
  ),
  174 =>
  array (
    'name' => 'firefox',
    'title' => 'Firefox',
    'description' => 'Firefox Browser, also known as Mozilla Firefox or simply Firefox, is a free and open-source web browser developed by the',
    'category' => 'Web Browser',
  ),
  175 =>
  array (
    'name' => 'helium',
    'title' => 'Helium',
    'description' => 'Helium is a Chromium-based web browser made for people, with love.',
    'category' => 'Web Browser',
  ),
  176 =>
  array (
    'name' => 'librewolf',
    'title' => 'LibreWolf',
    'description' => 'LibreWolf is a custom and independent version of Firefox, with the primary goals of privacy, security and user freedom.',
    'category' => 'Web Browser',
  ),
  177 =>
  array (
    'name' => 'msedge',
    'title' => 'Microsoft Edge',
    'description' => 'Microsoft Edge is a cross-platform web browser developed by Microsoft and based on Chromium.',
    'category' => 'Web Browser',
  ),
  178 =>
  array (
    'name' => 'opera',
    'title' => 'Opera',
    'description' => 'Opera is a multi-platform web browser developed by its namesake company Opera.',
    'category' => 'Web Browser',
  ),
  179 =>
  array (
    'name' => 'brave',
    'title' => 'The Brave browser',
    'description' => 'The Brave browser is a fast, private and secure web browser for PC, Mac and mobile.',
    'category' => 'Web Browser',
  ),
  180 =>
  array (
    'name' => 'ungoogled-chromium',
    'title' => 'Ungoogled Chromium',
    'description' => 'Ungoogled Chromium is Google Chromium, sans dependency on Google web services.',
    'category' => 'Web Browser',
  ),
  181 =>
  array (
    'name' => 'vivaldi',
    'title' => 'Vivaldi',
    'description' => 'Vivaldi is a Norwegian freeware, cross-platform web browser with a built-in email client developed by Vivaldi Technologi',
    'category' => 'Web Browser',
  ),
  182 =>
  array (
    'name' => 'zen',
    'title' => 'Zen Browser',
    'description' => 'Zen Browser is a free and open-source fork of Mozilla Firefox with a focus on privacy, customizability and design.',
    'category' => 'Web Browser',
  ),
  183 =>
  array (
    'name' => 'mullvad-browser',
    'title' => 'mullvad-browser',
    'description' => 'The Mullvad Browser is a privacy-focused web browser developed in a collaboration between Mullvad VPN and the Tor Projec',
    'category' => 'Web Browser,VPN',
  ),
));
