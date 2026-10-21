#!/usr/bin/env php
<?php
/**
 * Utility script: disk Usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$df = shell_exec('df /home | awk \'{ print $2,$3,$4 }\' | tail -n 1');

$segments = explode(' ', trim( $df ));


echo serialize($segments);
