<?php
// Rootless Docker installation script for Debian 10, 11, and 12

// Define required packages
$requiredPackages = ["dbus-user-session", "slirp4netns", "uidmap"];

// Add extra packages for Debian 10 and 11
if (version_compare($debianVersion, "12", "<")) {
    $requiredPackages[] = "fuse-overlayfs";
}

// Install required packages
passthru("apt update -y && apt install -y " . implode(" ", $requiredPackages));
passthru("apt install ca-certificates curl -y");

// Add Docker's official GPG key
passthru("install -m 0755 -d /etc/apt/keyrings");
passthru("curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc");
passthru("chmod a+r /etc/apt/keyrings/docker.asc");

// Get Debian codename
$debianCodename = trim(shell_exec(". /etc/os-release && echo \$VERSION_CODENAME"));

// Add the repository to Apt sources
$repoEntry = "deb [arch=" . trim(shell_exec("dpkg --print-architecture")) . " signed-by=/etc/apt/keyrings/docker.asc] ";
$repoEntry .= "https://download.docker.com/linux/debian $debianCodename stable";
file_put_contents("/etc/apt/sources.list.d/docker.list", $repoEntry . "\n");

passthru("apt update -y");
passthru("apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y");

// Disable Docker service
passthru("systemctl disable --now docker.service docker.socket");
passthru("rm /var/run/docker.sock");

// Enable unprivileged user namespace cloning
file_put_contents("/etc/sysctl.d/50-rootless.conf", "kernel.unprivileged_userns_clone = 1\n");
passthru("sysctl --system");

// Debian 10 and 11 extra modifications
if (version_compare($debianVersion, "12", "<")) {
    passthru("curl -o slirp4netns --fail -L https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-" . trim(shell_exec("uname -m")));
    passthru("install slirp4netns /usr/local/bin/");
    passthru("apt purge slirp4netns -y");
    passthru("ln -s /usr/sbin/iptables /usr/local/bin/");
}

echo "Rootless Docker installation completed successfully.";
