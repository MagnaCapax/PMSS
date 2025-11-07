<?php
// Rootless Docker installation script for Debian 10, 11, and 12
// #TODO Refactor to use runStep() for consistent logging instead of passthru.
// #TODO Move repo bootstrap into a unified third-party repo helper (deb822
//       sources + /etc/apt/keyrings signed-by) and reuse here.

// Repository and package installation handled centrally during package phase.
// Disable Docker service (we use rootless docker where applicable).
passthru("systemctl disable --now docker.service docker.socket");
passthru("rm /var/run/docker.sock");

// Enable unprivileged user namespace cloning
file_put_contents("/etc/sysctl.d/50-rootless.conf", "kernel.unprivileged_userns_clone = 1\n");
passthru("sysctl --system");

// Debian 10 and 11 extra modifications
if (version_compare($debianVersion, "12", "<")) {
    passthru("curl -o slirp4netns --fail -L https://github.com/rootless-containers/slirp4netns/releases/download/v1.3.2/slirp4netns-" . trim(shell_exec("uname -m")));
    passthru("install slirp4netns /usr/local/bin/");
    passthru("ln -s /usr/sbin/iptables /usr/local/bin/");
}

echo "Rootless Docker installation completed successfully.";
