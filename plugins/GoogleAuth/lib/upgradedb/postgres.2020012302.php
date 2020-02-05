<?php

$this->BeginTrans();

$this->Execute("UPDATE dbinfo SET keyvalue = '2020012002' WHERE keytype='dbversion_GoogleAuth'");

$this->CommitTrans();