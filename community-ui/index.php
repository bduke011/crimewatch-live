<?php
declare(strict_types=1);require __DIR__.'/member-lib.php';header('Cache-Control: private, no-store');
if(!cw_member()&&!cw_owner_signed()){header('Location: account.php?required=1',true,303);exit;}
readfile(__DIR__.'/index.html');
