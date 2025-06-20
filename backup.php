<?php
include 'server-backup.php';

$b = new ServerBackup;
$b->addPath('./testfile.php');
$b->addPath('./dir/');