#!/usr/bin/perl
use strict;
use warnings;
use Cwd qw(abs_path);
use Fcntl qw(O_RDONLY O_NOFOLLOW O_NONBLOCK);

# find -execdir supplies basenames while its cwd holds the traversed parent.
# Open the final inode without following a replacement link, then use ioctl on
# that handle. chattr's pathname ioctl would re-open the name after validation.
my $root = shift @ARGV // exit 1;
my $cwd = abs_path('.') // exit 1;
exit 1 unless $cwd eq $root || index($cwd, $root.'/') == 0;

for my $path (@ARGV) {
    next if $path eq '' || $path =~ m{\A(?:/|\.\./)};
    my @named = lstat($path);
    next unless @named;
    my $type = $named[2] & 0170000;
    next unless $type == 0100000 || $type == 0040000;

    my $opened = sysopen(my $handle, $path, O_RDONLY | O_NOFOLLOW | O_NONBLOCK);
    next unless $opened;
    my @inode = stat($handle);
    my @current = lstat($path);
    if (!@inode || !@current || $inode[0] != $named[0] || $inode[1] != $named[1]
        || $current[0] != $named[0] || $current[1] != $named[1]
        || (abs_path('.') // '') ne $cwd) {
        close($handle);
        next;
    }

    # Linux FS_IOC_GETFLAGS/SETFLAGS and FS_IMMUTABLE_FL. Preserve every
    # unrelated flag; a failed ioctl leaves the entry for the later retry.
    my $flags = pack('I', 0);
    if (ioctl($handle, 0x80086601, $flags)) {
        my $value = unpack('I', $flags);
        if ($value & 0x10) {
            $flags = pack('I', $value & ~0x10);
            ioctl($handle, 0x40086602, $flags);
        }
    }
    close($handle);
}
