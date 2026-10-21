#!/usr/bin/perl
use strict;
use warnings;
use Cwd qw(abs_path);
use Fcntl qw(O_RDONLY O_NOFOLLOW O_NONBLOCK);

# find -execdir supplies basenames while its cwd holds the traversed parent.
# Open the final inode without following a replacement link, then use ioctl on
# that handle. chattr's pathname ioctl would re-open the name after validation.
my $operation = (@ARGV && ($ARGV[0] eq '--set' || $ARGV[0] eq '--clear')) ? shift @ARGV : '';
my $expected = $operation ne '' ? (shift @ARGV // exit 1) : '';
exit 1 if $operation ne '' && $expected !~ /\A[0-9]+:[0-9]+\z/;
my $root = shift @ARGV // exit 1;
my $cwd = abs_path('.') // exit 1;
exit 1 unless $cwd eq $root || index($cwd, $root.'/') == 0;
my $changed = 0;

for my $path (@ARGV) {
    next if $path eq '' || $path =~ m{\A(?:/|\.\./)};
    my @named = lstat($path);
    next unless @named;
    my $type = $named[2] & 0170000;
    next unless $type == 0100000 || $type == 0040000;
    if ($operation ne '') {
        next unless $type == 0100000 && $named[4] == 0 && $named[3] == 1;
        next unless "$named[0]:$named[1]" eq $expected;
    }

    my $opened = sysopen(my $handle, $path, O_RDONLY | O_NOFOLLOW | O_NONBLOCK);
    next unless $opened;
    my @inode = stat($handle);
    my @current = lstat($path);
    if (!@inode || !@current || $inode[0] != $named[0] || $inode[1] != $named[1]
        || $current[0] != $named[0] || $current[1] != $named[1]
        || (abs_path('.') // '') ne $cwd
        || ($operation ne '' && ($inode[4] != 0 || $inode[3] != 1))) {
        close($handle);
        next;
    }

    # Linux FS_IOC_GETFLAGS/SETFLAGS and FS_IMMUTABLE_FL. Preserve every
    # unrelated flag; a failed ioctl leaves the entry for the later retry.
    my $flags = pack('I', 0);
    if (ioctl($handle, 0x80086601, $flags)) {
        my $value = unpack('I', $flags);
        my $wanted = $operation eq '--set' ? ($value | 0x10) : ($value & ~0x10);
        my $updated = pack('I', $wanted);
        if ($wanted == $value || ioctl($handle, 0x40086602, $updated)) {
            $changed++;
        }
    }
    close($handle);
}
exit 1 if $operation ne '' && $changed != 1;
