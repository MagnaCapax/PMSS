#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking quota and fixing' . "\n";

// If symlinks hasn't been created for compliance with debian when installed from source
// Only done if quota is compiled from source
if (file_exists('/usr/local/bin/quota')) {
 `rm -rf /usr/bin/quota`;
 `ln -s /usr/local/bin/quota /usr/bin/quota`;
}

echo `repquota -as`; // Report quotas
echo `quotaoff -av`;	// Turn off quota
echo `rm -rf /home/aquota*new`; // Remove possibly existing "new" quota files
echo `quotacheck -avugmn`; // do the quota checking and recalcs
sleep(1);
echo `quotaon -av`;	// Turn quota on
echo `repquota -as`; // Report quotas - so we can compare visually
